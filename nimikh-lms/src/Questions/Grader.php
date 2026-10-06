<?php
declare(strict_types=1);

namespace Nimikh\LMS\Questions;

/**
 * Server-side MCQ grading. Correct answers never leave the server before submission.
 */
final class Grader {

	/** Blueprint 7.4: retry allowed up to 2 tries, then the answer is shown. */
	public const MAX_TRIES = 2;

	/**
	 * @return array{correct:bool, resolved:bool, reveal:bool}
	 */
	public static function grade(int $correctIndex, int $selectedIndex, int $attemptNo, bool $allowRetry): array {
		$correct  = $selectedIndex === $correctIndex;
		$maxTries = $allowRetry ? self::MAX_TRIES : 1;
		$resolved = $correct || $attemptNo >= $maxTries;

		return [
			'correct'  => $correct,
			'resolved' => $resolved,
			'reveal'   => !$correct && $resolved,
		];
	}

	/** Whether a learner has finished with an interaction (answered right, or used all tries). */
	public static function isResolved(bool $everCorrect, int $attempts, bool $allowRetry): bool {
		return $everCorrect || $attempts >= ($allowRetry ? self::MAX_TRIES : 1);
	}
}
