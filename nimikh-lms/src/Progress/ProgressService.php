<?php
declare(strict_types=1);

namespace Nimikh\LMS\Progress;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Questions\AttemptRepository;
use Nimikh\LMS\Questions\Grader;
use Nimikh\LMS\Support\Settings;
use Nimikh\LMS\Support\Time;
use Nimikh\LMS\Video\InteractionRepository;

final class ProgressService {

	public const RESUME_LEAD_IN = 5; // seconds before an unanswered question (blueprint 7.4)

	public function __construct(
		private ProgressRepository $progress,
		private InteractionRepository $interactions,
		private AttemptRepository $attempts,
		private TutorAdapter $tutor
	) {}

	/** Lesson duration in seconds, set by the instructor's editor from the video metadata. */
	public function duration(int $lessonId): int {
		return (int) get_post_meta($lessonId, 'nimikh_video_duration', true);
	}

	/**
	 * The second at which the learner must stop until a required question is resolved,
	 * or null when nothing blocks them.
	 *
	 * @param array<int, array<string, mixed>> $interactions
	 * @param array<int, array{attempts:int, ever_correct:bool}> $summaries
	 */
	public static function gateSecond(array $interactions, array $summaries): ?int {
		foreach ($interactions as $it) {
			if (!$it['required']) {
				continue;
			}
			$s = $summaries[$it['id']] ?? ['attempts' => 0, 'ever_correct' => false];
			if (!Grader::isResolved($s['ever_correct'], $s['attempts'], $it['allow_retry'])) {
				return (int) $it['at_second'];
			}
		}
		return null;
	}

	/**
	 * Learner-facing state for the player payload.
	 *
	 * @return array{furthest:int, resume:int, percent:float, completed:bool, gate:?int, summaries:array}
	 */
	public function state(int $userId, int $lessonId): array {
		$items     = $this->interactions->forLesson($lessonId);
		$summaries = $this->attempts->summaries($userId, array_column($items, 'id'));
		$gate      = self::gateSecond($items, $summaries);
		$row       = $this->progress->find($userId, $lessonId);

		$resume = $row['last_second'] ?? 0;
		if ($gate !== null && $resume >= $gate) {
			$resume = max(0, $gate - self::RESUME_LEAD_IN);
		}

		return [
			'furthest'  => $row['furthest_second'] ?? 0,
			'resume'    => $resume,
			'percent'   => $row['percent'] ?? 0.0,
			'completed' => !empty($row['completed_at']),
			'gate'      => $gate,
			'summaries' => $summaries,
		];
	}

	/**
	 * Process a heartbeat. Never trusts the client: ranges are sanitised, capped by
	 * real elapsed time x max rate, and clipped at the first unresolved required question.
	 *
	 * @param array<string, mixed> $payload {second:int, ranges:array}
	 * @return array{ok:bool, rejected:bool, furthest:int, percent:float, completed:bool, gate:?int}|\WP_Error
	 */
	public function heartbeat(int $userId, int $lessonId, array $payload) {
		$duration = $this->duration($lessonId);
		if ($duration <= 0) {
			return new \WP_Error('nimikh_no_duration', __('This lesson has no video duration set.', 'nimikh-lms'), ['status' => 409]);
		}

		$row = $this->progress->find($userId, $lessonId);
		if (!$row) {
			$this->progress->touch($userId, $lessonId);
			$row = $this->progress->find($userId, $lessonId);
		}

		$items     = $this->interactions->forLesson($lessonId);
		$summaries = $this->attempts->summaries($userId, array_column($items, 'id'));
		$gate      = self::gateSecond($items, $summaries);

		$claimed = RangeMerger::sanitize($payload['ranges'] ?? [], $duration);
		$claimed = RangeMerger::clip($claimed, $gate);
		$second  = max(0, min($duration, (int) ($payload['second'] ?? 0)));
		if ($gate !== null) {
			$second = min($second, $gate);
		}

		$elapsed = max(0, time() - Time::toTimestamp($row['updated_at'] ?? null));
		$check   = HeartbeatValidator::validate(
			$row['ranges'],
			$claimed,
			(float) $elapsed,
			(float) Settings::get('max_playback_rate'),
			(float) Settings::get('heartbeat_tolerance')
		);

		if (!$check['ok']) {
			// Reset the timing baseline but keep stored progress; the player resyncs from the response.
			$this->progress->touch($userId, $lessonId);
			return [
				'ok'        => false,
				'rejected'  => true,
				'furthest'  => $row['furthest_second'],
				'percent'   => $row['percent'],
				'completed' => !empty($row['completed_at']),
				'gate'      => $gate,
			];
		}

		$ranges   = $check['ranges'];
		$furthest = RangeMerger::furthest($ranges);
		$percent  = RangeMerger::percent($ranges, $duration);

		$courseId  = $this->tutor->courseIdForLesson($lessonId);
		$rules     = CourseRules::forCourse($courseId);
		$wasDone   = !empty($row['completed_at']);
		$completed = $wasDone || ($percent >= $rules['min_watch_percent'] && $gate === null);

		$this->progress->save($userId, $lessonId, $ranges, $furthest, $second, $percent, $completed);

		if ($completed && !$wasDone) {
			$this->tutor->completeLesson($userId, $lessonId);
			/**
			 * Fires once when a learner completes an interactive-video lesson.
			 */
			do_action('nimikh_lesson_completed', $userId, $lessonId, $courseId);
		}

		return [
			'ok'        => true,
			'rejected'  => false,
			'furthest'  => max($furthest, $row['furthest_second']),
			'percent'   => $percent,
			'completed' => $completed,
			'gate'      => $gate,
		];
	}

	/**
	 * Re-check completion after an MCQ is resolved: the learner may already have watched
	 * enough, with the last required question the only thing left.
	 */
	public function recheckCompletion(int $userId, int $lessonId): bool {
		$row = $this->progress->find($userId, $lessonId);
		if (!$row || !empty($row['completed_at'])) {
			return !empty($row['completed_at']);
		}
		$items     = $this->interactions->forLesson($lessonId);
		$summaries = $this->attempts->summaries($userId, array_column($items, 'id'));
		if (self::gateSecond($items, $summaries) !== null) {
			return false;
		}
		$courseId = $this->tutor->courseIdForLesson($lessonId);
		$rules    = CourseRules::forCourse($courseId);
		if ($row['percent'] < $rules['min_watch_percent']) {
			return false;
		}
		$this->progress->save($userId, $lessonId, $row['ranges'], $row['furthest_second'], $row['last_second'], $row['percent'], true);
		$this->tutor->completeLesson($userId, $lessonId);
		do_action('nimikh_lesson_completed', $userId, $lessonId, $courseId);
		return true;
	}

	/** Average in-video MCQ score for a course as a percentage; null when it has no questions. */
	public function mcqPercent(int $userId, int $courseId): ?float {
		$score = $this->attempts->scoreForLessons($userId, $this->tutor->lessonIdsForCourse($courseId));
		return $score['total'] > 0 ? round($score['earned'] / $score['total'] * 100, 2) : null;
	}
}
