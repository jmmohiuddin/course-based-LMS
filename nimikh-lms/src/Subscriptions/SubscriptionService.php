<?php
declare(strict_types=1);

namespace Nimikh\LMS\Subscriptions;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Support\Installer;
use Nimikh\LMS\Support\Time;

/**
 * Phase 2 subscriptions: a plan unlocks a set of courses for N days. Payment is out of scope here:
 * any gateway integration calls do_action('nimikh_subscription_paid', $userId, $planId, $reference).
 */
final class SubscriptionService {

	public const CRON_HOOK = 'nimikh_expire_subscriptions';

	public function __construct(private TutorAdapter $tutor) {}

	public function register(): void {
		add_action('nimikh_subscription_paid', [$this, 'onPaid'], 10, 3);
		add_action(self::CRON_HOOK, [$this, 'expireDue']);
		add_filter('nimikh_lms_is_enrolled', [$this, 'filterEnrolled'], 20, 3);
		if (!wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
		}
	}

	// ---- plans ------------------------------------------------------------------

	/** @param int[] $courseIds */
	public function createPlan(string $name, array $courseIds, int $days, string $priceLabel = ''): int {
		global $wpdb;
		$wpdb->insert(Installer::table('plans'), [
			'name'          => mb_substr(sanitize_text_field($name), 0, 120),
			'course_ids'    => wp_json_encode(array_values(array_unique(array_map('intval', $courseIds)))),
			'duration_days' => max(1, $days),
			'price_label'   => mb_substr(sanitize_text_field($priceLabel), 0, 60),
			'status'        => 'active',
		]);
		return (int) $wpdb->insert_id;
	}

	/** @return array<string, mixed>|null */
	public function plan(int $id): ?array {
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Installer::table('plans') . ' WHERE id = %d', $id), ARRAY_A);
		return $row ? $this->hydratePlan($row) : null;
	}

	/** @return array<int, array<string, mixed>> */
	public function plans(bool $activeOnly = true): array {
		global $wpdb;
		$where = $activeOnly ? " WHERE status = 'active'" : '';
		$rows  = $wpdb->get_results('SELECT * FROM ' . Installer::table('plans') . $where . ' ORDER BY id ASC', ARRAY_A) ?: [];
		return array_map([$this, 'hydratePlan'], $rows);
	}

	public function setPlanStatus(int $id, string $status): void {
		global $wpdb;
		$wpdb->update(Installer::table('plans'), ['status' => $status === 'active' ? 'active' : 'archived'], ['id' => $id]);
	}

	/** @param array<string, mixed> $row */
	private function hydratePlan(array $row): array {
		return [
			'id'            => (int) $row['id'],
			'name'          => (string) $row['name'],
			'course_ids'    => array_map('intval', json_decode((string) $row['course_ids'], true) ?: []),
			'duration_days' => (int) $row['duration_days'],
			'price_label'   => (string) $row['price_label'],
			'status'        => (string) $row['status'],
		];
	}

	// ---- subscriptions -----------------------------------------------------------

	public function onPaid(int $userId, int $planId, string $reference = ''): void {
		$this->grant($userId, $planId, $reference !== '' ? 'paid:' . $reference : 'paid');
	}

	/** @return array<string, mixed>|\WP_Error */
	public function grant(int $userId, int $planId, string $source = 'manual') {
		global $wpdb;
		$plan = $this->plan($planId);
		if (!$plan || $plan['status'] !== 'active') {
			return new \WP_Error('nimikh_plan', __('Plan not found.', 'nimikh-lms'), ['status' => 404]);
		}
		if (!get_userdata($userId)) {
			return new \WP_Error('nimikh_user', __('User not found.', 'nimikh-lms'), ['status' => 404]);
		}
		$table = Installer::table('subscriptions');
		$now   = Time::nowGmt();

		$current = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM $table WHERE user_id = %d AND plan_id = %d AND status = 'active' AND expires_at > %s ORDER BY expires_at DESC LIMIT 1",
			$userId,
			$planId,
			$now
		), ARRAY_A);

		if ($current) { // renewal: extend, losing no remaining days
			$expires = Window::newExpiry((string) $current['expires_at'], $now, $plan['duration_days']);
			$wpdb->update($table, ['expires_at' => $expires], ['id' => (int) $current['id']]);
			$id = (int) $current['id'];
		} else {
			$created = [];
			foreach ($plan['course_ids'] as $courseId) {
				if (!$this->tutor->isEnrolled($userId, $courseId)) {
					$this->tutor->enrol($userId, $courseId);
					$created[] = $courseId; // only these may be cancelled when the subscription ends
				}
			}
			$expires = Window::newExpiry(null, $now, $plan['duration_days']);
			$wpdb->insert($table, [
				'user_id'         => $userId,
				'plan_id'         => $planId,
				'starts_at'       => $now,
				'expires_at'      => $expires,
				'status'          => 'active',
				'source'          => mb_substr($source, 0, 60),
				'enrolled_by_sub' => wp_json_encode($created),
			]);
			$id = (int) $wpdb->insert_id;
		}
		do_action('nimikh_subscription_granted', $id, $userId, $planId);
		return $this->subscription($id) ?? [];
	}

	public function cancel(int $subscriptionId): bool {
		global $wpdb;
		$sub = $this->subscription($subscriptionId);
		if (!$sub || $sub['status'] !== 'active') {
			return false;
		}
		$wpdb->update(Installer::table('subscriptions'), ['status' => 'cancelled'], ['id' => $subscriptionId]);
		$this->releaseEnrolments($sub);
		return true;
	}

	/** Cron: close lapsed subscriptions and release the enrolments they created. */
	public function expireDue(): int {
		global $wpdb;
		$table = Installer::table('subscriptions');
		$rows  = $wpdb->get_results($wpdb->prepare("SELECT id FROM $table WHERE status = 'active' AND expires_at <= %s", Time::nowGmt()), ARRAY_A) ?: [];
		foreach ($rows as $r) {
			$sub = $this->subscription((int) $r['id']);
			$wpdb->update($table, ['status' => 'expired'], ['id' => (int) $r['id']]);
			if ($sub) {
				$this->releaseEnrolments($sub);
				do_action('nimikh_subscription_expired', $sub['id'], $sub['user_id']);
			}
		}
		return count($rows);
	}

	/** @param array<string, mixed> $sub */
	private function releaseEnrolments(array $sub): void {
		foreach ($sub['enrolled_by_sub'] as $courseId) {
			if (!in_array($courseId, $this->activeCourseIds($sub['user_id'], $sub['id']), true)) {
				$this->tutor->cancelEnrolment($sub['user_id'], $courseId);
			}
		}
	}

	/** @return int[] courses unlocked by active subscriptions, optionally ignoring one subscription */
	public function activeCourseIds(int $userId, int $exceptSubscriptionId = 0): array {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			'SELECT id, plan_id FROM ' . Installer::table('subscriptions') . " WHERE user_id = %d AND status = 'active' AND expires_at > %s",
			$userId,
			Time::nowGmt()
		), ARRAY_A) ?: [];
		$out = [];
		foreach ($rows as $r) {
			if ((int) $r['id'] === $exceptSubscriptionId) {
				continue;
			}
			$plan = $this->plan((int) $r['plan_id']);
			$out  = array_merge($out, $plan['course_ids'] ?? []);
		}
		return array_values(array_unique($out));
	}

	/** Access check used by the REST layer even when Tutor's own enrolment record is absent. */
	public function filterEnrolled(bool $enrolled, int $userId, int $courseId): bool {
		return $enrolled || ($userId > 0 && in_array($courseId, $this->activeCourseIds($userId), true));
	}

	/** @return array<string, mixed>|null */
	public function subscription(int $id): ?array {
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Installer::table('subscriptions') . ' WHERE id = %d', $id), ARRAY_A);
		return $row ? $this->hydrate($row) : null;
	}

	/** @return array<int, array<string, mixed>> */
	public function recent(int $limit = 50): array {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Installer::table('subscriptions') . ' ORDER BY id DESC LIMIT %d', $limit), ARRAY_A) ?: [];
		return array_map([$this, 'hydrate'], $rows);
	}

	/** @return array<int, array<string, mixed>> */
	public function forUser(int $userId): array {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			'SELECT * FROM ' . Installer::table('subscriptions') . ' WHERE user_id = %d ORDER BY id DESC',
			$userId
		), ARRAY_A) ?: [];
		return array_map([$this, 'hydrate'], $rows);
	}

	/** @param array<string, mixed> $row */
	private function hydrate(array $row): array {
		$plan = $this->plan((int) $row['plan_id']);
		return [
			'id'              => (int) $row['id'],
			'user_id'         => (int) $row['user_id'],
			'plan_id'         => (int) $row['plan_id'],
			'plan'            => $plan['name'] ?? '',
			'starts_at'       => (string) $row['starts_at'],
			'expires_at'      => (string) $row['expires_at'],
			'status'          => (string) $row['status'],
			'source'          => (string) $row['source'],
			'enrolled_by_sub' => array_map('intval', json_decode((string) ($row['enrolled_by_sub'] ?? '[]'), true) ?: []),
		];
	}
}
