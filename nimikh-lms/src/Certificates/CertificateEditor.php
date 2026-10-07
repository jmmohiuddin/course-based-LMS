<?php
declare(strict_types=1);

namespace Nimikh\LMS\Certificates;

use Nimikh\LMS\Support\Roles;

/** Drag-and-drop editor on the certificate template screen (blueprint 8.2 "Admin certificate templates"). */
final class CertificateEditor {

	public const META = 'nimikh_layout';
	private const POST_TYPE = 'nimikh_cert_template';

	public function register(): void {
		add_action('add_meta_boxes_' . self::POST_TYPE, [$this, 'addBox']);
		add_action('save_post_' . self::POST_TYPE, [$this, 'save'], 10, 1);
		add_action('admin_enqueue_scripts', [$this, 'assets']);
	}

	public function addBox(): void {
		add_meta_box('nimikh-cert-editor', __('Visual layout', 'nimikh-lms'), [$this, 'box'], self::POST_TYPE, 'normal', 'high');
	}

	public function box(\WP_Post $post): void {
		wp_nonce_field('nimikh_layout_' . $post->ID, 'nimikh_layout_nonce');
		$layout = Layout::sanitize(json_decode((string) get_post_meta($post->ID, self::META, true), true)) ?? Layout::defaultLayout();
		printf('<p class="description">%s</p>', esc_html__('Drag items on the page, then adjust them on the right. When a visual layout is saved it is used instead of the HTML below.', 'nimikh-lms'));
		printf(
			'<div id="nk-certed" data-layout="%s" data-default="%s"></div><input type="hidden" id="nk-layout-json" name="nimikh_layout" value="%s"><p><label><input type="checkbox" name="nimikh_layout_clear" value="1"> %s</label></p>',
			esc_attr((string) wp_json_encode($layout)),
			esc_attr((string) wp_json_encode(Layout::defaultLayout())),
			esc_attr((string) wp_json_encode($layout)),
			esc_html__('Remove the visual layout and use the HTML below', 'nimikh-lms')
		);
	}

	public function save(int $postId): void {
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		if (!isset($_POST['nimikh_layout_nonce'])
			|| !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nimikh_layout_nonce'])), 'nimikh_layout_' . $postId)
			|| !current_user_can(Roles::CAP_MANAGE)) {
			return;
		}
		if (!empty($_POST['nimikh_layout_clear'])) {
			delete_post_meta($postId, self::META);
			return;
		}
		$raw    = isset($_POST['nimikh_layout']) ? json_decode(wp_unslash((string) $_POST['nimikh_layout']), true) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by Layout::sanitize
		$layout = Layout::sanitize($raw);
		if ($layout === null) {
			delete_post_meta($postId, self::META);
			return;
		}
		update_post_meta($postId, self::META, wp_slash((string) wp_json_encode($layout)));
	}

	public function assets(string $hook): void {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || $screen->post_type !== self::POST_TYPE || !in_array($hook, ['post.php', 'post-new.php'], true)) {
			return;
		}
		wp_enqueue_style('nimikh-cert-editor', NIMIKH_LMS_URL . 'assets/css/cert-editor.css', [], NIMIKH_LMS_VERSION);
		wp_enqueue_script('nimikh-cert-editor', NIMIKH_LMS_URL . 'assets/js/cert-editor.js', [], NIMIKH_LMS_VERSION, ['in_footer' => true]);
		wp_localize_script('nimikh-cert-editor', 'NimikhCertEditor', [
			'pageW'  => Layout::PAGE_W,
			'pageH'  => Layout::PAGE_H,
			'types'  => [
				'name' => __('Learner name', 'nimikh-lms'), 'course' => __('Course title', 'nimikh-lms'), 'date' => __('Issue date', 'nimikh-lms'),
				'score' => __('Score', 'nimikh-lms'), 'code' => __('Certificate ID', 'nimikh-lms'), 'url' => __('Verify link', 'nimikh-lms'),
				'issuer' => __('Issuer', 'nimikh-lms'), 'text' => __('Text', 'nimikh-lms'), 'qr' => __('QR code', 'nimikh-lms'),
				'logo' => __('Logo', 'nimikh-lms'), 'image' => __('Image (signature)', 'nimikh-lms'), 'line' => __('Line', 'nimikh-lms'),
			],
			'sample' => [
				'name' => 'Rafi Ahmed', 'course' => 'Excel for Work', 'date' => '7 Oct 2026', 'score' => '91%', 'code' => 'K7M2Q9XA4B',
				'url' => 'https://example.com/verify/K7M2Q9XA4B', 'issuer' => 'Nimikh',
			],
			'i18n'   => [
				'add' => __('Add', 'nimikh-lms'), 'remove' => __('Remove', 'nimikh-lms'), 'reset' => __('Reset to default layout', 'nimikh-lms'),
				'x' => __('Left (mm)', 'nimikh-lms'), 'y' => __('Top (mm)', 'nimikh-lms'), 'w' => __('Width (mm)', 'nimikh-lms'),
				'size' => __('Text size (px)', 'nimikh-lms'), 'color' => __('Colour', 'nimikh-lms'), 'brand' => __('Use institute colour', 'nimikh-lms'),
				'align' => __('Alignment', 'nimikh-lms'), 'bold' => __('Bold', 'nimikh-lms'), 'text' => __('Text', 'nimikh-lms'),
				'src' => __('Image URL (https)', 'nimikh-lms'), 'background' => __('Page colour', 'nimikh-lms'),
				'frame' => __('Border width (mm, 0 = none)', 'nimikh-lms'), 'left' => __('Left', 'nimikh-lms'), 'center' => __('Centre', 'nimikh-lms'), 'right' => __('Right', 'nimikh-lms'),
				'select' => __('Select an item on the page to edit it.', 'nimikh-lms'), 'layers' => __('Items', 'nimikh-lms'),
			],
		]);
	}
}
