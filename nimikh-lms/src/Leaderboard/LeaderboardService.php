<?php
declare(strict_types=1);

namespace Nimikh\LMS\Leaderboard;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Support\Installer;

/**
 * Per-course leaderboard. Privacy by design: a learner appears only after opting in (off by default), others see
 * "Rafi A." not a full name, and a learner who has not opted in still sees their own private rank.
 */
final class LeaderboardService {

	public const META_OPTIN = 'nimikh_leaderboard_optin';

	public function __construct(private TutorAdapter $tutor) {}

	public static function optedIn(int $userId): bool {
		return get_user_meta($userId, self::META_OPTIN, true) === '1';
	}

	public static function setOptIn(int $userId, bool $on): void {
		$on ? update_user_meta($userId, self::META_OPTIN, '1') : delete_user_meta($userId, self::META_OPTIN);
	}

	/** @return array<int, array{user_id:int, score:int, rank:int}> every enrolled learner, ranked */
	private function scores(int $courseId, array $userIds): array {
		global $wpdb;
		$lessons = $this->tutor->lessonIdsForCourse($courseId);
		if (!$userIds || !$lessons) {
			return [];
		}
		$L = implode(',', array_map('intval', $lessons));
		$U = implode(',', array_map('intval', $userIds));
		$w = Installer::table('watch_progress');
		$i = Installer::table('video_interactions');
		$a = Installer::table('interaction_attempts');
		$c = Installer::table('certificates');

		$done = [];
		foreach ($wpdb->get_results("SELECT user_id, COUNT(*) AS n FROM $w WHERE lesson_id IN ($L) AND completed_at IS NOT NULL AND user_id IN ($U) GROUP BY user_id", ARRAY_A) ?: [] as $r) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids are cast to int above
			$done[(int) $r['user_id']] = (int) $r['n'];
		}
		$mcq = [];
		foreach ($wpdb->get_results("SELECT x.user_id, SUM(i.points) AS pts FROM $i i JOIN (SELECT DISTINCT user_id, interaction_id FROM $a WHERE is_correct = 1 AND user_id IN ($U)) x ON x.interaction_id = i.id WHERE i.lesson_id IN ($L) GROUP BY x.user_id", ARRAY_A) ?: [] as $r) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$mcq[(int) $r['user_id']] = (int) $r['pts'];
		}
		$certs = array_flip(array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT user_id FROM $c WHERE course_id = %d AND status = 'valid' AND user_id IN ($U)", $courseId)) ?: [])); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = [];
		foreach ($userIds as $uid) {
			$rows[] = ['user_id' => (int) $uid, 'score' => Ranking::score($done[$uid] ?? 0, $mcq[$uid] ?? 0, isset($certs[$uid]))];
		}
		return Ranking::rank($rows);
	}

	/**
	 * @return array{top: array<int, array{rank:int, name:string, score:int, me:bool}>, me: ?array{rank:int, score:int, optin:bool}, total:int}
	 */
	public function forCourse(int $courseId, int $viewerId, int $limit = 10): array {
		$enrolled = $this->tutor->enrolledUserIds($courseId);
		$ranked   = $this->scores($courseId, $enrolled);

		$top = [];
		foreach ($ranked as $r) {
			if (!self::optedIn($r['user_id'])) {
				continue;
			}
			if (count($top) >= $limit && $r['user_id'] !== $viewerId) {
				continue;
			}
			$user  = get_userdata($r['user_id']);
			$top[] = ['rank' => $r['rank'], 'name' => $user ? Ranking::publicName($user->display_name) : '—', 'score' => $r['score'], 'me' => $r['user_id'] === $viewerId];
		}
		$top = array_slice($top, 0, $limit);

		$me = null;
		foreach ($ranked as $r) {
			if ($r['user_id'] === $viewerId) {
				$me = ['rank' => $r['rank'], 'score' => $r['score'], 'optin' => self::optedIn($viewerId)];
			}
		}
		return ['top' => $top, 'me' => $me, 'total' => count($ranked)];
	}
}
