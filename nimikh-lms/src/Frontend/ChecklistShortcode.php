<?php
declare(strict_types=1);

namespace Nimikh\LMS\Frontend;

use Nimikh\LMS\Certificates\CertificateService;
use Nimikh\LMS\Exam\Cooldown;
use Nimikh\LMS\Exam\ExamService;
use Nimikh\LMS\Integrations\Tutor\TutorAdapter;

/** [nimikh_checklist course="ID"]: what is left before the certificate. Server-rendered, no JS. */
final class ChecklistShortcode {

	public function __construct(private TutorAdapter $tutor, private CertificateService $certificates, private ExamService $exams) {}

	public function register(): void {
		add_shortcode('nimikh_checklist', [$this, 'render']);
	}

	/** @param array<string, string>|string $atts */
	public function render($atts = []): string {
		$atts     = shortcode_atts(['course' => 0], (array) $atts, 'nimikh_checklist');
		$courseId = (int) $atts['course'] ?: (int) get_the_ID();
		$uid      = get_current_user_id();
		if ($uid <= 0 || $courseId <= 0 || !$this->tutor->isEnrolled($uid, $courseId)) {
			return '';
		}
		wp_enqueue_style('nimikh-tokens');
		wp_enqueue_style('nimikh-player');

		$list = $this->certificates->checklist($uid, $courseId);
		$out  = '<section class="nk-checklist" aria-label="' . esc_attr__('Certificate checklist', 'nimikh-lms') . '">';
		$out .= '<h3>' . esc_html__('Your path to the certificate', 'nimikh-lms') . '</h3>';
		$out .= sprintf('<progress max="100" value="%d">%d%%</progress>', $list['percent'], $list['percent']);
		$out .= '<ul>';
		foreach ($list['items'] as $item) {
			$out .= sprintf(
				'<li data-done="%d"><span aria-hidden="true">%s</span> %s</li>',
				$item['done'] ? 1 : 0,
				$item['done'] ? '✔' : '○',
				esc_html(self::label($item))
			);
		}
		$out .= '</ul>';
		$out .= $this->examNotes($uid, $courseId);
		if ($list['certificate']) {
			$out .= '<p class="nk-checklist__done">' . esc_html__('You earned it', 'nimikh-lms') . '</p>';
		}
		return $out . '</section>';
	}

	/** After a failed final exam: when the retake opens and which lessons to rewatch. */
	private function examNotes(int $uid, int $courseId): string {
		$st = $this->exams->status($uid, $courseId);
		if (!$st['has_exam'] || $st['passed'] || $st['best_percent'] === null) {
			return '';
		}
		$out = '<div class="nk-checklist__exam"><p>' . esc_html(sprintf(
			/* translators: 1: best score percent, 2: pass mark percent */
			__('Your best exam score is %1$s%%; the pass mark is %2$s%%.', 'nimikh-lms'),
			rtrim(rtrim(number_format($st['best_percent'], 1), '0'), '.'),
			rtrim(rtrim(number_format($st['pass_percent'], 1), '0'), '.')
		)) . '</p>';
		if ($st['cooldown_remaining'] > 0) {
			$out .= '<p>' . esc_html(sprintf(
				/* translators: %s: time left, e.g. "5 h 20 min" */
				__('You can retake the exam in %s.', 'nimikh-lms'),
				Cooldown::human($st['cooldown_remaining'])
			)) . '</p>';
		}
		$rewatch = $this->exams->rewatch($uid, $courseId);
		if ($rewatch) {
			$out .= '<p>' . esc_html__('Lessons worth rewatching:', 'nimikh-lms') . '</p><ul>';
			foreach ($rewatch as $r) {
				$out .= sprintf('<li><a href="%s">%s</a></li>', esc_url($r['url']), esc_html($r['title']));
			}
			$out .= '</ul>';
		}
		return $out . '</div>';
	}

	/** @param array{key:string, done:bool, current:?float, target:?float} $item */
	private static function label(array $item): string {
		$cur = $item['current'] === null ? 0 : (float) $item['current'];
		$tgt = (float) $item['target'];
		$fmt = static fn(float $n): string => rtrim(rtrim(number_format($n, 1), '0'), '.');
		return match ($item['key']) {
			/* translators: 1: lessons completed, 2: total lessons */
			'lessons' => sprintf(__('Complete all lessons (%1$s of %2$s)', 'nimikh-lms'), $fmt($cur), $fmt($tgt)),
			/* translators: %s: pass mark percentage */
			'exam'    => sprintf(__('Pass the final exam (%s%% needed)', 'nimikh-lms'), $fmt($tgt)),
			/* translators: %s: minimum average percentage */
			default   => sprintf(__('Average at least %s%% on in-video questions', 'nimikh-lms'), $fmt($tgt)),
		};
	}
}
