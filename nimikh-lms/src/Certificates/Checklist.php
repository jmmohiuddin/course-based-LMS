<?php
declare(strict_types=1);

namespace Nimikh\LMS\Certificates;

/**
 * UX principle 4 ("show the finish line"): what is still left before the certificate.
 * Built from the same rule engine that issues certificates, so the two can never disagree.
 */
final class Checklist {

	/**
	 * @param array{lessons_total:int, lessons_completed:int, has_exam:bool, exam_percent:?float, mcq_percent:?float} $progress
	 * @param array{exam_pass_percent:float, min_mcq_percent:float} $rules
	 * @return array{items:array<int, array{key:string, done:bool, current:?float, target:?float}>, done:int, total:int, percent:int, ready:bool}
	 */
	public static function build(array $progress, array $rules): array {
		$failed = RuleEngine::evaluate($progress, $rules)['failures'];
		$items  = [];

		if ($progress['lessons_total'] > 0) {
			$items[] = [
				'key'     => 'lessons',
				'done'    => !in_array('lessons_incomplete', $failed, true),
				'current' => (float) $progress['lessons_completed'],
				'target'  => (float) $progress['lessons_total'],
			];
		}
		if ($progress['has_exam']) {
			$items[] = [
				'key'     => 'exam',
				'done'    => !in_array('exam_not_passed', $failed, true),
				'current' => $progress['exam_percent'],
				'target'  => $rules['exam_pass_percent'],
			];
		}
		if ($rules['min_mcq_percent'] > 0) {
			$items[] = [
				'key'     => 'mcq',
				'done'    => !in_array('mcq_score_too_low', $failed, true),
				'current' => $progress['mcq_percent'],
				'target'  => $rules['min_mcq_percent'],
			];
		}

		$total = count($items);
		$done  = count(array_filter($items, static fn(array $i): bool => $i['done']));

		return [
			'items'   => $items,
			'done'    => $done,
			'total'   => $total,
			'percent' => $total === 0 ? 0 : (int) round($done / $total * 100),
			'ready'   => $failed === [],
		];
	}
}
