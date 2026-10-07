<?php
declare(strict_types=1);

namespace Nimikh\LMS\Admin;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Live\LiveService;
use Nimikh\LMS\Orgs\OrgRepository;
use Nimikh\LMS\Orgs\OrgService;
use Nimikh\LMS\Orgs\PortalRoute;
use Nimikh\LMS\Subscriptions\SubscriptionService;
use Nimikh\LMS\Support\Roles;

/** wp-admin screens for the phase 2-3 modules: reports, plans, institutes, live classes. */
final class Phase3Pages {

	public function __construct(
		private TutorAdapter $tutor,
		private SubscriptionService $subs,
		private OrgRepository $orgs,
		private OrgService $orgService,
		private LiveService $live
	) {}

	public function register(): void {
		add_action('admin_menu', [$this, 'menu'], 20);
		add_action('admin_enqueue_scripts', [$this, 'assets']);
		foreach (['plan_create', 'plan_grant', 'sub_cancel', 'org_create', 'org_member', 'org_course', 'live_create', 'live_cancel'] as $action) {
			add_action('admin_post_nimikh_' . $action, [$this, 'handle_' . $action]);
		}
	}

	public function menu(): void {
		add_submenu_page('nimikh-lms', __('Reports', 'nimikh-lms'), __('Reports', 'nimikh-lms'), Roles::CAP_AUTHOR, 'nimikh-lms-reports', [$this, 'reportsPage']);
		add_submenu_page('nimikh-lms', __('Live classes', 'nimikh-lms'), __('Live classes', 'nimikh-lms'), Roles::CAP_AUTHOR, 'nimikh-lms-live', [$this, 'livePage']);
		add_submenu_page('nimikh-lms', __('Plans & subscriptions', 'nimikh-lms'), __('Plans & subscriptions', 'nimikh-lms'), Roles::CAP_MANAGE, 'nimikh-lms-plans', [$this, 'plansPage']);
		add_submenu_page('nimikh-lms', __('Institutes', 'nimikh-lms'), __('Institutes', 'nimikh-lms'), Roles::CAP_MANAGE, 'nimikh-lms-orgs', [$this, 'orgsPage']);
	}

	public function assets(string $hook): void {
		if (!str_ends_with($hook, 'nimikh-lms-reports')) {
			return;
		}
		wp_enqueue_style('nimikh-tokens', NIMIKH_LMS_URL . 'assets/css/tokens.css', [], NIMIKH_LMS_VERSION);
		wp_enqueue_style('nimikh-reports', NIMIKH_LMS_URL . 'assets/css/reports.css', ['nimikh-tokens'], NIMIKH_LMS_VERSION);
		wp_enqueue_script('nimikh-reports', NIMIKH_LMS_URL . 'assets/js/reports.js', [], NIMIKH_LMS_VERSION, ['in_footer' => true]);
		wp_localize_script('nimikh-reports', 'NimikhReports', ['restUrl' => esc_url_raw(rest_url('nimikh/v1')), 'nonce' => wp_create_nonce('wp_rest')]);
	}

	private function guard(string $cap): void {
		if (!current_user_can($cap)) {
			wp_die(esc_html__('Not allowed.', 'nimikh-lms'), '', ['response' => 403]);
		}
	}

	private function back(string $page, array $args = []): void {
		wp_safe_redirect(add_query_arg(['page' => $page] + $args, admin_url('admin.php')));
		exit;
	}

	private function notice(): void {
		if (!empty($_GET['done'])) {
			echo '<div class="notice notice-success"><p>' . esc_html__('Saved.', 'nimikh-lms') . '</p></div>';
		}
		if (!empty($_GET['error'])) {
			echo '<div class="notice notice-error"><p>' . esc_html(sanitize_text_field(wp_unslash((string) $_GET['error']))) . '</p></div>';
		}
	}

	/** @return array<int, array{id:int,title:string}> */
	private function courses(): array {
		return $this->tutor->managedCourses(get_current_user_id());
	}

	private function courseOptions(string $name, bool $multiple = false): string {
		$html = sprintf('<select name="%s"%s required>', esc_attr($name), $multiple ? ' multiple size="6"' : '');
		foreach ($this->courses() as $c) {
			$html .= sprintf('<option value="%d">%s</option>', $c['id'], esc_html($c['title']));
		}
		return $html . '</select>';
	}

	// ---- reports --------------------------------------------------------------

	public function reportsPage(): void {
		echo '<div class="wrap"><h1>' . esc_html__('Course reports', 'nimikh-lms') . '</h1><div id="nk-reports"></div></div>';
	}

	// ---- plans & subscriptions ---------------------------------------------------

	public function plansPage(): void {
		echo '<div class="wrap"><h1>' . esc_html__('Plans & subscriptions', 'nimikh-lms') . '</h1>';
		$this->notice();
		echo '<p>' . esc_html__('A plan unlocks courses for a number of days. Payment gateways grant access by firing do_action( \'nimikh_subscription_paid\', $user_id, $plan_id, $reference ).', 'nimikh-lms') . '</p>';

		echo '<h2>' . esc_html__('New plan', 'nimikh-lms') . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('nimikh_plan_create');
		echo '<input type="hidden" name="action" value="nimikh_plan_create"><table class="form-table">';
		echo '<tr><th>' . esc_html__('Name', 'nimikh-lms') . '</th><td><input name="name" required class="regular-text"></td></tr>';
		echo '<tr><th>' . esc_html__('Courses', 'nimikh-lms') . '</th><td>' . $this->courseOptions('course_ids[]', true) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>' . esc_html__('Duration (days)', 'nimikh-lms') . '</th><td><input type="number" name="days" min="1" value="30" required></td></tr>';
		echo '<tr><th>' . esc_html__('Price label', 'nimikh-lms') . '</th><td><input name="price_label" placeholder="৳ 499 / month" class="regular-text"></td></tr></table>';
		submit_button(__('Create plan', 'nimikh-lms'));
		echo '</form>';

		echo '<h2>' . esc_html__('Plans', 'nimikh-lms') . '</h2><table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__('Name', 'nimikh-lms') . '</th><th>' . esc_html__('Days', 'nimikh-lms') . '</th><th>' . esc_html__('Courses', 'nimikh-lms') . '</th></tr></thead><tbody>';
		$plans = $this->subs->plans();
		foreach ($plans as $p) {
			printf('<tr><td>%d</td><td>%s</td><td>%d</td><td>%s</td></tr>', $p['id'], esc_html($p['name']), $p['duration_days'], esc_html(implode(', ', array_map([$this->tutor, 'courseTitle'], $p['course_ids']))));
		}
		echo '</tbody></table>';

		if ($plans) {
			echo '<h2>' . esc_html__('Grant a subscription', 'nimikh-lms') . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
			wp_nonce_field('nimikh_plan_grant');
			echo '<input type="hidden" name="action" value="nimikh_plan_grant"><p><input type="email" name="email" placeholder="learner@example.com" required> <select name="plan_id">';
			foreach ($plans as $p) {
				printf('<option value="%d">%s</option>', $p['id'], esc_html($p['name']));
			}
			echo '</select> ';
			submit_button(__('Grant', 'nimikh-lms'), 'secondary', 'submit', false);
			echo '</p></form>';
		}
		echo '<h2>' . esc_html__('Recent subscriptions', 'nimikh-lms') . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__('Learner', 'nimikh-lms') . '</th><th>' . esc_html__('Plan', 'nimikh-lms') . '</th><th>' . esc_html__('Expires', 'nimikh-lms') . '</th><th>' . esc_html__('Status', 'nimikh-lms') . '</th><th></th></tr></thead><tbody>';
		foreach ($this->subs->recent() as $s) {
			$u = get_userdata($s['user_id']);
			printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>', esc_html($u ? $u->user_email : '#' . $s['user_id']), esc_html($s['plan']), esc_html($s['expires_at']), esc_html($s['status']));
			if ($s['status'] === 'active') {
				printf('<form method="post" action="%s">%s<input type="hidden" name="action" value="nimikh_sub_cancel"><input type="hidden" name="id" value="%d"><button class="button">%s</button></form>', esc_url(admin_url('admin-post.php')), wp_nonce_field('nimikh_sub_cancel_' . $s['id'], '_wpnonce', true, false), $s['id'], esc_html__('Cancel', 'nimikh-lms'));
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public function handle_plan_create(): void {
		check_admin_referer('nimikh_plan_create');
		$this->guard(Roles::CAP_MANAGE);
		$ids  = array_filter(array_map('intval', (array) ($_POST['course_ids'] ?? [])), fn(int $id): bool => get_post_type($id) === TutorAdapter::COURSE_POST_TYPE);
		$name = sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? '')));
		$days = (int) ($_POST['days'] ?? 0);
		if ($name === '' || !$ids || $days < 1) {
			$this->back('nimikh-lms-plans', ['error' => __('A plan needs a name, courses and a duration.', 'nimikh-lms')]);
		}
		$this->subs->createPlan($name, $ids, $days, sanitize_text_field(wp_unslash((string) ($_POST['price_label'] ?? ''))));
		$this->back('nimikh-lms-plans', ['done' => 1]);
	}

	public function handle_plan_grant(): void {
		check_admin_referer('nimikh_plan_grant');
		$this->guard(Roles::CAP_MANAGE);
		$user = get_user_by('email', sanitize_email(wp_unslash((string) ($_POST['email'] ?? ''))));
		if (!$user) {
			$this->back('nimikh-lms-plans', ['error' => __('No account with that email.', 'nimikh-lms')]);
		}
		$res = $this->subs->grant((int) $user->ID, (int) ($_POST['plan_id'] ?? 0), 'admin:' . get_current_user_id());
		$this->back('nimikh-lms-plans', is_wp_error($res) ? ['error' => $res->get_error_message()] : ['done' => 1]);
	}

	public function handle_sub_cancel(): void {
		$id = (int) ($_POST['id'] ?? 0);
		check_admin_referer('nimikh_sub_cancel_' . $id);
		$this->guard(Roles::CAP_MANAGE);
		$this->subs->cancel($id);
		$this->back('nimikh-lms-plans', ['done' => 1]);
	}

	// ---- institutes -------------------------------------------------------------

	public function orgsPage(): void {
		echo '<div class="wrap"><h1>' . esc_html__('Institutes', 'nimikh-lms') . '</h1>';
		$this->notice();
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('nimikh_org_create');
		echo '<input type="hidden" name="action" value="nimikh_org_create"><table class="form-table">';
		echo '<tr><th>' . esc_html__('Name', 'nimikh-lms') . '</th><td><input name="name" required class="regular-text"></td></tr>';
		echo '<tr><th>' . esc_html__('Web address', 'nimikh-lms') . '</th><td>/institute/<input name="slug" placeholder="dhaka-it"></td></tr>';
		echo '<tr><th>' . esc_html__('Brand colour', 'nimikh-lms') . '</th><td><input name="brand_color" value="#1E4FD8" size="8"></td></tr>';
		echo '<tr><th>' . esc_html__('Logo URL (https)', 'nimikh-lms') . '</th><td><input type="url" name="logo_url" class="regular-text"></td></tr>';
		echo '<tr><th>' . esc_html__('Admin email', 'nimikh-lms') . '</th><td><input type="email" name="admin_email" class="regular-text"></td></tr></table>';
		submit_button(__('Create institute', 'nimikh-lms'));
		echo '</form>';

		foreach ($this->orgs->all() as $o) {
			printf('<h2>%s <small><a href="%s" target="_blank" rel="noopener">%s</a></small></h2>', esc_html($o['name']), esc_url(PortalRoute::url($o['slug'])), esc_html__('portal', 'nimikh-lms'));
			printf('<p>%s: %d · %s: %s</p>', esc_html__('Members', 'nimikh-lms'), count($this->orgs->memberIds($o['id'])), esc_html__('Courses', 'nimikh-lms'), esc_html(implode(', ', array_map([$this->tutor, 'courseTitle'], $this->orgs->courseIds($o['id']))) ?: '-'));
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:16px">';
			wp_nonce_field('nimikh_org_member');
			printf('<input type="hidden" name="action" value="nimikh_org_member"><input type="hidden" name="org_id" value="%d"><input type="email" name="email" placeholder="member@example.com" required> <select name="role"><option value="member">%s</option><option value="admin">%s</option></select> ', $o['id'], esc_html__('Member', 'nimikh-lms'), esc_html__('Institute admin', 'nimikh-lms'));
			submit_button(__('Add member', 'nimikh-lms'), 'secondary', 'submit', false);
			echo '</form><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block">';
			wp_nonce_field('nimikh_org_course');
			printf('<input type="hidden" name="action" value="nimikh_org_course"><input type="hidden" name="org_id" value="%d">', $o['id']);
			echo $this->courseOptions('course_id'); // phpcs:ignore WordPress.Security.EscapeOutput
			echo ' ';
			submit_button(__('Assign course', 'nimikh-lms'), 'secondary', 'submit', false);
			echo '</form>';
		}
		echo '</div>';
	}

	public function handle_org_create(): void {
		check_admin_referer('nimikh_org_create');
		$this->guard(Roles::CAP_MANAGE);
		$org = $this->orgs->create(
			sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? ''))),
			sanitize_text_field(wp_unslash((string) ($_POST['slug'] ?? ''))),
			sanitize_text_field(wp_unslash((string) ($_POST['brand_color'] ?? ''))),
			esc_url_raw(wp_unslash((string) ($_POST['logo_url'] ?? '')))
		);
		if (is_wp_error($org)) {
			$this->back('nimikh-lms-orgs', ['error' => $org->get_error_message()]);
		}
		if (!empty($_POST['admin_email'])) {
			$this->orgService->addMemberByEmail($org['id'], sanitize_email(wp_unslash((string) $_POST['admin_email'])), 'admin');
		}
		flush_rewrite_rules(false);
		$this->back('nimikh-lms-orgs', ['done' => 1]);
	}

	public function handle_org_member(): void {
		check_admin_referer('nimikh_org_member');
		$this->guard(Roles::CAP_MANAGE);
		$res = $this->orgService->addMemberByEmail((int) ($_POST['org_id'] ?? 0), sanitize_email(wp_unslash((string) ($_POST['email'] ?? ''))), sanitize_key((string) ($_POST['role'] ?? 'member')));
		$this->back('nimikh-lms-orgs', is_wp_error($res) ? ['error' => $res->get_error_message()] : ['done' => 1]);
	}

	public function handle_org_course(): void {
		check_admin_referer('nimikh_org_course');
		$this->guard(Roles::CAP_MANAGE);
		$this->orgs->assignCourse((int) ($_POST['org_id'] ?? 0), (int) ($_POST['course_id'] ?? 0));
		$this->back('nimikh-lms-orgs', ['done' => 1]);
	}

	// ---- live classes -----------------------------------------------------------

	public function livePage(): void {
		echo '<div class="wrap"><h1>' . esc_html__('Live classes', 'nimikh-lms') . '</h1>';
		$this->notice();
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('nimikh_live_create');
		echo '<input type="hidden" name="action" value="nimikh_live_create"><table class="form-table">';
		echo '<tr><th>' . esc_html__('Course', 'nimikh-lms') . '</th><td>' . $this->courseOptions('course_id') . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>' . esc_html__('Title', 'nimikh-lms') . '</th><td><input name="title" required class="regular-text"></td></tr>';
		echo '<tr><th>' . esc_html__('Starts', 'nimikh-lms') . '</th><td><input type="datetime-local" name="starts_at" required> <small>' . esc_html(wp_timezone_string()) . '</small></td></tr>';
		echo '<tr><th>' . esc_html__('Length (minutes)', 'nimikh-lms') . '</th><td><input type="number" name="duration_min" value="60" min="10" max="480"></td></tr>';
		echo '<tr><th>' . esc_html__('Provider', 'nimikh-lms') . '</th><td><select name="provider"><option value="jitsi">Jitsi (room created for you)</option><option value="zoom">Zoom</option><option value="meet">Google Meet</option><option value="other">Other</option></select></td></tr>';
		echo '<tr><th>' . esc_html__('Join link (not needed for Jitsi)', 'nimikh-lms') . '</th><td><input type="url" name="join_url" class="regular-text" placeholder="https://"></td></tr></table>';
		submit_button(__('Schedule class', 'nimikh-lms'));
		echo '</form><h2>' . esc_html__('Upcoming', 'nimikh-lms') . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__('Course', 'nimikh-lms') . '</th><th>' . esc_html__('Title', 'nimikh-lms') . '</th><th>' . esc_html__('Starts', 'nimikh-lms') . '</th><th>' . esc_html__('State', 'nimikh-lms') . '</th><th></th></tr></thead><tbody>';
		foreach ($this->courses() as $c) {
			foreach ($this->live->upcoming($c['id']) as $s) {
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><form method="post" action="%s">%s<input type="hidden" name="action" value="nimikh_live_cancel"><input type="hidden" name="id" value="%d"><button class="button">%s</button></form></td></tr>',
					esc_html($c['title']),
					esc_html($s['title']),
					esc_html(wp_date('Y-m-d H:i', strtotime($s['starts_at'] . ' UTC'))),
					esc_html($s['state']),
					esc_url(admin_url('admin-post.php')),
					wp_nonce_field('nimikh_live_cancel_' . $s['id'], '_wpnonce', true, false),
					$s['id'],
					esc_html__('Cancel', 'nimikh-lms')
				);
			}
		}
		echo '</tbody></table></div>';
	}

	public function handle_live_create(): void {
		check_admin_referer('nimikh_live_create');
		$this->guard(Roles::CAP_AUTHOR);
		$res = $this->live->create(get_current_user_id(), [
			'course_id'    => (int) ($_POST['course_id'] ?? 0),
			'title'        => wp_unslash((string) ($_POST['title'] ?? '')),
			'starts_at'    => wp_unslash((string) ($_POST['starts_at'] ?? '')),
			'duration_min' => (int) ($_POST['duration_min'] ?? 60),
			'provider'     => sanitize_key((string) ($_POST['provider'] ?? 'jitsi')),
			'join_url'     => wp_unslash((string) ($_POST['join_url'] ?? '')),
		]);
		$this->back('nimikh-lms-live', is_wp_error($res) ? ['error' => $res->get_error_message()] : ['done' => 1]);
	}

	public function handle_live_cancel(): void {
		$id = (int) ($_POST['id'] ?? 0);
		check_admin_referer('nimikh_live_cancel_' . $id);
		$this->guard(Roles::CAP_AUTHOR);
		$this->live->cancel(get_current_user_id(), $id);
		$this->back('nimikh-lms-live', ['done' => 1]);
	}
}
