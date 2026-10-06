<?php
declare(strict_types=1);

namespace Nimikh\LMS\Rest;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Support\Roles;

abstract class BaseController {

	public const NAMESPACE = 'nimikh/v1';

	public function __construct(protected TutorAdapter $tutor) {}

	abstract public function registerRoutes(): void;

	protected function route(string $path, array $args): void {
		register_rest_route(self::NAMESPACE, $path, $args);
	}

	// ---- permission callbacks ------------------------------------------------

	public function canLearn(\WP_REST_Request $req) {
		if (!is_user_logged_in()) {
			return new \WP_Error('nimikh_auth', __('Please log in.', 'nimikh-lms'), ['status' => 401]);
		}
		$lessonId = (int) $req['id'];
		$courseId = $this->tutor->courseIdForLesson($lessonId);
		if ($courseId <= 0) {
			return new \WP_Error('nimikh_not_found', __('Lesson not found.', 'nimikh-lms'), ['status' => 404]);
		}
		$uid = get_current_user_id();
		if ($this->tutor->isEnrolled($uid, $courseId) || $this->tutor->canManageCourse($uid, $courseId)) {
			return true;
		}
		return new \WP_Error('nimikh_forbidden', __('You are not enrolled in this course.', 'nimikh-lms'), ['status' => 403]);
	}

	public function canAuthorLesson(\WP_REST_Request $req) {
		if (!current_user_can(Roles::CAP_AUTHOR)) {
			return new \WP_Error('nimikh_forbidden', __('Instructor access required.', 'nimikh-lms'), ['status' => 403]);
		}
		$courseId = $this->tutor->courseIdForLesson((int) $req['id']);
		if ($courseId <= 0 || !$this->tutor->canManageCourse(get_current_user_id(), $courseId)) {
			return new \WP_Error('nimikh_forbidden', __('You do not manage this course.', 'nimikh-lms'), ['status' => 403]);
		}
		return true;
	}

	public function canAuthor() {
		return current_user_can(Roles::CAP_AUTHOR)
			? true
			: new \WP_Error('nimikh_forbidden', __('Instructor access required.', 'nimikh-lms'), ['status' => 403]);
	}

	public function canManage() {
		return current_user_can(Roles::CAP_MANAGE)
			? true
			: new \WP_Error('nimikh_forbidden', __('Admin access required.', 'nimikh-lms'), ['status' => 403]);
	}

	public function loggedIn() {
		return is_user_logged_in()
			? true
			: new \WP_Error('nimikh_auth', __('Please log in.', 'nimikh-lms'), ['status' => 401]);
	}
}
