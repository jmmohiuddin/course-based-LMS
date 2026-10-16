<?php
declare(strict_types=1);

namespace Nimikh\LMS\Rest;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Offline\OfflineService;
use Nimikh\LMS\Offline\Policy;
use Nimikh\LMS\Support\RateLimiter;

final class OfflineController extends BaseController {

	public function __construct(TutorAdapter $tutor, private OfflineService $offline) {
		parent::__construct($tutor);
	}

	public function registerRoutes(): void {
		$this->route('/lessons/(?P<id>\d+)/offline', [
			['methods' => 'POST', 'callback' => [$this, 'grant'], 'permission_callback' => [$this, 'canLearn']],
			['methods' => 'DELETE', 'callback' => [$this, 'release'], 'permission_callback' => [$this, 'canLearn']],
		]);
		$this->route('/me/offline', ['methods' => 'GET', 'callback' => [$this, 'active'], 'permission_callback' => [$this, 'loggedIn']]);
	}

	public function grant(\WP_REST_Request $req) {
		if (!RateLimiter::hit('offline', (string) get_current_user_id(), 20, 3600)) {
			return new \WP_Error('nimikh_rate', __('Too many requests.', 'nimikh-lms'), ['status' => 429]);
		}
		$r = $this->offline->grant(get_current_user_id(), (int) $req['id'], (string) $req->get_param('device'));
		return is_wp_error($r) ? $r : rest_ensure_response($r);
	}

	public function release(\WP_REST_Request $req) {
		$device = (string) $req->get_param('device');
		if (Policy::validDevice($device)) {
			$this->offline->release(get_current_user_id(), (int) $req['id'], $device);
		}
		return rest_ensure_response(['released' => true]);
	}

	public function active(\WP_REST_Request $req) {
		$device = (string) $req->get_param('device');
		return rest_ensure_response(['licenses' => Policy::validDevice($device) ? $this->offline->activeFor(get_current_user_id(), $device) : []]);
	}
}
