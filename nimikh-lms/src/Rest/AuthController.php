<?php
declare(strict_types=1);

namespace Nimikh\LMS\Rest;

use Nimikh\LMS\Auth\AuthService;
use Nimikh\LMS\Integrations\Tutor\TutorAdapter;

/** Public sign-in routes. They are rate limited and answer identically whether or not an account exists. */
final class AuthController extends BaseController {

	public function __construct(TutorAdapter $tutor, private AuthService $auth) {
		parent::__construct($tutor);
	}

	public function registerRoutes(): void {
		$open = '__return_true';
		$this->route('/auth/otp/request', ['methods' => 'POST', 'callback' => [$this, 'requestOtp'], 'permission_callback' => $open]);
		$this->route('/auth/otp/verify', ['methods' => 'POST', 'callback' => [$this, 'verifyOtp'], 'permission_callback' => $open]);
		$this->route('/auth/google', ['methods' => 'POST', 'callback' => [$this, 'google'], 'permission_callback' => $open]);
	}

	public function requestOtp(\WP_REST_Request $req) {
		$r = $this->auth->requestOtp((string) $req->get_param('phone'));
		return is_wp_error($r) ? $r : rest_ensure_response(['sent' => true, 'ttl' => AuthService::OTP_TTL]);
	}

	public function verifyOtp(\WP_REST_Request $req) {
		return $this->done($this->auth->verifyOtp((string) $req->get_param('phone'), (string) $req->get_param('code')), $req);
	}

	public function google(\WP_REST_Request $req) {
		return $this->done($this->auth->googleSignIn((string) $req->get_param('credential')), $req);
	}

	/** @param int|\WP_Error $result */
	private function done($result, \WP_REST_Request $req) {
		if (is_wp_error($result)) {
			return $result;
		}
		$redirect = wp_validate_redirect((string) $req->get_param('redirect'), home_url('/'));
		return rest_ensure_response(['ok' => true, 'redirect' => esc_url_raw($redirect)]);
	}
}
