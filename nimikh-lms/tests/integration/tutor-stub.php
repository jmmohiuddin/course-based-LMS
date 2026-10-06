<?php
add_action('init', function () {
	foreach (['courses','lesson','tutor_quiz','tutor_enrolled'] as $pt) register_post_type($pt, ['public'=>true]);
}, 1);
add_filter('nimikh_lms_is_enrolled', function ($e, $u, $c) { return (bool) get_user_meta($u, 'stub_enrolled_' . $c, true); }, 10, 3);

// TEST HARNESS ONLY: the old SQLite driver cannot parse "ON DUPLICATE KEY UPDATE" when a literal contains a comma
// (the JSON ranges). Translate that one statement shape into INSERT OR IGNORE + UPDATE.
add_filter('query', function ($sql) {
	static $busy = false;
	if ($busy || stripos($sql, 'ON DUPLICATE KEY UPDATE') === false || strpos($sql, '_nimikh_watch_progress') === false) { return $sql; }
	[$insert, $update] = preg_split('/ON DUPLICATE KEY UPDATE/i', $sql, 2);
	if (!preg_match('/VALUES\s*\(\s*(\d+)\s*,\s*(\d+)/', $insert, $m)) { return $sql; }
	global $wpdb;
	$busy = true;
	$wpdb->query(preg_replace('/^\s*INSERT INTO/i', 'INSERT OR IGNORE INTO', $insert));
	$busy = false;
	return "UPDATE {$wpdb->prefix}nimikh_watch_progress SET " . trim($update) . " WHERE user_id = {$m[1]} AND lesson_id = {$m[2]}";
});
