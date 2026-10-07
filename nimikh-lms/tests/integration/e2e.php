<?php
// Integration test: boots a real WordPress, drives the nimikh/v1 REST routes end to end. See README.md in this folder.
$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
// Locate WordPress: $WP_ROOT, else the current directory, else any parent of this file.
$wpRoot = getenv('WP_ROOT') ?: (is_file(getcwd() . '/wp-load.php') ? getcwd() : (function () { $d = __DIR__; while ($d !== dirname($d)) { if (is_file($d . '/wp-load.php')) { return $d; } $d = dirname($d); } return getcwd(); })());
require $wpRoot . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$fail = 0; $n = 0;
function ok($cond, $label, $extra = '') { global $fail, $n; $n++; if ($cond) { echo "  ok   $label\n"; } else { $fail++; echo "  FAIL $label $extra\n"; } }
function call($method, $route, $body = null, $user = 0) {
	wp_set_current_user($user);
	$req = new WP_REST_Request($method, '/nimikh/v1' . $route);
	if ($body !== null) { $req->set_header('content-type', 'application/json'); $req->set_body(wp_json_encode($body)); $req->set_body_params($body); }
	$res = rest_do_request($req);
	return [$res->get_status(), $res->get_data()];
}
function backdate($user, $lesson, $secs) { global $wpdb; $wpdb->update($wpdb->prefix . 'nimikh_watch_progress', ['updated_at' => gmdate('Y-m-d H:i:s', time() - $secs)], ['user_id' => $user, 'lesson_id' => $lesson]); }

echo "== activate & schema\n";
activate_plugin('nimikh-lms/nimikh-lms.php');
ok(is_plugin_active('nimikh-lms/nimikh-lms.php'), 'plugin active');
\Nimikh\LMS\Plugin::instance()->boot();
\Nimikh\LMS\Activator::activate();
global $wpdb;
foreach (['questions','video_interactions','watch_progress','interaction_attempts','certificates'] as $t) {
	$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . "nimikh_$t")));
	ok((bool) $exists, "table nimikh_$t");
}
do_action('init'); do_action('rest_api_init');

foreach (['questions','video_interactions','watch_progress','interaction_attempts','certificates'] as $t) { $wpdb->query("DELETE FROM {$wpdb->prefix}nimikh_$t"); }
$wpdb->query("DELETE FROM {$wpdb->prefix}tutor_quiz_attempts");
echo "== fixtures\n"; $rid = substr(md5((string) microtime(true)), 0, 6);
$inst   = wp_insert_user(['user_login' => 'inst' . $rid, 'user_pass' => 'x', 'user_email' => 'inst' . $rid . '@example.com', 'role' => 'nimikh_instructor']);
$other  = wp_insert_user(['user_login' => 'other' . $rid, 'user_pass' => 'x', 'user_email' => 'other' . $rid . '@example.com', 'role' => 'nimikh_instructor']);
$learn  = wp_insert_user(['user_login' => 'rafi' . $rid, 'user_pass' => 'x', 'user_email' => 'rafi' . $rid . '@example.com', 'display_name' => 'Rafi Ahmed', 'role' => 'subscriber']);
$course = wp_insert_post(['post_type' => 'courses', 'post_title' => 'Intro to Excel', 'post_status' => 'publish', 'post_author' => $inst]);
$lesson = wp_insert_post(['post_type' => 'lesson', 'post_title' => 'Lesson 1', 'post_status' => 'publish', 'post_author' => $inst]);
update_post_meta($lesson, '_tutor_course_id_for_lesson', $course);
update_post_meta($lesson, 'nimikh_video_url', 'https://cdn.example.com/v1/playlist.m3u8');
update_post_meta($lesson, 'nimikh_interactive_enabled', '1');
$quiz = wp_insert_post(['post_type' => 'tutor_quiz', 'post_title' => 'Final exam', 'post_status' => 'publish']);
update_post_meta($quiz, '_tutor_course_id_for_quiz', $course);
update_post_meta($course, 'nimikh_exam_pass_percent', 70);
ok($lesson > 0 && $course > 0, 'created course + lesson');

echo "== authoring permissions\n";
[$s] = call('POST', '/questions', ['stem' => 'x', 'options' => ['a', 'b'], 'correct_index' => 0], $learn);
ok($s === 403, 'learner cannot create questions', "status $s");
[$s] = call('POST', "/lessons/$lesson/interactions", ['at_second' => 10, 'question' => ['stem' => 'x', 'options' => ['a', 'b'], 'correct_index' => 0]], $other);
ok($s === 403, 'instructor of another course cannot edit this lesson', "status $s");

echo "== authoring\n";
[$s, $d] = call('POST', "/lessons/$lesson/duration", ['duration' => 100], $inst);
ok($s === 200, 'set duration', "status $s");
[$s, $d] = call('POST', "/lessons/$lesson/interactions", ['at_second' => 30, 'required' => true, 'allow_retry' => true, 'points' => 2, 'rewind_to_second' => 10,
	'question' => ['stem' => 'What does SUM do?', 'options' => ['Adds', 'Multiplies', 'Counts'], 'correct_index' => 0, 'explanation' => 'SUM adds numbers.']], $inst);
ok($s === 201 && $d['correct_index'] === 0, 'create required interaction @30', "status $s " . json_encode($d));
$i1 = $d['id'] ?? 0;
[$s, $d] = call('POST', "/lessons/$lesson/interactions", ['at_second' => 70, 'required' => false, 'question' => ['stem' => 'Optional?', 'options' => ['Yes', 'No'], 'correct_index' => 1]], $inst);
ok($s === 201, 'create optional interaction @70', "status $s");
[$s] = call('POST', "/lessons/$lesson/interactions", ['at_second' => 50, 'rewind_to_second' => 60, 'question' => ['stem' => 'x', 'options' => ['a', 'b'], 'correct_index' => 0]], $inst);
ok($s === 400, 'rewind point after question rejected', "status $s");
[$s] = call('POST', "/lessons/$lesson/interactions", ['at_second' => 500, 'question' => ['stem' => 'x', 'options' => ['a', 'b'], 'correct_index' => 0]], $inst);
ok($s === 400, 'question after video end rejected', "status $s");
[$s] = call('POST', '/questions', ['stem' => 'x', 'options' => ['a'], 'correct_index' => 0], $inst);
ok($s === 400, 'single-option question rejected', "status $s");

echo "== learner access\n";
[$s] = call('GET', "/lessons/$lesson/player", null, $learn);
ok($s === 403, 'not enrolled -> 403', "status $s");
[$s] = call('GET', "/lessons/$lesson/player", null, 0);
ok($s === 401, 'logged out -> 401', "status $s");
update_user_meta($learn, 'stub_enrolled_' . $course, 1);
[$s, $d] = call('GET', "/lessons/$lesson/player", null, $learn);
ok($s === 200 && count($d['interactions']) === 2, 'enrolled learner gets player payload', "status $s");
$json = wp_json_encode($d);
ok(!str_contains($json, 'correct_index') && !str_contains($json, 'SUM adds numbers'), 'payload leaks no answers/explanations');
ok($d['gate'] === 30 && $d['duration'] === 100, 'gate = first required question (30)');

echo "== anti-skip heartbeat\n";
[$s, $d] = call('POST', "/lessons/$lesson/heartbeat", ['second' => 90, 'ranges' => [[0, 90]]], $learn);
ok($d['rejected'] === true && $d['furthest'] === 0, 'seek-jump (0-90 with 0 s elapsed) rejected', json_encode($d));
backdate($learn, $lesson, 60);
[$s, $d] = call('POST', "/lessons/$lesson/heartbeat", ['second' => 45, 'ranges' => [[0, 45]]], $learn);
ok($d['ok'] === true && $d['furthest'] === 30 && $d['percent'] == 30.0 && $d['gate'] === 30, 'progress clipped at unanswered required question', json_encode($d));
[$s, $d] = call('POST', "/lessons/$lesson/heartbeat", ['second' => 'abc', 'ranges' => 'garbage'], $learn);
ok($s === 200 && $d['furthest'] === 30, 'garbage payload tolerated');

echo "== MCQ attempts\n";
[$s, $d] = call('POST', "/interactions/$i1/attempt", ['selected_index' => 9], $learn);
ok($s === 400, 'invalid option rejected', "status $s");
[$s, $d] = call('POST', "/interactions/$i1/attempt", ['selected_index' => 1], $learn);
ok($d['correct'] === false && $d['resolved'] === false && ($d['rewind_to'] ?? null) === 10 && !isset($d['correct_index']), 'wrong #1: retry + rewind, answer hidden', json_encode($d));
[$s, $d] = call('POST', "/interactions/$i1/attempt", ['selected_index' => 2], $learn);
ok($d['correct'] === false && $d['resolved'] === true && ($d['correct_index'] ?? -1) === 0, 'wrong #2: resolved, answer revealed', json_encode($d));
[$s, $d] = call('POST', "/interactions/$i1/attempt", ['selected_index' => 0], $learn);
ok($d['resolved'] === true, 'further attempts do not rescore');
$cnt = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}nimikh_interaction_attempts WHERE user_id = $learn");
ok($cnt === 2, 'only 2 attempts recorded', "count $cnt");
[$s, $d] = call('GET', "/lessons/$lesson/player", null, $learn);
ok($d['gate'] === null, 'gate cleared after question resolved');

echo "== completion\n";
backdate($learn, $lesson, 120);
[$s, $d] = call('POST', "/lessons/$lesson/heartbeat", ['second' => 100, 'ranges' => [[30, 100]]], $learn);
ok($d['ok'] === true && $d['completed'] === true && $d['percent'] == 100.0, 'lesson completes at >=90% with required MCQs resolved', json_encode($d));
ok((bool) get_user_meta($learn, '_tutor_completed_lesson_id_' . $lesson, true), 'Tutor lesson completion meta written');
$certs = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}nimikh_certificates");
ok((int) $certs === 0, 'no certificate yet: exam not taken');

echo "== exam -> certificate\n";
$wpdb->insert($wpdb->prefix . 'tutor_quiz_attempts', ['course_id' => $course, 'quiz_id' => $quiz, 'user_id' => $learn, 'total_marks' => 10, 'earned_marks' => 5, 'attempt_status' => 'attempt_ended']);
do_action('tutor_quiz/attempt_ended', (int) $wpdb->insert_id);
ok((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}nimikh_certificates") === 0, 'failing exam (50% < 70%) issues nothing');
$wpdb->insert($wpdb->prefix . 'tutor_quiz_attempts', ['course_id' => $course, 'quiz_id' => $quiz, 'user_id' => $learn, 'total_marks' => 10, 'earned_marks' => 8, 'attempt_status' => 'attempt_ended']);
$aid = (int) $wpdb->insert_id;
do_action('tutor_quiz/attempt_ended', $aid);
do_action('tutor_quiz/attempt_ended', $aid); // idempotent
$rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}nimikh_certificates", ARRAY_A);
ok(count($rows) === 1, 'exactly one certificate issued (idempotent)', 'rows ' . count($rows));
$cert = $rows[0] ?? [];
ok(preg_match('/^[A-HJKMNP-Z2-9]{10}$/', $cert['code'] ?? '') === 1, 'random 10-char code', $cert['code'] ?? '');
ok((float) $cert['score'] === 80.0, 'headline score = exam %', (string) ($cert['score'] ?? ''));

do_action(\Nimikh\LMS\Certificates\CertificateService::RENDER_HOOK, (int) $cert['id']);
$cert = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}nimikh_certificates WHERE id = {$cert['id']}", ARRAY_A);
ok(!empty($cert['pdf_key']) && strlen((string) $cert['sha256']) === 64, 'rendered file stored with sha256', json_encode($cert));
$file = \Nimikh\LMS\Certificates\CertificateStorage::baseDir() . '/' . $cert['pdf_key'];
$html = (string) file_get_contents($file);
ok(str_contains($html, 'Rafi Ahmed') && str_contains($html, 'Intro to Excel') && str_contains($html, $cert['code']) && !str_contains($html, '{{'), 'certificate content filled, no raw placeholders');
ok(file_exists(dirname($file) . '/.htaccess'), 'storage dir protected');

echo "== verification\n";
[$s, $d] = call('GET', '/verify/' . strtolower(substr($cert['code'], 0, 5)) . '-' . substr($cert['code'], 5), null, 0);
ok($s === 200 && $d['status'] === 'valid' && $d['name'] === 'Rafi Ahmed', 'public verify (login-free, case/dash tolerant)', json_encode($d));
[$s, $d] = call('GET', '/verify/AAAAAAAAAA', null, 0);
ok($s === 404 && $d['status'] === 'not_found', 'unknown code -> not_found');
file_put_contents($file, $html . '<!-- tampered -->');
[$s, $d] = call('GET', '/verify/' . $cert['code'], null, 0);
ok($d['status'] === 'integrity_failed', 'tampered file fails SHA-256 check');
file_put_contents($file, $html);
ob_start(); $result = ['status' => 'valid'] + (new \Nimikh\LMS\Certificates\CertificateService(new \Nimikh\LMS\Certificates\CertificateRepository(), new \Nimikh\LMS\Certificates\CertificateRenderer(), new \Nimikh\LMS\Progress\ProgressRepository(), \Nimikh\LMS\Plugin::instance()->progress, \Nimikh\LMS\Plugin::instance()->tutor))->verify($cert['code']); $invalid = false;
include NIMIKH_LMS_DIR . 'templates/verify.php'; $page = ob_get_clean();
ok(str_contains($page, 'Rafi Ahmed') && str_contains($page, 'Valid'), 'verify page template renders');

[$s] = call('POST', "/certificates/{$cert['id']}/revoke", ['reason' => 'Test'], $learn);
ok($s === 403, 'learner cannot revoke');
[$s] = call('POST', "/certificates/{$cert['id']}/revoke", ['reason' => 'Issued in error'], 1);
ok($s === 200, 'admin revokes', "status $s");
[$s, $d] = call('GET', '/verify/' . $cert['code'], null, 0);
ok($d['status'] === 'revoked' && $d['reason'] === 'Issued in error', 'revoked status + reason on verify');
[$s, $d] = call('GET', '/me/certificates', null, $learn);
ok($s === 200 && count($d) === 1 && $d[0]['status'] === 'revoked', '/me/certificates lists own certs');

echo "== reports\n";
[$s] = call('GET', "/reports/course/$course", null, $learn);
ok($s === 403, 'learner cannot read reports');
$wpdb->insert($wpdb->posts, ['post_type' => 'tutor_enrolled', 'post_parent' => $course, 'post_author' => $learn, 'post_status' => 'completed', 'post_title' => 'enr', 'post_content' => '', 'post_excerpt' => '', 'to_ping' => '', 'pinged' => '', 'post_content_filtered' => '', 'post_date' => current_time('mysql'), 'post_date_gmt' => current_time('mysql', 1), 'post_modified' => current_time('mysql'), 'post_modified_gmt' => current_time('mysql', 1)]);
[$s, $d] = call('GET', "/reports/course/$course", null, $inst);
ok($s === 200 && $d['summary']['enrolled'] === 1 && $d['learners'][0]['exam_percent'] == 80.0, 'instructor report: learners', json_encode($d['summary'] ?? $d));
ok(count($d['lessons'][0]['retention']) === 10 && $d['lessons'][0]['retention'][5]['percent'] == 100.0, 'retention curve built (10 buckets)');
ok($d['lessons'][0]['questions'][0]['first_try_correct'] === 0.0, 'question accuracy: first-try correct 0%');
$csv = (new \Nimikh\LMS\Reports\ReportService(\Nimikh\LMS\Plugin::instance()->tutor, new \Nimikh\LMS\Progress\ProgressRepository(), new \Nimikh\LMS\Video\InteractionRepository(), new \Nimikh\LMS\Certificates\CertificateRepository(), \Nimikh\LMS\Plugin::instance()->certificates))->csv(['learners' => [['name' => '=cmd()', 'email' => 'a@b.c', 'lessons_completed' => 1, 'lessons_total' => 1, 'mcq_percent' => null, 'exam_percent' => 80, 'certificate' => null]]]);
ok(str_contains($csv, "'=cmd()"), 'CSV export neutralises formula injection');

echo "\n$n checks, $fail failed\n";
exit($fail ? 1 : 0);
