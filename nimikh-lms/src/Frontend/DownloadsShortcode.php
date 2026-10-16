<?php
declare(strict_types=1);

namespace Nimikh\LMS\Frontend;

use Nimikh\LMS\Video\FrontendPlayer;

/** [nimikh_downloads]: the learner's downloaded lessons on this device, with a remove button for each. */
final class DownloadsShortcode {

	public function register(): void {
		add_shortcode('nimikh_downloads', [$this, 'render']);
	}

	public function render(): string {
		if (!is_user_logged_in()) {
			return '';
		}
		wp_enqueue_style('nimikh-tokens');
		wp_enqueue_style('nimikh-player');
		wp_enqueue_script('nimikh-offline', NIMIKH_LMS_URL . 'assets/js/offline.js', [], NIMIKH_LMS_VERSION, ['in_footer' => true, 'strategy' => 'defer']);
		static $done = false;
		if (!$done) {
			$done = true;
			wp_localize_script('nimikh-offline', 'NimikhOffline', FrontendPlayer::offlineConfig());
		}
		return '<section class="nk-downloads" aria-label="' . esc_attr__('Downloaded lessons', 'nimikh-lms') . '"></section>';
	}
}
