<?php
declare(strict_types=1);

namespace Nimikh\LMS\Reports;

use Nimikh\LMS\Certificates\CertificateRepository;
use Nimikh\LMS\Certificates\CertificateService;
use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Progress\ProgressRepository;
use Nimikh\LMS\Progress\RangeMerger;
use Nimikh\LMS\Support\Installer;
use Nimikh\LMS\Video\InteractionRepository;

/** Instructor analytics (FR-11): learner progress, MCQ accuracy, per-video retention. */
final class ReportService {

	public const BUCKET_SECONDS = 10;

	public function __construct(
		private TutorAdapter $tutor,
		private ProgressRepository $progress,
		private InteractionRepository $interactions,
		private CertificateRepository $certs,
		private CertificateService $certService
	) {}

	/** @return array<string, mixed> */
	public function course(int $courseId): array {
		$lessonIds = $this->tutor->lessonIdsForCourse($courseId);
		$learners  = [];
		foreach ($this->tutor->enrolledUserIds($courseId) as $uid) {
			$user = get_userdata($uid);
			if (!$user) {
				continue;
			}
			$p    = $this->certService->courseProgress($uid, $courseId);
			$cert = $this->certs->findValidFor($uid, $courseId);
			$learners[] = [
				'user_id'           => $uid,
				'name'              => $user->display_name,
				'email'             => $user->user_email,
				'lessons_completed' => $p['lessons_completed'],
				'lessons_total'     => $p['lessons_total'],
				'mcq_percent'       => $p['mcq_percent'],
				'exam_percent'      => $p['exam_percent'],
				'certificate'       => $cert['code'] ?? null,
			];
		}

		$lessons = [];
		foreach ($lessonIds as $lessonId) {
			$lessons[] = [
				'lesson_id' => $lessonId,
				'title'     => get_the_title($lessonId),
				'retention' => $this->retention($lessonId),
				'questions' => $this->questionAccuracy($lessonId),
			];
		}

		$total = count($learners);
		$done  = count(array_filter($learners, static fn(array $l): bool => $l['certificate'] !== null));

		return [
			'course_id' => $courseId,
			'summary'   => [
				'enrolled'        => $total,
				'completed'       => $done,
				'completion_rate' => $total > 0 ? round($done / $total * 100, 1) : 0.0,
			],
			'learners'  => $learners,
			'lessons'   => $lessons,
		];
	}

	/**
	 * Share of learners (0-100) who watched each 10 s bucket; the drop-off curve.
	 *
	 * @return array<int, array{start:int, percent:float}>
	 */
	private function retention(int $lessonId): array {
		$duration = (int) get_post_meta($lessonId, 'nimikh_video_duration', true);
		$rows     = $this->progress->forLesson($lessonId);
		if ($duration <= 0 || !$rows) {
			return [];
		}
		$buckets = (int) ceil($duration / self::BUCKET_SECONDS);
		$counts  = array_fill(0, $buckets, 0);
		foreach ($rows as $row) {
			$ranges = json_decode((string) $row['watched_ranges'], true) ?: [];
			foreach (RangeMerger::merge($ranges) as [$s, $e]) {
				$from = intdiv($s, self::BUCKET_SECONDS);
				$to   = min($buckets - 1, intdiv(max($s, $e - 1), self::BUCKET_SECONDS));
				for ($b = $from; $b <= $to; $b++) {
					$counts[$b]++;
				}
			}
		}
		$out = [];
		foreach ($counts as $i => $c) {
			$out[] = ['start' => $i * self::BUCKET_SECONDS, 'percent' => round($c / count($rows) * 100, 1)];
		}
		return $out;
	}

	/** @return array<int, array<string, mixed>> */
	private function questionAccuracy(int $lessonId): array {
		global $wpdb;
		$a   = Installer::table('interaction_attempts');
		$out = [];
		foreach ($this->interactions->forLesson($lessonId) as $it) {
			$row = $wpdb->get_row($wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) AS learners,
				        COUNT(DISTINCT CASE WHEN attempt_no = 1 AND is_correct = 1 THEN user_id END) AS first_try_correct
				 FROM $a WHERE interaction_id = %d",
				$it['id']
			), ARRAY_A);
			$learners = (int) ($row['learners'] ?? 0);
			$out[] = [
				'interaction_id'    => $it['id'],
				'at_second'         => $it['at_second'],
				'stem'              => wp_strip_all_tags($it['stem']),
				'learners'          => $learners,
				'first_try_correct' => $learners > 0 ? round(((int) $row['first_try_correct']) / $learners * 100, 1) : null,
			];
		}
		return $out;
	}

	/** Learner table as CSV. Cells starting with = + - @ are prefixed to block spreadsheet formula injection. */
	public function csv(array $report): string {
		$fh = fopen('php://temp', 'r+');
		fputcsv($fh, ['Name', 'Email', 'Lessons completed', 'Lessons total', 'MCQ %', 'Exam %', 'Certificate']);
		foreach ($report['learners'] as $l) {
			fputcsv($fh, array_map([self::class, 'cell'], [
				$l['name'], $l['email'], $l['lessons_completed'], $l['lessons_total'],
				$l['mcq_percent'] ?? '', $l['exam_percent'] ?? '', $l['certificate'] ?? '',
			]));
		}
		rewind($fh);
		return (string) stream_get_contents($fh);
	}

	/** @param mixed $v */
	private static function cell($v): string {
		$s = (string) $v;
		return $s !== '' && strpbrk($s[0], "=+-@\t\r") !== false ? "'" . $s : $s;
	}
}
