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
		];
		echo '<div class="wrap"><h1>' . esc_html__('Nimikh LMS settings', 'nimikh-lms') . '</h1><form method="post" action="options.php">';
		settings_fields('nimikh_lms');
		echo '<table class="form-table">';
		foreach ($fields as $key => [$label, $type]) {
			printf(
				'<tr><th><label for="nk-%1$s">%2$s</label></th><td><input id="nk-%1$s" class="regular-text" type="%3$s" step="any" name="%4$s[%1$s]" value="%5$s" autocomplete="off"></td></tr>',
				esc_attr($key),
				esc_html($label),
				esc_attr($type),
				esc_attr(Settings::OPTION),
				esc_attr((string) Settings::get($key))
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
		$report   = ['enrolled' => 0, 'created' => 0, 'errors' => []];

		if (get_post_type($courseId) !== TutorAdapter::COURSE_POST_TYPE) {
			$report['errors'][] = __('Course not found.', 'nimikh-lms');
		} elseif (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
			$report['errors'][] = __('No file uploaded.', 'nimikh-lms');
		} else {
			$fh = fopen($_FILES['csv']['tmp_name'], 'r');
			$line = 0;
			while ($fh && ($cols = fgetcsv($fh)) !== false && $line < 5000) {
				$line++;
				$email = sanitize_email(trim((string) ($cols[0] ?? '')));
				if ($line === 1 && strtolower($email) === 'email' || !$cols || $cols === [null]) {
					continue; // header or blank line
				}
				if (!is_email($email)) {
					$report['errors'][] = sprintf(__('Line %d: invalid email', 'nimikh-lms'), $line);
					continue;
				}
				$user = get_user_by('email', $email);
				if (!$user) {
					$uid = wp_insert_user([
						'user_login'   => $email,
						'user_email'   => $email,
						'display_name' => sanitize_text_field((string) ($cols[1] ?? '')) ?: strstr($email, '@', true),
						'user_pass'    => wp_generate_password(20),
						'role'         => 'subscriber',
					]);
					if (is_wp_error($uid)) {
						$report['errors'][] = sprintf(__('Line %1$d: %2$s', 'nimikh-lms'), $line, $uid->get_error_message());
						continue;
					}
					$report['created']++;
					wp_new_user_notification($uid, null, 'user');
					$user = get_userdata($uid);
				}
				if ($this->tutor->enrol($user->ID, $courseId)) {
					$report['enrolled']++;
				} else {
					$report['errors'][] = sprintf(__('Line %d: could not enrol (is Tutor LMS active?)', 'nimikh-lms'), $line);
				}
			}
			if ($fh) {
				fclose($fh);
			}
		}
		set_transient('nimikh_batch_' . get_current_user_id(), $report, 300);
		wp_safe_redirect(add_query_arg(['page' => 'nimikh-lms-batch', 'report' => 1], admin_url('admin.php')));
		exit;
	}
}
