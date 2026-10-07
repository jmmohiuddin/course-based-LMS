<?php
declare(strict_types=1);

namespace Nimikh\LMS\Auth;

use Nimikh\LMS\Sms\PhoneNumber;
use Nimikh\LMS\Sms\SmsService;
use Nimikh\LMS\Support\RateLimiter;
use Nimikh\LMS\Support\Roles;
use Nimikh\LMS\Support\Settings;

/**
 * Phone-OTP and Google sign-in for learners (blueprint 4.3, 6.5). Staff are never signed in this way:
 * a SIM swap or a mailbox takeover must not hand over an instructor or admin account.
 */
final class AuthService {

	public const OTP_TTL        = 300;  // seconds a code stays valid
	public const OTP_MAX_TRIES  = 5;    // wrong guesses before the code is burned
	public const PHONE_LIMIT    = 3;    // blueprint 6.5: 3 codes per 15 minutes per phone
	public const PHONE_WINDOW   = 900;
	public const IP_LIMIT       = 10;   // code requests per hour per IP
	public const VERIFY_LIMIT   = 20;   // verify attempts per 15 min per IP

	public function __construct(private SmsService $sms) {}

	public static function otpEnabled(): bool {
		return (bool) Settings::get('otp_login_enabled') && (string) Settings::get('sms_gateway_url') !== '';
	}

	public static function googleEnabled(): bool {
		return (string) Settings::get('google_client_id') !== '';
	}

	private static function key(string $phone): string {
		return 'nimikh_otp_' . md5($phone);
	}

	/**
	 * Always answers the same way for a valid phone number, whether or not an account exists.
	 *
	 * @return true|\WP_Error
	 */
	public function requestOtp(string $rawPhone) {
		if (!self::otpEnabled()) {
			return new \WP_Error('nimikh_otp_off', __('Phone sign-in is not available.', 'nimikh-lms'), ['status' => 404]);
		}
		$phone = PhoneNumber::normalise($rawPhone);
		if ($phone === null) {
			return new \WP_Error('nimikh_phone_invalid', __('Enter a valid mobile number.', 'nimikh-lms'), ['status' => 400]);
		}
		if (!RateLimiter::hit('otp_ip', RateLimiter::clientIp(), self::IP_LIMIT, 3600)
			|| !RateLimiter::hit('otp_phone', $phone, self::PHONE_LIMIT, self::PHONE_WINDOW)) {
			return new \WP_Error('nimikh_otp_limited', __('Too many codes requested. Please wait a few minutes.', 'nimikh-lms'), ['status' => 429]);
		}

		$code = OtpCode::generate();
		set_transient(self::key($phone), ['hash' => OtpCode::hash($code, $phone, wp_salt('auth')), 'tries' => 0], self::OTP_TTL);

		$sent = $this->sms->sendRaw($phone, sprintf(
			/* translators: 1: code, 2: minutes */
			__('Your Nimikh code is %1$s. It expires in %2$d minutes.', 'nimikh-lms'),
			$code,
			(int) (self::OTP_TTL / 60)
		));
		if (!$sent) {
			delete_transient(self::key($phone));
			return new \WP_Error('nimikh_sms_failed', __('We could not send the code. Please try again.', 'nimikh-lms'), ['status' => 502]);
		}
		return true;
	}

	/** @return int|\WP_Error the signed-in user id */
	public function verifyOtp(string $rawPhone, string $code) {
		if (!self::otpEnabled()) {
			return new \WP_Error('nimikh_otp_off', __('Phone sign-in is not available.', 'nimikh-lms'), ['status' => 404]);
		}
		$phone = PhoneNumber::normalise($rawPhone);
		if ($phone === null || !RateLimiter::hit('otp_verify', RateLimiter::clientIp(), self::VERIFY_LIMIT, self::PHONE_WINDOW)) {
			return new \WP_Error('nimikh_otp_invalid', __('That code is not valid. Request a new one.', 'nimikh-lms'), ['status' => 400]);
		}
		$stored = get_transient(self::key($phone));
		$bad    = new \WP_Error('nimikh_otp_invalid', __('That code is not valid. Request a new one.', 'nimikh-lms'), ['status' => 400]);
		if (!is_array($stored) || ($stored['tries'] ?? 0) >= self::OTP_MAX_TRIES) {
			return $bad;
		}
		if (!OtpCode::verify($code, $phone, wp_salt('auth'), (string) $stored['hash'])) {
			$stored['tries'] = (int) $stored['tries'] + 1;
			$stored['tries'] >= self::OTP_MAX_TRIES ? delete_transient(self::key($phone)) : set_transient(self::key($phone), $stored, self::OTP_TTL);
			return $bad;
		}
		delete_transient(self::key($phone)); // one use only

		$user = $this->userByPhone($phone);
		if ($user === null) {
			if (!apply_filters('nimikh_lms_auth_allow_signup', true)) {
				return $bad;
			}
			$id = $this->createUser('p' . $phone, '', '', ['nimikh_phone' => $phone]);
			if (is_wp_error($id)) {
				return $id;
			}
			return $this->signIn($id);
		}
		return $this->signIn($user->ID);
	}

	/** @return int|\WP_Error */
	public function googleSignIn(string $idToken) {
		if (!self::googleEnabled()) {
			return new \WP_Error('nimikh_google_off', __('Google sign-in is not available.', 'nimikh-lms'), ['status' => 404]);
		}
		if (!RateLimiter::hit('google', RateLimiter::clientIp(), self::VERIFY_LIMIT, self::PHONE_WINDOW)) {
			return new \WP_Error('nimikh_otp_limited', __('Too many attempts. Please wait a few minutes.', 'nimikh-lms'), ['status' => 429]);
		}
		$bad = new \WP_Error('nimikh_google_invalid', __('Google sign-in failed. Please try again.', 'nimikh-lms'), ['status' => 401]);
		if ($idToken === '' || strlen($idToken) > 4096) {
			return $bad;
		}
		$claims = $this->tokenInfo($idToken);
		$info   = $claims === null ? null : GoogleToken::validate($claims, (string) Settings::get('google_client_id'), time());
		if ($info === null) {
			return $bad;
		}

		$user = get_user_by('email', $info['email']);
		if (!$user) {
			$existing = get_users(['meta_key' => 'nimikh_google_sub', 'meta_value' => $info['sub'], 'number' => 1]);
			$user     = $existing[0] ?? null;
		}
		if ($user instanceof \WP_User) {
			update_user_meta($user->ID, 'nimikh_google_sub', $info['sub']);
			return $this->signIn($user->ID);
		}
		if (!apply_filters('nimikh_lms_auth_allow_signup', true)) {
			return $bad;
		}
		$id = $this->createUser(strstr($info['email'], '@', true) ?: 'learner', $info['email'], $info['name'], ['nimikh_google_sub' => $info['sub']]);
		return is_wp_error($id) ? $id : $this->signIn($id);
	}

	/** Google validates the signature and returns the claims; we then check aud/iss/exp/email_verified ourselves. */
	private function tokenInfo(string $idToken): ?array {
		/** Test seam. Return an array of claims to bypass the network call. */
		$pre = apply_filters('nimikh_lms_google_tokeninfo', null, $idToken);
		if (is_array($pre)) {
			return $pre;
		}
		$res = wp_remote_get('https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken), ['timeout' => 8]);
		if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
			return null;
		}
		$data = json_decode((string) wp_remote_retrieve_body($res), true);
		return is_array($data) ? $data : null;
	}

	private function userByPhone(string $phone): ?\WP_User {
		$found = get_users(['meta_key' => 'nimikh_phone', 'meta_value' => $phone, 'number' => 2]);
		return count($found) === 1 ? $found[0] : null; // two accounts on one number: refuse rather than guess
	}

	/**
	 * @param array<string, string> $meta
	 * @return int|\WP_Error
	 */
	private function createUser(string $loginBase, string $email, string $name, array $meta) {
		$login = sanitize_user($loginBase, true) ?: 'learner';
		$base  = $login;
		for ($i = 1; username_exists($login); $i++) {
			$login = $base . $i;
		}
		$id = wp_insert_user([
			'user_login'   => $login,
			'user_pass'    => wp_generate_password(32, true, true),
			'user_email'   => $email,
			'display_name' => $name !== '' ? $name : $login,
			'role'         => get_option('default_role', 'subscriber'),
		]);
		if (is_wp_error($id)) {
			return $id;
		}
		foreach ($meta as $k => $v) {
			update_user_meta($id, $k, $v);
		}
		return $id;
	}

	/** @return int|\WP_Error */
	private function signIn(int $userId) {
		if (self::isStaff($userId)) {
			return new \WP_Error('nimikh_staff_password', __('This account must sign in with its password.', 'nimikh-lms'), ['status' => 403]);
		}
		wp_set_current_user($userId);
		wp_set_auth_cookie($userId, true);
		do_action('nimikh_lms_signed_in', $userId);
		return $userId;
	}

	public static function isStaff(int $userId): bool {
		return user_can($userId, 'manage_options') || user_can($userId, 'edit_others_posts') || user_can($userId, Roles::CAP_MANAGE);
	}
}
