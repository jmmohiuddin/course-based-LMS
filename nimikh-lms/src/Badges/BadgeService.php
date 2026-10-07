<?php
declare(strict_types=1);

namespace Nimikh\LMS\Badges;

use Nimikh\LMS\Certificates\CertificateRepository;
use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Progress\ProgressService;
use Nimikh\LMS\Support\Installer;
use Nimikh\LMS\Support\Time;

/** Phase 2: badges and learning streaks (the "gamification light" scope; no leaderboards). */
final class BadgeService {

	public function __construct(
		private CertificateRepository $certs,
		private ProgressService $progress,
		private TutorAdapter $tutor
	) {}

	public function register(): void {
		add_action('nimikh_lesson_completed', [$this, 'onLessonCompleted'], 20, 3);
		add_action('tutor_lesson_completed_after', [$this, 'onTutorLesson'], 20, 2);
		add_action('nimikh_certificate_issued', [$this, 'onCertificate'], 20, 1);
	}

	public function onLessonCompleted(int $userId): void {
		$this->recordActivity($userId);
		$this->evaluate($userId);
	}

	public function onTutorLesson(int $lessonId, int $userId): void {
		$this->onLessonCompleted($userId);
	}

	/** @param array<string, mixed> $cert */
	public function onCertificate(array $cert): void {
		$perfect = $this->progress->mcqPercent((int) $cert['user_id'], (int) $cert['course_id']) === 100.0;
		$this->evaluate((int) $cert['user_id'], $perfect);
	}

	public function recordActivity(int $userId): void {
		global $wpdb;
		$table = Installer::table('activity_days');
		$day   = wp_date('Y-m-d');
		$has   = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM $table WHERE user_id = %d AND day = %s", $userId, $day));
		if (!$has) {
			$wpdb->insert($table, ['user_id' => $userId, 'day' => $day]);
		}
	}

	/** @return int current daily streak */
	public function streak(int $userId): int {
		global $wpdb;
		$days = $wpdb->get_col($wpdb->prepare(
			'SELECT day FROM ' . Installer::table('activity_days') . ' WHERE user_id = %d ORDER BY day DESC LIMIT 60',
			$userId
		));
		return BadgeRules::streak(array_map('strval', $days ?: []), wp_date('Y-m-d'));
	}

	/** @return string[] newly awarded badge keys */
	public function evaluate(int $userId, bool $perfectMcq = false): array {
		global $wpdb;
		$lessons = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
			$userId,
			$wpdb->esc_like('_tutor_completed_lesson_id_') . '%'
		));
		$valid = array_filter($this->certs->forUser($userId), static fn(array $c): bool => $c['status'] === CertificateRepository::STATUS_VALID);

		$earned = BadgeRules::earned([
			'lessons_completed' => $lessons,
			'streak'            => $this->streak($userId),
			'certificates'      => count($valid),
			'perfect_mcq'       => $perfectMcq,
		]);
		$have = array_column($this->awarded($userId), 'key');
		$new  = array_values(array_diff($earned, $have));
		foreach ($new as $key) {
			$wpdb->insert(Installer::table('user_badges'), ['user_id' => $userId, 'badge' => $key, 'awarded_at' => Time::nowGmt()]);
			do_action('nimikh_badge_awarded', $userId, $key);
		}
		return $new;
	}

	/** @return array<int, array{key:string, label:string, description:string, awarded_at:string}> */
	public function awarded(int $userId): array {
		global $wpdb;
		$rows    = $wpdb->get_results($wpdb->prepare(
			'SELECT badge, awarded_at FROM ' . Installer::table('user_badges') . ' WHERE user_id = %d ORDER BY awarded_at ASC',
			$userId
		), ARRAY_A) ?: [];
		$catalog = BadgeCatalog::all();
		$out = [];
		foreach ($rows as $r) {
			if (isset($catalog[$r['badge']])) {
				$out[] = ['key' => $r['badge'], 'label' => $catalog[$r['badge']]['label'], 'description' => $catalog[$r['badge']]['description'], 'awarded_at' => $r['awarded_at']];
			}
		}
		return $out;
	}
}
