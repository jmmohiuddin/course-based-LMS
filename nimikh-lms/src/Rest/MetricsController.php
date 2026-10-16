<?php
declare(strict_types=1);

namespace Nimikh\LMS\Rest;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Metrics\MetricsService;
use Nimikh\LMS\Support\RateLimiter;

final class MetricsController extends BaseController {

	public function __construct(TutorAdapter $tutor, private MetricsService $metrics) {
		parent::__construct($tutor);
	}

	public function registerRoutes(): void {
		$this->route('/metrics/rum', ['methods' => 'POST', 'callback' => [$this, 'rum'], 'permission_callback' => [$this, 'loggedIn']]);
		$this->route('/metrics/kpis', ['methods' => 'GET', 'callback' => [$this, 'kpis'], 'permission_callback' => [$this, 'canManage']]);
	}

	/** Anonymous timing from a logged-in learner's browser; nothing about the learner is stored. */
	public function rum(\WP_REST_Request $req) {
		if (!RateLimiter::hit('rum', (string) get_current_user_id(), 20, 60)) {
			return new \WP_Error('nimikh_rate', __('Too many requests.', 'nimikh-lms'), ['status' => 429]);
		}
		$ok = $this->metrics->recordRum((string) $req->get_param('metric'), (int) $req->get_param('value'));
		return $ok ? rest_ensure_response(['ok' => true]) : new \WP_Error('nimikh_bad_metric', __('Unknown metric.', 'nimikh-lms'), ['status' => 400]);
	}

	public function kpis(\WP_REST_Request $req) {
		$days = (int) $req->get_param('days');
		return rest_ensure_response($this->metrics->kpis(in_array($days, [0, 30, 90, 365], true) ? $days : 90));
	}
}
