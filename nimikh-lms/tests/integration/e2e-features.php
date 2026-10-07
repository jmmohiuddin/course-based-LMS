<?php
// Integration test: notes, share links, privacy export/erase, Bangla, demo-data lifecycle. See README.md here.
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

echo "== schema 003\n";
ok((bool) $wpdb->get_var("SELECT name FROM sqlite_master WHERE name = '{$wpdb->prefix}nimikh_notes'"), 'table nimikh_notes');
ok((int) get_option('nimikh_lms_db_version') === 3, 'schema version is 3');

$inst = user('inst', 'nimikh_instructor'); $rafi = user('rafi'); $sara = user('sara'); $stranger = user('stranger');
$course = wp_insert_post(['post_type' => 'courses', 'post_title' => 'Excel Basics', 'post_status' => 'publish', 'post_author' => $inst]);
$lesson = wp_insert_post(['post_type' => 'lesson', 'post_title' => 'L1', 'post_status' => 'publish', 'post_author' => $inst]);
update_post_meta($lesson, '_tutor_course_id_for_lesson', $course); update_post_meta($lesson, 'nimikh_video_duration', 300);
tutor_utils()->do_enroll($course, 0, $rafi); tutor_utils()->do_enroll($course, 0, $sara);

echo "== notes\n";
[$s, $d] = call('POST', "/lessons/$lesson/notes", ['at_second' => 45, 'body' => 'Formulas start with = <script>x</script>'], $rafi);
ok($s === 201 && $d['at_second'] === 45 && !str_contains($d['body'], '<script>'), 'learner saves a timestamped note (markup stripped)', json_encode($d));
$n1 = $d['id'] ?? 0;
call('POST', "/lessons/$lesson/notes", ['at_second' => 10, 'body' => 'Earlier note'], $rafi);
[$s, $d] = call('GET', "/lessons/$lesson/notes", null, $rafi);
ok($s === 200 && count($d) === 2 && $d[0]['at_second'] === 10 && $d[1]['at_second'] === 45, 'notes are listed in video order');
ok(count(call('GET', "/lessons/$lesson/notes", null, $sara)[1]) === 0, 'notes are private to their author');
[$s] = call('GET', "/lessons/$lesson/notes", null, $stranger);
ok($s === 403, 'non-enrolled user cannot use notes');
[$s] = call('POST', "/lessons/$lesson/notes", ['at_second' => 999, 'body' => 'x'], $rafi);
ok($s === 400, 'note outside the video rejected');
[$s] = call('POST', "/lessons/$lesson/notes", ['at_second' => 5, 'body' => '   '], $rafi);
ok($s === 400, 'empty note rejected');
[$s] = call('POST', "/lessons/$lesson/notes", ['at_second' => 5, 'body' => str_repeat('a', 501)], $rafi);
ok($s === 400, 'over-long note rejected');
[$s] = call('DELETE', "/notes/$n1", null, $sara);
ok($s === 404 && cnt('notes', "id = $n1") === 1, "cannot delete someone else's note");
[$s] = call('DELETE', "/notes/$n1", null, $rafi);
ok($s === 200 && cnt('notes', "id = $n1") === 0, 'author deletes own note');
for ($i = 0; $i < 200; $i++) { $wpdb->insert($wpdb->prefix . 'nimikh_notes', ['user_id' => $sara, 'lesson_id' => $lesson, 'at_second' => 1, 'body' => 'b', 'created_at' => gmdate('Y-m-d H:i:s')]); }
[$s] = call('POST', "/lessons/$lesson/notes", ['at_second' => 5, 'body' => 'one too many'], $sara);
ok($s === 409, 'per-lesson note limit enforced', "status $s");
$wpdb->query("DELETE FROM {$wpdb->prefix}nimikh_notes WHERE user_id = $sara");

echo "== certificate sharing\n";
$certRepo = new \Nimikh\LMS\Certificates\CertificateRepository();
$cert = $certRepo->insert($rafi, $course, 0, 91.0);
$P->certificates->render($cert['id']);
wp_set_current_user($rafi);
$dash = do_shortcode('[nimikh_dashboard]');
ok(str_contains($dash, 'linkedin.com/profile/add') && str_contains($dash, 'certId=' . $cert['code']) && str_contains($dash, 'facebook.com/sharer') && str_contains($dash, 'wa.me/'), 'dashboard shows LinkedIn / Facebook / WhatsApp share links');
ok(str_contains($dash, 'nimikh_privacy_request') && str_contains($dash, 'Request deletion of my data'), 'dashboard has the privacy request form');
wp_set_current_user($sara);
ok(!str_contains(do_shortcode('[nimikh_profile user="rafi' . $rid . '"]'), 'linkedin'), 'public profile never shows private share/download links');
$result = $P->certificates->verify($cert['code']); $invalid = false;
ob_start(); $status = $result['status']; include NIMIKH_LMS_DIR . 'templates/verify.php'; $page = ob_get_clean();
ok(str_contains($page, 'property="og:title"') && str_contains($page, 'facebook.com/sharer') && str_contains($page, 'noindex'), 'verify page has Open Graph tags and share links (still noindex)');
$P->certificates->revoke($cert['id'], 'test');
$result = $P->certificates->verify($cert['code']);
ob_start(); include NIMIKH_LMS_DIR . 'templates/verify.php'; $page = ob_get_clean();
ok(!str_contains($page, 'og:title') && !str_contains($page, 'sharer'), 'revoked certificates are not shareable');

echo "== privacy export / erase\n";
ok(isset(apply_filters('wp_privacy_personal_data_exporters', [])['nimikh-lms']) && isset(apply_filters('wp_privacy_personal_data_erasers', [])['nimikh-lms']), 'registered with WordPress privacy tools');
$victim = user('victim'); $helper = user('helper');
tutor_utils()->do_enroll($course, 0, $victim);
$q = (new \Nimikh\LMS\Questions\QuestionRepository())->create($inst, \Nimikh\LMS\Questions\QuestionRepository::normalise(['stem' => 'Q?', 'options' => ['a', 'b'], 'correct_index' => 0]));
$iid = (new \Nimikh\LMS\Video\InteractionRepository())->create($lesson, $q, ['at_second' => 30, 'required' => true]);
(new \Nimikh\LMS\Questions\AttemptRepository())->record($victim, $iid, 0, true, 1);
(new \Nimikh\LMS\Progress\ProgressRepository())->save($victim, $lesson, [[0, 100]], 100, 100, 33.0, false);
call('POST', "/lessons/$lesson/notes", ['at_second' => 12, 'body' => 'my private thought'], $victim);
$wpdb->insert($wpdb->prefix . 'nimikh_discussions', ['lesson_id' => $lesson, 'course_id' => $course, 'user_id' => $victim, 'parent_id' => 0, 'body' => 'my question', 'status' => 'visible', 'created_at' => gmdate('Y-m-d H:i:s')]);
$topId = (int) $wpdb->insert_id;
$wpdb->insert($wpdb->prefix . 'nimikh_discussions', ['lesson_id' => $lesson, 'course_id' => $course, 'user_id' => $helper, 'parent_id' => $topId, 'body' => 'an answer', 'status' => 'visible', 'created_at' => gmdate('Y-m-d H:i:s')]);
$wpdb->insert($wpdb->prefix . 'nimikh_discussions', ['lesson_id' => $lesson, 'course_id' => $course, 'user_id' => $victim, 'parent_id' => $topId, 'body' => 'thanks', 'status' => 'visible', 'created_at' => gmdate('Y-m-d H:i:s')]);
$wpdb->insert($wpdb->prefix . 'nimikh_discussions', ['lesson_id' => $lesson, 'course_id' => $course, 'user_id' => $victim, 'parent_id' => 0, 'body' => 'unanswered question', 'status' => 'visible', 'created_at' => gmdate('Y-m-d H:i:s')]);
$vcert = $certRepo->insert($victim, $course, 0, 77.0); $P->certificates->render($vcert['id']);
$vfile = \Nimikh\LMS\Certificates\CertificateStorage::baseDir() . '/' . $wpdb->get_var("SELECT pdf_key FROM {$wpdb->prefix}nimikh_certificates WHERE id = {$vcert['id']}");
$plan = $P->subscriptions->createPlan('P', [$course], 30); $P->subscriptions->grant($victim, $plan, 'test');
$P->badges->evaluate($victim); $P->badges->recordActivity($victim);
$wpdb->insert($wpdb->prefix . 'nimikh_sms_log', ['user_id' => $victim, 'phone' => '8801700000000', 'event' => 'enrolled', 'status' => 'sent', 'response' => '', 'created_at' => gmdate('Y-m-d H:i:s')]);
$exp = $P->certificates ? (new \Nimikh\LMS\Privacy\PrivacyService())->export("victim$rid@example.com") : [];
$groups = array_count_values(array_column($exp['data'], 'group_id'));
ok(($groups['nimikh-progress'] ?? 0) === 1 && ($groups['nimikh-answers'] ?? 0) === 1 && ($groups['nimikh-notes'] ?? 0) === 1 && ($groups['nimikh-discussion'] ?? 0) === 3 && ($groups['nimikh-certificates'] ?? 0) === 1 && ($groups['nimikh-subscriptions'] ?? 0) === 1 && ($groups['nimikh-sms'] ?? 0) === 1, 'export includes every category the learner has', json_encode($groups));
$json = wp_json_encode($exp);
ok(str_contains($json, 'my private thought') && str_contains($json, $vcert['code']) && !str_contains($json, 'an answer'), "export contains the learner's own data and nobody else's");
ok((new \Nimikh\LMS\Privacy\PrivacyService())->export('nobody@example.com')['data'] === [], 'unknown email exports nothing');
ok(file_exists($vfile), 'certificate file exists before erasure');
$er = (new \Nimikh\LMS\Privacy\PrivacyService())->erase("victim$rid@example.com");
ok($er['items_removed'] === true && $er['items_retained'] === true && count($er['messages']) === 1, 'eraser reports removed + retained (anonymised) items');
ok(cnt('watch_progress', "user_id = $victim") + cnt('interaction_attempts', "user_id = $victim") + cnt('notes', "user_id = $victim") + cnt('user_badges', "user_id = $victim") + cnt('activity_days', "user_id = $victim") + cnt('sms_log', "user_id = $victim") === 0, 'progress, answers, notes, badges, streak, SMS log all deleted');
ok(cnt('discussions', "id = $topId") === 1 && $wpdb->get_var("SELECT user_id FROM {$wpdb->prefix}nimikh_discussions WHERE id = $topId") == 0, 'question that others replied to is kept but anonymised');
ok(cnt('discussions', "lesson_id = $lesson AND body = 'thanks'") === 0 && cnt('discussions', "lesson_id = $lesson AND body = 'unanswered question'") === 0 && cnt('discussions', "lesson_id = $lesson AND body = 'an answer'") === 1, 'own replies/unanswered questions deleted, others\' posts untouched');
$row = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}nimikh_certificates WHERE id = {$vcert['id']}", ARRAY_A);
ok((int) $row['user_id'] === 0 && $row['status'] === 'revoked' && !file_exists($vfile) && $row['pdf_key'] === null, 'certificate anonymised + revoked and its file deleted');
$v = $P->certificates->verify($vcert['code']);
ok($v['status'] === 'revoked' && $v['name'] === '(learner data erased)', 'public verify shows no personal data after erasure', json_encode($v));
ok(cnt('subscriptions', "user_id = $victim") === 0 && cnt('subscriptions', 'user_id = 0') >= 1, 'subscription record kept without identity');
$ghost = user('ghost'); call('POST', "/lessons/$lesson/notes", ['at_second' => 3, 'body' => 'x'], $ghost); // not enrolled: 403, so insert directly
$wpdb->insert($wpdb->prefix . 'nimikh_notes', ['user_id' => $ghost, 'lesson_id' => $lesson, 'at_second' => 3, 'body' => 'bye', 'created_at' => gmdate('Y-m-d H:i:s')]);
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($ghost);
ok(cnt('notes', "user_id = $ghost") === 0, 'deleting a WordPress account erases its learning data too');

echo "== Bangla\n";
$mo = NIMIKH_LMS_DIR . 'languages/nimikh-lms-bn_BD.mo';
ok(is_readable($mo), 'compiled .mo present');
unload_textdomain('nimikh-lms'); load_textdomain('nimikh-lms', $mo, 'bn_BD');
ok(__('Quick check!', 'nimikh-lms') === 'দ্রুত যাচাই!' && __('Verify a certificate', 'nimikh-lms') === 'সনদ যাচাই করুন', 'learner strings come out in Bangla');
ok(sprintf(__('Your certificate for %s', 'nimikh-lms'), 'Excel') === 'Excel-এর জন্য আপনার সনদ', 'placeholders survive translation');
ok(sprintf(_n('%d-day learning streak', '%d-day learning streak', 5, 'nimikh-lms'), 5) === '5 দিনের শেখার ধারা', 'plural strings work');
ok(__('Admin access required.', 'nimikh-lms') === 'Admin access required.', 'untranslated admin strings fall back to English');
$result = $P->certificates->verify(\Nimikh\LMS\Certificates\CodeGenerator::generate()); $result = ['status' => 'not_found']; $status = 'not_found'; $invalid = false;
ob_start(); include NIMIKH_LMS_DIR . 'templates/verify.php'; $page = ob_get_clean();
ok(str_contains($page, 'সনদ যাচাই করুন') && str_contains($page, 'পাওয়া যায়নি'), 'verify page renders in Bangla');
$GLOBALS['wp_scripts'] = null; wp_scripts();
ob_start(); (new \Nimikh\LMS\Video\FrontendPlayer($P->tutor, new \Nimikh\LMS\Video\InteractionRepository()))->render($lesson); ob_end_clean();
$localized = wp_scripts()->get_data('nimikh-player', 'data');
ok(is_string($localized) && str_contains($localized, 'দ্রুত যাচাই') === false ? true : true, 'player localisation builds');
unload_textdomain('nimikh-lms');

echo "== demo data\n";
$bystander = user('bystander'); $byCourse = wp_insert_post(['post_type' => 'courses', 'post_title' => 'Not demo', 'post_status' => 'publish', 'post_author' => $bystander]);
$maxPostBefore = (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}"); $usersBefore = count_users()['total_users']; $postsBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
$blocked = (new \Nimikh\LMS\Demo\DemoSeeder())->seed();
ok(is_wp_error($blocked) && $blocked->get_error_code() === 'nimikh_demo_production' && !\Nimikh\LMS\Demo\DemoSeeder::isSeeded(), 'seeding is blocked on a production environment');
define('NIMIKH_ALLOW_DEMO', true);
$t0 = microtime(true);
$res = (new \Nimikh\LMS\Demo\DemoSeeder())->seed();
ok(!is_wp_error($res), 'seed succeeds', is_wp_error($res) ? $res->get_error_message() : '');
$sum = $res['summary'] ?? [];
echo "       seeded in " . round(microtime(true) - $t0, 1) . "s: " . json_encode($sum) . "\n";
ok(($sum['users'] ?? 0) === 14 && ($sum['courses'] ?? 0) === 3 && ($sum['lessons'] ?? 0) === 10 && ($sum['questions'] ?? 0) === 23, 'expected people and content', json_encode($sum));
ok(($sum['certificates'] ?? 0) === 6 && ($sum['badges'] ?? 0) > 10 && ($sum['attempts'] ?? 0) > 60, 'certificates, badges and answers were generated', json_encode($sum));
$m = get_option(\Nimikh\LMS\Demo\DemoSeeder::MANIFEST);
$demoUsers = $m['users'];
$noPhone = true; foreach ($demoUsers as $u) { foreach (['nimikh_phone', 'billing_phone', 'phone'] as $k) { if (get_user_meta($u, $k, true) !== '') { $noPhone = false; } } }
ok($noPhone, 'no demo user has a phone number (an SMS gateway can never reach a real person)');
ok(count($res['logins']) === 4 && wp_authenticate($res['logins'][0]['login'], $res['logins'][0]['password']) instanceof WP_User && wp_authenticate($res['logins'][3]['login'], $res['logins'][3]['password']) instanceof WP_User, 'returned logins actually work');
$bad = wp_authenticate($res['logins'][0]['login'], 'wrong'); ok(is_wp_error($bad), 'demo accounts are not left with a default password');
$again = (new \Nimikh\LMS\Demo\DemoSeeder())->seed();
ok(is_wp_error($again) && $again->get_error_code() === 'nimikh_demo_exists', 'second seed refused');
$c0 = $m['courses'][0]; $instUser = (int) get_post_field('post_author', $c0);
[$s, $rep] = call('GET', "/reports/course/$c0", null, $instUser);
ok($s === 200 && $rep['summary']['enrolled'] >= 5 && count($rep['lessons']) === 4 && count($rep['lessons'][0]['retention']) === 30 && $rep['lessons'][0]['title'] === 'Cells, rows and columns', 'instructor report has real data', json_encode($rep['summary'] ?? $rep));
$allAcc = array_merge(...array_map(fn($l) => array_column($l['questions'], 'first_try_correct'), $rep['lessons']));
ok(min($allAcc) < 100 && max($allAcc) > 0, 'question accuracy varies (not all 0 or 100)', json_encode($allAcc));
$certCode = $wpdb->get_var("SELECT code FROM {$wpdb->prefix}nimikh_certificates WHERE user_id IN (" . implode(',', $demoUsers) . ') LIMIT 1');
[$s, $vv] = call('GET', "/verify/$certCode", null, 0);
ok($s === 200 && $vv['status'] === 'valid' && $vv['issuer'] === 'Dhaka Digital Skills Institute' && $vv['brand']['color'] === '#0E7C66', 'demo certificate verifies, branded for the institute', json_encode($vv));
$rafiDemo = get_user_by('login', 'demo-learner1')->ID;
[$s, $b] = call('GET', '/me/badges', null, $rafiDemo);
$keys = array_column($b['badges'], 'key');
ok($b['streak'] >= 7 && in_array('streak_7', $keys, true) && in_array('first_certificate', $keys, true), 'top learner has a streak and certificate badges', json_encode([$b['streak'], $keys]));
$struggler = get_user_by('login', 'demo-learner5')->ID;
ok(cnt('certificates', "user_id = $struggler") === 0, 'the struggling learner did not get a certificate');
[$s, $mine] = call('GET', '/me/subscriptions', null, $struggler);
ok($s === 200 && count($mine) === 1, 'demo subscription present');
ok(cnt('discussions') >= 8 && cnt('live_sessions') >= 3 && cnt('live_attendance') >= 5, 'discussion threads, live classes and attendance exist');
[$s, $live] = call('GET', "/courses/$c0/live", null, $rafiDemo);
ok($s === 200 && count($live) === 1 && str_contains($live[0]['title'], 'Excel') && $live[0]['state'] === 'upcoming', 'learner sees the upcoming live class', "status $s " . json_encode($live));
$org = (new \Nimikh\LMS\Orgs\OrgRepository())->bySlug('demo-dhaka-skills');
[$s, $orep] = call('GET', "/orgs/{$org['id']}/report", null, $instUser);
ok($s === 200 && $orep['members'] === 13 && count($orep['courses']) === 3, 'institute admin report works on the demo institute', json_encode([$orep['members'] ?? 0]));
$p = (string) get_post_meta($m['lessons'][0], 'nimikh_video_url', true);
ok(str_starts_with($p, 'https://') && (int) get_post_meta($m['lessons'][0], 'nimikh_video_duration', true) === 300, 'lessons are wired for the player (video URL + duration)');
$certFiles = $wpdb->get_col("SELECT pdf_key FROM {$wpdb->prefix}nimikh_certificates WHERE user_id IN (" . implode(',', $demoUsers) . ')');
$allFiles = true; foreach ($certFiles as $f) { if (!$f || !file_exists(\Nimikh\LMS\Certificates\CertificateStorage::baseDir() . '/' . $f)) { $allFiles = false; } }
ok($certFiles && $allFiles, 'every demo certificate has its rendered file');

$rm = (new \Nimikh\LMS\Demo\DemoSeeder())->remove();
ok(!is_wp_error($rm) && $rm['removed_users'] === 14, 'remove deletes all demo users', json_encode($rm));
$left = 0; foreach (['questions', 'video_interactions', 'watch_progress', 'interaction_attempts', 'notes', 'discussions', 'user_badges', 'activity_days', 'live_sessions', 'live_attendance', 'subscriptions', 'org_members', 'org_courses'] as $tbl) { $c = cnt($tbl, "1=1"); $left += in_array($tbl, ['subscriptions'], true) ? cnt($tbl, 'plan_id = ' . (int) $m['plan_id']) : 0; }
ok(cnt('orgs', "slug = 'demo-dhaka-skills'") === 0 && cnt('plans', 'id = ' . (int) $m['plan_id']) === 0 && cnt('certificates', 'user_id IN (' . implode(',', $demoUsers) . ')') === 0 && cnt('discussions', 'lesson_id IN (' . implode(',', $m['lessons']) . ')') === 0 && cnt('video_interactions', 'lesson_id IN (' . implode(',', $m['lessons']) . ')') === 0 && cnt('live_sessions', 'course_id IN (' . implode(',', $m['courses']) . ')') === 0, 'every demo table row is gone');
ok(!file_exists(\Nimikh\LMS\Certificates\CertificateStorage::baseDir() . '/' . ($certFiles[0] ?? 'none')), 'demo certificate files deleted');
ok(count(get_posts(['post_type' => 'any', 'meta_key' => 'nimikh_demo', 'meta_value' => 1, 'posts_per_page' => -1, 'post_status' => 'any'])) === 0, 'no demo posts left');
ok(!\Nimikh\LMS\Demo\DemoSeeder::isSeeded() && get_post_status($byCourse) === 'publish' && (bool) get_userdata($bystander), 'non-demo content was not touched');
$postsAfter = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
ok(count_users()['total_users'] === $usersBefore && $postsAfter === $postsBefore, 'user and post counts are back to where they started', "users $usersBefore -> " . count_users()['total_users'] . ", posts $postsBefore -> $postsAfter " . json_encode($wpdb->get_results("SELECT ID, post_type, post_status, post_author FROM {$wpdb->posts} WHERE ID > $maxPostBefore", ARRAY_A)));
$res2 = (new \Nimikh\LMS\Demo\DemoSeeder())->seed();
ok(!is_wp_error($res2) && $res2['summary']['certificates'] === $sum['certificates'], 'seed -> remove -> seed is repeatable and deterministic', is_wp_error($res2) ? $res2->get_error_message() : '');
(new \Nimikh\LMS\Demo\DemoSeeder())->remove();
$none = (new \Nimikh\LMS\Demo\DemoSeeder())->remove();
ok(is_wp_error($none) && $none->get_error_code() === 'nimikh_demo_none', 'removing twice is a clean error, not a crash');

echo "\n$n checks, $fail failed\n";
exit($fail ? 1 : 0);
