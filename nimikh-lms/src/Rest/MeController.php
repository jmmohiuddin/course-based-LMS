<?php
declare(strict_types=1);

namespace Nimikh\LMS\Rest;

use Nimikh\LMS\Badges\BadgeService;
use Nimikh\LMS\Certificates\CertificateService;
use Nimikh\LMS\Exam\ExamService;
use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Subscriptions\SubscriptionService;

final class MeController extends BaseController {

	public function __construct(TutorAdapter $tutor, private BadgeService $badges, private SubscriptionService $subs, private CertificateService $certificates, private ExamService $exams) {
		parent::__construct($tutor);
	}

	public function registerRoutes(): void {
		$this->route('/me/badges', ['methods' => 'GET', 'callback' => [$this, 'badges'], 'permission_callback' => [$this, 'loggedIn']]);
		$this->route('/me/subscriptions', ['methods' => 'GET', 'callback' => [$this, 'subscriptions'], 'permission_callback' => [$this, 'loggedIn']]);
		$this->route('/me/courses/(?P<id>\d+)/checklist', ['methods' => 'GET', 'callback' => [$this, 'checklist'], 'permission_callback' => [$this, 'loggedIn']]);
		$this->route('/me/courses/(?P<id>\d+)/exam', ['methods' => 'GET', 'callback' => [$this, 'exam'], 'permission_callback' => [$this, 'loggedIn']]);
		$this->route('/me/managed-courses', ['methods' => 'GET', 'callback' => [$this, 'managedCourses'], 'permission_callback' => [$this, 'canAuthor']]);
	}

	public function badges() {
		$uid = get_current_user_id();
		return rest_ensure_response(['streak' => $this->badges->streak($uid), 'badges' => $this->badges->awarded($uid)]);
	}

	public function subscriptions() {
		return rest_ensure_response($this->subs->forUser(get_current_user_id()));
	}

	public function managedCourses() {
		return rest_ensure_response($this->tutor->managedCourses(get_current_user_id()));
	}

	/** What is still needed for the certificate in one course. */
	public function checklist(\WP_REST_Request $req) {
		$uid      = get_current_user_id();
		$courseId = (int) $req['id'];
		if ($courseId <= 0 || !$this->tutor->isEnrolled($uid, $courseId)) {
			return new \WP_Error('nimikh_forbidden', __('You are not enrolled in this course.', 'nimikh-lms'), ['status' => 403]);
		}
		return rest_ensure_response($this->certificates->checklist($uid, $courseId));
	}

	/** Exam status for the learner: best score, cooldown and which lessons to rewatch after a fail. */
	public function exam(\WP_REST_Request $req) {
		$uid      = get_current_user_id();
		$courseId = (int) $req['id'];
		if ($courseId <= 0 || !$this->tutor->isEnrolled($uid, $courseId)) {
			return new \WP_Error('nimikh_forbidden', __('You are not enrolled in this course.', 'nimikh-lms'), ['status' => 403]);
		}
		return rest_ensure_response($this->exams->status($uid, $courseId) + ['rewatch' => $this->exams->rewatch($uid, $courseId)]);
	}
}
