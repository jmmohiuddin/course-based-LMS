<?php
declare(strict_types=1);

namespace Nimikh\LMS\Exam;

/**
 * After a failed exam, suggest which lessons to rewatch. Tutor's exam questions carry no topic, so the signal is
 * the learner's own in-video question accuracy and watch percentage per lesson: weakest first.
 */
final class Rewatch {

	public const WEAK_MCQ_PERCENT = 70.0;

	/**
	 * @param array<int, array{lesson_id:int, mcq_percent:?float, watch_percent:float}> $lessons
	 * @return array<int, array{lesson_id:int, reason:string, mcq_percent:?float, watch_percent:float}>
	 */
	public static function suggest(array $lessons, float $minWatchPercent, int $limit = 5): array {
		$out = [];
		foreach ($lessons as $l) {
			$weakMcq   = $l['mcq_percent'] !== null && $l['mcq_percent'] < self::WEAK_MCQ_PERCENT;
			$lowWatch  = $l['watch_percent'] < $minWatchPercent;
			if (!$weakMcq && !$lowWatch) {
				continue;
			}
			$out[] = [
				'lesson_id'     => $l['lesson_id'],
				'reason'        => $weakMcq ? 'weak_questions' : 'not_fully_watched',
				'mcq_percent'   => $l['mcq_percent'],
				'watch_percent' => $l['watch_percent'],
			];
		}
		// Weakest question accuracy first; lessons without questions sort by watch percentage.
		usort($out, static function (array $a, array $b): int {
			return [$a['mcq_percent'] ?? 101.0, $a['watch_percent'], $a['lesson_id']] <=> [$b['mcq_percent'] ?? 101.0, $b['watch_percent'], $b['lesson_id']];
		});
		return array_slice($out, 0, max(0, $limit));
	}
}
