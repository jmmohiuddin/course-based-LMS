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

echo "== success metrics (PRD 4.1)\n";
ok(cnt('verify_views') >= 0 && cnt('rum') >= 0, 'metrics tables exist');
$mcert = (new \Nimikh\LMS\Certificates\CertificateRepository())->insert($rafi, $course, 0, 88.0);
$M = new \Nimikh\LMS\Metrics\MetricsService($P->tutor);
$M->recordVerifyView($mcert['code']); $M->recordVerifyView($mcert['code']); $M->recordVerifyView('NOSUCHCODE');
ok((int) $wpdb->get_var("SELECT SUM(views) FROM {$wpdb->prefix}nimikh_verify_views WHERE certificate_id = {$mcert['id']}") === 2, 'verification views are counted per certificate per day; unknown codes ignored');
[$s] = call('POST', '/metrics/rum', ['metric' => 'video_start', 'value' => 1800], $rafi);
ok($s === 200, 'a learner can report a video start time');
[$s] = call('POST', '/metrics/rum', ['metric' => 'evil', 'value' => 5], $rafi);
ok($s === 400, 'unknown metric rejected');
[$s] = call('POST', '/metrics/rum', ['metric' => 'lcp', 'value' => 999999], $rafi);
ok($s === 400, 'absurd value rejected');
[$s] = call('POST', '/metrics/rum', ['metric' => 'lcp', 'value' => 1000], 0);
ok($s === 401 || $s === 403, 'anonymous visitors cannot post timings');
[$s] = call('GET', '/metrics/kpis', null, $rafi);
ok($s === 403, 'learners cannot read the KPI dashboard');
$adminId = user('kpiadmin', 'administrator'); get_userdata($adminId)->add_cap('nimikh_manage');
[$s, $d] = call('GET', '/metrics/kpis', null, $adminId);
$by = $d ? array_column($d, null, 'key') : [];
ok($s === 200 && count($d) === 7 && isset($by['completion'], $by['mcq_answer'], $by['first_pass'], $by['verify_views'], $by['paid'], $by['video_start'], $by['lcp']), 'admin gets all seven PRD metrics', json_encode(array_keys($by)));
ok($by['video_start']['value'] === 1800.0 && $by['video_start']['status'] === 'met', 'video start time p75 is computed from reported timings', json_encode($by['video_start'] ?? null));
ok($by['paid']['status'] === 'no_data', 'paid enrolments report "no data" until a gateway filter supplies them');
ok($by['verify_views']['value'] !== null, 'verification views per certificate is computed');

echo "== instructor guidance\n";
[$s, $d] = call('POST', "/lessons/$l1/interactions", ['at_second' => 12, 'question' => ['stem' => 'Early?', 'options' => ['a', 'b'], 'correct_index' => 0]], $inst);
[$s, $d] = call('GET', "/lessons/$l1/interactions", null, $inst);
ok($s === 200 && !empty($d['guidance']) && $d['guidance'][0]['code'] === 'too_early', 'a question in the first 30 s produces advice', json_encode($d['guidance'] ?? null));

echo "== leaderboard (opt-in)\n";
$sam = user('sam'); tutor_utils()->do_enroll($course, 0, $sam);
$wpdb->replace($wpdb->prefix . 'nimikh_watch_progress', ['user_id' => $rafi, 'lesson_id' => $l1, 'furthest_second' => 300, 'last_second' => 300, 'watched_ranges' => '[[0,300]]', 'percent' => 100, 'completed_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
$board = new \Nimikh\LMS\Leaderboard\LeaderboardService($P->tutor);
$d = $board->forCourse($course, $rafi);
ok($d['top'] === [] && $d['me'] !== null && $d['me']['optin'] === false && $d['me']['score'] >= 10, 'nobody is listed until they opt in; the learner still sees their own private rank', json_encode($d));
\Nimikh\LMS\Leaderboard\LeaderboardService::setOptIn($rafi, true);
$d = $board->forCourse($course, $sam);
ok(count($d['top']) === 1 && $d['top'][0]['name'] === 'Rafi' && $d['top'][0]['rank'] === 1 && $d['me']['optin'] === false, 'an opted-in learner appears to others by first name only', json_encode($d));
wp_set_current_user($rafi);
$html = do_shortcode("[nimikh_leaderboard course=\"$course\"]");
ok(str_contains($html, 'nk-board__list') && str_contains($html, 'aria-current'), 'shortcode shows the list and marks the viewer');
wp_set_current_user($stranger);
ok(do_shortcode("[nimikh_leaderboard course=\"$course\"]") === '', 'non-enrolled users see nothing');
\Nimikh\LMS\Leaderboard\LeaderboardService::setOptIn($rafi, false);
ok($board->forCourse($course, $sam)['top'] === [], 'opting out removes the learner again');

echo "== SCORM\n";
$manifest = '<?xml version="1.0"?><manifest identifier="m" xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2" xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_rootv1p2"><metadata><schemaversion>1.2</schemaversion></metadata><organizations default="o"><organization identifier="o"><title>Safety</title><item identifier="i" identifierref="r"><title>t</title><adlcp:masteryscore>80</adlcp:masteryscore></item></organization></organizations><resources><resource identifier="r" type="webcontent" href="index.html"/></resources></manifest>';
$zipPath = sys_get_temp_dir() . '/nk-e2e-' . $rid . '.zip';
$z = new ZipArchive(); $z->open($zipPath, ZipArchive::CREATE); $z->addFromString('imsmanifest.xml', $manifest); $z->addFromString('index.html', '<html><head></head><body>SCO</body></html>'); $z->close();
$sco = wp_insert_post(['post_type' => 'lesson', 'post_title' => 'SCORM lesson', 'post_status' => 'publish', 'post_author' => $inst, 'menu_order' => 3]);
update_post_meta($sco, '_tutor_course_id_for_lesson', $course);
// upload through the REST route (instructor), as the metabox does
add_filter('nimikh_lms_allow_nonhttp_upload', '__return_true');
wp_set_current_user($inst);
$req = new WP_REST_Request('POST', "/nimikh/v1/lessons/$sco/scorm");
$req->set_file_params(['package' => ['name' => 'p.zip', 'tmp_name' => $zipPath, 'error' => UPLOAD_ERR_OK, 'size' => filesize($zipPath), 'type' => 'application/zip']]);
$res = rest_do_request($req);
ok($res->get_status() === 201 && $res->get_data()['version'] === '1.2' && $res->get_data()['title'] === 'Safety', 'instructor uploads a SCORM package through REST', json_encode($res->get_data()));
$pkg = \Nimikh\LMS\Scorm\PackageStore::forLesson($sco);
ok($pkg && is_file(\Nimikh\LMS\Scorm\PackageStore::dir($pkg['id']) . '/index.html'), 'files were extracted under uploads/nimikh-scorm');
$req = new WP_REST_Request('POST', "/nimikh/v1/lessons/$sco/scorm");
$req->set_file_params(['package' => ['name' => 'p.zip', 'tmp_name' => $zipPath, 'error' => UPLOAD_ERR_OK, 'size' => 1, 'type' => 'application/zip']]);
wp_set_current_user($rafi);
ok(rest_do_request($req)->get_status() === 403, 'a learner cannot upload a package');

[$s, $d] = call('POST', "/lessons/$sco/scorm/launch", [], $stranger);
ok($s === 403, 'a learner who is not enrolled cannot launch');
[$s, $d] = call('POST', "/lessons/$sco/scorm/launch", [], $rafi);
ok($s === 200 && str_contains($d['url'], '/nimikh-scorm/' . $sco . '.' . $rafi . '.') && str_ends_with($d['url'], '/index.html') && $d['completed'] === false, 'an enrolled learner gets a signed content URL', json_encode($d));
$tok = explode('/', parse_url($d['url'], PHP_URL_PATH))[2];
ok(\Nimikh\LMS\Scorm\Token::verify($tok, $pkg['id'], time(), \Nimikh\LMS\Scorm\ScormService::secret()) !== null, 'the token verifies for this package');
ok(\Nimikh\LMS\Scorm\Token::verify($tok, 'ffffffffffffffff', time(), \Nimikh\LMS\Scorm\ScormService::secret()) === null, 'and not for another package');

[$s, $d] = call('POST', "/lessons/$sco/scorm/commit", ['data' => ['cmi.core.lesson_status' => 'completed', 'cmi.core.score.raw' => '90', 'cmi.core.student_id' => '999', 'evil' => 'x', 'cmi.suspend_data' => 'page=4']], $rafi);
ok($s === 200 && $d['completed'] === false && $d['deferred'] === true, 'completion reported straight after launch is held back (minimum time)', json_encode($d));
$trk = (new \Nimikh\LMS\Scorm\TrackingRepository())->find($rafi, $sco);
ok($trk && !isset($trk['data']['evil']) && !isset($trk['data']['cmi.core.student_id']) && $trk['data']['cmi.suspend_data'] === 'page=4', 'only whitelisted data is stored');
ok(!$P->tutor->isLessonComplete($rafi, $sco), 'the lesson is not complete yet');
$wpdb->query("UPDATE {$wpdb->prefix}nimikh_scorm_tracking SET started_at = '" . gmdate('Y-m-d H:i:s', time() - 120) . "' WHERE user_id = $rafi AND lesson_id = $sco");
[$s, $d] = call('POST', "/lessons/$sco/scorm/commit", ['data' => []], $rafi);
ok($s === 200 && $d['completed'] === true && $d['score'] === 90.0, 'after the minimum time a re-check completes the lesson', json_encode($d));
ok($P->tutor->isLessonComplete($rafi, $sco), 'Tutor sees the lesson as complete');
$_POST['lesson_id'] = $sco; wp_set_current_user($rafi);
try { (new \Nimikh\LMS\Integrations\Tutor\TutorHooks($P->tutor, $P->certificates, new \Nimikh\LMS\Progress\ProgressRepository()))->guardManualComplete(); ok(true, 'manual "Mark as complete" passes once the package completed it'); } catch (\Throwable $e) { ok(false, 'manual complete guard', $e->getMessage()); }
[$s, $d] = call('POST', "/lessons/$sco/scorm/launch", [], $rafi);
ok($d['completed'] === true, 'relaunching shows the completed state');
$flat = json_encode(\Nimikh\LMS\Scorm\ContentRoute::url('1.2.3.' . str_repeat('a', 64), 'content/a b.html', '?x=1'));
ok(str_contains($flat, 'content\/a%20b.html') && str_ends_with($flat, '?x=1"'), 'content URLs encode each path segment');
$exp = (new \Nimikh\LMS\Privacy\PrivacyService())->export(get_userdata($rafi)->user_email);
ok(count(array_filter($exp['data'], fn($i) => $i['group_id'] === 'nimikh-scorm')) === 1, 'SCORM progress is part of the privacy export');
(new \Nimikh\LMS\Privacy\PrivacyService())->eraseUser($rafi);
ok((new \Nimikh\LMS\Scorm\TrackingRepository())->find($rafi, $sco) === null, 'and is erased with the learner');
\Nimikh\LMS\Scorm\PackageStore::remove($sco);
ok(!is_dir(\Nimikh\LMS\Scorm\PackageStore::dir($pkg['id'])) && \Nimikh\LMS\Scorm\PackageStore::forLesson($sco) === null, 'removing the package deletes its files');
@unlink($zipPath);

echo "== offline downloads\n";
$off = wp_insert_post(['post_type' => 'lesson', 'post_title' => 'Offline lesson', 'post_status' => 'publish', 'post_author' => $inst, 'menu_order' => 4]);
update_post_meta($off, '_tutor_course_id_for_lesson', $course);
update_post_meta($off, 'nimikh_video_url', 'https://cdn.example.test/v/lesson.mp4');
$dev1 = str_repeat('a', 32); $dev2 = str_repeat('b', 32); $dev3 = str_repeat('c', 32);
$offlineUser = user('offl'); tutor_utils()->do_enroll($course, 0, $offlineUser);
[$s] = call('POST', "/lessons/$off/offline", ['device' => $dev1], $offlineUser);
ok($s === 404, 'offline is refused until the instructor enables it for the lesson');
update_post_meta($off, 'nimikh_offline_enabled', '1');
[$s, $d] = call('POST', "/lessons/$off/offline", ['device' => 'not-a-device'], $offlineUser);
ok($s === 400, 'a malformed device id is refused');
[$s, $d] = call('POST', "/lessons/$off/offline", ['device' => $dev1], $stranger);
ok($s === 403, 'a learner who is not enrolled cannot download');
[$s, $d] = call('POST', "/lessons/$off/offline", ['device' => $dev1], $offlineUser);
ok($s === 200 && $d['url'] === 'https://cdn.example.test/v/lesson.mp4' && $d['expires_at'] > time() + 6 * 86400 && $d['expires_at'] < time() + 8 * 86400, 'an enrolled learner gets a 7-day licence and the file URL', json_encode($d));
[$s] = call('POST', "/lessons/$off/offline", ['device' => $dev2], $offlineUser);
ok($s === 200, 'a second device is allowed');
[$s, $d] = call('POST', "/lessons/$off/offline", ['device' => $dev3], $offlineUser);
ok($s === 409 && $d['code'] === 'nimikh_offline_limit', 'a third device is refused (default limit 2)', json_encode($d));
[$s] = call('POST', "/lessons/$off/offline", ['device' => $dev1], $offlineUser);
ok($s === 200, 'the same device can renew');
$req = new WP_REST_Request('GET', '/nimikh/v1/me/offline'); $req->set_param('device', $dev1); wp_set_current_user($offlineUser);
$act = rest_do_request($req)->get_data()['licenses'];
ok(count($act) === 1 && $act[0]['lesson_id'] === $off, 'the device can list the licences it may still use', json_encode($act));
do_action('nimikh_enrolment_cancelled', $offlineUser, $course);
$req = new WP_REST_Request('GET', '/nimikh/v1/me/offline'); $req->set_param('device', $dev1);
ok(rest_do_request($req)->get_data()['licenses'] === [], 'cancelling enrolment revokes every offline copy of that course');
$req = new WP_REST_Request('DELETE', "/nimikh/v1/lessons/$off/offline"); $req->set_param('device', $dev2);
rest_do_request($req);
ok((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}nimikh_offline_licenses WHERE user_id = $offlineUser AND device_id = '$dev2'") === 0, 'a learner can release a licence');
$exp = (new \Nimikh\LMS\Privacy\PrivacyService())->export(get_userdata($offlineUser)->user_email);
ok(count(array_filter($exp['data'], fn($i) => $i['group_id'] === 'nimikh-offline')) >= 1, 'licences are part of the privacy export');
(new \Nimikh\LMS\Privacy\PrivacyService())->eraseUser($offlineUser);
ok((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}nimikh_offline_licenses WHERE user_id = $offlineUser") === 0, 'and erased with the learner');

echo "\n$n checks, $fail failed\n";
exit($fail ? 1 : 0);
