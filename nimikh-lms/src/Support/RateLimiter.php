<?php
declare(strict_types=1);

namespace Nimikh\LMS\Support;

/** Fixed-window limiter on transients (Redis object cache makes these cheap). */
final class RateLimiter {

	public static function hit(string $bucket, string $subject, int $limit, int $windowSeconds = 60): bool {
		$key   = 'nimikh_rl_' . md5($bucket . '|' . $subject);
		$count = (int) get_transient($key);
		if ($count >= $limit) {
			return false;
		}
		set_transient($key, $count + 1, $windowSeconds);
		return true;
	}

	public static function clientIp(): string {
		$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
		return filter_var($ip, FILTER_VALIDATE_IP) ? (string) $ip : 'unknown';
	}
}
