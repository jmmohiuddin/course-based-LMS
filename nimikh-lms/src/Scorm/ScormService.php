<?php
declare(strict_types=1);

namespace Nimikh\LMS\Scorm;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Support\Settings;
use Nimikh\LMS\Support\Time;

/**
 * Launches a SCORM package for a learner and turns what it reports into lesson completion.
 *
 * Honest limit: a package reports its own completion from the learner's browser, so a determined learner can fake it.
 * Two guards: only whitelisted data-model keys are stored, and completion is ignored until a minimum time has passed
 * since launch. Content that must be tamper-resistant should use the native interactive video, which is graded server-side.
 */
final class ScormService {

	public function __construct(private TrackingRepository $tracking, private TutorAdapter $tutor) {}

	/** @return array{url:string, version:string, title:string, completed:bool, status:string, score:?float}|\WP_Error */
	public function launch(int $userId, int $lessonId) {
		$pkg = PackageStore::forLesson($lessonId);
		if (!$pkg) {
			return new \WP_Error('nimikh_scorm_none', __('This lesson has no SCORM package.', 'nimikh-lms'), ['status' => 404]);
		}
		$this->tracking->startSession($userId, $lessonId, $pkg['version']);
		$row = $this->tracking->find($userId, $lessonId);

		$expires = time() + (int) Settings::get('url_ttl');
		$token   = Token::make($lessonId, $userId, $pkg['id'], $expires, self::secret());
		return [
			'url'       => ContentRoute::url($token, $pkg['launch'], $pkg['query']),
			'version'   => $pkg['version'],
			'title'     => $pkg['title'],
			'completed' => $row !== null && $row['completed_at'] !== null,
			'status'    => $row['status'] ?? '',
			'score'     => $row['score'] ?? null,
		];
	}

	/**
	 * @param mixed $incoming key => value pairs from the package
	 * @return array{completed:bool, status:string, score:?float, deferred:bool}|\WP_Error
	 */
	public function commit(int $userId, int $lessonId, $incoming, bool $finish) {
		$pkg = PackageStore::forLesson($lessonId);
		$row = $this->tracking->find($userId, $lessonId);
		if (!$pkg || !$row) {
			return new \WP_Error('nimikh_scorm_none', __('Launch the package first.', 'nimikh-lms'), ['status' => 409]);
		}
		$version = $pkg['version'];
		$data    = DataModel::sanitize($version, $incoming) + $row['data'];
		if (strlen((string) wp_json_encode($data)) > 524288) {
			return new \WP_Error('nimikh_scorm_big', __('The package reported too much data.', 'nimikh-lms'), ['status' => 413]);
		}

		$session = DataModel::seconds($data['cmi.core.session_time'] ?? $data['cmi.session_time'] ?? '') ?? $row['session_seconds'];
		$verdict = DataModel::evaluate($version, $data, $pkg['mastery']);

		// Completion is only honoured after a minimum time since launch (see class note).
		$min      = (int) apply_filters('nimikh_lms_scorm_min_seconds', (int) Settings::get('scorm_min_seconds'), $lessonId);
		$since    = max(0, time() - Time::toTimestamp($row['started_at']));
		$wasDone  = $row['completed_at'] !== null;
		$deferred = $verdict['completed'] && !$wasDone && $since < $min;
		$complete = $verdict['completed'] && !$deferred;

		$this->tracking->save($userId, $lessonId, $data, $verdict['status'], $verdict['score'], $session, $complete);

		if ($complete && !$wasDone) {
			$courseId = $this->tutor->courseIdForLesson($lessonId);
			$this->tutor->completeLesson($userId, $lessonId);
			do_action('nimikh_lesson_completed', $userId, $lessonId, $courseId);
		}
		return ['completed' => $complete || $wasDone, 'status' => $verdict['status'], 'score' => $verdict['score'], 'deferred' => $deferred];
	}

	/** What the package may read when it starts (resume data, learner id/name, total time). @return array<string,string> */
	public function initialValues(int $userId, int $lessonId): array {
		$pkg = PackageStore::forLesson($lessonId);
		$row = $this->tracking->find($userId, $lessonId);
		$u   = get_userdata($userId);
		if (!$pkg || !$row) {
			return [];
		}
		return DataModel::initial($pkg['version'], $userId, $u ? $u->display_name : '', $row['data'], '', $pkg['mastery'], $row['total_seconds']);
	}

	public static function secret(): string {
		return wp_salt('auth') . '|nimikh-scorm';
	}
}
