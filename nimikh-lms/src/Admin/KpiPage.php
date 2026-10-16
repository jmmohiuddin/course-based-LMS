<?php
declare(strict_types=1);

namespace Nimikh\LMS\Admin;

use Nimikh\LMS\Metrics\MetricsService;
use Nimikh\LMS\Support\Roles;

/** Nimikh LMS → Success metrics: the PRD 4.1 targets against what is actually happening. */
final class KpiPage {

	public function __construct(private MetricsService $metrics) {}

	public function register(): void {
		add_action('admin_menu', [$this, 'menu'], 25);
	}

	public function menu(): void {
		add_submenu_page('nimikh-lms', __('Success metrics', 'nimikh-lms'), __('Success metrics', 'nimikh-lms'), Roles::CAP_MANAGE, 'nimikh-lms-kpis', [$this, 'page']);
	}

	public function page(): void {
		if (!current_user_can(Roles::CAP_MANAGE)) {
			wp_die(esc_html__('Not allowed.', 'nimikh-lms'), '', ['response' => 403]);
		}
		$days = isset($_GET['days']) ? (int) $_GET['days'] : 90; // phpcs:ignore WordPress.Security.NonceVerification -- read-only filter
		$days = in_array($days, [0, 30, 90, 365], true) ? $days : 90;

		echo '<div class="wrap"><h1>' . esc_html__('Success metrics', 'nimikh-lms') . '</h1><p>';
		foreach ([30 => __('Last 30 days', 'nimikh-lms'), 90 => __('Last 90 days', 'nimikh-lms'), 365 => __('Last year', 'nimikh-lms'), 0 => __('All time', 'nimikh-lms')] as $d => $label) {
			printf('<a class="button%s" href="%s">%s</a> ', $d === $days ? ' button-primary' : '', esc_url(add_query_arg(['page' => 'nimikh-lms-kpis', 'days' => $d], admin_url('admin.php'))), esc_html($label));
		}
		echo '</p><table class="widefat striped"><thead><tr><th>' . esc_html__('Metric', 'nimikh-lms') . '</th><th>' . esc_html__('Now', 'nimikh-lms') . '</th><th>' . esc_html__('Target', 'nimikh-lms') . '</th><th>' . esc_html__('Status', 'nimikh-lms') . '</th><th>' . esc_html__('Detail', 'nimikh-lms') . '</th></tr></thead><tbody>';
		$status = [
			'met'     => ['✔', __('On target', 'nimikh-lms')],
			'missed'  => ['✖', __('Below target', 'nimikh-lms')],
			'watch'   => ['!', __('Check: possibly too easy', 'nimikh-lms')],
			'no_data' => ['–', __('No data yet', 'nimikh-lms')],
		];
		foreach ($this->metrics->kpis($days) as $r) {
			[$icon, $text] = $status[$r['status']];
			$value = $r['value'] === null ? '–' : rtrim(rtrim(number_format($r['value'], 1), '0'), '.') . $r['unit'];
			printf('<tr><td>%s</td><td><strong>%s</strong></td><td>%s</td><td><span aria-hidden="true">%s</span> %s</td><td>%s</td></tr>', esc_html($r['label']), esc_html($value), esc_html($r['target']), esc_html($icon), esc_html($text), esc_html($r['detail']));
		}
		echo '</tbody></table><p class="description">' . esc_html__('Completion is measured on the learners who enrolled in the period. Timings come from real learners’ browsers and store no personal data.', 'nimikh-lms') . '</p></div>';
	}
}
