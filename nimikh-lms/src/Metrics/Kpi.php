<?php
declare(strict_types=1);

namespace Nimikh\LMS\Metrics;

/** Success metrics and targets from PRD 4.1. Pure: turns numbers into a verdict. */
final class Kpi {

	public const COMPLETION_MIN   = 35.0;   // % of enrolled learners who earn the certificate
	public const MCQ_ANSWER_MIN   = 90.0;   // % of shown in-video questions that get answered
	public const FIRST_PASS_MIN   = 60.0;   // first-attempt exam pass rate band: 60-80 %
	public const FIRST_PASS_MAX   = 80.0;   // above this the exam is probably too easy
	public const VERIFY_MIN       = 20.0;   // >= 1 verification page view per 5 certificates
	public const PAID_MIN         = 300;    // paid enrolments in the first 90 days
	public const VIDEO_START_MAX  = 3000;   // ms on 4G
	public const LCP_MAX          = 2500;   // ms

	public static function percent(int $part, int $whole): ?float {
		return $whole > 0 ? round($part / $whole * 100, 1) : null;
	}

	/** Nearest-rank percentile; null for an empty set. @param int[] $values */
	public static function percentile(array $values, float $p): ?int {
		if (!$values) {
			return null;
		}
		sort($values);
		$rank = (int) ceil($p / 100 * count($values));
		return $values[max(0, min(count($values) - 1, $rank - 1))];
	}

	/** @return 'met'|'missed'|'watch'|'no_data' */
	public static function status(string $key, ?float $value): string {
		if ($value === null) {
			return 'no_data';
		}
		return match ($key) {
			'completion'   => $value >= self::COMPLETION_MIN ? 'met' : 'missed',
			'mcq_answer'   => $value >= self::MCQ_ANSWER_MIN ? 'met' : 'missed',
			'first_pass'   => $value > self::FIRST_PASS_MAX ? 'watch' : ($value >= self::FIRST_PASS_MIN ? 'met' : 'missed'),
			'verify_views' => $value >= self::VERIFY_MIN ? 'met' : 'missed',
			'paid'         => $value >= self::PAID_MIN ? 'met' : 'missed',
			'video_start'  => $value < self::VIDEO_START_MAX ? 'met' : 'missed',
			'lcp'          => $value < self::LCP_MAX ? 'met' : 'missed',
			default        => 'no_data',
		};
	}
}
