<?php
declare(strict_types=1);

namespace Nimikh\LMS\Rest;

use Nimikh\LMS\Badges\BadgeService;
use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Subscriptions\SubscriptionService;

final class MeController extends BaseController {

	public function __construct(TutorAdapter $tutor, private BadgeService $badges, private SubscriptionService $subs) {
		parent::__construct($tutor);
	}

	public function registerRoutes(): void {
		$this->route('/me/badges', ['methods' => 'GET', 'callback' => [$this, 'badges'], 'permission_callback' => [$this, 'loggedIn']]);
		$this->route('/me/subscriptions', ['methods' => 'GET', 'callback' => [$this, 'subscriptions'], 'permission_callback' => [$this, 'loggedIn']]);
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
}
