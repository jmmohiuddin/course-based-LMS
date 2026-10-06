<?php
declare(strict_types=1);

namespace Nimikh\LMS\Progress;

/**
 * Rejects progress that advances faster than real playback allows (blueprint 6.5).
 */
final class HeartbeatValidator {

	/** Extra seconds allowed to absorb timer jitter and the gap between page load and first beat. */
	public const SLACK_SECONDS = 15;

	/**
	 * @param array<int, array{0:int,1:int}> $existing
	 * @param array<int, array{0:int,1:int}> $claimed
	 * @return array{ok:bool, ranges:array<int, array{0:int,1:int}>, gained:int, allowed:float}
	 */
	public static function validate(
		array $existing,
		array $claimed,
		float $elapsedSeconds,
		float $maxRate,
		float $tolerance = 0.10
	): array {
		$merged  = RangeMerger::merge(array_merge($existing, $claimed));
		$gained  = RangeMerger::covered($merged) - RangeMerger::covered($existing);
		$allowed = max(0.0, $elapsedSeconds) * $maxRate * (1 + $tolerance) + self::SLACK_SECONDS;

		return [
			'ok'      => $gained <= $allowed,
			'ranges'  => $merged,
			'gained'  => $gained,
			'allowed' => $allowed,
		];
	}
}
