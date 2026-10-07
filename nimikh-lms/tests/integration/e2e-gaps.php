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

echo "\n$n checks, $fail failed\n";
exit($fail ? 1 : 0);
