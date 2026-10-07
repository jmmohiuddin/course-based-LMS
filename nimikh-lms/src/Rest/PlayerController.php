<?php
declare(strict_types=1);

namespace Nimikh\LMS\Rest;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Progress\ProgressRepository;
use Nimikh\LMS\Progress\ProgressService;
use Nimikh\LMS\Questions\AttemptService;
use Nimikh\LMS\Support\Settings;
use Nimikh\LMS\Video\InteractionRepository;
use Nimikh\LMS\Video\PlayerSource;

final class PlayerController extends BaseController {

	public function __construct(
		TutorAdapter $tutor,
		private InteractionRepository $interactions,
		private ProgressRepository $progressRepo,
		private ProgressService $progress,
		private AttemptService $attempts
	) {
		parent::__construct($tutor);
	}

	public function registerRoutes(): void {
		$this->route('/lessons/(?P<id>\d+)/player', [
			'methods' => 'GET', 'callback' => [$this, 'player'], 'permission_callback' => [$this, 'canLearn'],
		]);
		$this->route('/lessons/(?P<id>\d+)/heartbeat', [
			'methods' => 'POST', 'callback' => [$this, 'heartbeat'], 'permission_callback' => [$this, 'canLearn'],
		]);
		$this->route('/interactions/(?P<id>\d+)/attempt', [
			'methods' => 'POST', 'callback' => [$this, 'attempt'], 'permission_callback' => [$this, 'canAttempt'],
			'args'    => ['selected_index' => ['required' => true, 'type' => 'integer']],
		]);
	}

	/** Enrolment for attempts is checked in AttemptService (it resolves the lesson from the interaction). */
	public function canAttempt() {
		return $this->loggedIn();
	}

	public function player(\WP_REST_Request $req) {
		$lessonId = (int) $req['id'];
		$userId   = get_current_user_id();

		$source = PlayerSource::forLesson($lessonId);
		if (!$source) {
			return new \WP_Error('nimikh_no_video', __('This lesson has no video yet.', 'nimikh-lms'), ['status' => 404]);
		}
		$duration = $this->progress->duration($lessonId);

		$this->progressRepo->touch($userId, $lessonId);
		$state = $this->progress->state($userId, $lessonId);

		$items = [];
		foreach ($this->interactions->forLesson($lessonId) as $it) {
			$s = $state['summaries'][$it['id']] ?? ['attempts' => 0, 'ever_correct' => false];
			$items[] = [
				'id'               => $it['id'],
				'at_second'        => $it['at_second'],
				'required'         => $it['required'],
				'allow_retry'      => $it['allow_retry'],
				'points'           => $it['points'],
				'rewind_to_second' => $it['rewind_to_second'],
				'resolved'         => \Nimikh\LMS\Questions\Grader::isResolved($s['ever_correct'], $s['attempts'], $it['allow_retry']),
				'question'         => [ // never includes correct_index or explanation
					'stem'    => $it['stem'],
					'options' => $it['options'],
					'lang'    => $it['lang'],
				],
			];
		}

		$courseId = $this->tutor->courseIdForLesson($lessonId);
		$ids      = $this->tutor->lessonIdsForCourse($courseId);
		$at       = array_search($lessonId, $ids, true);
		$nextUrl  = $at !== false && isset($ids[$at + 1]) ? (string) get_permalink($ids[$at + 1]) : '';

		$user  = wp_get_current_user();
		$phone = (string) get_user_meta($userId, 'billing_phone', true);

		return rest_ensure_response([
			'lesson_id'      => $lessonId,
			'title'          => get_the_title($lessonId),
			'duration'       => $duration,
			'source'         => $source,
			'captions'       => PlayerSource::captions($lessonId),
			'resume_second'  => $state['resume'],
			'furthest'       => $state['furthest'],
			'percent'        => $state['percent'],
			'completed'      => $state['completed'],
			'gate'           => $state['gate'],
			'next_url'       => $nextUrl !== '' ? esc_url_raw($nextUrl) : '',
			'interactions'   => $items,
			'watermark'      => $phone !== '' ? $phone : $user->user_email,
			'settings'       => [
				'max_rate'           => (float) Settings::get('max_playback_rate'),
				'heartbeat_interval' => (int) Settings::get('heartbeat_interval'),
			],
		]);
	}

	public function heartbeat(\WP_REST_Request $req) {
		$result = $this->progress->heartbeat(get_current_user_id(), (int) $req['id'], [
			'second' => $req->get_param('second'),
			'ranges' => $req->get_param('ranges'),
		]);
		return is_wp_error($result) ? $result : rest_ensure_response($result);
	}

	public function attempt(\WP_REST_Request $req) {
		$result = $this->attempts->submit(get_current_user_id(), (int) $req['id'], (int) $req['selected_index']);
		return is_wp_error($result) ? $result : rest_ensure_response($result);
	}
}
