<?php
// Integration test for phase 2-3 modules on a real WordPress. See README.md in this folder.
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
function user($login, $role = 'subscriber', $extra = []) { global $rid; $id = wp_insert_user(['user_login' => $login . $rid, 'user_pass' => 'x', 'user_email' => $login . $rid . '@example.com', 'display_name' => ucfirst($login), 'role' => $role] + $extra); return is_wp_error($id) ? 0 : $id; }
function course($title, $author) { $c = wp_insert_post(['post_type' => 'courses', 'post_title' => $title, 'post_status' => 'publish', 'post_author' => $author]); return $c; }
function enrol_stub($u, $c) { tutor_utils()->do_enroll($c, 0, $u); } // flag + enrolment post, like Tutor

activate_plugin('nimikh-lms/nimikh-lms.php');
\Nimikh\LMS\Plugin::instance()->boot();
\Nimikh\LMS\Activator::activate();
do_action('init'); do_action('rest_api_init');
delete_option(\Nimikh\LMS\Support\Settings::OPTION); // start from defaults every run
global $wpdb; $rid = substr(md5((string) microtime(true)), 0, 6);
foreach (['user_badges','activity_days','discussions','plans','subscriptions','orgs','org_members','org_courses','live_sessions','live_attendance','sms_log','certificates'] as $t) { $wpdb->query("DELETE FROM {$wpdb->prefix}nimikh_$t"); }

echo "== schema (migration 002)\n";
foreach (['user_badges','activity_days','discussions','plans','subscriptions','orgs','org_members','org_courses','live_sessions','live_attendance','sms_log'] as $t) {
	ok((bool) $wpdb->get_var("SELECT name FROM sqlite_master WHERE name = '{$wpdb->prefix}nimikh_$t'"), "table nimikh_$t");
}
ok((int) get_option('nimikh_lms_db_version') >= 2, 'phase 2-3 schema installed');

$admin = 1;
$inst = user('inst', 'nimikh_instructor');
$other = user('other', 'nimikh_instructor');
$rafi = user('rafi', 'subscriber');
$sara = user('sara', 'subscriber');
$cA = course('Excel Basics', $inst);
$cB = course('Advanced Excel', $inst);
$lesson = wp_insert_post(['post_type' => 'lesson', 'post_title' => 'L1', 'post_status' => 'publish', 'post_author' => $inst]);
update_post_meta($lesson, '_tutor_course_id_for_lesson', $cA);
enrol_stub($rafi, $cA); enrol_stub($sara, $cA);
add_filter('pre_wp_mail', function ($pre, $atts) { $GLOBALS['mails'][] = $atts; return true; }, 10, 2);
$GLOBALS['mails'] = [];

echo "== badges & streaks\n";
$svc = \Nimikh\LMS\Plugin::instance();
$wpdb->insert($wpdb->prefix . 'nimikh_activity_days', ['user_id' => $rafi, 'day' => wp_date('Y-m-d', time() - 2 * 86400)]);
$wpdb->insert($wpdb->prefix . 'nimikh_activity_days', ['user_id' => $rafi, 'day' => wp_date('Y-m-d', time() - 86400)]);
update_user_meta($rafi, '_tutor_completed_lesson_id_' . $lesson, time());
do_action('nimikh_lesson_completed', $rafi, $lesson, $cA); // records today -> 3-day streak
[$s, $d] = call('GET', '/me/badges', null, $rafi);
$keys = array_column($d['badges'] ?? [], 'key');
ok($s === 200 && $d['streak'] === 3, '3-day streak counted', json_encode($d));
ok(in_array('first_lesson', $keys, true) && in_array('streak_3', $keys, true) && !in_array('streak_7', $keys, true), 'first_lesson + streak_3 awarded, not streak_7', json_encode($keys));
do_action('nimikh_lesson_completed', $rafi, $lesson, $cA);
ok(count(array_column(call('GET', '/me/badges', null, $rafi)[1]['badges'], 'key')) === count($keys), 'badges are not awarded twice');
$cert = (new \Nimikh\LMS\Certificates\CertificateRepository())->insert($sara, $cA, 0, 90.0);
do_action('nimikh_certificate_issued', $cert);
ok(in_array('first_certificate', array_column(call('GET', '/me/badges', null, $sara)[1]['badges'], 'key'), true), 'certificate awards first_certificate');
[$s] = call('GET', '/me/badges', null, 0);
ok($s === 401, 'badges need login');

echo "== SMS\n";
update_option(\Nimikh\LMS\Support\Settings::OPTION, ['sms_enabled' => 1] + (array) get_option(\Nimikh\LMS\Support\Settings::OPTION, []));
update_user_meta($rafi, 'billing_phone', '০১৭১২৩৪৫৬৭৮');
$GLOBALS['sent'] = [];
add_filter('nimikh_lms_sms_deliver', function ($pre, $phone, $msg) { $GLOBALS['sent'][] = [$phone, $msg]; return ['sent', 'HTTP 200']; }, 10, 3);
do_action('nimikh_notify_sms', $rafi, 'enrolled', ['course' => 'Excel Basics', 'url' => 'https://x.test/c']);
ok(count($GLOBALS['sent']) === 1 && $GLOBALS['sent'][0][0] === '8801712345678' && str_contains($GLOBALS['sent'][0][1], 'Excel Basics'), 'Bangla-digit phone normalised, template rendered', json_encode($GLOBALS['sent']));
ok((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}nimikh_sms_log WHERE user_id = $rafi AND status = 'sent'") === 1, 'SMS logged');
update_user_meta($rafi, 'nimikh_sms_optout', 1);
do_action('nimikh_notify_sms', $rafi, 'enrolled', ['course' => 'X', 'url' => '']);
ok(count($GLOBALS['sent']) === 1, 'opted-out learner gets nothing');
do_action('nimikh_notify_sms', $sara, 'enrolled', ['course' => 'X', 'url' => '']);
ok(count($GLOBALS['sent']) === 1, 'learner without a phone number gets nothing');
$clean = \Nimikh\LMS\Support\Settings::sanitize(['sms_gateway_url' => 'http://insecure.test/?k={api_key}']);
ok($clean['sms_gateway_url'] === '', 'non-https SMS gateway URL refused');
update_option(\Nimikh\LMS\Support\Settings::OPTION, ['sms_enabled' => 0] + (array) get_option(\Nimikh\LMS\Support\Settings::OPTION, []));
delete_user_meta($rafi, 'nimikh_sms_optout');
do_action('nimikh_notify_sms', $rafi, 'enrolled', ['course' => 'X', 'url' => '']);
ok(count($GLOBALS['sent']) === 1, 'SMS globally disabled sends nothing');

echo "== discussion\n";
[$s, $d] = call('POST', "/lessons/$lesson/discussion", ['body' => 'How do I lock cells in a formula?'], $rafi);
ok($s === 201 && $d['author'] === 'Rafi', 'enrolled learner asks a question', "status $s");
$q1 = $d['id'] ?? 0;
ok(count($GLOBALS['mails']) === 1 && str_contains(implode(',', (array) $GLOBALS['mails'][0]['to']), 'inst'), 'instructor emailed about the new question');
[$s, $d] = call('POST', "/lessons/$lesson/discussion", ['body' => 'Use the $ sign.', 'parent_id' => $q1], $inst);
ok($s === 201 && $d['is_staff'] === true, 'instructor replies (flagged as staff)');
[$s, $d] = call('GET', "/lessons/$lesson/discussion", null, $sara);
ok($s === 200 && count($d) === 1 && count($d[0]['replies']) === 1, 'classmate sees the thread with its reply');
[$s] = call('GET', "/lessons/$lesson/discussion", null, $admin ? user('stranger') : 0);
ok($s === 403, 'non-enrolled user cannot read the discussion');
[$s, $d] = call('POST', "/lessons/$lesson/discussion", ['body' => '<script>alert(1)</script>hello there'], $sara);
ok($s === 201 && !str_contains($d['body'], '<script>'), 'markup stripped from posts', $d['body'] ?? '');
$q2 = $d['id'];
[$s] = call('POST', "/lessons/$lesson/discussion", ['body' => 'x'], $sara);
ok($s === 400, 'too-short post rejected');
[$s] = call('POST', "/lessons/$lesson/discussion", ['body' => 'reply to a reply', 'parent_id' => call('GET', "/lessons/$lesson/discussion", null, $inst)[1][1]['replies'][0]['id'] ?? 999999], $sara);
ok($s === 400, 'replies only attach to top-level questions');
[$s] = call('POST', "/discussion/$q1/hide", ['hidden' => true], $rafi);
ok($s === 403, 'learner cannot moderate');
[$s] = call('POST', "/discussion/$q2/hide", ['hidden' => true], $inst);
ok($s === 200, 'instructor hides a post');
ok(count(call('GET', "/lessons/$lesson/discussion", null, $rafi)[1]) === 1, 'hidden post vanishes for learners');
ok(count(call('GET', "/lessons/$lesson/discussion", null, $inst)[1]) === 2, 'instructor still sees hidden post');
[$s] = call('DELETE', "/discussion/$q1", null, $sara);
ok($s === 403, 'cannot delete someone else\'s post');
[$s] = call('DELETE', "/discussion/$q1", null, $rafi);
ok($s === 200 && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}nimikh_discussions WHERE parent_id = $q1") === 0, 'author deletes own post and its replies');
$codes = []; for ($i = 0; $i < 12; $i++) { $codes[] = call('POST', "/lessons/$lesson/discussion", ['body' => "flood $i"], $rafi)[0]; }
ok(in_array(429, $codes, true), 'posting is rate limited');

echo "== subscriptions\n";
[$s] = call('POST', '/plans', ['name' => 'P', 'course_ids' => [$cB], 'duration_days' => 30], $rafi);
ok($s === 403, 'learner cannot create plans');
[$s, $plan] = call('POST', '/plans', ['name' => 'Pro monthly', 'course_ids' => [$cB], 'duration_days' => 30, 'price_label' => '৳499'], 1);
ok($s === 201 && $plan['course_ids'] === [$cB], 'admin creates a plan', json_encode($plan));
[$s, $d] = call('GET', '/plans', null, 0);
ok($s === 200 && count($d) === 1 && !isset($d[0]['course_ids']) && $d[0]['course_count'] === 1, 'public plan list exposes no course ids');
$lessonB = wp_insert_post(['post_type' => 'lesson', 'post_title' => 'B1', 'post_status' => 'publish', 'post_author' => $inst]);
update_post_meta($lessonB, '_tutor_course_id_for_lesson', $cB); update_post_meta($lessonB, 'nimikh_video_url', 'https://cdn.example.com/b.mp4');
[$s] = call('GET', "/lessons/$lessonB/player", null, $rafi);
ok($s === 403, 'no access to course B without a subscription');
[$s, $sub] = call('POST', '/subscriptions', ['email' => "rafi$rid@example.com", 'plan_id' => $plan['id']], 1);
ok($s === 201 && $sub['status'] === 'active', 'admin grants a subscription', json_encode($sub));
[$s] = call('GET', "/lessons/$lessonB/player", null, $rafi);
ok($s === 200, 'subscriber can open course B');
ok(in_array($cB, \Nimikh\LMS\Plugin::instance()->tutor->managedCourses(1) ? [$cB] : [], true) && (bool) get_user_meta($rafi, 'stub_enrolled_' . $cB, true), 'subscription created the Tutor enrolment');
$exp1 = $sub['expires_at'];
[$s, $sub2] = call('POST', '/subscriptions', ['user_id' => $rafi, 'plan_id' => $plan['id']], 1);
ok($sub2['id'] === $sub['id'] && $sub2['expires_at'] > $exp1, 'granting again renews the same subscription', "$exp1 -> {$sub2['expires_at']}");
[$s, $mine] = call('GET', '/me/subscriptions', null, $rafi);
ok(count($mine) === 1 && $mine[0]['plan'] === 'Pro monthly', '/me/subscriptions lists it');
$wpdb->update($wpdb->prefix . 'nimikh_subscriptions', ['expires_at' => gmdate('Y-m-d H:i:s', time() - 60)], ['id' => $sub['id']]);
$expired = \Nimikh\LMS\Plugin::instance(); // cron handler
do_action(\Nimikh\LMS\Subscriptions\SubscriptionService::CRON_HOOK);
ok($wpdb->get_var("SELECT status FROM {$wpdb->prefix}nimikh_subscriptions WHERE id = {$sub['id']}") === 'expired', 'cron expires a lapsed subscription');
[$s] = call('GET', "/lessons/$lessonB/player", null, $rafi);
ok($s === 403 && !get_user_meta($rafi, 'stub_enrolled_' . $cB, true), 'access and subscription-created enrolment removed on expiry');
// purchased enrolment must survive
enrol_stub($sara, $cB);
[$s, $sub3] = call('POST', '/subscriptions', ['user_id' => $sara, 'plan_id' => $plan['id']], 1);
call('POST', "/subscriptions/{$sub3['id']}/cancel", null, 1);
ok((bool) get_user_meta($sara, 'stub_enrolled_' . $cB, true), 'cancelling never removes an enrolment the learner already had');
do_action('nimikh_subscription_paid', $rafi, $plan['id'], 'TXN123');
ok(call('GET', "/lessons/$lessonB/player", null, $rafi)[0] === 200, 'payment hook (nimikh_subscription_paid) grants access');

echo "== institutes\n";
[$s] = call('POST', '/orgs', ['name' => 'X'], $inst);
ok($s === 403, 'only site admins create institutes');
[$s, $org] = call('POST', '/orgs', ['name' => 'Dhaka IT Institute', 'brand_color' => '#112233', 'logo_url' => 'https://cdn.example.com/logo.png', 'admin_email' => "inst$rid@example.com"], 1);
ok($s === 201 && $org['slug'] === 'dhaka-it-institute' && $org['brand_color'] === '#112233', 'admin creates institute', json_encode($org));
[$s] = call('POST', '/orgs', ['name' => 'Dhaka IT Institute'], 1);
ok($s === 409, 'duplicate web address rejected');
$oid = $org['id'];
[$s] = call('POST', "/orgs/$oid/courses", ['course_id' => $cA], $other);
ok($s === 403, 'a non-member cannot manage the institute');
[$s] = call('POST', "/orgs/$oid/courses", ['course_id' => $cA], $inst);
ok($s === 200, 'institute admin assigns a course they manage');
$cOther = course('Not mine', $other);
[$s] = call('POST', "/orgs/$oid/courses", ['course_id' => $cOther], $inst);
ok($s === 403, 'institute admin cannot assign a course they do not manage');
[$s, $res] = call('POST', "/orgs/$oid/enrol", ['course_id' => $cA, 'learners' => [['email' => "newbie$rid@example.com", 'name' => 'Newbie'], 'bad-email']], $inst);
ok($s === 200 && $res['created'] === 1 && $res['enrolled'] === 1 && count($res['errors']) === 1, 'institute batch enrol creates + enrols + reports bad rows', json_encode($res));
$newbie = get_user_by('email', "newbie$rid@example.com");
ok($newbie && \Nimikh\LMS\Plugin::instance()->tutor->isEnrolled($newbie->ID, $cA) && (new \Nimikh\LMS\Orgs\OrgRepository())->role($oid, $newbie->ID) === 'member', 'new learner is enrolled and is an institute member');
[$s, $res] = call('POST', "/orgs/$oid/enrol", ['course_id' => $cB, 'learners' => ['x@example.com']], $inst);
ok($res['enrolled'] === 0 && $res['errors'], 'cannot enrol into a course outside the institute');
$wpdb->insert($wpdb->posts, ['post_type' => 'tutor_enrolled', 'post_parent' => $cA, 'post_author' => $sara, 'post_status' => 'completed', 'post_title' => 'e', 'post_content' => '', 'post_excerpt' => '', 'to_ping' => '', 'pinged' => '', 'post_content_filtered' => '', 'post_date' => current_time('mysql'), 'post_date_gmt' => current_time('mysql', 1), 'post_modified' => current_time('mysql'), 'post_modified_gmt' => current_time('mysql', 1)]);
[$s, $rep] = call('GET', "/orgs/$oid/report", null, $inst);
$learners = array_column($rep['courses'][0]['learners'] ?? [], 'user_id');
ok($s === 200 && in_array($newbie->ID, $learners, true) && !in_array($sara, $learners, true), 'institute report lists only its own members (tenant isolation)', json_encode($learners));
[$s] = call('GET', "/orgs/$oid/report", null, $rafi);
ok($s === 403, 'learner cannot read institute report');
// branding on certificate + verify
$certO = (new \Nimikh\LMS\Certificates\CertificateRepository())->insert($newbie->ID, $cA, 0, 88.0);
do_action(\Nimikh\LMS\Certificates\CertificateService::RENDER_HOOK, $certO['id']);
$file = \Nimikh\LMS\Certificates\CertificateStorage::baseDir() . '/' . $wpdb->get_var("SELECT pdf_key FROM {$wpdb->prefix}nimikh_certificates WHERE id = {$certO['id']}");
$html = (string) file_get_contents($file);
ok(str_contains($html, '#112233') && str_contains($html, 'Dhaka IT Institute') && str_contains($html, 'logo.png') && !str_contains($html, '{{'), 'certificate carries institute name, colour and logo');
[$s, $v] = call('GET', '/verify/' . $certO['code'], null, 0);
ok($v['issuer'] === 'Dhaka IT Institute' && $v['brand']['color'] === '#112233', 'verify page shows institute as issuer');
ob_start(); $org2 = (new \Nimikh\LMS\Orgs\OrgRepository())->bySlug('dhaka-it-institute'); $courses = [['title' => 'Excel Basics', 'url' => 'https://x.test/c', 'excerpt' => '']]; $org = $org2; include NIMIKH_LMS_DIR . 'templates/institute.php'; $page = ob_get_clean();
ok(str_contains($page, 'Dhaka IT Institute') && str_contains($page, '#112233') && str_contains($page, 'Excel Basics'), 'institute portal page renders branded');

echo "== live classes\n";
$when = gmdate('Y-m-d\TH:i:s\Z', time() + 3 * 3600);
[$s] = call('POST', "/courses/$cA/live", ['title' => 'Q&A', 'starts_at' => $when], $rafi);
ok($s === 403, 'learner cannot schedule');
[$s] = call('POST', "/courses/$cA/live", ['title' => 'Q&A', 'starts_at' => $when], $other);
ok($s === 403, 'instructor of another course cannot schedule');
[$s] = call('POST', "/courses/$cA/live", ['title' => 'Past', 'starts_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 7200)], $inst);
ok($s === 400, 'past start time rejected');
[$s] = call('POST', "/courses/$cA/live", ['title' => 'Bad', 'starts_at' => $when, 'provider' => 'zoom', 'join_url' => 'http://zoom.example/j/1'], $inst);
ok($s === 400, 'non-https join link rejected');
[$s, $live] = call('POST', "/courses/$cA/live", ['title' => 'Weekly Q&A', 'starts_at' => $when, 'duration_min' => 45], $inst);
ok($s === 201 && $live['state'] === 'upcoming' && !isset($live['join_url']), 'instructor schedules a Jitsi class (no join URL in the response)', json_encode($live));
$lid = $live['id'];
[$s, $list] = call('GET', "/courses/$cA/live", null, $rafi);
ok($s === 200 && count($list) === 1 && !isset($list[0]['join_url']) && !str_contains(wp_json_encode($list), 'meet.jit.si'), 'enrolled learner sees the class but not the room link');
[$s] = call('GET', "/courses/$cA/live", null, user('stranger2'));
ok($s === 403, 'non-enrolled user cannot list classes');
[$s, $d] = call('POST', "/live/$lid/join", null, $rafi);
ok($s === 409, 'cannot join before the class opens', "status $s");
$wpdb->update($wpdb->prefix . 'nimikh_live_sessions', ['starts_at' => gmdate('Y-m-d H:i:s', time() + 5 * 60)], ['id' => $lid]);
[$s, $d] = call('POST', "/live/$lid/join", null, $rafi);
ok($s === 200 && str_starts_with($d['url'], 'https://meet.jit.si/nimikh-') && $d['state'] === 'open', 'learner joins inside the 10-minute window', json_encode($d));
call('POST', "/live/$lid/join", null, $rafi);
ok((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}nimikh_live_attendance WHERE session_id = $lid") === 1, 'attendance recorded once per learner');
[$s] = call('POST', "/live/$lid/join", null, user('stranger3'));
ok($s === 403, 'non-enrolled user cannot get the join link');
[$s, $att] = call('GET', "/live/$lid/attendance", null, $inst);
ok($s === 200 && count($att) === 1 && $att[0]['name'] === 'Rafi', 'instructor sees attendance');
[$s] = call('GET', "/live/$lid/attendance", null, $rafi);
ok($s === 403, 'learner cannot see attendance');
$GLOBALS['mails'] = []; $GLOBALS['sent'] = [];
update_option(\Nimikh\LMS\Support\Settings::OPTION, ['sms_enabled' => 1] + (array) get_option(\Nimikh\LMS\Support\Settings::OPTION, []));
$count = do_action(\Nimikh\LMS\Live\LiveService::CRON_HOOK);
ok(count($GLOBALS['mails']) >= 2 && count($GLOBALS['sent']) === 1, 'reminder emails to enrolled learners (+SMS to those with a phone)', 'mails ' . count($GLOBALS['mails']) . ' sms ' . count($GLOBALS['sent']));
$before = count($GLOBALS['mails']); do_action(\Nimikh\LMS\Live\LiveService::CRON_HOOK);
ok(count($GLOBALS['mails']) === $before, 'reminders are sent only once');
[$s] = call('POST', "/live/$lid/cancel", null, $rafi);
ok($s === 403, 'learner cannot cancel');
[$s] = call('POST', "/live/$lid/cancel", null, $inst);
ok($s === 200 && count(call('GET', "/courses/$cA/live", null, $rafi)[1]) === 0, 'cancelled class disappears');

echo "== AI question suggestions\n";
update_post_meta($lesson, 'nimikh_video_duration', 300);
[$s] = call('POST', "/lessons/$lesson/ai-questions", ['transcript' => 'x'], $rafi);
ok($s === 403, 'learner cannot request suggestions');
[$s, $d] = call('POST', "/lessons/$lesson/ai-questions", ['transcript' => 'x'], $inst);
ok($s === 400 && $d['code'] === 'nimikh_ai_off', 'disabled by default');
update_option(\Nimikh\LMS\Support\Settings::OPTION, ['ai_enabled' => 1, 'ai_api_key' => 'sk-test-secret', 'ai_model' => 'claude-sonnet-5-5'] + (array) get_option(\Nimikh\LMS\Support\Settings::OPTION, []));
$canned = ['content' => [['type' => 'text', 'text' => "Here you go:\n```json\n" . json_encode([
	['at_second' => 90, 'stem' => 'What does SUM do?', 'options' => ['Adds', 'Multiplies', 'Counts'], 'correct_index' => 0, 'explanation' => 'It adds.'],
	['at_second' => 100, 'stem' => 'Too close to the last', 'options' => ['a', 'b'], 'correct_index' => 0],
	['at_second' => 200, 'stem' => 'Bad index', 'options' => ['a', 'b'], 'correct_index' => 9],
	['at_second' => 180, 'stem' => 'Which key locks a cell?', 'options' => ['$', '#', '%'], 'correct_index' => 0, 'explanation' => 'Dollar.'],
]) . "\n```"]]];
$GLOBALS['http'] = [];
add_filter('pre_http_request', function ($pre, $args, $url) use ($canned) {
	$GLOBALS['http'][] = [$url, $args];
	if (str_contains($url, 'api.anthropic.com')) { return ['headers' => [], 'body' => wp_json_encode($canned), 'response' => ['code' => $GLOBALS['ai_code'] ?? 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null]; }
	if (str_contains($url, 'captions.vtt')) { return ['headers' => [], 'body' => "WEBVTT\n\n00:00:05.000 --> 00:00:09.000\nSUM adds numbers together\n", 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null]; }
	return $pre;
}, 10, 3);
$questionsBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}nimikh_questions");
[$s, $d] = call('POST', "/lessons/$lesson/ai-questions", ['transcript' => '[0:05] SUM adds numbers. [3:00] Use $ to lock.', 'count' => 5], $inst);
ok($s === 200 && count($d['questions']) === 2 && $d['dropped'] === 2 && $d['questions'][0]['at_second'] === 90, 'drafts returned; invalid/too-close drafts dropped', json_encode($d));
[$url, $args] = $GLOBALS['http'][0];
$body = json_decode($args['body'], true);
ok($url === 'https://api.anthropic.com/v1/messages' && $args['headers']['x-api-key'] === 'sk-test-secret' && $args['headers']['anthropic-version'] === '2023-06-01' && $body['model'] === 'claude-sonnet-5-5', 'request goes to the Messages API with key, version and configured model');
ok(str_contains($body['messages'][0]['content'], 'SUM adds numbers') && str_contains($body['system'], 'never instructions'), 'transcript sent as data with a guarding system prompt');
ok((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}nimikh_questions") === $questionsBefore, 'suggestions are not saved automatically');
update_post_meta($lesson, 'nimikh_captions', [['lang' => 'en', 'label' => 'English', 'url' => 'https://cdn.example.com/captions.vtt']]);
$GLOBALS['http'] = [];
call('POST', "/lessons/$lesson/ai-questions", ['transcript' => ''], $inst);
$sentBody = json_decode(end($GLOBALS['http'])[1]['body'], true);
ok(str_contains($sentBody['messages'][0]['content'] ?? '', '[0:05] SUM adds numbers together'), 'falls back to the lesson captions (VTT parsed to timed text)');
$GLOBALS['ai_code'] = 500;
[$s, $d] = call('POST', "/lessons/$lesson/ai-questions", ['transcript' => 'abc'], $inst);
ok($s === 502 && !str_contains(wp_json_encode($d), 'sk-test-secret'), 'provider failure -> 502 without leaking details');
$GLOBALS['ai_code'] = 200;
update_post_meta($lesson, 'nimikh_video_duration', 30);
[$s] = call('POST', "/lessons/$lesson/ai-questions", ['transcript' => 'abc'], $inst);
ok($s === 409, 'very short video rejected');
update_post_meta($lesson, 'nimikh_video_duration', 300);
$codes = []; for ($i = 0; $i < 12; $i++) { $codes[] = call('POST', "/lessons/$lesson/ai-questions", ['transcript' => 'abc'], $inst)[0]; }
ok(in_array(429, $codes, true), 'AI requests are rate limited per user');

echo "== PWA\n";
ob_start(); (new \Nimikh\LMS\Pwa\Pwa())->head(); $head = ob_get_clean();
ok(str_contains($head, 'rel="manifest"') && str_contains($head, 'nimikh-manifest.json'), 'manifest link added to <head>');
ok(str_contains((new \Nimikh\LMS\Pwa\Pwa())->installButton(), 'data-nk-install'), 'install button shortcode');
global $wp_rewrite; $wp_rewrite->set_permalink_structure('/%postname%/'); // pretty permalinks are required for /verify, /institute and the PWA files
flush_rewrite_rules();
$rules = get_option('rewrite_rules');
ok(isset($rules['^nimikh-sw\.js$']) && isset($rules['^nimikh-manifest\.json$']) && isset($rules['^institute/([a-z0-9\-]+)/?$']) && isset($rules['^verify/?$']), 'rewrite rules registered (PWA, institute, verify)');

echo "\n$n checks, $fail failed\n";
exit($fail ? 1 : 0);
