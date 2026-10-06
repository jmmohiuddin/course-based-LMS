<?php
declare(strict_types=1);

namespace Nimikh\LMS\Rest;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Questions\QuestionRepository;
use Nimikh\LMS\Support\Roles;
use Nimikh\LMS\Video\InteractionRepository;

/** Instructor endpoints: the MCQ bank and the per-lesson video timeline. */
final class AuthoringController extends BaseController {

	public function __construct(
		TutorAdapter $tutor,
		private QuestionRepository $questions,
		private InteractionRepository $interactions
	) {
		parent::__construct($tutor);
	}

	public function registerRoutes(): void {
		$this->route('/questions', [
			['methods' => 'GET', 'callback' => [$this, 'listQuestions'], 'permission_callback' => [$this, 'canAuthor']],
			['methods' => 'POST', 'callback' => [$this, 'createQuestion'], 'permission_callback' => [$this, 'canAuthor']],
		]);
		$this->route('/questions/(?P<id>\d+)', [
			['methods' => 'GET', 'callback' => [$this, 'getQuestion'], 'permission_callback' => [$this, 'canAuthor']],
			['methods' => 'PUT,PATCH', 'callback' => [$this, 'updateQuestion'], 'permission_callback' => [$this, 'canAuthor']],
			['methods' => 'DELETE', 'callback' => [$this, 'deleteQuestion'], 'permission_callback' => [$this, 'canAuthor']],
		]);
		$this->route('/lessons/(?P<id>\d+)/interactions', [
			['methods' => 'GET', 'callback' => [$this, 'listInteractions'], 'permission_callback' => [$this, 'canAuthorLesson']],
			['methods' => 'POST', 'callback' => [$this, 'createInteraction'], 'permission_callback' => [$this, 'canAuthorLesson']],
		]);
		$this->route('/lessons/(?P<id>\d+)/interactions/(?P<iid>\d+)', [
			['methods' => 'PUT,PATCH', 'callback' => [$this, 'updateInteraction'], 'permission_callback' => [$this, 'canAuthorLesson']],
			['methods' => 'DELETE', 'callback' => [$this, 'deleteInteraction'], 'permission_callback' => [$this, 'canAuthorLesson']],
		]);
		$this->route('/lessons/(?P<id>\d+)/duration', [
			'methods' => 'POST', 'callback' => [$this, 'setDuration'], 'permission_callback' => [$this, 'canAuthorLesson'],
			'args'    => ['duration' => ['required' => true, 'type' => 'integer', 'minimum' => 1]],
		]);
	}

	// ---- question bank -------------------------------------------------------

	public function listQuestions(\WP_REST_Request $req) {
		$rows = $this->questions->search(
			get_current_user_id(),
			current_user_can(Roles::CAP_MANAGE),
			(string) $req->get_param('search'),
			(int) ($req->get_param('per_page') ?: 50),
			(int) ($req->get_param('offset') ?: 0)
		);
		return rest_ensure_response($rows);
	}

	public function getQuestion(\WP_REST_Request $req) {
		$q = $this->ownedQuestion((int) $req['id']);
		return is_wp_error($q) ? $q : rest_ensure_response($q);
	}

	public function createQuestion(\WP_REST_Request $req) {
		$data = QuestionRepository::normalise($req->get_json_params() ?: $req->get_params());
		if (is_wp_error($data)) {
			return $data;
		}
		$id = $this->questions->create(get_current_user_id(), $data);
		return new \WP_REST_Response($this->questions->find($id), 201);
	}

	public function updateQuestion(\WP_REST_Request $req) {
		$q = $this->ownedQuestion((int) $req['id']);
		if (is_wp_error($q)) {
			return $q;
		}
		$data = QuestionRepository::normalise($req->get_json_params() ?: $req->get_params());
		if (is_wp_error($data)) {
			return $data;
		}
		$this->questions->update($q['id'], $data);
		return rest_ensure_response($this->questions->find($q['id']));
	}

	public function deleteQuestion(\WP_REST_Request $req) {
		$q = $this->ownedQuestion((int) $req['id']);
		if (is_wp_error($q)) {
			return $q;
		}
		if (!$this->questions->delete($q['id'])) {
			return new \WP_Error('nimikh_in_use', __('This question is used in a video. Remove it from the timeline first.', 'nimikh-lms'), ['status' => 409]);
		}
		return rest_ensure_response(['deleted' => true]);
	}

	/** @return array<string, mixed>|\WP_Error */
	private function ownedQuestion(int $id) {
		$q = $this->questions->find($id);
		if (!$q) {
			return new \WP_Error('nimikh_not_found', __('Question not found.', 'nimikh-lms'), ['status' => 404]);
		}
		if ($q['author_id'] !== get_current_user_id() && !current_user_can(Roles::CAP_MANAGE)) {
			return new \WP_Error('nimikh_forbidden', __('This is not your question.', 'nimikh-lms'), ['status' => 403]);
		}
		return $q;
	}

	// ---- timeline --------------------------------------------------------------

	public function listInteractions(\WP_REST_Request $req) {
		return rest_ensure_response([
			'duration'     => (int) get_post_meta((int) $req['id'], 'nimikh_video_duration', true),
			'interactions' => $this->interactions->forLesson((int) $req['id']),
		]);
	}

	/**
	 * Body: { at_second, required, allow_retry, points, rewind_to_second,
	 *         question_id | question: {stem, options[], correct_index, explanation} }
	 */
	public function createInteraction(\WP_REST_Request $req) {
		$lessonId = (int) $req['id'];
		$p        = $req->get_json_params() ?: $req->get_params();

		$questionId = (int) ($p['question_id'] ?? 0);
		if ($questionId > 0) {
			$owned = $this->ownedQuestion($questionId);
			if (is_wp_error($owned)) {
				return $owned;
			}
		} else {
			$data = QuestionRepository::normalise((array) ($p['question'] ?? []));
			if (is_wp_error($data)) {
				return $data;
			}
			$questionId = $this->questions->create(get_current_user_id(), $data);
		}

		$check = $this->validateTiming($lessonId, $p);
		if (is_wp_error($check)) {
			return $check;
		}

		$id = $this->interactions->create($lessonId, $questionId, $p);
		return new \WP_REST_Response($this->interactions->find($id), 201);
	}

	public function updateInteraction(\WP_REST_Request $req) {
		$it = $this->interactions->find((int) $req['iid']);
		if (!$it || $it['lesson_id'] !== (int) $req['id']) {
			return new \WP_Error('nimikh_not_found', __('Interaction not found.', 'nimikh-lms'), ['status' => 404]);
		}
		$p = array_merge($it, $req->get_json_params() ?: $req->get_params());
		$check = $this->validateTiming((int) $req['id'], $p);
		if (is_wp_error($check)) {
			return $check;
		}
		$this->interactions->update($it['id'], $p);

		// Optional inline edit of the question text.
		if (!empty($p['question']) && is_array($p['question'])) {
			$owned = $this->ownedQuestion($it['question_id']);
			if (is_wp_error($owned)) {
				return $owned;
			}
			$data = QuestionRepository::normalise($p['question']);
			if (is_wp_error($data)) {
				return $data;
			}
			$this->questions->update($it['question_id'], $data);
		}
		return rest_ensure_response($this->interactions->find($it['id']));
	}

	public function deleteInteraction(\WP_REST_Request $req) {
		$it = $this->interactions->find((int) $req['iid']);
		if (!$it || $it['lesson_id'] !== (int) $req['id']) {
			return new \WP_Error('nimikh_not_found', __('Interaction not found.', 'nimikh-lms'), ['status' => 404]);
		}
		$this->interactions->delete($it['id']);
		return rest_ensure_response(['deleted' => true]);
	}

	/** Called by the editor once it knows the video length from the player's metadata. */
	public function setDuration(\WP_REST_Request $req) {
		update_post_meta((int) $req['id'], 'nimikh_video_duration', (int) $req['duration']);
		return rest_ensure_response(['duration' => (int) $req['duration']]);
	}

	/** @param array<string, mixed> $p */
	private function validateTiming(int $lessonId, array $p) {
		$at       = (int) ($p['at_second'] ?? -1);
		$duration = (int) get_post_meta($lessonId, 'nimikh_video_duration', true);
		if ($at < 0) {
			return new \WP_Error('nimikh_bad_time', __('Choose a time for the question.', 'nimikh-lms'), ['status' => 400]);
		}
		if ($duration > 0 && $at >= $duration) {
			return new \WP_Error('nimikh_bad_time', __('The question must appear before the video ends.', 'nimikh-lms'), ['status' => 400]);
		}
		$rewind = $p['rewind_to_second'] ?? null;
		if ($rewind !== null && $rewind !== '' && (int) $rewind >= $at) {
			return new \WP_Error('nimikh_bad_rewind', __('The rewind point must be before the question.', 'nimikh-lms'), ['status' => 400]);
		}
		return true;
	}
}
