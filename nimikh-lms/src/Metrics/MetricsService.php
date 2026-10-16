<?php
declare(strict_types=1);

namespace Nimikh\LMS\Metrics;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Progress\CourseRules;
use Nimikh\LMS\Support\Installer;

/** Computes the PRD 4.1 success metrics from data the plugin already keeps, plus two small counters. */
final class MetricsService {

	public const RUM_METRICS = ['video_start', 'lcp'];

	public function __construct(private TutorAdapter $tutor) {}

	// ---- collection ---------------------------------------------------------

	/** One public lookup of an existing certificate. Stored per day, never per visitor. */
	public function recordVerifyView(string $code): void {
		global $wpdb;
		$id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Installer::table('certificates') . ' WHERE code = %s', $code)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ($id <= 0) {
			return;
		}
		$wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'INSERT INTO ' . Installer::table('verify_views') . ' (certificate_id, day, views) VALUES (%d, %s, 1) ON DUPLICATE KEY UPDATE views = views + 1',
			$id,
			gmdate('Y-m-d')
		));
	}

	/** Anonymous client timing (no user id stored). */
	public function recordRum(string $metric, int $ms): bool {
		global $wpdb;
		if (!in_array($metric, self::RUM_METRICS, true) || $ms < 0 || $ms > 120000) {
			return false;
		}
		$wpdb->insert(Installer::table('rum'), ['day' => gmdate('Y-m-d'), 'metric' => $metric, 'value_ms' => $ms]);
		if (random_int(1, 100) === 1) { // keep the table small without a cron job
			$wpdb->query($wpdb->prepare('DELETE FROM ' . Installer::table('rum') . ' WHERE day < %s', gmdate('Y-m-d', time() - 120 * DAY_IN_SECONDS))); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return true;
	}

	// ---- reading ------------------------------------------------------------

	/**
	 * @param int $days 0 = all time
	 * @return array<int, array{key:string, label:string, value:?float, unit:string, target:string, status:string, detail:string}>
	 */
	public function kpis(int $days): array {
		$since = $days > 0 ? gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS) : '1970-01-01 00:00:00';
		$rows  = [];

		[$enrolled, $completed] = $this->completion($since);
		$rows[] = $this->row('completion', __('Course completion rate', 'nimikh-lms'), Kpi::percent($completed, $enrolled), '%',
			/* translators: %s: percent */
			sprintf(__('at least %s%%', 'nimikh-lms'), Kpi::COMPLETION_MIN),
			/* translators: 1: learners who earned the certificate, 2: learners enrolled in the period */
			sprintf(__('%1$d of %2$d enrolments earned the certificate', 'nimikh-lms'), $completed, $enrolled));

		[$shown, $answered] = $this->mcq($since);
		$rows[] = $this->row('mcq_answer', __('In-video question answer rate', 'nimikh-lms'), Kpi::percent($answered, $shown), '%',
			sprintf(__('at least %s%%', 'nimikh-lms'), Kpi::MCQ_ANSWER_MIN),
			/* translators: 1: answered, 2: reached */
			sprintf(__('%1$d of %2$d questions reached were answered', 'nimikh-lms'), $answered, $shown));

		[$firsts, $passes] = $this->firstAttempts();
		$rows[] = $this->row('first_pass', __('First-attempt final exam pass rate', 'nimikh-lms'), Kpi::percent($passes, $firsts), '%',
			sprintf(__('%1$s–%2$s%% (higher means the exam may be too easy)', 'nimikh-lms'), Kpi::FIRST_PASS_MIN, Kpi::FIRST_PASS_MAX),
			/* translators: 1: passed, 2: learners with a finished attempt (all time) */
			sprintf(__('%1$d of %2$d learners passed first time (all time)', 'nimikh-lms'), $passes, $firsts));

		[$views, $issued] = $this->verification($since);
		$rows[] = $this->row('verify_views', __('Certificate verification views per certificate', 'nimikh-lms'), Kpi::percent($views, $issued), '%',
			__('at least 1 view per 5 certificates (20%)', 'nimikh-lms'),
			/* translators: 1: verification views, 2: certificates issued in the period */
			sprintf(__('%1$d views for %2$d certificates issued. Views served from a CDN cache are not counted.', 'nimikh-lms'), $views, $issued));

		$paid = apply_filters('nimikh_lms_paid_enrolments', null, $since);
		$rows[] = $this->row('paid', __('Paid enrolments', 'nimikh-lms'), $paid === null ? null : (float) $paid, '',
			/* translators: %d: target count */
			sprintf(__('at least %d in 90 days', 'nimikh-lms'), Kpi::PAID_MIN),
			$paid === null ? __('Not measured here: connect your payment gateway with the nimikh_lms_paid_enrolments filter.', 'nimikh-lms') : '');

		foreach (['video_start' => [__('Video start time (75th percentile)', 'nimikh-lms'), Kpi::VIDEO_START_MAX], 'lcp' => [__('Largest contentful paint on lesson pages (75th percentile)', 'nimikh-lms'), Kpi::LCP_MAX]] as $key => [$label, $max]) {
			$times = $this->rum($key, $since);
			$p75   = Kpi::percentile($times, 75);
			$rows[] = $this->row($key, $label, $p75 === null ? null : (float) $p75, ' ms',
				/* translators: %d: milliseconds */
				sprintf(__('under %d ms', 'nimikh-lms'), $max),
				/* translators: %d: number of measurements */
				sprintf(__('%d measurements from real learners', 'nimikh-lms'), count($times)));
		}
		return $rows;
	}

	/** @return array{key:string, label:string, value:?float, unit:string, target:string, status:string, detail:string} */
	private function row(string $key, string $label, ?float $value, string $unit, string $target, string $detail): array {
		return ['key' => $key, 'label' => $label, 'value' => $value, 'unit' => $unit, 'target' => $target, 'status' => Kpi::status($key, $value), 'detail' => $detail];
	}

	/** Cohort: enrolments created in the period; completed = that learner holds a valid certificate for that course. @return array{0:int,1:int} */
	private function completion(string $since): array {
		global $wpdb;
		$c   = Installer::table('certificates');
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT COUNT(*) AS total,
			        COALESCE(SUM(EXISTS (SELECT 1 FROM $c c WHERE c.user_id = p.post_author AND c.course_id = p.post_parent AND c.status = 'valid')), 0) AS done
			 FROM {$wpdb->posts} p
			 WHERE p.post_type = 'tutor_enrolled' AND p.post_status = 'completed' AND p.post_date_gmt >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$since
		), ARRAY_A);
		return [(int) ($row['total'] ?? 0), (int) ($row['done'] ?? 0)];
	}

	/** A question counts as shown when the learner's furthest point reached its timestamp. @return array{0:int,1:int} */
	private function mcq(string $since): array {
		global $wpdb;
		$i = Installer::table('video_interactions');
		$w = Installer::table('watch_progress');
		$a = Installer::table('interaction_attempts');
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT COUNT(*) AS shown,
			        COALESCE(SUM(EXISTS (SELECT 1 FROM $a a WHERE a.user_id = w.user_id AND a.interaction_id = i.id)), 0) AS answered
			 FROM $i i JOIN $w w ON w.lesson_id = i.lesson_id AND w.furthest_second >= i.at_second
			 WHERE w.updated_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$since
		), ARRAY_A);
		return [(int) ($row['shown'] ?? 0), (int) ($row['answered'] ?? 0)];
	}

	/** First finished attempt per learner on each course's final exam. @return array{0:int,1:int} [learners, passed] */
	private function firstAttempts(): array {
		global $wpdb;
		$t = $wpdb->prefix . 'tutor_quiz_attempts';
		$learners = 0;
		$passed   = 0;
		foreach (get_posts(['post_type' => TutorAdapter::COURSE_POST_TYPE, 'post_status' => 'publish', 'numberposts' => 500, 'fields' => 'ids']) as $courseId) {
			$quiz = $this->tutor->examQuizId((int) $courseId);
			if ($quiz <= 0) {
				continue;
			}
			$pass = CourseRules::forCourse((int) $courseId)['exam_pass_percent'];
			$firsts = $wpdb->get_results($wpdb->prepare(
				"SELECT a.earned_marks, a.total_marks FROM $t a
				 JOIN (SELECT MIN(attempt_id) AS id FROM $t WHERE quiz_id = %d AND attempt_status = 'attempt_ended' GROUP BY user_id) f ON f.id = a.attempt_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$quiz
			), ARRAY_A) ?: [];
			foreach ($firsts as $f) {
				$learners++;
				if ((float) $f['total_marks'] > 0 && (float) $f['earned_marks'] / (float) $f['total_marks'] * 100 >= $pass) {
					$passed++;
				}
			}
		}
		return [$learners, $passed];
	}

	/** @return array{0:int,1:int} [verification views, certificates issued] in the period */
	private function verification(string $since): array {
		global $wpdb;
		$views  = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(views),0) FROM ' . Installer::table('verify_views') . ' WHERE day >= %s', substr($since, 0, 10))); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$issued = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Installer::table('certificates') . ' WHERE issued_at >= %s', $since)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return [$views, $issued];
	}

	/** @return int[] */
	private function rum(string $metric, string $since): array {
		global $wpdb;
		return array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT value_ms FROM ' . Installer::table('rum') . ' WHERE metric = %s AND day >= %s LIMIT 20000', $metric, substr($since, 0, 10))) ?: []); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
