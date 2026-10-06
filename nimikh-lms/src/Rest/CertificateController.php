<?php
declare(strict_types=1);

namespace Nimikh\LMS\Rest;

use Nimikh\LMS\Certificates\CertificateRepository;
use Nimikh\LMS\Certificates\CertificateService;
use Nimikh\LMS\Certificates\VerifyRoute;
use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Support\RateLimiter;

final class CertificateController extends BaseController {

	public function __construct(
		TutorAdapter $tutor,
		private CertificateRepository $certs,
		private CertificateService $service
	) {
		parent::__construct($tutor);
	}

	public function registerRoutes(): void {
		$this->route('/me/certificates', [
			'methods' => 'GET', 'callback' => [$this, 'mine'], 'permission_callback' => [$this, 'loggedIn'],
		]);
		$this->route('/verify/(?P<code>[A-Za-z0-9\-]+)', [
			'methods' => 'GET', 'callback' => [$this, 'verify'], 'permission_callback' => '__return_true',
		]);
		$this->route('/certificates/(?P<id>\d+)/revoke', [
			'methods' => 'POST', 'callback' => [$this, 'revoke'], 'permission_callback' => [$this, 'canManage'],
			'args'    => ['reason' => ['required' => true, 'type' => 'string']],
		]);
	}

	public function mine() {
		$out = [];
		foreach ($this->certs->forUser(get_current_user_id()) as $c) {
			$out[] = [
				'id'           => $c['id'],
				'code'         => $c['code'],
				'course_id'    => $c['course_id'],
				'course'       => $this->tutor->courseTitle($c['course_id']),
				'score'        => $c['score'],
				'issued_at'    => $c['issued_at'],
				'status'       => $c['status'],
				'ready'        => (bool) $c['pdf_key'],
				'verify_url'   => VerifyRoute::url($c['code']),
				'download_url' => home_url('/certificate/' . $c['code'] . '/download'),
			];
		}
		return rest_ensure_response($out);
	}

	public function verify(\WP_REST_Request $req) {
		if (!RateLimiter::hit('verify', RateLimiter::clientIp(), 30)) {
			return new \WP_Error('nimikh_rate_limited', __('Too many lookups.', 'nimikh-lms'), ['status' => 429]);
		}
		$result = $this->service->verify((string) $req['code']);
		$res    = rest_ensure_response($result);
		$res->set_status($result['status'] === 'not_found' ? 404 : 200);
		$res->header('Cache-Control', 'public, max-age=300');
		return $res;
	}

	public function revoke(\WP_REST_Request $req) {
		$reason = sanitize_text_field((string) $req['reason']);
		if ($reason === '') {
			return new \WP_Error('nimikh_reason', __('A reason is required.', 'nimikh-lms'), ['status' => 400]);
		}
		if (!$this->certs->findById((int) $req['id'])) {
			return new \WP_Error('nimikh_not_found', __('Certificate not found.', 'nimikh-lms'), ['status' => 404]);
		}
		$this->service->revoke((int) $req['id'], $reason);
		return rest_ensure_response(['revoked' => true]);
	}
}
