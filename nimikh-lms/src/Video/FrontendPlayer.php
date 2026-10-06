<?php
declare(strict_types=1);

namespace Nimikh\LMS\Video;

use Nimikh\LMS\Integrations\Tutor\FrontendPlayerGuard;
use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Support\Roles;

/** Mounts the learner player on lesson pages and exposes the [nimikh_player] shortcode. */
final class FrontendPlayer {

	public function __construct(private TutorAdapter $tutor, private InteractionRepository $interactions) {}

	public function register(): void {
		add_shortcode('nimikh_player', [$this, 'shortcode']);
		add_filter('the_content', [$this, 'prependToLesson'], 5);
		add_action('wp_enqueue_scripts', [$this, 'registerAssets']);
	}

	public function registerAssets(): void {
		$v = NIMIKH_LMS_VERSION;
		wp_register_style('nimikh-tokens', NIMIKH_LMS_URL . 'assets/css/tokens.css', [], $v);
		wp_register_style('nimikh-player', NIMIKH_LMS_URL . 'assets/css/player.css', ['nimikh-tokens'], $v);
		wp_register_script('nimikh-player', NIMIKH_LMS_URL . 'assets/js/player.js', [], $v, ['in_footer' => true, 'strategy' => 'defer']);
	}

	public function prependToLesson(string $content): string {
		if (!is_singular(TutorAdapter::LESSON_POST_TYPE) || !in_the_loop() || !is_main_query()) {
			return $content;
		}
		$id = (int) get_the_ID();
		return FrontendPlayerGuard::isInteractive($id) ? $this->render($id) . $content : $content;
	}

	/** @param array<string, string>|string $atts */
	public function shortcode($atts): string {
		$atts = shortcode_atts(['lesson' => 0], (array) $atts, 'nimikh_player');
		return $this->render((int) $atts['lesson'] ?: (int) get_the_ID());
	}

	public function render(int $lessonId): string {
		if (!is_user_logged_in()) {
			return '<p class="nk-notice">' . esc_html__('Please log in to watch this lesson.', 'nimikh-lms') . '</p>';
		}
		wp_enqueue_style('nimikh-player');
		wp_enqueue_script('nimikh-player');
		wp_localize_script('nimikh-player', 'NimikhPlayer', [
			'restUrl' => esc_url_raw(rest_url('nimikh/v1')),
			'nonce'   => wp_create_nonce('wp_rest'),
			'hlsSrc'  => 'https://cdnjs.cloudflare.com/ajax/libs/hls.js/1.5.13/hls.min.js',
			'i18n'    => [
				'quickCheck'  => __('Quick check!', 'nimikh-lms'),
				'continue'    => __('Continue', 'nimikh-lms'),
				'submit'      => __('Check answer', 'nimikh-lms'),
				'skip'        => __('Skip', 'nimikh-lms'),
				'correct'     => __('Correct', 'nimikh-lms'),
				'tryAgain'    => __('Not quite. Have another go.', 'nimikh-lms'),
				'rewind'      => __("Let's watch that part again", 'nimikh-lms'),
				'answerIs'    => __('The correct answer is highlighted.', 'nimikh-lms'),
				'mustAnswer'  => __('Answer the question to continue', 'nimikh-lms'),
				'speedCapped' => __('Playback speed is limited for this course', 'nimikh-lms'),
				'completed'   => __('Lesson complete', 'nimikh-lms'),
				'loadError'   => __('Could not load the video. Please refresh.', 'nimikh-lms'),
				'saving'      => __('Progress saved', 'nimikh-lms'),
			],
		]);
		return sprintf('<div class="nk-player" data-lesson="%d" data-mode="learner"></div>', $lessonId);
	}

	/** Instructor-side mount used by the lesson metabox. */
	public static function editorMount(int $lessonId): string {
		return sprintf(
			'<div class="nk-editor" data-lesson="%d" data-can="%d" data-permalink="%s"></div>',
			$lessonId,
			current_user_can(Roles::CAP_AUTHOR) ? 1 : 0,
			esc_url((string) get_permalink($lessonId))
		);
	}
}
