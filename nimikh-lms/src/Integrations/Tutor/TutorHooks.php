<?php
declare(strict_types=1);

namespace Nimikh\LMS\Integrations\Tutor;

use Nimikh\LMS\Certificates\CertificateService;
use Nimikh\LMS\Progress\CourseRules;
use Nimikh\LMS\Progress\ProgressRepository;
use Nimikh\LMS\Video\InteractionRepository;

/**
 * Subscribes to Tutor LMS events (blueprint 6.4). Hook names follow Tutor 2.x/3.x; verify
 * them against the pinned Tutor version on staging before each upgrade.
 */
final class TutorHooks {

	public function __construct(
		private TutorAdapter $tutor,
		private CertificateService $certificates,
		private ProgressRepository $progress
	) {}

	public function register(): void {
		// Lesson finished (ours or Tutor's own) -> maybe the course is now complete.
		add_action('nimikh_lesson_completed', [$this, 'onLessonCompleted'], 10, 3);
		add_action('tutor_lesson_completed_after', [$this, 'onTutorLessonCompleted'], 10, 2);

		// Final exam finished.
		add_action('tutor_quiz/attempt_ended', [$this, 'onQuizFinished'], 10, 1);

		// SMS/email style notifications (phase 2).
		add_action('tutor_after_enroll', [$this, 'onEnrolled'], 10, 2);

		// Block "Mark as complete" on interactive lessons until the player says so.
		add_action('tutor_action_tutor_complete_lesson', [$this, 'guardManualComplete'], 1);
	}

	public function onLessonCompleted(int $userId, int $lessonId, int $courseId): void {
		$this->certificates->maybeIssue($userId, $courseId);
	}

	public function onTutorLessonCompleted(int $lessonId, int $userId): void {
		$courseId = $this->tutor->courseIdForLesson($lessonId);
		if ($courseId > 0) {
			$this->certificates->maybeIssue($userId, $courseId);
		}
	}

	public function onQuizFinished(int $attemptId): void {
		$attempt = $this->tutor->attempt($attemptId);
		if ($attempt && $attempt['course_id'] > 0) {
			$this->notifyExamResult($attempt);
			$this->certificates->maybeIssue($attempt['user_id'], $attempt['course_id']);
		}
	}

	/** @param array{user_id:int, course_id:int, quiz_id:int} $attempt */
	private function notifyExamResult(array $attempt): void {
		if ($attempt['quiz_id'] !== $this->tutor->examQuizId($attempt['course_id'])) {
			return; // only the final exam, not every lesson quiz
		}
		$score = $this->tutor->bestQuizPercent($attempt['user_id'], $attempt['quiz_id']);
		if ($score === null) {
			return;
		}
		$pass = CourseRules::forCourse($attempt['course_id'])['exam_pass_percent'];
		do_action('nimikh_notify_sms', $attempt['user_id'], 'exam_result', [
			'course' => $this->tutor->courseTitle($attempt['course_id']),
			'score'  => rtrim(rtrim(number_format($score, 1), '0'), '.'),
			'result' => $score >= $pass ? __('passed', 'nimikh-lms') : __('not passed yet', 'nimikh-lms'),
		]);
	}

	/** Tutor passes the course id and the enrolment post id; the learner is the enrolment's author. */
	public function onEnrolled(int $courseId, int $enrolmentId = 0): void {
		$userId = $enrolmentId > 0 ? (int) get_post_field('post_author', $enrolmentId) : 0;
		if ($userId > 0) {
			do_action('nimikh_notify_sms', $userId, 'enrolled', [
				'course' => $this->tutor->courseTitle($courseId),
				'url'    => (string) get_permalink($courseId),
			]);
		}
	}

	/** Runs before Tutor's own handler (priority 1). */
	public function guardManualComplete(): void {
		$lessonId = isset($_POST['lesson_id']) ? (int) $_POST['lesson_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification -- Tutor verifies the nonce in its own handler
		if ($lessonId <= 0 || !FrontendPlayerGuard::isInteractive($lessonId)) {
			return;
		}
		$row = $this->progress->find(get_current_user_id(), $lessonId);
		if (!empty($row['completed_at'])) {
			return;
		}
		wp_die(
			esc_html__('Finish watching the video and answer its questions to complete this lesson.', 'nimikh-lms'),
			'',
			['response' => 403, 'back_link' => true]
		);
	}
}
