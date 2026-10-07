<?php
declare(strict_types=1);

namespace Nimikh\LMS\Support;

/** Blueprint 6.5: login attempts are limited to 5 per minute per IP (filterable). */
final class LoginLimiter {

	public const LIMIT = 5;

	public function register(): void {
		add_filter('authenticate', [$this, 'throttle'], 1, 3);
	}

	/**
	 * Counts every attempt that carries credentials, before WordPress checks them.
	 *
	 * @param \WP_User|\WP_Error|null $user
	 * @return \WP_User|\WP_Error|null
	 */
	public function throttle($user, $username = '', $password = '') {
		if ($username === '' || $password === '') {
			return $user; // the login form being displayed, not an attempt
		}
		$limit = (int) apply_filters('nimikh_lms_login_limit_per_minute', self::LIMIT);
		if ($limit > 0 && !RateLimiter::hit('login', RateLimiter::clientIp(), $limit, 60)) {
			return new \WP_Error('nimikh_login_limited', __('Too many login attempts. Please wait a minute and try again.', 'nimikh-lms'));
		}
		return $user;
	}
}
