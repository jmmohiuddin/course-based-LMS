<?php
declare(strict_types=1);

namespace Nimikh\LMS\Admin;

use Nimikh\LMS\Demo\DemoSeeder;
use Nimikh\LMS\Support\Roles;

/** Nimikh LMS -> Demo data: load or remove the sample institute, courses and learners. */
final class DemoPage {

	public function register(): void {
		add_action('admin_menu', [$this, 'menu'], 30);
		add_action('admin_post_nimikh_demo_seed', [$this, 'handleSeed']);
		add_action('admin_post_nimikh_demo_remove', [$this, 'handleRemove']);
	}

	public function menu(): void {
		add_submenu_page('nimikh-lms', __('Demo data', 'nimikh-lms'), __('Demo data', 'nimikh-lms'), Roles::CAP_MANAGE, 'nimikh-lms-demo', [$this, 'page']);
	}

	public function page(): void {
		echo '<div class="wrap"><h1>' . esc_html__('Demo data', 'nimikh-lms') . '</h1>';
		$err = isset($_GET['demo_error']) ? sanitize_text_field(wp_unslash((string) $_GET['demo_error'])) : '';
		if ($err !== '') {
			echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>';
		}
		$creds = get_transient('nimikh_demo_logins_' . get_current_user_id());
		if (is_array($creds)) {
			delete_transient('nimikh_demo_logins_' . get_current_user_id()); // shown once
			echo '<div class="notice notice-success"><p><strong>' . esc_html__('Demo data loaded. These passwords are shown once:', 'nimikh-lms') . '</strong></p><ul>';
			foreach ($creds as $l) {
				printf('<li>%s — <code>%s</code> / <code>%s</code> (%s)</li>', esc_html($l['name']), esc_html($l['login']), esc_html($l['password']), esc_html($l['role']));
			}
			echo '</ul></div>';
		}
		echo '<p>' . esc_html__('Loads a sample institute, 3 bilingual courses with in-video questions, 2 instructors and 12 learners with realistic progress, certificates, badges, discussion, a subscription plan and live classes. Demo users have no phone numbers and no email is sent. Everything it creates is tracked and can be removed in one click.', 'nimikh-lms') . '</p>';
		echo '<p>' . esc_html__('Tip: lesson videos use a public HLS test stream. Open a lesson in the editor once so its real duration is detected, or set the nimikh_lms_demo_video_url filter before loading.', 'nimikh-lms') . '</p>';

		$allowed = DemoSeeder::allowed();
		if (is_wp_error($allowed)) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html($allowed->get_error_message()) . '</p></div>';
		}
		if (DemoSeeder::isSeeded()) {
			echo '<p><strong>' . esc_html__('Demo data is loaded.', 'nimikh-lms') . '</strong></p>';
			$this->form('nimikh_demo_remove', __('Remove demo data', 'nimikh-lms'), true);
		} elseif (!is_wp_error($allowed)) {
			$this->form('nimikh_demo_seed', __('Load demo data', 'nimikh-lms'), false);
		}
		echo '</div>';
	}

	private function form(string $action, string $label, bool $danger): void {
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field($action);
		printf('<input type="hidden" name="action" value="%s">', esc_attr($action));
		submit_button($label, $danger ? 'delete' : 'primary');
		echo '</form>';
	}

	private function guard(string $action): void {
		check_admin_referer($action);
		if (!current_user_can(Roles::CAP_MANAGE)) {
			wp_die(esc_html__('Not allowed.', 'nimikh-lms'), '', ['response' => 403]);
		}
	}

	public function handleSeed(): void {
		$this->guard('nimikh_demo_seed');
		@set_time_limit(300); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$res = (new DemoSeeder())->seed();
		if (is_wp_error($res)) {
			$this->back(['demo_error' => $res->get_error_message()]);
		}
		set_transient('nimikh_demo_logins_' . get_current_user_id(), $res['logins'], 300);
		$this->back();
	}

	public function handleRemove(): void {
		$this->guard('nimikh_demo_remove');
		$res = (new DemoSeeder())->remove();
		$this->back(is_wp_error($res) ? ['demo_error' => $res->get_error_message()] : []);
	}

	private function back(array $args = []): void {
		wp_safe_redirect(add_query_arg(['page' => 'nimikh-lms-demo'] + $args, admin_url('admin.php')));
		exit;
	}
}
