<?php
declare(strict_types=1);

namespace Nimikh\LMS\Rest;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Live\LiveService;

final class LiveController extends BaseController {

	public function __construct(TutorAdapter $tutor, private LiveService $live) {
		parent::__construct($tutor);
	}

	public function registerRoutes(): void {
		$this->route('/courses/(?P<id>\d+)/live', [
			['methods' => 'GET', 'callback' => [$this, 'list'], 'permission_callback' => [$this, 'canSeeCourse']],
			['methods' => 'POST', 'callback' => [$this, 'create'], 'permission_callback' => [$this, 'canAuthor']],
		]);
		$this->route('/live/(?P<id>\d+)/join', ['methods' => 'POST', 'callback' => [$this, 'join'], 'permission_callback' => [$this, 'loggedIn']]);
		$this->route('/live/(?P<id>\d+)/cancel', ['methods' => 'POST', 'callback' => [$this, 'cancel'], 'permission_callback' => [$this, 'canAuthor']]);
		$this->route('/live/(?P<id>\d+)/attendance', ['methods' => 'GET', 'callback' => [$this, 'attendance'], 'permission_callback' => [$this, 'canAuthor']]);
	}

	public function canSeeCourse(\WP_REST_Request $req) {
		$uid = get_current_user_id();
		if (!$uid) {
			return new \WP_Error('nimikh_auth', __('Please log in.', 'nimikh-lms'), ['status' => 401]);
		}
		$course = (int) $req['id'];
		return $this->tutor->isEnrolled($uid, $course) || $this->tutor->canManageCourse($uid, $course)
			? true
			: new \WP_Error('nimikh_forbidden', __('You are not enrolled in this course.', 'nimikh-lms'), ['status' => 403]);
	}

	public function list(\WP_REST_Request $req) {
		return rest_ensure_response($this->live->upcoming((int) $req['id']));
	}

	public function create(\WP_REST_Request $req) {
		$p   = $req->get_json_params() ?: $req->get_params();
		$res = $this->live->create(get_current_user_id(), ['course_id' => (int) $req['id']] + $p);
		return is_wp_error($res) ? $res : new \WP_REST_Response($res, 201);
	}

	public function join(\WP_REST_Request $req) {
		$res = $this->live->join(get_current_user_id(), (int) $req['id']);
		return is_wp_error($res) ? $res : rest_ensure_response($res);
	}

	public function cancel(\WP_REST_Request $req) {
		return $this->live->cancel(get_current_user_id(), (int) $req['id'])
			? rest_ensure_response(['cancelled' => true])
			: new \WP_Error('nimikh_forbidden', __('You do not manage this class.', 'nimikh-lms'), ['status' => 403]);
	}

	public function attendance(\WP_REST_Request $req) {
		$rows = $this->live->attendance(get_current_user_id(), (int) $req['id']);
		return $rows === null ? new \WP_Error('nimikh_forbidden', __('You do not manage this class.', 'nimikh-lms'), ['status' => 403]) : rest_ensure_response($rows);
	}
}
