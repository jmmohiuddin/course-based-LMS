<?php
declare(strict_types=1);

namespace Nimikh\LMS\Video;

/**
 * Instructor guidance from the UX document (7.4): one question every 3-5 minutes, never in the first 30 seconds.
 * Advice only: the editor shows it, nothing is blocked. Pure.
 */
final class Guidance {

	public const MIN_START   = 30;   // seconds: no question before this
	public const MIN_GAP     = 60;   // seconds: closer than this feels like an interrogation
	public const MAX_GAP     = 300;  // seconds: longer than 5 minutes loses the learner

	/**
	 * @param int[] $times question timestamps in seconds (any order)
	 * @return array<int, array{code:string, at:int, gap:int}>
	 */
	public static function check(array $times, int $duration): array {
		sort($times);
		$out = [];
		$prev = 0;
		foreach ($times as $t) {
			if ($t < self::MIN_START) {
				$out[] = ['code' => 'too_early', 'at' => $t, 'gap' => 0];
			} elseif ($prev > 0 && $t - $prev < self::MIN_GAP) {
				$out[] = ['code' => 'crowded', 'at' => $t, 'gap' => $t - $prev];
			}
			if ($t - $prev > self::MAX_GAP) {
				$out[] = ['code' => 'sparse', 'at' => $prev, 'gap' => $t - $prev];
			}
			$prev = $t;
		}
		if ($times && $duration > 0 && $duration - $prev > self::MAX_GAP) {
			$out[] = ['code' => 'sparse', 'at' => $prev, 'gap' => $duration - $prev];
		}
		return $out;
	}
}
