<?php
declare(strict_types=1);

namespace Nimikh\LMS\Admin;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Progress\CourseRules;
use Nimikh\LMS\Support\Roles;
use Nimikh\LMS\Video\FrontendPlayer;

/**
 * Authoring UI: course completion rules on the course screen, and the "Interactive video"
 * timeline editor on the lesson screen.
 */
final class Metaboxes {

	public function __construct(private TutorAdapter $tutor) {}

	public function register(): void {
		add_action('add_meta_boxes', [$this, 'add']);
		add_action('save_post', [$this, 'save'], 10, 2);
		add_action('admin_enqueue_scripts', [$this, 'assets']);
	}

	public function add(): void {
		add_meta_box('nimikh-rules', __('Nimikh completion rules', 'nimikh-lms'), [$this, 'rulesBox'], TutorAdapter::COURSE_POST_TYPE, 'side');
		add_meta_box('nimikh-video', __('Interactive video', 'nimikh-lms'), [$this, 'videoBox'], TutorAdapter::LESSON_POST_TYPE, 'normal', 'high');
	}

	public function rulesBox(\WP_Post $post): void {
		$r = CourseRules::forCourse($post->ID);
		wp_nonce_field('nimikh_rules_' . $post->ID, 'nimikh_rules_nonce');
		$rows = [
			'min_watch_percent' => __('Min % of each video watched', 'nimikh-lms'),
			'min_mcq_percent'   => __('Min average in-video MCQ % (0 = off)', 'nimikh-lms'),
			'exam_pass_percent' => __('Final exam pass mark %', 'nimikh-lms'),
		];
		foreach ($rows as $key => $label) {
			printf('<p><label>%s<br><input type="number" min="0" max="100" step="1" name="nimikh_rules[%s]" value="%s" class="small-text"></label></p>', esc_html($label), esc_attr($key), esc_attr((string) $r[$key]));
		}
		printf('<p><label>%s<br><input type="number" min="0" name="nimikh_rules[certificate_template_id]" value="%d" class="small-text"></label></p>', esc_html__('Certificate template ID (0 = default)', 'nimikh-lms'), (int) $r['certificate_template_id']);
		printf('<p><label>%s<br><input type="number" min="0" name="nimikh_rules[exam_cooldown_hours]" value="%d" class="small-text"></label></p>', esc_html__('Wait between failed exam attempts (hours, 0 = none)', 'nimikh-lms'), (int) $r['exam_cooldown_hours']);
		printf('<p><label>%s<br><input type="number" min="0" name="nimikh_exam_quiz_id" value="%d" class="small-text"></label></p>', esc_html__('Final exam quiz ID (0 = last quiz)', 'nimikh-lms'), (int) get_post_meta($post->ID, 'nimikh_exam_quiz_id', true));
	}

	public function videoBox(\WP_Post $post): void {
		wp_nonce_field('nimikh_video_' . $post->ID, 'nimikh_video_nonce');
		$enabled = get_post_meta($post->ID, 'nimikh_interactive_enabled', true) === '1';
		printf('<p><label><input type="checkbox" name="nimikh_interactive_enabled" value="1" %s> %s</label></p>', checked($enabled, true, false), esc_html__('Use the interactive player (anti-skip + MCQ pop-ups) for this lesson', 'nimikh-lms'));
		printf('<p><label>%s <input class="regular-text" type="text" name="nimikh_bunny_video_id" value="%s"></label></p>', esc_html__('Bunny video ID', 'nimikh-lms'), esc_attr((string) get_post_meta($post->ID, 'nimikh_bunny_video_id', true)));
		printf('<p><label>%s <input class="large-text" type="url" name="nimikh_video_url" value="%s" placeholder="https://… .m3u8 / .mp4"></label><br><small>%s</small></p>', esc_html__('…or direct video URL (dev / other CDN)', 'nimikh-lms'), esc_attr((string) get_post_meta($post->ID, 'nimikh_video_url', true)), esc_html__('Save the lesson, then add questions below. Duration is detected automatically.', 'nimikh-lms'));
		if (get_post_status($post) === 'auto-draft') {
			echo '<p><em>' . esc_html__('Save the lesson once to enable the timeline editor.', 'nimikh-lms') . '</em></p>';
			return;
		}
		echo FrontendPlayer::editorMount($post->ID); // phpcs:ignore WordPress.Security.EscapeOutput -- built with sprintf of ints
	}

	public function save(int $postId, \WP_Post $post): void {
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		if ($post->post_type === TutorAdapter::COURSE_POST_TYPE
			&& isset($_POST['nimikh_rules_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nimikh_rules_nonce'])), 'nimikh_rules_' . $postId)
			&& current_user_can('edit_post', $postId)) {
			CourseRules::save($postId, (array) wp_unslash($_POST['nimikh_rules'] ?? []));
			update_post_meta($postId, 'nimikh_exam_quiz_id', max(0, (int) ($_POST['nimikh_exam_quiz_id'] ?? 0)));
		}
		if ($post->post_type === TutorAdapter::LESSON_POST_TYPE
			&& isset($_POST['nimikh_video_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nimikh_video_nonce'])), 'nimikh_video_' . $postId)
			&& current_user_can('edit_post', $postId) && current_user_can(Roles::CAP_AUTHOR)) {
			update_post_meta($postId, 'nimikh_interactive_enabled', empty($_POST['nimikh_interactive_enabled']) ? '0' : '1');
			update_post_meta($postId, 'nimikh_bunny_video_id', sanitize_text_field(wp_unslash((string) ($_POST['nimikh_bunny_video_id'] ?? ''))));
			update_post_meta($postId, 'nimikh_video_url', esc_url_raw(wp_unslash((string) ($_POST['nimikh_video_url'] ?? ''))));
		}
	}

	public function assets(string $hook): void {
		$screen = get_current_screen();
		if (!$screen || $screen->post_type !== TutorAdapter::LESSON_POST_TYPE || !in_array($hook, ['post.php', 'post-new.php'], true)) {
			return;
		}
		$v = NIMIKH_LMS_VERSION;
		wp_enqueue_style('nimikh-editor', NIMIKH_LMS_URL . 'assets/css/editor.css', [], $v);
		wp_enqueue_style('nimikh-tokens', NIMIKH_LMS_URL . 'assets/css/tokens.css', [], $v);
		wp_enqueue_script('nimikh-editor', NIMIKH_LMS_URL . 'assets/js/editor.js', [], $v, ['in_footer' => true]);
		wp_localize_script('nimikh-editor', 'NimikhEditor', [
			'restUrl' => esc_url_raw(rest_url('nimikh/v1')),
			'nonce'   => wp_create_nonce('wp_rest'),
			'hlsSrc'  => 'https://cdnjs.cloudflare.com/ajax/libs/hls.js/1.5.13/hls.min.js',
		]);
	}
}
