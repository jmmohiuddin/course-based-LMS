<?php
// Integration test: certificate checklist, next-lesson link, login rate limit. See README.md here.
$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
// Locate WordPress: $WP_ROOT, else the current directory, else any parent of this file.
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
function user($login, $role = 'subscriber') { global $rid; $id = wp_insert_user(['user_login' => $login . $rid, 'user_pass' => 'x', 'user_email' => $login . $rid . '@example.com', 'display_name' => ucfirst($login), 'role' => $role]); return is_wp_error($id) ? 0 : $id; }
function cnt($table, $where = '1=1') { global $wpdb; return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}nimikh_$table WHERE $where"); }

delete_option(\Nimikh\LMS\Support\Settings::OPTION);
activate_plugin('nimikh-lms/nimikh-lms.php');
\Nimikh\LMS\Plugin::instance()->boot();
\Nimikh\LMS\Activator::activate();
do_action('init'); do_action('rest_api_init');
global $wpdb; $rid = substr(md5((string) microtime(true)), 0, 6);
add_filter('pre_wp_mail', '__return_true');
$P = \Nimikh\LMS\Plugin::instance();

$inst = user('inst', 'nimikh_instructor'); $rafi = user('rafi'); $stranger = user('stranger');
$course = wp_insert_post(['post_type' => 'courses', 'post_title' => 'Excel Basics', 'post_status' => 'publish', 'post_author' => $inst]);
$l1 = wp_insert_post(['post_type' => 'lesson', 'post_title' => 'L1', 'post_status' => 'publish', 'post_author' => $inst, 'menu_order' => 1]);
$l2 = wp_insert_post(['post_type' => 'lesson', 'post_title' => 'L2', 'post_status' => 'publish', 'post_author' => $inst, 'menu_order' => 2]);
foreach ([$l1, $l2] as $l) { update_post_meta($l, '_tutor_course_id_for_lesson', $course); update_post_meta($l, 'nimikh_video_duration', 300); }
tutor_utils()->do_enroll($course, 0, $rafi);

echo "== checklist\n";
[$s, $d] = call('GET', "/me/courses/$course/checklist", null, $rafi);
ok($s === 200 && $d['total'] >= 1 && $d['ready'] === false && $d['items'][0]['key'] === 'lessons' && $d['items'][0]['done'] === false, 'enrolled learner sees what is left', json_encode($d));
[$s] = call('GET', "/me/courses/$course/checklist", null, $stranger);
ok($s === 403, 'non-enrolled user cannot read a course checklist');
[$s] = call('GET', "/me/courses/$course/checklist", null, 0);
ok($s === 401 || $s === 403, 'anonymous user is rejected', "status $s");
wp_set_current_user($rafi);
$html = do_shortcode("[nimikh_checklist course=\"$course\"]");
ok(str_contains($html, 'nk-checklist') && str_contains($html, 'data-done="0"'), 'shortcode renders the checklist');
wp_set_current_user($stranger);
ok(do_shortcode("[nimikh_checklist course=\"$course\"]") === '', 'shortcode renders nothing for non-enrolled users');

echo "== next lesson\n";
[$s, $d] = call('GET', "/lessons/$l1/player", null, $rafi);
ok($s === 200 && $d['next_url'] === get_permalink($l2), 'player payload links to the next lesson', json_encode($d['next_url'] ?? null));
[$s, $d] = call('GET', "/lessons/$l2/player", null, $rafi);
ok($s === 200 && $d['next_url'] === '', 'last lesson has no next link');

echo "== login rate limit\n";
$lim = new \Nimikh\LMS\Support\LoginLimiter();
$r = null; for ($i = 0; $i < 6; $i++) { $r = $lim->throttle(null, 'someone', 'wrong'); }
ok($r instanceof WP_Error && $r->get_error_code() === 'nimikh_login_limited', 'sixth attempt in a minute is refused');
ok($lim->throttle(null, '', '') === null, 'showing the login form is not counted');

echo "== exam cooldown and rewatch\n";
$quiz = wp_insert_post(['post_type' => 'tutor_quiz', 'post_title' => 'Final exam', 'post_status' => 'publish', 'post_author' => $inst]);
update_post_meta($quiz, '_tutor_course_id_for_quiz', $course);
$wpdb->insert($wpdb->prefix . 'tutor_quiz_attempts', ['quiz_id' => $quiz, 'course_id' => $course, 'user_id' => $rafi, 'total_marks' => 10, 'earned_marks' => 3, 'attempt_status' => 'attempt_ended']);
$attemptId = (int) $wpdb->insert_id;
do_action('tutor_quiz/attempt_ended', $attemptId);
[$s, $d] = call('GET', "/me/courses/$course/exam", null, $rafi);
ok($s === 200 && $d['has_exam'] && !$d['passed'] && $d['best_percent'] === 30.0 && $d['cooldown_remaining'] > 23 * 3600, 'failed exam starts a 24 h cooldown', json_encode($d));
$_POST['quiz_id'] = $quiz; wp_set_current_user($rafi);
$msg = $P->exams->blockMessage($rafi, $quiz);
ok($msg !== null && str_contains($msg, 'retake'), 'starting the exam inside the cooldown is blocked', json_encode($msg));
update_post_meta($course, 'nimikh_exam_cooldown_hours', 0);
ok($P->exams->blockMessage($rafi, $quiz) === null, 'cooldown 0 turns the rule off');
update_post_meta($course, 'nimikh_exam_cooldown_hours', 24);
update_user_meta($rafi, 'nimikh_exam_last_attempt_' . $quiz, time() - 25 * 3600);
ok($P->exams->blockMessage($rafi, $quiz) === null, 'after the cooldown the learner can retake');
ok($P->exams->blockMessage($rafi, $l1) === null, 'only the final exam is guarded');
wp_set_current_user($rafi);
ok(str_contains(do_shortcode("[nimikh_checklist course=\"$course\"]"), 'nk-checklist__exam'), 'checklist shows the failed-exam block');

echo "== phone OTP\n";
update_option(\Nimikh\LMS\Support\Settings::OPTION, ['otp_login_enabled' => 1, 'sms_gateway_url' => 'https://sms.example.test/send?to={to}&m={message}'] + (array) get_option(\Nimikh\LMS\Support\Settings::OPTION, []));
$sentTo = null; $sentMsg = null;
add_filter('nimikh_lms_sms_deliver', function ($pre, $phone, $message) use (&$sentTo, &$sentMsg) { $sentTo = $phone; $sentMsg = $message; return ['sent', 'HTTP 200']; }, 10, 3);
$ph = '01712' . random_int(100000, 999999);
[$s, $d] = call('POST', '/auth/otp/request', ['phone' => 'abc'], 0);
ok($s === 400, 'invalid phone rejected');
[$s, $d] = call('POST', '/auth/otp/request', ['phone' => $ph], 0);
ok($s === 200 && $sentTo === '880' . substr($ph, 1) && preg_match('/\b(\d{6})\b/', (string) $sentMsg, $m), 'code is sent by SMS');
$code = $m[1] ?? '000000';
[$s] = call('POST', '/auth/otp/verify', ['phone' => $ph, 'code' => $code === '000001' ? '000002' : '000001'], 0);
ok($s === 400, 'wrong code rejected');
[$s, $d] = call('POST', '/auth/otp/verify', ['phone' => $ph, 'code' => $code], 0);
ok($s === 200 && !empty($d['ok']), 'right code signs in (new learner created)', json_encode($d));
$u = get_users(['meta_key' => 'nimikh_phone', 'meta_value' => '880' . substr($ph, 1)]);
ok(count($u) === 1 && in_array('subscriber', $u[0]->roles, true), 'new account is a plain learner with the phone saved');
[$s] = call('POST', '/auth/otp/verify', ['phone' => $ph, 'code' => $code], 0);
ok($s === 400, 'a code works once only');
// brute force: 5 wrong guesses burn the code
call('POST', '/auth/otp/request', ['phone' => $ph], 0);
for ($i = 0; $i < 5; $i++) { call('POST', '/auth/otp/verify', ['phone' => $ph, 'code' => '99999' . $i], 0); }
preg_match('/\b(\d{6})\b/', (string) $sentMsg, $m2);
[$s] = call('POST', '/auth/otp/verify', ['phone' => $ph, 'code' => $m2[1] ?? '0'], 0);
ok($s === 400, 'five wrong guesses burn the code even if the right one follows');
// staff are refused
$adm = user('adm', 'administrator'); update_user_meta($adm, 'nimikh_phone', '8801811223344');
delete_transient('nimikh_rl_' . md5('otp_phone|8801811223344'));
call('POST', '/auth/otp/request', ['phone' => '01811223344'], 0);
preg_match('/\b(\d{6})\b/', (string) $sentMsg, $m3);
[$s, $d] = call('POST', '/auth/otp/verify', ['phone' => '01811223344', 'code' => $m3[1] ?? '0'], 0);
ok($s === 403, 'an administrator cannot sign in with a phone code', "status $s");
// per-phone rate limit: 3 per 15 minutes
$ph2 = '01911' . random_int(100000, 999999); $last = 0;
for ($i = 0; $i < 4; $i++) { [$last] = call('POST', '/auth/otp/request', ['phone' => $ph2], 0); }
ok($last === 429, 'fourth code request in 15 minutes is refused');

echo "== Google sign-in\n";
update_option(\Nimikh\LMS\Support\Settings::OPTION, ['google_client_id' => 'client-123.apps.googleusercontent.com'] + (array) get_option(\Nimikh\LMS\Support\Settings::OPTION, []));
$gem = 'g' . $rid . '@example.com';
add_filter('nimikh_lms_google_tokeninfo', fn($pre, $tok) => $tok === 'good' ? ['aud' => 'client-123.apps.googleusercontent.com', 'iss' => 'https://accounts.google.com', 'exp' => time() + 600, 'email' => $gem, 'email_verified' => 'true', 'sub' => 'sub-' . $rid, 'name' => 'Gina'] : ($tok === 'wrongaud' ? ['aud' => 'x', 'iss' => 'https://accounts.google.com', 'exp' => time() + 600, 'email' => $gem, 'email_verified' => 'true', 'sub' => '1'] : null), 10, 2);
[$s] = call('POST', '/auth/google', ['credential' => 'bad'], 0);
ok($s === 401, 'unknown token rejected');
[$s] = call('POST', '/auth/google', ['credential' => 'wrongaud'], 0);
ok($s === 401, 'token issued for another app rejected');
[$s, $d] = call('POST', '/auth/google', ['credential' => 'good'], 0);
$gu = get_user_by('email', $gem);
ok($s === 200 && $gu && get_user_meta($gu->ID, 'nimikh_google_sub', true) === 'sub-' . $rid, 'valid token creates and signs in a learner', json_encode($d));
[$s] = call('POST', '/auth/google', ['credential' => 'good'], 0);
ok($s === 200 && count(get_users(['search' => $gem, 'search_columns' => ['user_email']])) === 1, 'second sign-in reuses the account');
wp_update_user(['ID' => $gu->ID, 'role' => 'administrator']);
[$s] = call('POST', '/auth/google', ['credential' => 'good'], 0);
ok($s === 403, 'an administrator cannot sign in with Google');
$html = do_shortcode('[nimikh_login]');
wp_set_current_user(0);
ok(str_contains(do_shortcode('[nimikh_login]'), 'nk-auth'), 'login shortcode renders for visitors');

echo "== visual certificate layout\n";
$tpl = wp_insert_post(['post_type' => 'nimikh_cert_template', 'post_title' => 'Visual', 'post_status' => 'publish', 'post_content' => '<p>HAND-WRITTEN {{name}}</p>']);
$renderer = new \Nimikh\LMS\Certificates\CertificateRenderer();
ok(str_contains($renderer->html($tpl, ['name' => 'Rafi <b>', 'brand_color' => '#1E4FD8'], ''), 'HAND-WRITTEN Rafi &lt;b&gt;'), 'without a layout the HTML body is used (values escaped)');
update_post_meta($tpl, 'nimikh_layout', wp_slash(wp_json_encode(\Nimikh\LMS\Certificates\Layout::defaultLayout())));
$out = $renderer->html($tpl, ['name' => 'Rafi <b>', 'course' => 'Excel', 'brand_color' => '#1E4FD8', 'code' => 'ABC', 'url' => 'https://x/verify/ABC', 'issuer' => 'Nimikh', 'date' => 'today', 'score' => '90%'], '');
ok(!str_contains($out, 'HAND-WRITTEN') && str_contains($out, 'Rafi &lt;b&gt;') && str_contains($out, 'class="page"') && str_contains($out, '#1E4FD8') && !str_contains($out, '{{'), 'a saved layout replaces the HTML; every placeholder is filled and escaped');
wp_set_current_user($adm ?? 0);

echo "\n$n checks, $fail failed\n";
exit($fail ? 1 : 0);
