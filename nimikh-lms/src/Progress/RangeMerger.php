<?php
declare(strict_types=1);

namespace Nimikh\LMS\Progress;

/**
 * Pure helpers for "watched ranges": lists of [startSecond, endSecond] pairs.
 */
final class RangeMerger {

	/**
	 * Coerce untrusted input into a clean, clamped list of [start, end] integer pairs.
	 *
	 * @param mixed $raw
	 * @return array<int, array{0:int,1:int}>
	 */
	public static function sanitize($raw, int $duration): array {
		if (!is_array($raw)) {
			return [];
		}
		$out = [];
		foreach ($raw as $pair) {
			if (!is_array($pair) || count($pair) < 2) {
				continue;
			}
			$pair = array_values($pair);
			if (!is_numeric($pair[0]) || !is_numeric($pair[1])) {
				continue;
			}
			$start = max(0, (int) floor((float) $pair[0]));
			$end   = min($duration, (int) ceil((float) $pair[1]));
			if ($end > $start) {
				$out[] = [$start, $end];
			}
		}
		return self::merge($out);
	}

	/**
	 * Merge overlapping or touching ranges and sort them.
	 *
	 * @param array<int, array{0:int,1:int}> $ranges
	 * @return array<int, array{0:int,1:int}>
	 */
	public static function merge(array $ranges): array {
		usort($ranges, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
		$merged = [];
		foreach ($ranges as [$start, $end]) {
			$last = count($merged) - 1;
			if ($last >= 0 && $start <= $merged[$last][1]) {
				$merged[$last][1] = max($merged[$last][1], $end);
			} else {
				$merged[] = [$start, $end];
			}
		}
		return $merged;
	}

	/** @param array<int, array{0:int,1:int}> $ranges */
	public static function covered(array $ranges): int {
		$total = 0;
		foreach (self::merge($ranges) as [$start, $end]) {
			$total += $end - $start;
		}
		return $total;
	}

	/**
	 * Drop everything past $limit (used to stop progress at an unanswered required question).
	 *
	 * @param array<int, array{0:int,1:int}> $ranges
	 * @return array<int, array{0:int,1:int}>
	 */
	public static function clip(array $ranges, ?int $limit): array {
		if ($limit === null) {
			return $ranges;
		}
		$out = [];
		foreach ($ranges as [$start, $end]) {
			if ($start >= $limit) {
				continue;
			}
			$out[] = [$start, min($end, $limit)];
		}
		return $out;
	}

	/** @param array<int, array{0:int,1:int}> $ranges */
	public static function furthest(array $ranges): int {
		$max = 0;
		foreach ($ranges as [, $end]) {
			$max = max($max, $end);
		}
		return $max;
	}

	/** @param array<int, array{0:int,1:int}> $ranges */
	public static function percent(array $ranges, int $duration): float {
		if ($duration <= 0) {
			return 0.0;
		}
		return min(100.0, round(self::covered($ranges) / $duration * 100, 2));
	}
}
