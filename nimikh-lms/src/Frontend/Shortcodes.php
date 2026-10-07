<?php
declare(strict_types=1);

namespace Nimikh\LMS\Frontend;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Support\Settings;

/**
 * [nimikh_discussion lesson="ID"], [nimikh_live course="ID"]. The discussion is appended to lesson
 * pages automatically; add [nimikh_live] to a course page (or a widget) to list upcoming classes.
 */
final class Shortcodes {

	public function __construct(private TutorAdapter $tutor) {}

	public function register(): void {
		add_shortcode('nimikh_discussion', [$this, 'discussion']);
		add_shortcode('nimikh_live', [$this, 'live']);
		add_filter('the_content', [$this, 'appendDiscussion'], 20);
	}

	public function appendDiscussion(string $content): string {
		if (!is_singular(TutorAdapter::LESSON_POST_TYPE) || !in_the_loop() || !is_main_query() || !Settings::get('discussion_enabled') || !is_user_logged_in()) {
			return $content;
		}
		return $content . $this->discussion(['lesson' => (int) get_the_ID()]);
	}

	/** @param array<string, string>|string $atts */
	public function discussion($atts = []): string {
		$atts     = shortcode_atts(['lesson' => 0], (array) $atts, 'nimikh_discussion');
		$lessonId = (int) $atts['lesson'] ?: (int) get_the_ID();
		if (!is_user_logged_in() || $lessonId <= 0 || !Settings::get('discussion_enabled')) {
			return '';
		}
		$this->assets('discussion');
		$mod = $this->tutor->canManageCourse(get_current_user_id(), $this->tutor->courseIdForLesson($lessonId));
		return sprintf('<section class="nk-discussion" data-lesson="%d" data-user="%d" data-moderator="%d" aria-label="%s"></section>', $lessonId, get_current_user_id(), $mod ? 1 : 0, esc_attr__('Discussion', 'nimikh-lms'));
	}

	/** @param array<string, string>|string $atts */
	public function live($atts = []): string {
		$atts     = shortcode_atts(['course' => 0], (array) $atts, 'nimikh_live');
		$courseId = (int) $atts['course'] ?: (int) get_the_ID();
		if (!is_user_logged_in() || $courseId <= 0) {
			return '';
		}
		$this->assets('live');
		return sprintf('<section class="nk-live" data-course="%d" aria-label="%s"></section>', $courseId, esc_attr__('Live classes', 'nimikh-lms'));
	}

	private function assets(string $script): void {
		wp_enqueue_style('nimikh-tokens');
		wp_enqueue_style('nimikh-player');
		wp_enqueue_script('nimikh-' . $script, NIMIKH_LMS_URL . 'assets/js/' . $script . '.js', [], NIMIKH_LMS_VERSION, ['in_footer' => true, 'strategy' => 'defer']);
		static $localized = [];
		if (!isset($localized[$script])) {
			$localized[$script] = true;
			wp_localize_script('nimikh-' . $script, 'NimikhApp', [
				'restUrl' => esc_url_raw(rest_url('nimikh/v1')),
				'nonce'   => wp_create_nonce('wp_rest'),
				'i18n'    => [
					'askPlaceholder' => __('Ask a question about this lesson…', 'nimikh-lms'),
					'ask'            => __('Ask', 'nimikh-lms'),
					'reply'          => __('Reply', 'nimikh-lms'),
					'send'           => __('Send', 'nimikh-lms'),
					'delete'         => __('Delete', 'nimikh-lms'),
					'hide'           => __('Hide', 'nimikh-lms'),
					'unhide'         => __('Unhide', 'nimikh-lms'),
					'staff'          => __('Instructor', 'nimikh-lms'),
					'hidden'         => __('Hidden from learners', 'nimikh-lms'),
					'empty'          => __('No questions yet. Be the first to ask.', 'nimikh-lms'),
					'error'          => __('Something went wrong. Please try again.', 'nimikh-lms'),
					'noLive'         => __('No live classes scheduled.', 'nimikh-lms'),
					'join'           => __('Join class', 'nimikh-lms'),
					'opensSoon'      => __('Opens 10 minutes before start', 'nimikh-lms'),
					'liveNow'        => __('Live now', 'nimikh-lms'),
				],
			]);
		}
	}
}
