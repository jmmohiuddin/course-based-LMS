<?php
declare(strict_types=1);

namespace Nimikh\LMS\Admin;

use Nimikh\LMS\Certificates\CertificateRepository;
use Nimikh\LMS\Certificates\CertificateService;
use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Support\Roles;
use Nimikh\LMS\Support\Settings;

final class AdminPages {

	public function __construct(
		private TutorAdapter $tutor,
		private CertificateRepository $certs,
		private CertificateService $service
	) {}

	public function register(): void {
		add_action('admin_menu', [$this, 'menu']);
		add_action('admin_init', [$this, 'registerSettings']);
		add_action('admin_post_nimikh_revoke', [$this, 'handleRevoke']);
		add_action('admin_post_nimikh_batch_enrol', [$this, 'handleBatchEnrol']);
	}

	public function menu(): void {
		add_menu_page(__('Nimikh LMS', 'nimikh-lms'), __('Nimikh LMS', 'nimikh-lms'), Roles::CAP_AUTHOR, 'nimikh-lms', [$this, 'certificatesPage'], 'dashicons-welcome-learn-more', 30);
		add_submenu_page('nimikh-lms', __('Certificates', 'nimikh-lms'), __('Certificates', 'nimikh-lms'), Roles::CAP_MANAGE, 'nimikh-lms', [$this, 'certificatesPage']);
		add_submenu_page('nimikh-lms', __('Batch enrolment', 'nimikh-lms'), __('Batch enrolment', 'nimikh-lms'), Roles::CAP_MANAGE, 'nimikh-lms-batch', [$this, 'batchPage']);
		add_submenu_page('nimikh-lms', __('Settings', 'nimikh-lms'), __('Settings', 'nimikh-lms'), 'manage_options', 'nimikh-lms-settings', [$this, 'settingsPage']);
	}

	public function registerSettings(): void {
		foreach (array_keys(\Nimikh\LMS\Sms\SmsService::DEFAULT_TEMPLATES) as $event) {
			register_setting('nimikh_lms', 'nimikh_sms_template_' . $event, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '']);
		}
		register_setting('nimikh_lms', Settings::OPTION, [
			'type'              => 'array',
			'sanitize_callback' => static fn($in): array => Settings::sanitize(is_array($in) ? $in : []),
			'default'           => Settings::DEFAULTS,
		]);
	}

	public function settingsPage(): void {
		$fields = [
			'issuer_name'         => [__('Issuer name (on certificates)', 'nimikh-lms'), 'text'],
			'bunny_host'          => [__('Bunny pull-zone hostname', 'nimikh-lms'), 'text'],
			'bunny_token_key'     => [__('Bunny token authentication key', 'nimikh-lms'), 'password'],
			'url_ttl'             => [__('Signed URL lifetime (seconds)', 'nimikh-lms'), 'number'],
			'max_playback_rate'   => [__('Max playback speed', 'nimikh-lms'), 'number'],
			'heartbeat_tolerance' => [__('Heartbeat tolerance (0.10 = 10%)', 'nimikh-lms'), 'number'],
			'attempts_per_minute' => [__('MCQ answers per minute per learner', 'nimikh-lms'), 'number'],
			'gotenberg_url'       => [__('Gotenberg URL (optional PDF renderer)', 'nimikh-lms'), 'url'],
			'discussion_enabled'  => [__('Lesson discussion', 'nimikh-lms'), 'checkbox'],
			'jitsi_host'          => [__('Jitsi host for live classes', 'nimikh-lms'), 'text'],
			'sms_enabled'         => [__('Send SMS notifications', 'nimikh-lms'), 'checkbox'],
			'sms_gateway_url'     => [__('SMS gateway URL (https; use {to} {message} {sender} {api_key})', 'nimikh-lms'), 'url'],
			'sms_method'          => [__('SMS request method (GET or POST)', 'nimikh-lms'), 'text'],
			'sms_api_key'         => [__('SMS API key', 'nimikh-lms'), 'password'],
			'sms_sender'          => [__('SMS sender ID', 'nimikh-lms'), 'text'],
			'ai_enabled'          => [__('AI question suggestions (sends transcripts to Anthropic)', 'nimikh-lms'), 'checkbox'],
			'ai_api_key'          => [__('Anthropic API key', 'nimikh-lms'), 'password'],
			'ai_model'            => [__('AI model ID', 'nimikh-lms'), 'text'],
			'pwa_enabled'         => [__('Installable app (PWA)', 'nimikh-lms'), 'checkbox'],
			'pwa_start_url'       => [__('App start URL (e.g. the dashboard page)', 'nimikh-lms'), 'url'],
			'pwa_icon_url'        => [__('App icon URL (optional, SVG or PNG)', 'nimikh-lms'), 'url'],
			'remove_data_on_uninstall' => [__('Delete ALL Nimikh data (tables, certificates, files) when the plugin is deleted', 'nimikh-lms'), 'checkbox'],
		];
		echo '<div class="wrap"><h1>' . esc_html__('Nimikh LMS settings', 'nimikh-lms') . '</h1><form method="post" action="options.php">';
		settings_fields('nimikh_lms');
		echo '<table class="form-table">';
		foreach ($fields as $key => [$label, $type]) {
			if ($type === 'checkbox') {
				printf(
					'<tr><th>%2$s</th><td><label><input type="checkbox" name="%3$s[%1$s]" value="1" %4$s> %5$s</label></td></tr>',
					esc_attr($key),
					esc_html($label),
					esc_attr(Settings::OPTION),
					checked((bool) Settings::get($key), true, false),
					esc_html__('Enabled', 'nimikh-lms')
				);
				continue;
			}
			printf(
				'<tr><th><label for="nk-%1$s">%2$s</label></th><td><input id="nk-%1$s" class="regular-text" type="%3$s" step="any" name="%4$s[%1$s]" value="%5$s" autocomplete="off"></td></tr>',
				esc_attr($key),
				esc_html($label),
				esc_attr($type),
				esc_attr(Settings::OPTION),
				esc_attr((string) Settings::get($key))
			);
		}
		foreach (\Nimikh\LMS\Sms\SmsService::DEFAULT_TEMPLATES as $event => $default) {
			printf(
				'<tr><th><label for="nk-sms-%1$s">%2$s</label></th><td><input id="nk-sms-%1$s" class="large-text" name="nimikh_sms_template_%1$s" value="%3$s"></td></tr>',
				esc_attr($event),
				esc_html(sprintf(__('SMS template: %s', 'nimikh-lms'), $event)),
				esc_attr(\Nimikh\LMS\Sms\SmsService::template($event))
			);
		}
		echo '</table>';
		submit_button();
		echo '</form></div>';
	}

	public function certificatesPage(): void {
		if (!current_user_can(Roles::CAP_MANAGE)) {
			echo '<div class="wrap"><p>' . esc_html__('Use the course builder to add questions to your videos.', 'nimikh-lms') . '</p></div>';
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__('Certificates', 'nimikh-lms') . '</h1>';
		if (isset($_GET['revoked'])) {
			echo '<div class="notice notice-success"><p>' . esc_html__('Certificate revoked.', 'nimikh-lms') . '</p></div>';
		}
		echo '<table class="widefat striped"><thead><tr>';
		foreach ([__('ID', 'nimikh-lms'), __('Learner', 'nimikh-lms'), __('Course', 'nimikh-lms'), __('Score', 'nimikh-lms'), __('Issued', 'nimikh-lms'), __('Status', 'nimikh-lms'), ''] as $h) {
			echo '<th>' . esc_html($h) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ($this->certs->recent(200) as $c) {
			$user = get_userdata($c['user_id']);
			echo '<tr><td><code>' . esc_html($c['code']) . '</code></td>';
			echo '<td>' . esc_html($user ? $user->display_name : '#' . $c['user_id']) . '</td>';
			echo '<td>' . esc_html($this->tutor->courseTitle($c['course_id'])) . '</td>';
			echo '<td>' . esc_html($c['score'] === null ? '-' : $c['score'] . '%') . '</td>';
			echo '<td>' . esc_html($c['issued_at']) . '</td>';
			echo '<td>' . esc_html($c['status']) . '</td><td>';
			if ($c['status'] === CertificateRepository::STATUS_VALID) {
				printf(
					'<form method="post" action="%s" style="display:flex;gap:4px"><input type="hidden" name="action" value="nimikh_revoke"><input type="hidden" name="id" value="%d">%s<input name="reason" placeholder="%s" required><button class="button">%s</button></form>',
					esc_url(admin_url('admin-post.php')),
					$c['id'],
					wp_nonce_field('nimikh_revoke_' . $c['id'], '_wpnonce', true, false),
					esc_attr__('Reason', 'nimikh-lms'),
					esc_html__('Revoke', 'nimikh-lms')
				);
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public function handleRevoke(): void {
		$id = (int) ($_POST['id'] ?? 0);
		check_admin_referer('nimikh_revoke_' . $id);
		if (!current_user_can(Roles::CAP_MANAGE)) {
			wp_die(esc_html__('Not allowed.', 'nimikh-lms'), '', ['response' => 403]);
		}
		$reason = sanitize_text_field(wp_unslash((string) ($_POST['reason'] ?? '')));
		if ($id > 0 && $reason !== '') {
			$this->service->revoke($id, $reason);
		}
		wp_safe_redirect(add_query_arg(['page' => 'nimikh-lms', 'revoked' => 1], admin_url('admin.php')));
		exit;
	}

	// ---- FR-14 batch enrolment ------------------------------------------------

	public function batchPage(): void {
		echo '<div class="wrap"><h1>' . esc_html__('Batch enrolment', 'nimikh-lms') . '</h1>';
		if (isset($_GET['report'])) {
			$r = get_transient('nimikh_batch_' . get_current_user_id());
			if (is_array($r)) {
				printf(
					'<div class="notice notice-info"><p>%s</p>%s</div>',
					esc_html(sprintf(__('%1$d enrolled, %2$d created, %3$d skipped.', 'nimikh-lms'), $r['enrolled'], $r['created'], count($r['errors']))),
					$r['errors'] ? '<ul><li>' . implode('</li><li>', array_map('esc_html', $r['errors'])) . '</li></ul>' : ''
				);
			}
		}
		echo '<p>' . esc_html__('Upload a CSV with columns: email, name (name optional). Unknown emails get a new learner account and a password-reset email.', 'nimikh-lms') . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('nimikh_batch_enrol');
		echo '<input type="hidden" name="action" value="nimikh_batch_enrol">';
		echo '<p><label>' . esc_html__('Course ID', 'nimikh-lms') . ' <input type="number" name="course_id" min="1" required></label></p>';
		echo '<p><input type="file" name="csv" accept=".csv,text/csv" required></p>';
		submit_button(__('Enrol learners', 'nimikh-lms'));
		echo '</form></div>';
	}

	public function handleBatchEnrol(): void {
		check_admin_referer('nimikh_batch_enrol');
		if (!current_user_can(Roles::CAP_MANAGE)) {
			wp_die(esc_html__('Not allowed.', 'nimikh-lms'), '', ['response' => 403]);
		}
		$courseId = (int) ($_POST['course_id'] ?? 0);
		$rows     = [];
		if (!empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
			$fh = fopen($_FILES['csv']['tmp_name'], 'r');
			while ($fh && ($cols = fgetcsv($fh)) !== false && count($rows) < \Nimikh\LMS\Support\BatchEnrol::MAX_ROWS) {
				$rows[] = $cols;
			}
			if ($fh) {
				fclose($fh);
			}
		}
		$report = $rows
			? (new \Nimikh\LMS\Support\BatchEnrol($this->tutor))->run($rows, $courseId)
			: ['enrolled' => 0, 'created' => 0, 'errors' => [__('No file uploaded.', 'nimikh-lms')]];
		set_transient('nimikh_batch_' . get_current_user_id(), $report, 300);
		wp_safe_redirect(add_query_arg(['page' => 'nimikh-lms-batch', 'report' => 1], admin_url('admin.php')));
		exit;
	}
}
