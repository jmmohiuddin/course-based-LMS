<?php
declare(strict_types=1);

namespace Nimikh\LMS\Ops;

use Nimikh\LMS\Support\Installer;

/** Readings behind GET /nimikh/v1/health and the weekly report (blueprint 6.8). */
final class Health {

	public static function dbOk(): bool {
		global $wpdb;
		return (string) $wpdb->get_var('SELECT 1') === '1';
	}

	private static function asTable(): ?string {
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table ? $table : null;
	}

	/** Seconds the oldest overdue background job has been waiting (0 = nothing is late). Late jobs mean cron has stopped. */
	public static function cronLagSeconds(): int {
		global $wpdb;
		$table = self::asTable();
		if ($table === null) {
			return 0;
		}
		$oldest = $wpdb->get_var("SELECT MIN(scheduled_date_gmt) FROM $table WHERE status = 'pending' AND scheduled_date_gmt < UTC_TIMESTAMP()"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only
		return $oldest ? max(0, time() - (int) strtotime((string) $oldest . ' UTC')) : 0;
	}

	public static function failedJobs(int $days = 7): int {
		global $wpdb;
		$table = self::asTable();
		if ($table === null) {
			return 0;
		}
		return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status = 'failed' AND last_attempt_gmt > %s", gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS))); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function failedSms(int $days = 7): int {
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Installer::table('sms_log') . " WHERE status = 'failed' AND created_at > %s", gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS))); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** @return array{issued:int, revoked:int, unrendered:int} */
	public static function certificates(int $days = 7): array {
		global $wpdb;
		$t     = Installer::table('certificates');
		$since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
		return [
			'issued'     => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE issued_at > %s", $since)), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'revoked'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status = 'revoked'"), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'unrendered' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE status = 'valid' AND (pdf_key IS NULL OR pdf_key = '') AND issued_at < %s", gmdate('Y-m-d H:i:s', time() - 300))), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		];
	}
}
