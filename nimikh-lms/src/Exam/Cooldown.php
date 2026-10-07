<?php
declare(strict_types=1);

namespace Nimikh\LMS\Exam;

/** Blueprint 7.3 flow A: after a failed final exam the learner can retake after a cooldown (default 24 h). */
final class Cooldown {

	public const DEFAULT_HOURS = 24;

	/**
	 * Seconds left before the next attempt is allowed. 0 when there is no cooldown to wait for:
	 * the rule is off, there was no earlier attempt, or the learner has already passed.
	 */
	public static function remaining(?int $lastAttemptTs, int $hours, int $now, bool $passed = false): int {
		if ($passed || $hours <= 0 || $lastAttemptTs === null || $lastAttemptTs <= 0) {
			return 0;
		}
		return max(0, $lastAttemptTs + $hours * 3600 - $now);
	}

	/** "23 h 59 min" style text for the learner. */
	public static function human(int $seconds): string {
		$minutes = (int) ceil($seconds / 60);
		$h       = intdiv($minutes, 60);
		$m       = $minutes % 60;
		if ($h > 0) {
			/* translators: 1: hours, 2: minutes */
			return sprintf(__('%1$d h %2$d min', 'nimikh-lms'), $h, $m);
		}
		/* translators: %d: minutes */
		return sprintf(__('%d min', 'nimikh-lms'), max(1, $m));
	}
}
