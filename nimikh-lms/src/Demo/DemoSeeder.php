<?php
declare(strict_types=1);

namespace Nimikh\LMS\Demo;

use Nimikh\LMS\Certificates\CertificateRepository;
use Nimikh\LMS\Certificates\CertificateStorage;
use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Notes\NoteService;
use Nimikh\LMS\Orgs\OrgRepository;
use Nimikh\LMS\Plugin;
use Nimikh\LMS\Progress\ProgressRepository;
use Nimikh\LMS\Progress\RangeMerger;
use Nimikh\LMS\Questions\AttemptRepository;
use Nimikh\LMS\Questions\QuestionRepository;
use Nimikh\LMS\Support\Installer;
use Nimikh\LMS\Video\InteractionRepository;

/**
 * Builds a believable demo world (an institute, 3 bilingual courses, instructors, 12 learners with
 * different behaviours, certificates, badges, discussion, a plan, live classes) and removes it again.
 *
 * Safety: refuses to run on a production environment unless NIMIKH_ALLOW_DEMO is defined, never sets phone
 * numbers (so an SMS gateway can never message a real person), uses example.test addresses, sends no email,
 * and records every object it creates in an option so remove() deletes exactly those.
 */
final class DemoSeeder {

	public const MANIFEST = 'nimikh_demo_manifest';
	public const SEED     = 20261007;

	private TutorAdapter $tutor;
	/** @var array<string, mixed> */
	private array $m = ['users' => [], 'posts' => [], 'courses' => [], 'lessons' => [], 'quizzes' => [], 'questions' => [], 'org_id' => 0, 'plan_id' => 0, 'sessions' => []];

	public function __construct() {
		$this->tutor = Plugin::instance()->tutor;
	}

	public static function isSeeded(): bool {
		return is_array(get_option(self::MANIFEST));
	}

	/** @return true|\WP_Error */
	public static function allowed() {
		if (wp_get_environment_type() === 'production' && !(defined('NIMIKH_ALLOW_DEMO') && NIMIKH_ALLOW_DEMO)) {
			return new \WP_Error('nimikh_demo_production', __('Demo data is blocked on production sites. Set WP_ENVIRONMENT_TYPE to "staging" or "local", or define NIMIKH_ALLOW_DEMO as true in wp-config.php.', 'nimikh-lms'), ['status' => 403]);
		}
		return true;
	}

	/**
	 * @return array{summary: array<string,int>, logins: array<int, array{role:string, name:string, login:string, password:string}>}|\WP_Error
	 */
	public function seed() {
		$ok = self::allowed();
		if (is_wp_error($ok)) {
			return $ok;
		}
		if (self::isSeeded()) {
			return new \WP_Error('nimikh_demo_exists', __('Demo data is already loaded. Remove it first.', 'nimikh-lms'), ['status' => 409]);
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		mt_srand(self::SEED);
		add_filter('pre_wp_mail', '__return_true', 99); // no email from a demo

		$logins = [];
		try {
			$org        = $this->createOrg();
			$instructor = $this->createUser('Karim Hossain', 'instructor', 'nimikh_instructor', $logins);
			$instructor2 = $this->createUser('Salma Khatun', 'instructor2', 'nimikh_instructor', $logins);
			(new OrgRepository())->setMember($org['id'], $instructor, 'admin');

			$courses = $this->createCourses([$instructor, $instructor2], $org['id']);
			$learners = [];
			foreach (DemoContent::learners() as $i => $l) {
				$uid = $this->createUser($l['name'], 'learner' . ($i + 1), 'subscriber', $logins);
				$learners[] = ['id' => $uid] + $l;
				(new OrgRepository())->setMember($org['id'], $uid, 'member');
			}
			foreach ($learners as $idx => $l) {
				foreach ($l['courses'] as $ci) {
					$this->enrol($l['id'], $courses[$ci]);
					$this->simulateLearning($l['id'], $courses[$ci], $l['profile'], $idx);
				}
				$this->simulateActivity($l['id'], $l['profile']);
			}
			$this->createDiscussion($courses, $learners, [$instructor, $instructor2]);
			$this->createNotes($learners[0]['id'], $courses[0]);
			$this->createPlan($courses, $learners);
			$this->createLive($courses);
			foreach ($learners as $l) {
				Plugin::instance()->badges->evaluate($l['id']);
			}
		} catch (\Throwable $e) {
			// Keep the manifest so "Remove demo data" can clean up whatever was created before the failure.
			update_option(self::MANIFEST, $this->m, false);
			return new \WP_Error('nimikh_demo_failed', sprintf(__('Demo data could not be completed: %s. Use "Remove demo data" to clean up.', 'nimikh-lms'), $e->getMessage()), ['status' => 500]);
		} finally {
			remove_filter('pre_wp_mail', '__return_true', 99);
		}
		update_option(self::MANIFEST, $this->m, false);

		return ['summary' => $this->summary(), 'logins' => array_slice($logins, 0, 4)];
	}

	/** @return array<string, int> */
	public function summary(): array {
		global $wpdb;
		$users = array_map('intval', $this->m['users'] ?: []);
		$in    = $users ? implode(',', $users) : '0';
		$cnt   = static fn(string $table, string $col = 'user_id') => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Installer::table($table) . " WHERE $col IN ($in)");
		return [
			'users'        => count($users),
			'courses'      => count($this->m['courses']),
			'lessons'      => count($this->m['lessons']),
			'questions'    => count($this->m['questions']),
			'certificates' => $cnt('certificates'),
			'badges'       => $cnt('user_badges'),
			'attempts'     => $cnt('interaction_attempts'),
		];
	}

	// ---- removal ----------------------------------------------------------------

	/** @return array{removed_users:int, removed_posts:int}|\WP_Error */
	public function remove() {
		$m = get_option(self::MANIFEST);
		if (!is_array($m)) {
			return new \WP_Error('nimikh_demo_none', __('There is no demo data to remove.', 'nimikh-lms'), ['status' => 404]);
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		global $wpdb;
		$ids  = static fn(array $a): string => $a ? implode(',', array_map('intval', $a)) : '0';
		$users = $ids($m['users']);
		$lessons = $ids($m['lessons']);
		$courses = $ids($m['courses']);
		$t = static fn(string $n): string => Installer::table($n);

		// Certificate files first (rows are deleted outright, not anonymised: this is test data).
		foreach ($wpdb->get_col("SELECT pdf_key FROM {$t('certificates')} WHERE user_id IN ($users) AND pdf_key IS NOT NULL") ?: [] as $key) {
			$path = CertificateStorage::baseDir() . '/' . basename((string) $key);
			if (is_file($path)) {
				@unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		$sessions = $ids($wpdb->get_col("SELECT id FROM {$t('live_sessions')} WHERE course_id IN ($courses)") ?: []);
		$interactions = $ids($wpdb->get_col("SELECT id FROM {$t('video_interactions')} WHERE lesson_id IN ($lessons)") ?: []);
		$queries = [
			"DELETE FROM {$t('certificates')} WHERE user_id IN ($users) OR course_id IN ($courses)",
			"DELETE FROM {$t('interaction_attempts')} WHERE user_id IN ($users) OR interaction_id IN ($interactions)",
			"DELETE FROM {$t('video_interactions')} WHERE lesson_id IN ($lessons)",
			"DELETE FROM {$t('questions')} WHERE id IN (" . $ids($m['questions']) . ')',
			"DELETE FROM {$t('watch_progress')} WHERE user_id IN ($users) OR lesson_id IN ($lessons)",
			"DELETE FROM {$t('notes')} WHERE user_id IN ($users) OR lesson_id IN ($lessons)",
			"DELETE FROM {$t('discussions')} WHERE lesson_id IN ($lessons) OR user_id IN ($users)",
			"DELETE FROM {$t('user_badges')} WHERE user_id IN ($users)",
			"DELETE FROM {$t('activity_days')} WHERE user_id IN ($users)",
			"DELETE FROM {$t('live_attendance')} WHERE session_id IN ($sessions) OR user_id IN ($users)",
			"DELETE FROM {$t('live_sessions')} WHERE course_id IN ($courses)",
			"DELETE FROM {$t('subscriptions')} WHERE user_id IN ($users)",
			"DELETE FROM {$t('sms_log')} WHERE user_id IN ($users)",
			"DELETE FROM {$t('org_members')} WHERE org_id = " . (int) $m['org_id'],
			"DELETE FROM {$t('org_courses')} WHERE org_id = " . (int) $m['org_id'],
			"DELETE FROM {$t('orgs')} WHERE id = " . (int) $m['org_id'],
		];
		foreach ($queries as $sql) {
			$wpdb->query($sql);
		}
		if (!empty($m['plan_id'])) {
			$wpdb->query($wpdb->prepare("DELETE FROM {$t('subscriptions')} WHERE plan_id = %d", (int) $m['plan_id']));
			$wpdb->query($wpdb->prepare("DELETE FROM {$t('plans')} WHERE id = %d", (int) $m['plan_id']));
		}
		if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}tutor_quiz_attempts'")) {
			$wpdb->query("DELETE FROM {$wpdb->prefix}tutor_quiz_attempts WHERE user_id IN ($users)");
		}

		// Enrolments created along the way (e.g. when a demo subscription was granted) are not in the manifest.
		$extra = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tutor_enrolled' AND (post_author IN ($users) OR post_parent IN ($courses))") ?: [];
		$m['posts'] = array_merge($m['posts'], array_map('intval', $extra));

		$posts = 0;
		foreach (array_reverse($m['posts']) as $pid) {
			if (wp_delete_post((int) $pid, true)) {
				$posts++;
			}
		}
		// Privacy eraser must not re-create anonymised rows: tables are already clean when users are deleted.
		$removedUsers = 0;
		foreach ($m['users'] as $uid) {
			if (wp_delete_user((int) $uid)) {
				$removedUsers++;
			}
		}
		delete_option(self::MANIFEST);
		return ['removed_users' => $removedUsers, 'removed_posts' => $posts];
	}

	// ---- builders -----------------------------------------------------------------

	/** @return array<string, mixed> */
	private function createOrg(): array {
		$org = (new OrgRepository())->create('Dhaka Digital Skills Institute', 'demo-dhaka-skills', '#0E7C66', '');
		if (is_wp_error($org)) { // slug left over from a failed run: reuse it
			$org = (new OrgRepository())->bySlug('demo-dhaka-skills') ?? [];
		}
		$this->m['org_id'] = (int) ($org['id'] ?? 0);
		return $org;
	}

	/** @param array<int, array<string, string>> $logins */
	private function createUser(string $name, string $slug, string $role, array &$logins): int {
		$login = 'demo-' . $slug;
		$pass  = wp_generate_password(14, false);
		$id    = wp_insert_user([
			'user_login'   => $login,
			'user_pass'    => $pass,
			'user_email'   => $login . '@example.test',
			'display_name' => $name,
			'first_name'   => strtok($name, ' ') ?: $name,
			'role'         => $role,
		]);
		if (is_wp_error($id)) {
			throw new \RuntimeException($id->get_error_message());
		}
		update_user_meta($id, 'nimikh_demo', 1);
		$this->m['users'][] = $id;
		$logins[] = ['role' => $role === 'subscriber' ? 'learner' : 'instructor', 'name' => $name, 'login' => $login, 'password' => $pass];
		return (int) $id;
	}

	private function post(array $args, int $author = 0): int {
		$id = wp_insert_post($args + ['post_status' => 'publish', 'post_author' => $author ?: 1], true);
		if (is_wp_error($id)) {
			throw new \RuntimeException($id->get_error_message());
		}
		update_post_meta($id, 'nimikh_demo', 1);
		$this->m['posts'][] = $id;
		return (int) $id;
	}

	/**
	 * @param int[] $instructors
	 * @return array<int, array{id:int, lessons:int[], quiz:int, questions:array<int, array<int, array<string,mixed>>>, durations:int[]}>
	 */
	private function createCourses(array $instructors, int $orgId): array {
		$questions = new QuestionRepository();
		$inter     = new InteractionRepository();
		$videoUrl  = (string) apply_filters('nimikh_lms_demo_video_url', 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8');
		$out = [];
		foreach (DemoContent::courses() as $ci => $c) {
			$author = $instructors[$ci % count($instructors)];
			$course = $this->post(['post_type' => 'courses', 'post_title' => $c['title'], 'post_excerpt' => $c['excerpt'], 'post_content' => $c['excerpt']], $author);
			add_user_meta($author, '_tutor_instructor_course_id', $course); // Tutor's instructor link
			update_post_meta($course, 'nimikh_exam_pass_percent', 60);
			update_post_meta($course, 'nimikh_min_watch_percent', 90);
			(new OrgRepository())->assignCourse($orgId, $course);
			$this->m['courses'][] = $course;

			$topic = $this->post(['post_type' => 'topics', 'post_title' => 'Lessons', 'post_parent' => $course, 'menu_order' => 1], $author);
			$lessons = []; $durations = []; $qs = [];
			foreach ($c['lessons'] as $li => $l) {
				$lesson = $this->post(['post_type' => 'lesson', 'post_title' => $l['title'], 'post_parent' => $topic, 'menu_order' => $li + 1, 'post_content' => ''], $author);
				update_post_meta($lesson, '_tutor_course_id_for_lesson', $course);
				update_post_meta($lesson, 'nimikh_interactive_enabled', '1');
				update_post_meta($lesson, 'nimikh_video_url', $videoUrl);
				update_post_meta($lesson, 'nimikh_video_duration', $l['duration']);
				$this->m['lessons'][] = $lesson;
				$lessons[] = $lesson; $durations[] = $l['duration'];
				$qs[$li] = [];
				foreach ($l['questions'] as $qi => $q) {
					$data = QuestionRepository::normalise(['stem' => $q[1], 'options' => $q[2], 'correct_index' => $q[3], 'explanation' => $q[4], 'lang' => $q[5] ?? 'en', 'tags' => $c['key']]);
					if (is_wp_error($data)) {
						throw new \RuntimeException($data->get_error_message());
					}
					$qid = $questions->create($author, $data);
					$this->m['questions'][] = $qid;
					$optional = count($l['questions']) === 3 && $qi === 2;
					$iid = $inter->create($lesson, $qid, ['at_second' => $q[0], 'required' => !$optional, 'allow_retry' => true, 'points' => 1, 'rewind_to_second' => max(0, $q[0] - 20)]);
					$qs[$li][] = ['id' => $iid, 'at' => $q[0], 'correct' => $q[3], 'n' => count($q[2]), 'required' => !$optional];
				}
			}
			$quiz = $this->post(['post_type' => 'tutor_quiz', 'post_title' => $c['exam'], 'post_parent' => $topic], $author);
			update_post_meta($quiz, '_tutor_course_id_for_quiz', $course);
			update_post_meta($course, 'nimikh_exam_quiz_id', $quiz);
			$this->m['quizzes'][] = $quiz;
			$out[] = ['id' => $course, 'lessons' => $lessons, 'quiz' => $quiz, 'questions' => $qs, 'durations' => $durations];
		}
		return $out;
	}

	/** @param array<string, mixed> $course */
	private function enrol(int $userId, array $course): void {
		$id = $this->post(['post_type' => 'tutor_enrolled', 'post_title' => 'Enrolment ' . $userId . '/' . $course['id'], 'post_parent' => $course['id'], 'post_status' => 'completed'], $userId);
		update_user_meta($userId, 'nimikh_demo_enrolled_' . $course['id'], $id);
	}

	/** @param array<string, mixed> $course */
	private function simulateLearning(int $uid, array $course, string $profile, int $learnerIdx): void {
		$progress = new ProgressRepository();
		$attempts = new AttemptRepository();
		$n = count($course['lessons']);

		// how far the learner got: lessons fully done, and the fraction of the next one watched
		[$done, $partial, $accuracy] = match ($profile) {
			'finisher'  => [$n, 0.0, 0.88],
			'struggler' => [$n, 0.0, 0.30],
			'midway'    => [max(1, intdiv($n, 2)), 0.5, 0.72],
			'dropoff'   => [0, 0.4, 0.60],
			default     => [0, 0.15, 0.60], // starter
		};
		$started = time() - (3 + $learnerIdx % 14) * 86400;

		for ($li = 0; $li < $n; $li++) {
			$isDone = $li < $done;
			$isPartial = !$isDone && $li === $done && $partial > 0;
			if (!$isDone && !$isPartial) {
				break;
			}
			$lessonId = $course['lessons'][$li];
			$dur      = $course['durations'][$li];
			$end      = $isDone ? $dur : (int) floor($dur * ($partial + mt_rand(-5, 5) / 100));
			$ranges   = [[0, $end]];
			if ($isDone && $profile === 'struggler' && mt_rand(0, 1)) { // skipped back and forth: a little rewatching, not full coverage
				$ranges = [[0, $dur]];
			}
			$percent = RangeMerger::percent($ranges, $dur);
			$when = gmdate('Y-m-d H:i:s', $started + $li * 3600 * 20);

			// answer every question the learner reached
			foreach ($course['questions'][$li] as $q) {
				if ($q['at'] > $end) {
					continue;
				}
				if (!$q['required'] && mt_rand(0, 99) < 30) {
					continue; // skipped optional question
				}
				$wrong = ($q['correct'] + 1) % $q['n'];
				$first = mt_rand(0, 99) < $accuracy * 100;
				if ($first) {
					$attempts->record($uid, $q['id'], $q['correct'], true, 1);
				} else {
					$attempts->record($uid, $q['id'], $wrong, false, 1);
					if (mt_rand(0, 99) < 55 && $profile !== 'struggler') {
						$attempts->record($uid, $q['id'], $q['correct'], true, 2);
					} else {
						$attempts->record($uid, $q['id'], $wrong, false, 2); // used both tries
					}
				}
			}
			$progress->save($uid, $lessonId, $ranges, $end, $end, $percent, $isDone);
			$GLOBALS['wpdb']->update(Installer::table('watch_progress'), ['updated_at' => $when], ['user_id' => $uid, 'lesson_id' => $lessonId]);
			if ($isDone) {
				update_user_meta($uid, '_tutor_completed_lesson_id_' . $lessonId, $started + $li * 3600 * 20);
			}
		}

		if ($done === $n) {
			$score = $profile === 'struggler' ? mt_rand(40, 55) : mt_rand(72, 96);
			$this->examAttempt($uid, $course, $score);
			$this->certificateIfEarned($uid, $course['id'], $score, gmdate('Y-m-d H:i:s', $started + $n * 3600 * 22));
		}
	}

	/** Insert a finished Tutor quiz attempt, only into the columns the installed Tutor schema has. */
	private function examAttempt(int $uid, array $course, int $percent): void {
		global $wpdb;
		$table = $wpdb->prefix . 'tutor_quiz_attempts';
		if (!$wpdb->get_var("SHOW TABLES LIKE '$table'")) {
			return;
		}
		$cols = array_map(static fn($c) => $c->Field ?? ($c['Field'] ?? ''), $wpdb->get_results("SHOW COLUMNS FROM $table") ?: []);
		$now  = gmdate('Y-m-d H:i:s');
		$row  = ['course_id' => $course['id'], 'quiz_id' => $course['quiz'], 'user_id' => $uid, 'total_questions' => 10, 'total_answered_questions' => 10,
			'total_marks' => 100, 'earned_marks' => $percent, 'attempt_info' => 'a:0:{}', 'attempt_status' => 'attempt_ended', 'attempt_started_at' => $now, 'attempt_ended_at' => $now];
		$wpdb->insert($table, array_intersect_key($row, array_flip($cols)));
	}

	private function certificateIfEarned(int $uid, int $courseId, int $score, string $issuedAt): void {
		global $wpdb;
		$svc  = Plugin::instance()->certificates;
		$repo = new CertificateRepository();
		$res  = $svc->maybeIssue($uid, $courseId);
		if (!$res['issued'] && $score >= 60 && !$repo->findValidFor($uid, $courseId)) {
			// Tutor's attempts table is not available: record the certificate directly so the demo still has them.
			$cert = $repo->insert($uid, $courseId, 0, (float) $score);
			if ($cert) {
				do_action('nimikh_certificate_issued', $cert);
				$res = ['issued' => true, 'certificate' => $cert];
			}
		}
		if (!empty($res['issued']) && !empty($res['certificate'])) {
			$id = (int) $res['certificate']['id'];
			$wpdb->update(Installer::table('certificates'), ['issued_at' => $issuedAt], ['id' => $id]);
			$svc->render($id); // synchronous so the demo has files immediately
		}
	}

	private function simulateActivity(int $uid, string $profile): void {
		global $wpdb;
		$table = Installer::table('activity_days');
		$days = match ($profile) {
			'finisher' => range(0, 8),
			'midway'   => [0, 1, 2],
			'struggler' => [0, 2, 3, 5],
			'dropoff'  => [4, 5],
			default    => [1],
		};
		foreach ($days as $d) {
			$wpdb->insert($table, ['user_id' => $uid, 'day' => wp_date('Y-m-d', time() - $d * 86400)]);
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $courses
	 * @param array<int, array<string, mixed>> $learners
	 * @param int[] $instructors
	 */
	private function createDiscussion(array $courses, array $learners, array $instructors): void {
		global $wpdb;
		$table = Installer::table('discussions');
		$threads = [
			[0, 2, 'Why do I get #NAME? when I write =SUMM(A1:A5)?', 'Check the spelling of the function. It is SUM, with one M. Excel shows #NAME? when it does not recognise a name.'],
			[0, 3, 'Can I lock only the column and not the row?', 'Yes. Use $A1 to lock the column only, or A$1 to lock the row only.'],
			[1, 1, 'How much should a small shop spend on a first Facebook ad?', 'Start small, like ৳300–500 a day for a few days, then keep the version that gets more clicks per taka.'],
			[2, 0, 'Is it okay to say "Pleased to meet you" in an interview?', 'Absolutely. It is polite and natural. "Nice to meet you" works too.'],
		];
		foreach ($threads as $i => [$ci, $li, $q, $a]) {
			$course = $courses[$ci];
			$lessonId = $course['lessons'][min($li, count($course['lessons']) - 1)];
			$asker = $learners[($i * 3) % count($learners)]['id'];
			$when = gmdate('Y-m-d H:i:s', time() - (5 - $i) * 86400);
			$wpdb->insert($table, ['lesson_id' => $lessonId, 'course_id' => $course['id'], 'user_id' => $asker, 'parent_id' => 0, 'body' => $q, 'status' => 'visible', 'created_at' => $when]);
			$parent = (int) $wpdb->insert_id;
			$wpdb->insert($table, ['lesson_id' => $lessonId, 'course_id' => $course['id'], 'user_id' => $instructors[$ci % count($instructors)], 'parent_id' => $parent, 'body' => $a, 'status' => 'visible', 'created_at' => gmdate('Y-m-d H:i:s', strtotime($when . ' UTC') + 5400)]);
		}
	}

	/** @param array<string, mixed> $course */
	private function createNotes(int $uid, array $course): void {
		$notes = new NoteService(Plugin::instance()->progress);
		$prev  = get_current_user_id();
		foreach ([[45, 'Formulas always start with =. Remember the = sign!'], [120, 'SUM(A1:A5): colon means "from … to".'], [205, 'Practice: total my monthly expenses sheet.']] as $i => [$at, $body]) {
			$lesson = $course['lessons'][min($i, count($course['lessons']) - 1)];
			$notes->add($uid, $lesson, $at, $body);
		}
		wp_set_current_user($prev);
	}

	/**
	 * @param array<int, array<string, mixed>> $courses
	 * @param array<int, array<string, mixed>> $learners
	 */
	private function createPlan(array $courses, array $learners): void {
		$subs = Plugin::instance()->subscriptions;
		global $wpdb;
		$plan = $subs->createPlan('Pro monthly — প্রো মাসিক', array_column($courses, 'id'), 30, '৳499 / month');
		$this->m['plan_id'] = $plan;
		foreach ([$learners[4]['id'] => 28, $learners[9]['id'] => 2] as $uid => $daysLeft) {
			$sub = $subs->grant((int) $uid, $plan, 'demo');
			if (!is_wp_error($sub)) {
				$wpdb->update(Installer::table('subscriptions'), ['expires_at' => gmdate('Y-m-d H:i:s', time() + $daysLeft * 86400)], ['id' => $sub['id']]);
			}
		}
	}

	/** @param array<int, array<string, mixed>> $courses */
	private function createLive(array $courses): void {
		global $wpdb;
		$table = Installer::table('live_sessions');
		$author = (int) get_post_field('post_author', $courses[0]['id']);
		$rows = [
			[0, 'Excel Q&A — আলোচনা ও প্রশ্নোত্তর', time() + 26 * 3600, 60],
			[1, 'Live: Setting up your first Facebook ad', time() + 3 * 86400, 90],
			[0, 'Welcome session (recording not kept)', time() - 6 * 86400, 45],
		];
		foreach ($rows as [$ci, $title, $ts, $mins]) {
			$room = \Nimikh\LMS\Live\SessionState::roomName();
			$wpdb->insert($table, ['course_id' => $courses[$ci]['id'], 'title' => $title, 'starts_at' => gmdate('Y-m-d H:i:s', $ts), 'duration_min' => $mins, 'provider' => 'jitsi',
				'join_url' => 'https://' . \Nimikh\LMS\Support\Settings::get('jitsi_host') . '/' . $room, 'room' => $room, 'status' => 'scheduled', 'created_by' => $author,
				'reminded_at' => $ts < time() ? gmdate('Y-m-d H:i:s', $ts - 1800) : null]);
			$id = (int) $wpdb->insert_id;
			$this->m['sessions'][] = $id;
			if ($ts < time()) { // past class: attendance from a few demo learners
				foreach (array_slice($this->m['users'], 2, 5) as $uid) {
					$wpdb->insert(Installer::table('live_attendance'), ['session_id' => $id, 'user_id' => $uid, 'joined_at' => gmdate('Y-m-d H:i:s', $ts + 120)]);
				}
			}
		}
	}
}
