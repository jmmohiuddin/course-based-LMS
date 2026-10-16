<?php
declare(strict_types=1);

namespace Nimikh\LMS\Frontend;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Leaderboard\LeaderboardService;

/** [nimikh_leaderboard course="ID" limit="10"]: opt-in ranking for a course. */
final class LeaderboardShortcode {

	public function __construct(private TutorAdapter $tutor, private LeaderboardService $board) {}

	public function register(): void {
		add_shortcode('nimikh_leaderboard', [$this, 'render']);
		add_action('admin_post_nimikh_toggle_leaderboard', [$this, 'toggle']);
	}

	/** @param array<string, string>|string $atts */
	public function render($atts = []): string {
		$atts     = shortcode_atts(['course' => 0, 'limit' => 10], (array) $atts, 'nimikh_leaderboard');
		$courseId = (int) $atts['course'] ?: (int) get_the_ID();
		$uid      = get_current_user_id();
		if ($uid <= 0 || $courseId <= 0 || !$this->tutor->isEnrolled($uid, $courseId)) {
			return '';
		}
		wp_enqueue_style('nimikh-tokens');
		wp_enqueue_style('nimikh-player');
		$data = $this->board->forCourse($courseId, $uid, max(3, min(50, (int) $atts['limit'])));

		$out = '<section class="nk-board" aria-label="' . esc_attr__('Leaderboard', 'nimikh-lms') . '"><h3>' . esc_html__('Leaderboard', 'nimikh-lms') . '</h3>';
		if ($data['top']) {
			$out .= '<ol class="nk-board__list">';
			foreach ($data['top'] as $row) {
				$out .= sprintf('<li value="%d"%s><span class="nk-board__name">%s</span> <span class="nk-board__score">%s</span></li>', $row['rank'], $row['me'] ? ' aria-current="true"' : '', esc_html($row['name']), esc_html(number_format_i18n($row['score'])));
			}
			$out .= '</ol>';
		} else {
			$out .= '<p>' . esc_html__('Nobody has joined the leaderboard yet.', 'nimikh-lms') . '</p>';
		}
		if ($data['me']) {
			$out .= '<p class="nk-board__me">' . esc_html(sprintf(
				/* translators: 1: rank, 2: number of learners, 3: points */
				__('Your rank: %1$d of %2$d (%3$s points). Only you can see this unless you join.', 'nimikh-lms'),
				$data['me']['rank'],
				$data['total'],
				number_format_i18n($data['me']['score'])
			)) . '</p>';
		}
		$out .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('nimikh_toggle_leaderboard', '_wpnonce', true, false)
			. '<input type="hidden" name="action" value="nimikh_toggle_leaderboard">'
			. sprintf('<label><input type="checkbox" name="optin" value="1" %s> %s</label> <button type="submit" class="nk-btn nk-btn--ghost">%s</button>',
				checked(LeaderboardService::optedIn($uid), true, false),
				esc_html__('Show me on leaderboards as first name and initial', 'nimikh-lms'),
				esc_html__('Save', 'nimikh-lms'))
			. '</form></section>';
		return $out;
	}

	public function toggle(): void {
		check_admin_referer('nimikh_toggle_leaderboard');
		$uid = get_current_user_id();
		if ($uid) {
			LeaderboardService::setOptIn($uid, !empty($_POST['optin']));
		}
		wp_safe_redirect(wp_get_referer() ?: home_url('/'));
		exit;
	}
}
