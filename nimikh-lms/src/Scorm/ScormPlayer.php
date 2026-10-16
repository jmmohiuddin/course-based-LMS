<?php
declare(strict_types=1);

namespace Nimikh\LMS\Scorm;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;

/** Shows a lesson's SCORM package in a sandboxed iframe: [nimikh_scorm lesson="ID"], and automatically on lessons that have one. */
final class ScormPlayer {

	public function register(): void {
		add_shortcode('nimikh_scorm', [$this, 'shortcode']);
		add_filter('the_content', [$this, 'prependToLesson'], 9);
	}

	public function prependToLesson(string $content): string {
		if (!is_singular(TutorAdapter::LESSON_POST_TYPE) || !in_the_loop() || !is_main_query()) {
			return $content;
		}
		$id = (int) get_the_ID();
		return PackageStore::forLesson($id) ? $this->render($id) . $content : $content;
	}

	/** @param array<string, string>|string $atts */
	public function shortcode($atts): string {
		$atts = shortcode_atts(['lesson' => 0], (array) $atts, 'nimikh_scorm');
		return $this->render((int) $atts['lesson'] ?: (int) get_the_ID());
	}

	public function render(int $lessonId): string {
		if (!PackageStore::forLesson($lessonId)) {
			return '';
		}
		if (!is_user_logged_in()) {
			return '<p class="nk-notice">' . esc_html__('Please log in to open this lesson.', 'nimikh-lms') . '</p>';
		}
		wp_enqueue_style('nimikh-tokens');
		wp_enqueue_style('nimikh-player');
		wp_enqueue_script('nimikh-scorm', NIMIKH_LMS_URL . 'assets/js/scorm-player.js', [], NIMIKH_LMS_VERSION, ['in_footer' => true, 'strategy' => 'defer']);
		wp_localize_script('nimikh-scorm', 'NimikhScorm', [
			'restUrl' => esc_url_raw(rest_url('nimikh/v1')),
			'nonce'   => wp_create_nonce('wp_rest'),
			'origin'  => home_url(),
			'i18n'    => [
				'loading'   => __('Loading the lesson…', 'nimikh-lms'),
				'error'     => __('Could not open this lesson. Please refresh.', 'nimikh-lms'),
				'completed' => __('Lesson complete', 'nimikh-lms'),
				'saved'     => __('Progress saved', 'nimikh-lms'),
				'title'     => __('Interactive lesson', 'nimikh-lms'),
				'score'     => __('Score', 'nimikh-lms'),
			],
		]);
		return sprintf('<div class="nk-scorm" data-lesson="%d"></div>', $lessonId);
	}
}
