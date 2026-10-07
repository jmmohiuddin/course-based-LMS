<?php
declare(strict_types=1);

namespace Nimikh\LMS\Badges;

/** Pure rules: which badges a learner's stats qualify for. */
final class BadgeRules {

	/**
	 * @param array{lessons_completed:int, streak:int, certificates:int, perfect_mcq:bool} $stats
	 * @return string[] badge keys
	 */
	public static function earned(array $stats): array {
		$out = [];
		if ($stats['lessons_completed'] >= 1) {
			$out[] = 'first_lesson';
		}
		if ($stats['lessons_completed'] >= 25) {
			$out[] = 'lessons_25';
		}
		foreach ([3, 7, 30] as $days) {
			if ($stats['streak'] >= $days) {
				$out[] = 'streak_' . $days;
			}
		}
		if ($stats['certificates'] >= 1) {
			$out[] = 'first_certificate';
		}
		if ($stats['certificates'] >= 3) {
			$out[] = 'certificates_3';
		}
		if ($stats['perfect_mcq']) {
			$out[] = 'sharp_mind';
		}
		return $out;
	}

	/**
	 * Length of the current daily streak. A streak survives until the end of the day after the
	 * last activity, so a learner who studied yesterday still has it today.
	 *
	 * @param string[] $days Y-m-d dates with activity
	 */
	public static function streak(array $days, string $today): int {
		$set = array_flip($days);
		$cursor = new \DateTimeImmutable($today);
		if (!isset($set[$cursor->format('Y-m-d')])) {
			$cursor = $cursor->modify('-1 day');
		}
		$count = 0;
		while (isset($set[$cursor->format('Y-m-d')])) {
			$count++;
			$cursor = $cursor->modify('-1 day');
		}
		return $count;
	}
}
