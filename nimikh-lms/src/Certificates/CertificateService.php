<?php
declare(strict_types=1);

namespace Nimikh\LMS\Certificates;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Orgs\Brand;
use Nimikh\LMS\Orgs\OrgRepository;
use Nimikh\LMS\Progress\CourseRules;
use Nimikh\LMS\Progress\ProgressRepository;
use Nimikh\LMS\Progress\ProgressService;
use Nimikh\LMS\Support\Settings;

final class CertificateService {

	public const RENDER_HOOK = 'nimikh_render_certificate';

	public function __construct(
		private CertificateRepository $certs,
		private CertificateRenderer $renderer,
		private ProgressRepository $progress,
		private ProgressService $progressService,
		private TutorAdapter $tutor
	) {}

	/** @return array{lessons_total:int, lessons_completed:int, has_exam:bool, exam_percent:?float, mcq_percent:?float} */
	public function courseProgress(int $userId, int $courseId): array {
		$lessons = $this->tutor->lessonIdsForCourse($courseId);
		$done    = 0;
		foreach ($lessons as $lessonId) {
			if ($this->tutor->isLessonComplete($userId, $lessonId)) {
				$done++;
			}
		}
		$quizId = $this->tutor->examQuizId($courseId);

		return [
			'lessons_total'     => count($lessons),
			'lessons_completed' => $done,
			'has_exam'          => $quizId > 0,
			'exam_percent'      => $this->tutor->bestQuizPercent($userId, $quizId),
			'mcq_percent'       => $this->progressService->mcqPercent($userId, $courseId),
		];
	}

	/** What is left before the learner earns the certificate (same rules as maybeIssue). */
	public function checklist(int $userId, int $courseId): array {
		$rules = CourseRules::forCourse($courseId);
		return Checklist::build($this->courseProgress($userId, $courseId), [
			'exam_pass_percent' => $rules['exam_pass_percent'],
			'min_mcq_percent'   => $rules['min_mcq_percent'],
		]) + ['certificate' => $this->certs->findValidFor($userId, $courseId) !== null];
	}

	/**
	 * Run the rule engine and issue a certificate if everything is met. Idempotent.
	 *
	 * @return array{issued:bool, certificate:?array, failures:string[]}
	 */
	public function maybeIssue(int $userId, int $courseId): array {
		if ($userId <= 0 || $courseId <= 0) {
			return ['issued' => false, 'certificate' => null, 'failures' => ['invalid']];
		}
		$existing = $this->certs->findValidFor($userId, $courseId);
		if ($existing) {
			return ['issued' => false, 'certificate' => $existing, 'failures' => []];
		}

		$rules    = CourseRules::forCourse($courseId);
		$progress = $this->courseProgress($userId, $courseId);
		$result   = RuleEngine::evaluate($progress, [
			'exam_pass_percent' => $rules['exam_pass_percent'],
			'min_mcq_percent'   => $rules['min_mcq_percent'],
		]);
		if (!$result['passed']) {
			return ['issued' => false, 'certificate' => null, 'failures' => $result['failures']];
		}

		$score = RuleEngine::headlineScore($progress);
		$cert  = $this->certs->insert($userId, $courseId, $rules['certificate_template_id'], $score);
		if (!$cert) {
			return ['issued' => false, 'certificate' => null, 'failures' => ['storage_error']];
		}

		$this->scheduleRender((int) $cert['id']);
		do_action('nimikh_certificate_issued', $cert);

		return ['issued' => true, 'certificate' => $cert, 'failures' => []];
	}

	/** Render in the background (Action Scheduler if present, else WP-Cron). Target: < 60 s (FR-07). */
	private function scheduleRender(int $certId): void {
		if (function_exists('as_enqueue_async_action')) {
			as_enqueue_async_action(self::RENDER_HOOK, [$certId], 'nimikh');
		} else {
			wp_schedule_single_event(time(), self::RENDER_HOOK, [$certId]);
		}
	}

	/** Background job: render the file, store it, hash it, email the learner. */
	public function render(int $certId): void {
		$cert = $this->certs->findById($certId);
		if (!$cert || $cert['status'] !== CertificateRepository::STATUS_VALID) {
			return;
		}
		$user = get_userdata($cert['user_id']);
		if (!$user) {
			return;
		}

		$verifyUrl = VerifyRoute::url($cert['code']);
		$org       = (new OrgRepository())->forCourse($cert['course_id']); // institute branding (phase 3)
		$vars      = [
			'name'   => $user->display_name,
			'course' => $this->tutor->courseTitle($cert['course_id']),
			'date'   => wp_date(get_option('date_format'), strtotime($cert['issued_at'] . ' UTC')),
			'score'  => $cert['score'] === null ? '' : rtrim(rtrim(number_format($cert['score'], 2), '0'), '.') . '%',
			'code'   => $cert['code'],
			'url'    => $verifyUrl,
			'issuer' => $org['name'] ?? (string) Settings::get('issuer_name'),
			'brand_color' => $org['brand_color'] ?? Brand::DEFAULT_COLOR,
			'logo'   => $org['logo_url'] ?? '',
		];

		$html = $this->renderer->html($cert['template_id'], $vars, $this->renderer->qrDataUri($verifyUrl));
		$file = $this->renderer->toFile($html);
		$key  = CertificateStorage::put($cert['code'], $file['ext'], $file['bytes']);
		$this->certs->attachFile($certId, $key, hash('sha256', $file['bytes']));

		$this->notify($user, $vars, $cert);
	}

	/** @param array<string, string> $vars */
	private function notify(\WP_User $user, array $vars, array $cert): void {
		$subject = sprintf(__('Your certificate for %s', 'nimikh-lms'), $vars['course']);
		$message = sprintf(
			/* translators: 1: name, 2: course, 3: verification URL */
			__("Hi %1\$s,\n\nCongratulations, you earned your certificate for \"%2\$s\".\nAnyone can verify it here: %3\$s\n\nCertificate ID: %4\$s\n", 'nimikh-lms'),
			$vars['name'],
			$vars['course'],
			$vars['url'],
			$cert['code']
		);
		wp_mail($user->user_email, $subject, $message);
		do_action('nimikh_notify_sms', $user->ID, 'certificate_issued', $vars);
	}

	/**
	 * Public verification result. "valid" also requires the stored file to still match its
	 * SHA-256 so a tampered or swapped file never verifies.
	 *
	 * @return array<string, mixed>
	 */
	public function verify(string $rawCode): array {
		$code = CodeGenerator::normalise($rawCode);
		$cert = $code === '' ? null : $this->certs->findByCode($code);
		if (!$cert) {
			return ['status' => 'not_found'];
		}
		$user = get_userdata($cert['user_id']);

		$status = $cert['status'] === CertificateRepository::STATUS_REVOKED ? 'revoked' : 'valid';
		if ($status === 'valid' && $cert['pdf_key'] && $cert['sha256']) {
			$bytes = CertificateStorage::get($cert['pdf_key']);
			if ($bytes === null || !hash_equals($cert['sha256'], hash('sha256', $bytes))) {
				$status = 'integrity_failed';
			}
		}

		$org = (new OrgRepository())->forCourse($cert['course_id']);

		return [
			'status'  => $status,
			'brand'   => ['color' => $org['brand_color'] ?? Brand::DEFAULT_COLOR, 'logo' => $org['logo_url'] ?? ''],
			'code'    => $cert['code'],
			'name'    => $user ? $user->display_name : __('(learner data erased)', 'nimikh-lms'),
			'course'  => $this->tutor->courseTitle($cert['course_id']),
			'date'    => wp_date(get_option('date_format'), strtotime($cert['issued_at'] . ' UTC')),
			'issued_at' => $cert['issued_at'],
			'score'   => $cert['score'],
			'issuer'  => $org['name'] ?? (string) Settings::get('issuer_name'),
			'reason'  => $status === 'revoked' ? (string) $cert['revoked_reason'] : '',
		];
	}

	public function revoke(int $certId, string $reason): bool {
		$ok = $this->certs->revoke($certId, $reason);
		if ($ok) {
			do_action('nimikh_certificate_revoked', $certId, $reason);
		}
		return $ok;
	}
}
