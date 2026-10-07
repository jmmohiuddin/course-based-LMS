<?php
declare(strict_types=1);

namespace Nimikh\LMS\Live;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Support\Installer;
use Nimikh\LMS\Support\Settings;
use Nimikh\LMS\Support\Time;

/**
 * Phase 3 live classes. WordPress does not host video: a session points at Jitsi (auto-created room),
 * or a Zoom / Google Meet / other https link. Joining goes through the server so attendance is recorded
 * and only enrolled learners ever receive the join URL.
 */
final class LiveService {

	public const CRON_HOOK = 'nimikh_live_reminders';
	public const REMIND_WITHIN_MINUTES = 60;

	public function __construct(private TutorAdapter $tutor) {}

	public function register(): void {
		add_action(self::CRON_HOOK, [$this, 'sendReminders']);
		if (!wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time() + 120, 'nimikh_five_minutes', self::CRON_HOOK);
		}
		add_filter('cron_schedules', static function (array $s): array {
			$s['nimikh_five_minutes'] = ['interval' => 300, 'display' => __('Every five minutes', 'nimikh-lms')];
			return $s;
		});
	}

	/**
	 * @param array<string, mixed> $in {course_id, title, starts_at (ISO 8601 or "Y-m-d H:i" site time), duration_min, provider, join_url}
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create(int $userId, array $in) {
		$courseId = (int) ($in['course_id'] ?? 0);
		if ($courseId <= 0 || !$this->tutor->canManageCourse($userId, $courseId)) {
			return new \WP_Error('nimikh_forbidden', __('You do not manage this course.', 'nimikh-lms'), ['status' => 403]);
		}
		$title = mb_substr(sanitize_text_field((string) ($in['title'] ?? '')), 0, 160);
		if ($title === '') {
			return new \WP_Error('nimikh_live_title', __('Give the class a title.', 'nimikh-lms'), ['status' => 400]);
		}
		$ts = $this->parseTime((string) ($in['starts_at'] ?? ''));
		if ($ts === null || $ts < time() - 300) {
			return new \WP_Error('nimikh_live_time', __('Choose a start time in the future.', 'nimikh-lms'), ['status' => 400]);
		}
		$duration = max(10, min(480, (int) ($in['duration_min'] ?? 60)));

		$provider = in_array($in['provider'] ?? 'jitsi', ['jitsi', 'zoom', 'meet', 'other'], true) ? (string) ($in['provider'] ?? 'jitsi') : 'jitsi';
		$room = '';
		$url  = '';
		if ($provider === 'jitsi') {
			$room = SessionState::roomName();
			$url  = 'https://' . Settings::get('jitsi_host') . '/' . $room;
		} else {
			$url = trim((string) ($in['join_url'] ?? ''));
			$host = (string) wp_parse_url($url, PHP_URL_HOST);
			if (!str_starts_with($url, 'https://') || $host === '' || strlen($url) > 500) {
				return new \WP_Error('nimikh_live_url', __('Join link must be an https URL.', 'nimikh-lms'), ['status' => 400]);
			}
			$url = esc_url_raw($url, ['https']);
		}

		global $wpdb;
		$wpdb->insert(Installer::table('live_sessions'), [
			'course_id'    => $courseId,
			'title'        => $title,
			'starts_at'    => gmdate('Y-m-d H:i:s', $ts),
			'duration_min' => $duration,
			'provider'     => $provider,
			'join_url'     => $url,
			'room'         => $room,
			'status'       => 'scheduled',
			'created_by'   => $userId,
		]);
		$id = (int) $wpdb->insert_id;
		do_action('nimikh_live_scheduled', $id, $courseId);
		return $this->present($this->find($id) ?? [], false);
	}

	public function cancel(int $userId, int $id): bool {
		global $wpdb;
		$row = $this->find($id);
		if (!$row || !$this->tutor->canManageCourse($userId, (int) $row['course_id'])) {
			return false;
		}
		$wpdb->update(Installer::table('live_sessions'), ['status' => 'cancelled'], ['id' => $id]);
		return true;
	}

	/** @return array<int, array<string, mixed>> sessions that are not over yet; join URLs are never included */
	public function upcoming(int $courseId): array {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			'SELECT * FROM ' . Installer::table('live_sessions') . " WHERE course_id = %d AND status = 'scheduled' ORDER BY starts_at ASC LIMIT 50",
			$courseId
		), ARRAY_A) ?: [];
		$out = [];
		foreach ($rows as $r) {
			$p = $this->present($r, false);
			if ($p['state'] !== 'ended') {
				$out[] = $p;
			}
		}
		return $out;
	}

	/** @return array{url:string, state:string}|\WP_Error */
	public function join(int $userId, int $id) {
		$row = $this->find($id);
		if (!$row) {
			return new \WP_Error('nimikh_not_found', __('Class not found.', 'nimikh-lms'), ['status' => 404]);
		}
		$courseId = (int) $row['course_id'];
		if (!$this->tutor->isEnrolled($userId, $courseId) && !$this->tutor->canManageCourse($userId, $courseId)) {
			return new \WP_Error('nimikh_forbidden', __('You are not enrolled in this course.', 'nimikh-lms'), ['status' => 403]);
		}
		$state = $this->state($row);
		if (!SessionState::canJoin($state)) {
			return new \WP_Error('nimikh_live_closed', $state === 'upcoming' ? __('This class has not opened yet. You can join 10 minutes before it starts.', 'nimikh-lms') : __('This class is over.', 'nimikh-lms'), ['status' => 409]);
		}
		global $wpdb;
		$table = Installer::table('live_attendance');
		if (!$wpdb->get_var($wpdb->prepare("SELECT 1 FROM $table WHERE session_id = %d AND user_id = %d", $id, $userId))) {
			$wpdb->insert($table, ['session_id' => $id, 'user_id' => $userId, 'joined_at' => Time::nowGmt()]);
		}
		return ['url' => (string) $row['join_url'], 'state' => $state];
	}

	/** @return array<int, array{user_id:int, name:string, joined_at:string}>|null null if not allowed */
	public function attendance(int $userId, int $id): ?array {
		global $wpdb;
		$row = $this->find($id);
		if (!$row || !$this->tutor->canManageCourse($userId, (int) $row['course_id'])) {
			return null;
		}
		$rows = $wpdb->get_results($wpdb->prepare('SELECT user_id, joined_at FROM ' . Installer::table('live_attendance') . ' WHERE session_id = %d ORDER BY joined_at ASC', $id), ARRAY_A) ?: [];
		return array_map(static function (array $r): array {
			$u = get_userdata((int) $r['user_id']);
			return ['user_id' => (int) $r['user_id'], 'name' => $u ? $u->display_name : '', 'joined_at' => (string) $r['joined_at']];
		}, $rows);
	}

	/** Cron: remind enrolled learners about classes starting within the hour (once per session). */
	public function sendReminders(): int {
		global $wpdb;
		$table = Installer::table('live_sessions');
		$now   = Time::nowGmt();
		$until = gmdate('Y-m-d H:i:s', time() + self::REMIND_WITHIN_MINUTES * 60);
		$rows  = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM $table WHERE status = 'scheduled' AND reminded_at IS NULL AND starts_at > %s AND starts_at <= %s",
			$now,
			$until
		), ARRAY_A) ?: [];

		foreach ($rows as $row) {
			$wpdb->update($table, ['reminded_at' => $now], ['id' => (int) $row['id']]); // mark first: never double-send
			$when = wp_date(get_option('time_format'), strtotime($row['starts_at'] . ' UTC'));
			foreach ($this->tutor->enrolledUserIds((int) $row['course_id']) as $uid) {
				$user = get_userdata($uid);
				if (!$user) {
					continue;
				}
				$url = (string) get_permalink((int) $row['course_id']);
				wp_mail($user->user_email, sprintf(__('Live class soon: %s', 'nimikh-lms'), $row['title']), sprintf(__("Your live class \"%1\$s\" starts at %2\$s.\nOpen your course to join: %3\$s", 'nimikh-lms'), $row['title'], $when, $url));
				do_action('nimikh_notify_sms', $uid, 'live_reminder', ['title' => $row['title'], 'time' => $when, 'url' => $url]);
			}
		}
		return count($rows);
	}

	/** @return array<string, mixed>|null */
	private function find(int $id): ?array {
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Installer::table('live_sessions') . ' WHERE id = %d', $id), ARRAY_A) ?: null;
	}

	/** @param array<string, mixed> $row */
	private function state(array $row): string {
		return SessionState::state(Time::toTimestamp((string) $row['starts_at']), (int) $row['duration_min'], time(), (string) $row['status']);
	}

	/** @param array<string, mixed> $row */
	private function present(array $row, bool $withUrl): array {
		return [
			'id'           => (int) ($row['id'] ?? 0),
			'course_id'    => (int) ($row['course_id'] ?? 0),
			'title'        => (string) ($row['title'] ?? ''),
			'starts_at'    => (string) ($row['starts_at'] ?? ''),
			'duration_min' => (int) ($row['duration_min'] ?? 0),
			'provider'     => (string) ($row['provider'] ?? ''),
			'state'        => $row ? $this->state($row) : 'ended',
		] + ($withUrl ? ['join_url' => (string) $row['join_url']] : []);
	}

	private function parseTime(string $input): ?int {
		$input = trim($input);
		if ($input === '') {
			return null;
		}
		try {
			// Inputs without a zone ("2026-10-12 19:00") are in the site's timezone.
			$dt = new \DateTimeImmutable($input, wp_timezone());
		} catch (\Exception $e) {
			return null;
		}
		return $dt->getTimestamp();
	}
}
