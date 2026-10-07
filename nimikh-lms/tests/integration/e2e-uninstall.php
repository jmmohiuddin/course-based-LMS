<?php
// Uninstall test: needs a database that really drops tables (MySQL/MariaDB). DESTRUCTIVE: run it last, on a throwaway site.
$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$wpRoot = getenv('WP_ROOT') ?: (is_file(getcwd() . '/wp-load.php') ? getcwd() : getcwd());
require $wpRoot . '/wp-load.php';
if (defined('FQDB')) { echo "SKIPPED: the SQLite test driver cannot DROP TABLE\n0 checks, 0 failed\n"; exit(0); }
$fail = 0; $n = 0;
function ok($c, $l, $x = '') { global $fail, $n; $n++; if ($c) { echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l $x\n"; } }
global $wpdb;
$exists = fn($t) => (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'nimikh_' . $t)));
$all = ['questions','video_interactions','watch_progress','interaction_attempts','certificates','user_badges','activity_days','discussions','plans','subscriptions','orgs','org_members','org_courses','live_sessions','live_attendance','sms_log','notes'];
\Nimikh\LMS\Plugin::instance()->boot(); \Nimikh\LMS\Activator::activate();
$cert = (new \Nimikh\LMS\Certificates\CertificateRepository())->insert(1, 1, 0, 90.0);
$file = \Nimikh\LMS\Certificates\CertificateStorage::put($cert['code'], 'html', '<p>x</p>');
$path = \Nimikh\LMS\Certificates\CertificateStorage::baseDir() . '/' . $file;
define('WP_UNINSTALL_PLUGIN', 'nimikh-lms/nimikh-lms.php');
delete_option('nimikh_lms_settings');
include NIMIKH_LMS_DIR . 'uninstall.php';
ok(count(array_filter($all, $exists)) === count($all) && is_file($path) && get_role('nimikh_instructor'), 'default: nothing is deleted');
update_option('nimikh_lms_settings', ['remove_data_on_uninstall' => 1]);
include NIMIKH_LMS_DIR . 'uninstall.php';
ok(count(array_filter($all, $exists)) === 0, 'opt-in: all 17 tables dropped', 'left: ' . implode(',', array_filter($all, $exists)));
ok(!is_file($path), 'opt-in: certificate files removed');
ok(!get_role('nimikh_instructor') && !get_role('nimikh_institute_admin'), 'opt-in: roles removed');
ok(get_option('nimikh_lms_settings') === false && get_option('nimikh_lms_db_version') === false, 'opt-in: options removed');
echo "\n$n checks, $fail failed\n";
exit($fail ? 1 : 0);
