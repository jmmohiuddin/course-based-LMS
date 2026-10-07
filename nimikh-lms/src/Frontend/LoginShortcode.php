<?php
declare(strict_types=1);

namespace Nimikh\LMS\Frontend;

use Nimikh\LMS\Auth\AuthService;

/** [nimikh_login redirect="/my-courses/"]: phone code and Google buttons. Password login stays on wp-login.php. */
final class LoginShortcode {

	public function register(): void {
		add_shortcode('nimikh_login', [$this, 'render']);
	}

	/** @param array<string, string>|string $atts */
	public function render($atts = []): string {
		$atts = shortcode_atts(['redirect' => ''], (array) $atts, 'nimikh_login');
		if (is_user_logged_in()) {
			return '';
		}
		$otp    = AuthService::otpEnabled();
		$google = AuthService::googleEnabled();
		if (!$otp && !$google) {
			return '';
		}
		wp_enqueue_style('nimikh-tokens');
		wp_enqueue_style('nimikh-player');
		wp_enqueue_script('nimikh-auth', NIMIKH_LMS_URL . 'assets/js/auth.js', [], NIMIKH_LMS_VERSION, ['in_footer' => true, 'strategy' => 'defer']);
		wp_localize_script('nimikh-auth', 'NimikhAuth', [
			'restUrl'  => esc_url_raw(rest_url('nimikh/v1')),
			'otp'      => $otp,
			'google'   => $google ? (string) \Nimikh\LMS\Support\Settings::get('google_client_id') : '',
			'redirect' => $atts['redirect'] !== '' ? esc_url_raw(home_url($atts['redirect'])) : esc_url_raw((string) get_permalink() ?: home_url('/')),
			'i18n'     => [
				'phone'     => __('Mobile number', 'nimikh-lms'),
				'sendCode'  => __('Send code', 'nimikh-lms'),
				'code'      => __('6-digit code', 'nimikh-lms'),
				'verify'    => __('Sign in', 'nimikh-lms'),
				'codeSent'  => __('We sent a code to your phone.', 'nimikh-lms'),
				'change'    => __('Use another number', 'nimikh-lms'),
				'or'        => __('or', 'nimikh-lms'),
				'error'     => __('Something went wrong. Please try again.', 'nimikh-lms'),
				'heading'   => __('Sign in', 'nimikh-lms'),
			],
		]);
		return '<section class="nk-auth" aria-label="' . esc_attr__('Sign in', 'nimikh-lms') . '"></section>';
	}
}
