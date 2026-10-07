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


// Minimal stand-in for tutor_utils(): enrolment only (is_enrolled / do_enroll), plus the enrolment post Tutor creates.
if (!function_exists('tutor_utils')) {
	function tutor_utils() {
		static $o;
		return $o ??= new class {
			public function is_enrolled($course_id, $user_id) { return (bool) get_user_meta($user_id, 'stub_enrolled_' . $course_id, true); }
			public function do_enroll($course_id, $order_id, $user_id) {
				update_user_meta($user_id, 'stub_enrolled_' . $course_id, 1);
				return wp_insert_post(['post_type' => 'tutor_enrolled', 'post_parent' => $course_id, 'post_author' => $user_id, 'post_status' => 'completed', 'post_title' => 'enrol']);
			}
		};
	}
}
// Tutor flips the enrolment post to "cancel" when an enrolment is cancelled; mirror that on the stub flag.
add_action('transition_post_status', function ($new, $old, $post) {
	if ($post->post_type === 'tutor_enrolled' && $new === 'cancel') { delete_user_meta($post->post_author, 'stub_enrolled_' . $post->post_parent); }
}, 10, 3);
