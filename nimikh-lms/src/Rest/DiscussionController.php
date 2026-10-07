<?php
declare(strict_types=1);

namespace Nimikh\LMS\Rest;

use Nimikh\LMS\Discussion\DiscussionService;
use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Support\Settings;

final class DiscussionController extends BaseController {

	public function __construct(TutorAdapter $tutor, private DiscussionService $discussion) {
		parent::__construct($tutor);
	}

	public function registerRoutes(): void {
		$this->route('/lessons/(?P<id>\d+)/discussion', [
			['methods' => 'GET', 'callback' => [$this, 'list'], 'permission_callback' => [$this, 'canDiscuss']],
			['methods' => 'POST', 'callback' => [$this, 'create'], 'permission_callback' => [$this, 'canDiscuss']],
		]);
		$this->route('/discussion/(?P<id>\d+)', ['methods' => 'DELETE', 'callback' => [$this, 'delete'], 'permission_callback' => [$this, 'loggedIn']]);
		$this->route('/discussion/(?P<id>\d+)/hide', [
			'methods' => 'POST', 'callback' => [$this, 'hide'], 'permission_callback' => [$this, 'loggedIn'],
			'args'    => ['hidden' => ['type' => 'boolean', 'default' => true]],
		]);
	}

	public function canDiscuss(\WP_REST_Request $req) {
		if (!Settings::get('discussion_enabled')) {
			return new \WP_Error('nimikh_discussion_off', __('Discussion is turned off.', 'nimikh-lms'), ['status' => 404]);
		}
		return $this->canLearn($req);
	}

	public function list(\WP_REST_Request $req) {
		return rest_ensure_response($this->discussion->thread((int) $req['id'], get_current_user_id(), max(1, (int) $req->get_param('page'))));
	}

	public function create(\WP_REST_Request $req) {
		$p   = $req->get_json_params() ?: $req->get_params();
		$res = $this->discussion->post(get_current_user_id(), (int) $req['id'], (string) ($p['body'] ?? ''), (int) ($p['parent_id'] ?? 0));
		return is_wp_error($res) ? $res : new \WP_REST_Response($res, 201);
	}

	public function delete(\WP_REST_Request $req) {
		return $this->discussion->remove(get_current_user_id(), (int) $req['id'])
			? rest_ensure_response(['deleted' => true])
			: new \WP_Error('nimikh_forbidden', __('You cannot delete that.', 'nimikh-lms'), ['status' => 403]);
	}

	public function hide(\WP_REST_Request $req) {
		return $this->discussion->setHidden(get_current_user_id(), (int) $req['id'], (bool) $req->get_param('hidden'))
			? rest_ensure_response(['ok' => true])
			: new \WP_Error('nimikh_forbidden', __('Only course staff can moderate.', 'nimikh-lms'), ['status' => 403]);
	}
}
