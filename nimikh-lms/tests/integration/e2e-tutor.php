<?php
// Integration test against REAL Tutor LMS (no stub): the adapter's assumptions, the player flow, and the demo seeder
// writing into Tutor's real schema. Run in CI (see .github/workflows/integration.yml) on MySQL with Tutor installed.
$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$wpRoot = getenv('WP_ROOT') ?: (is_file(getcwd() . '/wp-load.php') ? getcwd() : (function () { $d = __DIR__; while ($d !== dirname($d)) { if (is_file($d . '/wp-load.php')) { return $d; } $d = dirname($d); } return getcwd(); })());
require $wpRoot . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$fail = 0; $n = 0;
function ok($c, $l, $x = '') { global $fail, $n; $n++; if ($c) { echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l $x\n"; } }
function call($m, $route, $body = null, $user = 0) {
	wp_set_current_user($user);
	$req = new WP_REST_Request($m, '/nimikh/v1' . $route);
	if ($body !== null) { $req->set_header('content-type', 'application/json'); $req->set_body(wp_json_encode($body)); $req->set_body_params($body); }
	$res = rest_do_request($req);
	return [$res->get_status(), $res->get_data()];
}
add_filter('pre_wp_mail', '__return_true');
global $wpdb; $rid = substr(md5((string) microtime(true)), 0, 6);
$P = \Nimikh\LMS\Plugin::instance();

echo "== real Tutor is present\n";
ok(function_exists('tutor_utils'), 'tutor_utils() exists');
foreach (['courses', 'topics', 'lesson', 'tutor_quiz', 'tutor_enrolled'] as $pt) { ok(post_type_exists($pt), "post type $pt registered by Tutor"); }
ok(is_plugin_active('nimikh-lms/nimikh-lms.php') || class_exists('Nimikh\LMS\Plugin'), 'nimikh-lms is loaded alongside Tutor');
ok((bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'tutor_quiz_attempts'))), 'tutor_quiz_attempts table exists');
$cols = array_map(fn($c) => $c->Field, $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}tutor_quiz_attempts") ?: []);
foreach (['attempt_id', 'course_id', 'quiz_id', 'user_id', 'total_marks', 'earned_marks', 'attempt_status'] as $c) { ok(in_array($c, $cols, true), "attempts column $c"); }
ok((bool) has_action('tutor_quiz/attempt_ended'), 'our listener is attached to tutor_quiz/attempt_ended');

echo "== adapter against real Tutor data\n";
$inst = wp_insert_user(['user_login' => "tinst$rid", 'user_pass' => 'x', 'user_email' => "tinst$rid@example.com", 'role' => get_role('tutor_instructor') ? 'tutor_instructor' : 'nimikh_instructor']);
$learn = wp_insert_user(['user_login' => "tlearn$rid", 'user_pass' => 'x', 'user_email' => "tlearn$rid@example.com", 'role' => 'subscriber']);
$course = wp_insert_post(['post_type' => 'courses', 'post_title' => 'Real Tutor course', 'post_status' => 'publish', 'post_author' => $inst]);
$topic  = wp_insert_post(['post_type' => 'topics', 'post_title' => 'Topic', 'post_status' => 'publish', 'post_parent' => $course, 'menu_order' => 1]);
$lesson = wp_insert_post(['post_type' => 'lesson', 'post_title' => 'Lesson', 'post_status' => 'publish', 'post_parent' => $topic, 'post_author' => $inst]);
update_post_meta($lesson, '_tutor_course_id_for_lesson', $course);
update_post_meta($lesson, 'nimikh_video_url', 'https://cdn.example.com/v.mp4'); update_post_meta($lesson, 'nimikh_video_duration', 100); update_post_meta($lesson, 'nimikh_interactive_enabled', '1');
$quiz = wp_insert_post(['post_type' => 'tutor_quiz', 'post_title' => 'Exam', 'post_status' => 'publish', 'post_parent' => $topic]);
update_post_meta($quiz, '_tutor_course_id_for_quiz', $course);
$T = $P->tutor;
ok($T->courseIdForLesson($lesson) === $course, 'courseIdForLesson');
ok($T->lessonIdsForCourse($course) === [$lesson], 'lessonIdsForCourse');
ok($T->examQuizId($course) === $quiz, 'examQuizId finds the quiz');
ok(!$T->isEnrolled($learn, $course), 'not enrolled before enrolling');
$enrolId = tutor_utils()->do_enroll($course, 0, $learn);
ok((bool) $enrolId, 'tutor_utils()->do_enroll works', var_export($enrolId, true));
ok($T->isEnrolled($learn, $course), 'adapter sees the real enrolment');
ok(in_array($learn, $T->enrolledUserIds($course), true), 'enrolledUserIds sees it (post status "completed")');
add_user_meta($inst, '_tutor_instructor_course_id', $course);
ok($T->isCourseInstructor($inst, $course) && $T->canManageCourse($inst, $course), 'instructor can manage the course');

echo "== player flow with real enrolment\n";
[$s, $d] = call('GET', "/lessons/$lesson/player", null, $learn);
ok($s === 200, 'enrolled learner gets the player payload', "status $s " . json_encode($d));
$wpdb->update($wpdb->prefix . 'nimikh_watch_progress', ['updated_at' => gmdate('Y-m-d H:i:s', time() - 300)], ['user_id' => $learn, 'lesson_id' => $lesson]);
[$s, $d] = call('POST', "/lessons/$lesson/heartbeat", ['second' => 100, 'ranges' => [[0, 100]]], $learn);
ok($s === 200 && !empty($d['completed']), 'lesson completes via heartbeat', json_encode($d));
ok($T->isLessonComplete($learn, $lesson), 'completion marker is read back by the adapter');
if (method_exists(tutor_utils(), 'is_completed_lesson')) {
	ok((bool) tutor_utils()->is_completed_lesson($lesson, $learn), "Tutor itself reports the lesson as completed");
}

echo "== cancelling an enrolment\n";
$T->cancelEnrolment($learn, $course);
ok(!$T->isEnrolled($learn, $course), 'Tutor no longer sees the enrolment after cancelEnrolment');

echo "== demo seeder against Tutor's real schema\n";
define('NIMIKH_ALLOW_DEMO', true);
$res = (new \Nimikh\LMS\Demo\DemoSeeder())->seed();
ok(!is_wp_error($res), 'seed succeeds', is_wp_error($res) ? $res->get_error_message() : '');
if (!is_wp_error($res)) {
	$m = get_option(\Nimikh\LMS\Demo\DemoSeeder::MANIFEST); $in = implode(',', array_map('intval', $m['users']));
	$attempts = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tutor_quiz_attempts WHERE user_id IN ($in)");
	ok($attempts >= 6, 'exam attempts were written into the real Tutor table (schema compatible)', "rows: $attempts");
	ok($res['summary']['certificates'] >= 5, 'certificates issued from real Tutor exam data', json_encode($res['summary']));
	$viaRules = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}nimikh_certificates WHERE user_id IN ($in) AND score IS NOT NULL");
	ok($viaRules >= 5, 'certificates carry exam scores');
	$rm = (new \Nimikh\LMS\Demo\DemoSeeder())->remove();
	ok(!is_wp_error($rm), 'remove succeeds', is_wp_error($rm) ? $rm->get_error_message() : '');
	ok((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tutor_quiz_attempts WHERE user_id IN ($in)") === 0, "Tutor attempt rows for demo users are gone");
	ok(!get_user_by('login', 'demo-learner1'), 'demo users are gone');
}

echo "\n$n checks, $fail failed\n";
exit($fail ? 1 : 0);
