<?php
declare(strict_types=1);

namespace Nimikh\LMS\Integrations\Tutor;

use Nimikh\LMS\Exam\ExamService;

/**
 * Enforces the retake cooldown on Tutor's "start quiz" request (priority 1, before Tutor's own handler) and
 * records finished final-exam attempts. Tutor registers the start action both as an ajax action and as a
 * form action; both are guarded. If a Tutor version renames them the cooldown silently stops being enforced
 * (fail-open on purpose, so learners are never locked out): tools/check-tutor-contract.php reports it.
 */
final class ExamGuard {

	public function __construct(private TutorAdapter $tutor, private ExamService $exams) {}

	public function register(): void {
		add_action('wp_ajax_tutor_start_quiz', [$this, 'guardAjax'], 1);
		add_action('tutor_action_tutor_start_quiz', [$this, 'guardForm'], 1);
		add_action('tutor_quiz/attempt_ended', [$this, 'record'], 5, 1);
	}

	public function guardAjax(): void {
		$msg = $this->message();
		if ($msg !== null) {
			wp_send_json_error(['message' => $msg, 'code' => 'nimikh_exam_cooldown'], 403);
		}
	}

	public function guardForm(): void {
		$msg = $this->message();
		if ($msg !== null) {
			wp_die(esc_html($msg), '', ['response' => 403, 'back_link' => true]);
		}
	}

	public function record(int $attemptId): void {
		$a = $this->tutor->attempt($attemptId);
		if ($a) {
			$this->exams->recordAttempt($a['user_id'], $a['course_id'], $a['quiz_id']);
		}
	}

	private function message(): ?string {
		$quizId = isset($_POST['quiz_id']) ? (int) $_POST['quiz_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification -- read-only check; Tutor verifies the nonce in its own handler
		return $this->exams->blockMessage(get_current_user_id(), $quizId);
	}
}
