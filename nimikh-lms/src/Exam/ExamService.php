<?php
declare(strict_types=1);

namespace Nimikh\LMS\Exam;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Progress\CourseRules;
use Nimikh\LMS\Progress\ProgressRepository;
use Nimikh\LMS\Questions\AttemptRepository;

/**
 * Final-exam policy that Tutor does not provide: a retake cooldown and "what to rewatch" after a fail.
 * The last-attempt time is recorded by us when Tutor reports the attempt as finished, so the cooldown does not
 * depend on how Tutor stores its own timestamps (local vs GMT).
 */
final class ExamService {

	public function __construct(
		private TutorAdapter $tutor,
		private ProgressRepository $progress,
		private AttemptRepository $attempts
	) {}

	private static function metaKey(int $quizId): string {
		return 'nimikh_exam_last_attempt_' . $quizId;
	}

	/** Called when Tutor reports a finished attempt. Only the course's final exam is tracked. */
	public function recordAttempt(int $userId, int $courseId, int $quizId): void {
		if ($userId > 0 && $quizId > 0 && $quizId === $this->tutor->examQuizId($courseId)) {
			update_user_meta($userId, self::metaKey($quizId), time());
		}
	}

	/** @return array{has_exam:bool, quiz_id:int, best_percent:?float, pass_percent:float, passed:bool, cooldown_hours:int, cooldown_remaining:int, next_attempt_at:?int} */
	public function status(int $userId, int $courseId): array {
		$quizId = $this->tutor->examQuizId($courseId);
		$rules  = CourseRules::forCourse($courseId);
		$best   = $quizId > 0 ? $this->tutor->bestQuizPercent($userId, $quizId) : null;
		$passed = $best !== null && $best >= $rules['exam_pass_percent'];
		$last   = $quizId > 0 ? (int) get_user_meta($userId, self::metaKey($quizId), true) : 0;
		$left   = $quizId > 0 ? Cooldown::remaining($last ?: null, (int) $rules['exam_cooldown_hours'], time(), $passed) : 0;

		return [
			'has_exam'           => $quizId > 0,
			'quiz_id'            => $quizId,
			'best_percent'       => $best,
			'pass_percent'       => $rules['exam_pass_percent'],
			'passed'             => $passed,
			'cooldown_hours'     => (int) $rules['exam_cooldown_hours'],
			'cooldown_remaining' => $left,
			'next_attempt_at'    => $left > 0 ? time() + $left : null,
		];
	}

	/** Lessons to revisit, weakest first. Empty when the exam is passed or there is nothing weak to point at. */
	public function rewatch(int $userId, int $courseId): array {
		$status = $this->status($userId, $courseId);
		if (!$status['has_exam'] || $status['passed'] || $status['best_percent'] === null) {
			return [];
		}
		$rules   = CourseRules::forCourse($courseId);
		$lessons = [];
		foreach ($this->tutor->lessonIdsForCourse($courseId) as $lessonId) {
			$score = $this->attempts->scoreForLessons($userId, [$lessonId]);
			$row   = $this->progress->find($userId, $lessonId);
			$lessons[] = [
				'lesson_id'     => $lessonId,
				'mcq_percent'   => $score['total'] > 0 ? round($score['earned'] / $score['total'] * 100, 1) : null,
				'watch_percent' => (float) ($row['percent'] ?? 0.0),
			];
		}
		$out = [];
		foreach (Rewatch::suggest($lessons, $rules['min_watch_percent']) as $s) {
			$out[] = $s + ['title' => get_the_title($s['lesson_id']), 'url' => (string) get_permalink($s['lesson_id'])];
		}
		return $out;
	}

	/**
	 * Message to show when a learner tries to start the exam too early; null when starting is allowed.
	 */
	public function blockMessage(int $userId, int $quizId): ?string {
		if ($userId <= 0 || $quizId <= 0) {
			return null;
		}
		$courseId = (int) get_post_meta($quizId, '_tutor_course_id_for_quiz', true);
		if ($courseId <= 0 || $quizId !== $this->tutor->examQuizId($courseId)) {
			return null; // only the final exam has a cooldown
		}
		$left = $this->status($userId, $courseId)['cooldown_remaining'];
		if ($left <= 0) {
			return null;
		}
		/* translators: %s: time left, e.g. "5 h 20 min" */
		return sprintf(__('You can retake the exam in %s. Use the time to rewatch the lessons below your score.', 'nimikh-lms'), Cooldown::human($left));
	}
}
