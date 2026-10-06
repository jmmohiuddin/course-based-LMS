<?php
declare(strict_types=1);

namespace Nimikh\LMS\Integrations\Tutor;

final class FrontendPlayerGuard {

	/** A lesson is "interactive" when it has a video duration set by the editor (source is checked at play time). */
	public static function isInteractive(int $lessonId): bool {
		return (int) get_post_meta($lessonId, 'nimikh_video_duration', true) > 0
			&& get_post_meta($lessonId, 'nimikh_interactive_enabled', true) === '1';
	}
}
