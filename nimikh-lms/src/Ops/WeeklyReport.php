<?php
declare(strict_types=1);

namespace Nimikh\LMS\Ops;

use Nimikh\LMS\Support\Settings;

/** Blueprint 6.8: a weekly email with failed jobs and anything that needs attention. Off by default. */
final class WeeklyReport {

	public const HOOK = 'nimikh_weekly_report';

	public function register(): void {
		add_action(self::HOOK, [$this, 'send']);
		add_action('init', function (): void {
			$on = (bool) Settings::get('weekly_report_enabled');
			if ($on && !wp_next_scheduled(self::HOOK)) {
				wp_schedule_event(strtotime('next monday 06:00 UTC'), 'weekly', self::HOOK);
			} elseif (!$on && wp_next_scheduled(self::HOOK)) {
				wp_clear_scheduled_hook(self::HOOK);
			}
		});
		add_filter('cron_schedules', static function (array $s): array {
			$s['weekly'] ??= ['interval' => WEEK_IN_SECONDS, 'display' => __('Once weekly', 'nimikh-lms')];
			return $s;
		});
	}

	/**
	 * @param array{failed_jobs:int, failed_sms:int, issued:int, revoked:int, unrendered:int, cron_lag:int} $s
	 * @return string[] lines that need attention (empty = all quiet)
	 */
	public static function attention(array $s): array {
		$out = [];
		if ($s['failed_jobs'] > 0) {
			/* translators: %d: number of failed background jobs */
			$out[] = sprintf(__('%d background job(s) failed. Check Tools → Scheduled Actions → Failed.', 'nimikh-lms'), $s['failed_jobs']);
		}
		if ($s['failed_sms'] > 0) {
			/* translators: %d: number of failed SMS */
			$out[] = sprintf(__('%d SMS message(s) failed to send. Check the gateway settings.', 'nimikh-lms'), $s['failed_sms']);
		}
		if ($s['unrendered'] > 0) {
			/* translators: %d: number of certificates */
			$out[] = sprintf(__('%d certificate(s) have no file yet. Is the cron job running?', 'nimikh-lms'), $s['unrendered']);
		}
		if ($s['cron_lag'] > HealthStatus::MAX_CRON_LAG) {
			/* translators: %d: minutes */
			$out[] = sprintf(__('Background jobs are running %d minutes late.', 'nimikh-lms'), (int) round($s['cron_lag'] / 60));
		}
		return $out;
	}

	public function send(): void {
		if (!Settings::get('weekly_report_enabled')) {
			return;
		}
		$certs = Health::certificates();
		$stats = [
			'failed_jobs' => Health::failedJobs(),
			'failed_sms'  => Health::failedSms(),
			'issued'      => $certs['issued'],
			'revoked'     => $certs['revoked'],
			'unrendered'  => $certs['unrendered'],
			'cron_lag'    => Health::cronLagSeconds(),
		];
		$issues = self::attention($stats);
		$lines  = [
			/* translators: %s: site name */
			sprintf(__('Weekly report for %s', 'nimikh-lms'), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)),
			'',
			$issues ? __('Needs attention:', 'nimikh-lms') : __('Nothing needs attention this week.', 'nimikh-lms'),
		];
		foreach ($issues as $i) {
			$lines[] = '- ' . $i;
		}
		/* translators: %d: number of certificates */
		$lines[] = '';
		$lines[] = sprintf(__('Certificates issued in the last 7 days: %d', 'nimikh-lms'), $stats['issued']);
		$to = (string) apply_filters('nimikh_lms_weekly_report_to', get_option('admin_email'));
		if (is_email($to)) {
			wp_mail($to, $issues ? __('[Nimikh] Weekly report: needs attention', 'nimikh-lms') : __('[Nimikh] Weekly report', 'nimikh-lms'), implode("\n", $lines));
		}
	}
}
