<?php
declare(strict_types=1);

namespace Nimikh\LMS\Rest;

use Nimikh\LMS\Ai\QuestionGenerator;
use Nimikh\LMS\Integrations\Tutor\TutorAdapter;

final class AiController extends BaseController {

	public function __construct(TutorAdapter $tutor, private QuestionGenerator $generator) {
		parent::__construct($tutor);
	}

	public function registerRoutes(): void {
		$this->route('/lessons/(?P<id>\d+)/ai-questions', [
			'methods' => 'POST', 'callback' => [$this, 'suggest'], 'permission_callback' => [$this, 'canAuthorLesson'],
		]);
	}

	/** Returns drafts only; the editor saves them through the normal interactions endpoint after review. */
	public function suggest(\WP_REST_Request $req) {
		$p   = $req->get_json_params() ?: $req->get_params();
		$res = $this->generator->suggest(
			get_current_user_id(),
			(int) $req['id'],
			(string) ($p['transcript'] ?? ''),
			(int) ($p['count'] ?? 5),
			(string) ($p['language'] ?? 'en')
		);
		return is_wp_error($res) ? $res : rest_ensure_response($res);
	}
}
