<?php
/**
 * Runs when the plugin is deleted from wp-admin. By default NOTHING is removed: certificates are verifiable
 * records and learners' progress is hard to recreate. Data is only deleted if an admin ticked
 * "Delete ALL Nimikh data" in Nimikh LMS -> Settings before deleting the plugin.
 */
declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

$settings = get_option('nimikh_lms_settings', []);
if (empty($settings['remove_data_on_uninstall'])) {
	return;
}

global $wpdb;
foreach (['questions', 'video_interactions', 'watch_progress', 'interaction_attempts', 'certificates', 'user_badges', 'activity_days',
	'discussions', 'plans', 'subscriptions', 'orgs', 'org_members', 'org_courses', 'live_sessions', 'live_attendance', 'sms_log', 'notes'] as $table) {
	$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'nimikh_' . $table); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

// Certificate files.
$uploads = wp_upload_dir(null, false);
$dir     = trailingslashit($uploads['basedir']) . 'nimikh-certificates';
if (is_dir($dir)) {
	foreach (glob($dir . '/*') ?: [] as $file) {
		if (is_file($file)) {
			@unlink($file); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}
	@rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

// Options, scheduled jobs, roles, per-user meta, post meta and certificate templates.
foreach (['nimikh_lms_settings', 'nimikh_lms_db_version', 'nimikh_demo_manifest', 'nimikh_sms_template_enrolled', 'nimikh_sms_template_certificate_issued', 'nimikh_sms_template_exam_result', 'nimikh_sms_template_live_reminder'] as $opt) {
	delete_option($opt);
}
foreach (['nimikh_expire_subscriptions', 'nimikh_live_reminders', 'nimikh_render_certificate', 'nimikh_weekly_report'] as $hook) {
	wp_clear_scheduled_hook($hook);
}
remove_role('nimikh_instructor');
remove_role('nimikh_institute_admin');
foreach (['administrator', 'tutor_instructor'] as $roleName) {
	if ($role = get_role($roleName)) {
		$role->remove_cap('nimikh_manage');
		$role->remove_cap('nimikh_author_video');
	}
}
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('nimikh_profile_public','nimikh_phone','nimikh_google_sub','nimikh_sms_optout','nimikh_demo') OR meta_key LIKE 'nimikh_demo_enrolled_%' OR meta_key LIKE 'nimikh_exam_last_attempt_%'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'nimikh\\_%'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach (get_posts(['post_type' => 'nimikh_cert_template', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']) as $id) {
	wp_delete_post((int) $id, true);
}
