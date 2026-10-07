<?php
declare(strict_types=1);

namespace Nimikh\LMS\Ops;

/** Turns raw health readings into the status an uptime monitor alerts on. Pure. */
final class HealthStatus {

	public const MAX_CRON_LAG   = 300;  // seconds: certificates must render within 60 s, so 5 min late is a real problem
	public const MAX_FAILED_JOBS = 0;   // any failed background job in the last 7 days is "degraded"

	/** @return 'ok'|'degraded'|'down' */
	public static function evaluate(bool $db, int $cronLagSeconds, int $failedJobs): string {
		if (!$db) {
			return 'down';
		}
		return $cronLagSeconds > self::MAX_CRON_LAG || $failedJobs > self::MAX_FAILED_JOBS ? 'degraded' : 'ok';
	}
}
