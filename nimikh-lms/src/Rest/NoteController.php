<?php
declare(strict_types=1);

namespace Nimikh\LMS\Rest;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Notes\NoteService;

final class NoteController extends BaseController {

	public function __construct(TutorAdapter $tutor, private NoteService $notes) {
		parent::__construct($tutor);
	}

	public function registerRoutes(): void {
		$this->route('/lessons/(?P<id>\d+)/notes', [
			['methods' => 'GET', 'callback' => [$this, 'list'], 'permission_callback' => [$this, 'canLearn']],
			['methods' => 'POST', 'callback' => [$this, 'create'], 'permission_callback' => [$this, 'canLearn']],
		]);
		$this->route('/notes/(?P<id>\d+)', ['methods' => 'DELETE', 'callback' => [$this, 'delete'], 'permission_callback' => [$this, 'loggedIn']]);
	}

	public function list(\WP_REST_Request $req) {
		return rest_ensure_response($this->notes->forLesson(get_current_user_id(), (int) $req['id']));
	}

	public function create(\WP_REST_Request $req) {
		$p   = $req->get_json_params() ?: $req->get_params();
		$res = $this->notes->add(get_current_user_id(), (int) $req['id'], (int) ($p['at_second'] ?? -1), (string) ($p['body'] ?? ''));
		return is_wp_error($res) ? $res : new \WP_REST_Response($res, 201);
	}

	/** Only the author can delete; a missing and a foreign note look identical. */
	public function delete(\WP_REST_Request $req) {
		return $this->notes->delete(get_current_user_id(), (int) $req['id'])
			? rest_ensure_response(['deleted' => true])
			: new \WP_Error('nimikh_not_found', __('Note not found.', 'nimikh-lms'), ['status' => 404]);
	}
}
