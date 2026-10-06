<?php
declare(strict_types=1);

namespace Nimikh\LMS\Certificates;

/**
 * FR-06: a course is complete when every required lesson is complete AND the exam is
 * passed AND (optionally) the average in-video MCQ score meets a minimum.
 */
final class RuleEngine {

	/**
	 * @param array{lessons_total:int, lessons_completed:int, has_exam:bool, exam_percent:?float, mcq_percent:?float} $progress
	 * @param array{exam_pass_percent:float, min_mcq_percent:float} $rules
	 * @return array{passed:bool, failures:string[]}
	 */
	public static function evaluate(array $progress, array $rules): array {
		$failures = [];

		if ($progress['lessons_total'] > 0 && $progress['lessons_completed'] < $progress['lessons_total']) {
			$failures[] = 'lessons_incomplete';
		}

		if ($progress['has_exam']) {
			if ($progress['exam_percent'] === null || $progress['exam_percent'] < $rules['exam_pass_percent']) {
				$failures[] = 'exam_not_passed';
			}
		}

		if ($rules['min_mcq_percent'] > 0 && $progress['mcq_percent'] !== null
			&& $progress['mcq_percent'] < $rules['min_mcq_percent']) {
			$failures[] = 'mcq_score_too_low';
		}

		return ['passed' => $failures === [], 'failures' => $failures];
	}

	/** Headline score shown on the certificate: exam score if there is one, else MCQ average. */
	public static function headlineScore(array $progress): ?float {
		return $progress['exam_percent'] ?? $progress['mcq_percent'];
	}
}
