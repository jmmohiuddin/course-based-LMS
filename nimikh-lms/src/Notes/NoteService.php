<?php
declare(strict_types=1);

namespace Nimikh\LMS\Notes;

use Nimikh\LMS\Progress\ProgressService;
use Nimikh\LMS\Support\Installer;
use Nimikh\LMS\Support\RateLimiter;
use Nimikh\LMS\Support\Time;

/** Private, timestamped notes a learner takes while watching a lesson. */
final class NoteService {

	public const MAX_LENGTH = 500;
	public const MAX_PER_LESSON = 200;

	public function __construct(private ProgressService $progress) {}

	/** @return array<int, array{id:int, at_second:int, body:string, created_at:string}> */
	public function forLesson(int $userId, int $lessonId): array {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			'SELECT id, at_second, body, created_at FROM ' . Installer::table('notes') . ' WHERE user_id = %d AND lesson_id = %d ORDER BY at_second ASC, id ASC',
			$userId,
			$lessonId
		), ARRAY_A) ?: [];
		return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'at_second' => (int) $r['at_second'], 'body' => (string) $r['body'], 'created_at' => (string) $r['created_at']], $rows);
	}

	/** @return array{id:int, at_second:int, body:string, created_at:string}|\WP_Error */
	public function add(int $userId, int $lessonId, int $atSecond, string $body) {
		$body = trim(sanitize_textarea_field($body));
		if ($body === '') {
			return new \WP_Error('nimikh_note_empty', __('Write something first.', 'nimikh-lms'), ['status' => 400]);
		}
		if (mb_strlen($body) > self::MAX_LENGTH) {
			return new \WP_Error('nimikh_note_long', __('That note is too long.', 'nimikh-lms'), ['status' => 400]);
		}
		$duration = $this->progress->duration($lessonId);
		if ($atSecond < 0 || ($duration > 0 && $atSecond > $duration)) {
			return new \WP_Error('nimikh_note_time', __('That time is outside the video.', 'nimikh-lms'), ['status' => 400]);
		}
		if (!RateLimiter::hit('notes', (string) $userId, 30)) {
			return new \WP_Error('nimikh_rate_limited', __('Slow down a little.', 'nimikh-lms'), ['status' => 429]);
		}
		global $wpdb;
		$table = Installer::table('notes');
		$count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE user_id = %d AND lesson_id = %d", $userId, $lessonId));
		if ($count >= self::MAX_PER_LESSON) {
			return new \WP_Error('nimikh_note_limit', __('You have reached the note limit for this lesson.', 'nimikh-lms'), ['status' => 409]);
		}
		$now = Time::nowGmt();
		$wpdb->insert($table, ['user_id' => $userId, 'lesson_id' => $lessonId, 'at_second' => $atSecond, 'body' => $body, 'created_at' => $now]);
		return ['id' => (int) $wpdb->insert_id, 'at_second' => $atSecond, 'body' => $body, 'created_at' => $now];
	}

	public function delete(int $userId, int $noteId): bool {
		global $wpdb;
		return (bool) $wpdb->delete(Installer::table('notes'), ['id' => $noteId, 'user_id' => $userId]);
	}
}
