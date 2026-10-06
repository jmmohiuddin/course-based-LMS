<?php
declare(strict_types=1);

namespace Nimikh\LMS\Questions;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Progress\ProgressService;
use Nimikh\LMS\Support\RateLimiter;
use Nimikh\LMS\Support\Settings;
use Nimikh\LMS\Video\InteractionRepository;

final class AttemptService {

	public function __construct(
		private InteractionRepository $interactions,
		private AttemptRepository $attempts,
		private ProgressService $progress,
		private TutorAdapter $tutor
	) {}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function submit(int $userId, int $interactionId, int $selected) {
		if (!RateLimiter::hit('attempt', (string) $userId, (int) Settings::get('attempts_per_minute'))) {
			return new \WP_Error('nimikh_rate_limited', __('Too many answers. Please slow down.', 'nimikh-lms'), ['status' => 429]);
		}

		$it = $this->interactions->find($interactionId);
		if (!$it) {
			return new \WP_Error('nimikh_not_found', __('Question not found.', 'nimikh-lms'), ['status' => 404]);
		}

		$courseId = $this->tutor->courseIdForLesson($it['lesson_id']);
		if (!$this->tutor->isEnrolled($userId, $courseId) && !$this->tutor->canManageCourse($userId, $courseId)) {
			return new \WP_Error('nimikh_forbidden', __('You are not enrolled in this course.', 'nimikh-lms'), ['status' => 403]);
		}
		if ($selected < 0 || $selected >= count($it['options'])) {
			return new \WP_Error('nimikh_bad_option', __('Invalid option.', 'nimikh-lms'), ['status' => 400]);
		}

		$prior = $this->attempts->summary($userId, $interactionId);
		if (Grader::isResolved($prior['ever_correct'], $prior['attempts'], $it['allow_retry'])) {
			// Already finished: do not record more attempts or re-score; just replay the outcome.
			return $this->response($it, $prior['ever_correct'], true, !$prior['ever_correct']);
		}

		$attemptNo = $prior['attempts'] + 1;
		$grade     = Grader::grade($it['correct_index'], $selected, $attemptNo, $it['allow_retry']);
		$this->attempts->record($userId, $interactionId, $selected, $grade['correct'], $attemptNo);

		$completed = false;
		if ($grade['resolved']) {
			$completed = $this->progress->recheckCompletion($userId, $it['lesson_id']);
		}

		$out = $this->response($it, $grade['correct'], $grade['resolved'], $grade['reveal']);
		$out['lesson_completed'] = $completed;
		$out['attempt_no']       = $attemptNo;
		return $out;
	}

	/** @return array<string, mixed> */
	private function response(array $it, bool $correct, bool $resolved, bool $reveal): array {
		$out = [
			'correct'  => $correct,
			'resolved' => $resolved,
			'tries_left' => 0,
		];
		if ($resolved) {
			$out['explanation'] = (string) $it['explanation'];
		}
		if ($reveal) {
			$out['correct_index'] = $it['correct_index'];
		}
		if (!$correct && !$resolved) {
			$out['tries_left'] = 1;
			if ($it['rewind_to_second'] !== null) {
				$out['rewind_to'] = $it['rewind_to_second'];
			}
		}
		return $out;
	}
}
