<?php
declare(strict_types=1);

namespace Nimikh\LMS\Profile;

use Nimikh\LMS\Certificates\CertificateRepository;
use Nimikh\LMS\Certificates\CertificateService;
use Nimikh\LMS\Certificates\VerifyRoute;
use Nimikh\LMS\Integrations\Tutor\TutorAdapter;

/**
 * FR-09 learner profile.
 *   [nimikh_dashboard]  private: progress, scores, certificates, public-profile toggle
 *   [nimikh_profile user="login"]  public page, only if that learner opted in
 */
final class ProfileShortcodes {

	public const META_PUBLIC = 'nimikh_profile_public';

	public function __construct(
		private TutorAdapter $tutor,
		private CertificateRepository $certs,
		private CertificateService $service
	) {}

	public function register(): void {
		add_shortcode('nimikh_dashboard', [$this, 'dashboard']);
		add_shortcode('nimikh_profile', [$this, 'publicProfile']);
		add_action('admin_post_nimikh_toggle_public', [$this, 'togglePublic']);
	}

	public function dashboard(): string {
		if (!is_user_logged_in()) {
			return '<p>' . esc_html__('Please log in to see your dashboard.', 'nimikh-lms') . '</p>';
		}
		wp_enqueue_style('nimikh-tokens');
		wp_enqueue_style('nimikh-player');
		$user   = wp_get_current_user();
		$public = (bool) get_user_meta($user->ID, self::META_PUBLIC, true);

		ob_start();
		echo '<section class="nk-dash">';
		printf('<h2>%s</h2>', esc_html(sprintf(__('Hi %s', 'nimikh-lms'), $user->display_name)));
		echo $this->certificateList($user->ID, true); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside

		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="nk-dash__public">';
		wp_nonce_field('nimikh_toggle_public');
		echo '<input type="hidden" name="action" value="nimikh_toggle_public">';
		printf(
			'<label><input type="checkbox" name="public" value="1" %s> %s</label> <button type="submit">%s</button>',
			checked($public, true, false),
			esc_html__('Show my certificates on a public profile', 'nimikh-lms'),
			esc_html__('Save', 'nimikh-lms')
		);
		if ($public) {
			printf('<p><a href="%s">%s</a></p>', esc_url(self::profileUrl($user->user_login)), esc_html__('View my public profile', 'nimikh-lms'));
		}
		echo '</form></section>';
		return (string) ob_get_clean();
	}

	public function publicProfile($atts): string {
		$atts = shortcode_atts(['user' => ''], (array) $atts, 'nimikh_profile');
		$login = $atts['user'] !== '' ? (string) $atts['user'] : (isset($_GET['learner']) ? sanitize_user(wp_unslash((string) $_GET['learner'])) : '');
		$user  = $login !== '' ? get_user_by('login', $login) : false;

		if (!$user || !get_user_meta($user->ID, self::META_PUBLIC, true)) {
			return '<p>' . esc_html__('This profile is not available.', 'nimikh-lms') . '</p>';
		}
		wp_enqueue_style('nimikh-tokens');
		wp_enqueue_style('nimikh-player');
		return '<section class="nk-dash"><h2>' . esc_html($user->display_name) . '</h2>' . $this->certificateList($user->ID, false) . '</section>';
	}

	public function togglePublic(): void {
		check_admin_referer('nimikh_toggle_public');
		$uid = get_current_user_id();
		if ($uid) {
			update_user_meta($uid, self::META_PUBLIC, empty($_POST['public']) ? '' : '1');
		}
		wp_safe_redirect(wp_get_referer() ?: home_url('/'));
		exit;
	}

	public static function profileUrl(string $login): string {
		return add_query_arg('learner', rawurlencode($login), (string) apply_filters('nimikh_lms_profile_page_url', home_url('/learner/')));
	}

	private function certificateList(int $userId, bool $private): string {
		$certs = array_filter($this->certs->forUser($userId), static fn(array $c): bool => $c['status'] === CertificateRepository::STATUS_VALID);
		if (!$certs) {
			return '<p class="nk-empty">' . esc_html__('No certificates yet. Finish a course to earn one.', 'nimikh-lms') . '</p>';
		}
		$html = '<ul class="nk-certs">';
		foreach ($certs as $c) {
			$html .= '<li class="nk-cert"><strong>' . esc_html($this->tutor->courseTitle($c['course_id'])) . '</strong>';
			$html .= ' <span class="nk-badge nk-badge--valid">' . esc_html__('Verified', 'nimikh-lms') . '</span><br>';
			$html .= '<small>' . esc_html(wp_date(get_option('date_format'), strtotime($c['issued_at'] . ' UTC')));
			if ($c['score'] !== null) {
				$html .= ' · ' . esc_html(rtrim(rtrim(number_format($c['score'], 2), '0'), '.')) . '%';
			}
			$html .= '</small><br>';
			$html .= '<a href="' . esc_url(VerifyRoute::url($c['code'])) . '">' . esc_html__('Verification link', 'nimikh-lms') . '</a>';
			if ($private) {
				$html .= ' · <a href="' . esc_url(home_url('/certificate/' . $c['code'] . '/download')) . '">' . esc_html__('Download', 'nimikh-lms') . '</a>';
			}
			$html .= '</li>';
		}
		return $html . '</ul>';
	}
}
