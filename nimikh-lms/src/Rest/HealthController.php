<?php
declare(strict_types=1);

namespace Nimikh\LMS\Rest;

use Nimikh\LMS\Ops\Health;
use Nimikh\LMS\Ops\HealthStatus;

/** Public, uncached, cheap: what an uptime monitor polls every minute. Reveals counts only, no names or paths. */
final class HealthController extends BaseController {

	public function registerRoutes(): void {
		$this->route('/health', ['methods' => 'GET', 'callback' => [$this, 'health'], 'permission_callback' => '__return_true']);
	}

	public function health() {
		$db     = Health::dbOk();
		$lag    = $db ? Health::cronLagSeconds() : 0;
		$failed = $db ? Health::failedJobs() : 0;
		$status = HealthStatus::evaluate($db, $lag, $failed);

		$res = new \WP_REST_Response([
			'status'           => $status,
			'db'               => $db,
			'cron_lag_seconds' => $lag,
			'failed_jobs'      => $failed,
			'version'          => NIMIKH_LMS_VERSION,
		], $status === 'down' ? 503 : 200);
		$res->header('Cache-Control', 'no-store');
		return $res;
	}
}
