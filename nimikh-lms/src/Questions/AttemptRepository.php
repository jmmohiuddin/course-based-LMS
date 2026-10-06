<?php
declare(strict_types=1);

namespace Nimikh\LMS\Questions;

use Nimikh\LMS\Support\Installer;
use Nimikh\LMS\Support\Time;

final class AttemptRepository {

	public function record(int $userId, int $interactionId, int $selected, bool $correct, int $attemptNo): void {
		global $wpdb;
		$wpdb->insert(Installer::table('interaction_attempts'), [
			'user_id'        => $userId,
			'interaction_id' => $interactionId,
			'selected_index' => $selected,
			'is_correct'     => $correct ? 1 : 0,
			'attempt_no'     => $attemptNo,
			'answered_at'    => Time::nowGmt(),
		]);
	}

	/** @return array{attempts:int, ever_correct:bool} */
	public function summary(int $userId, int $interactionId): array {
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare(
			'SELECT COUNT(*) AS attempts, COALESCE(MAX(is_correct),0) AS ever_correct FROM '
			. Installer::table('interaction_attempts') . ' WHERE user_id = %d AND interaction_id = %d',
			$userId,
			$interactionId
		), ARRAY_A);
		return ['attempts' => (int) ($row['attempts'] ?? 0), 'ever_correct' => (bool) ($row['ever_correct'] ?? 0)];
	}

	/**
	 * Attempt summaries for many interactions at once.
	 *
	 * @param int[] $interactionIds
	 * @return array<int, array{attempts:int, ever_correct:bool}>
	 */
	public function summaries(int $userId, array $interactionIds): array {
		global $wpdb;
		$out = [];
		if (!$interactionIds) {
			return $out;
		}
		$ids  = implode(',', array_map('intval', $interactionIds));
		$rows = $wpdb->get_results($wpdb->prepare(
			'SELECT interaction_id, COUNT(*) AS attempts, MAX(is_correct) AS ever_correct FROM '
			. Installer::table('interaction_attempts')
			. " WHERE user_id = %d AND interaction_id IN ($ids) GROUP BY interaction_id",
			$userId
		), ARRAY_A) ?: [];
		foreach ($rows as $r) {
			$out[(int) $r['interaction_id']] = ['attempts' => (int) $r['attempts'], 'ever_correct' => (bool) $r['ever_correct']];
		}
		return $out;
	}

	/**
	 * Points earned vs available across a set of lessons (optional questions count 0 if skipped).
	 *
	 * @param int[] $lessonIds
	 * @return array{earned:int,total:int}
	 */
	public function scoreForLessons(int $userId, array $lessonIds): array {
		global $wpdb;
		if (!$lessonIds) {
			return ['earned' => 0, 'total' => 0];
		}
		$ids = implode(',', array_map('intval', $lessonIds));
		$i   = Installer::table('video_interactions');
		$a   = Installer::table('interaction_attempts');
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT COALESCE(SUM(i.points),0) AS total,
			        COALESCE(SUM(CASE WHEN EXISTS (
			            SELECT 1 FROM $a a WHERE a.interaction_id = i.id AND a.user_id = %d AND a.is_correct = 1
			        ) THEN i.points ELSE 0 END),0) AS earned
			 FROM $i i WHERE i.lesson_id IN ($ids)",
			$userId
		), ARRAY_A);
		return ['earned' => (int) ($row['earned'] ?? 0), 'total' => (int) ($row['total'] ?? 0)];
	}
}
