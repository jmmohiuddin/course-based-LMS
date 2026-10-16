<?php
declare(strict_types=1);

namespace Nimikh\LMS\Rest;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Scorm\PackageStore;
use Nimikh\LMS\Scorm\ScormService;
use Nimikh\LMS\Support\RateLimiter;

final class ScormController extends BaseController {

	public function __construct(TutorAdapter $tutor, private ScormService $scorm) {
		parent::__construct($tutor);
	}

	public function registerRoutes(): void {
		$id = '/lessons/(?P<id>\d+)/scorm';
		$this->route($id, [
			['methods' => 'GET', 'callback' => [$this, 'info'], 'permission_callback' => [$this, 'canAuthorLesson']],
			['methods' => 'POST', 'callback' => [$this, 'upload'], 'permission_callback' => [$this, 'canAuthorLesson']],
			['methods' => 'DELETE', 'callback' => [$this, 'remove'], 'permission_callback' => [$this, 'canAuthorLesson']],
		]);
		$this->route($id . '/launch', ['methods' => 'POST', 'callback' => [$this, 'launch'], 'permission_callback' => [$this, 'canLearn']]);
		$this->route($id . '/commit', ['methods' => 'POST', 'callback' => [$this, 'commit'], 'permission_callback' => [$this, 'canLearn']]);
	}

	public function info(\WP_REST_Request $req) {
		$p = PackageStore::forLesson((int) $req['id']);
		return rest_ensure_response($p ? ['present' => true] + array_intersect_key($p, array_flip(['version', 'title', 'launch', 'files', 'bytes', 'uploaded_at', 'mastery'])) : ['present' => false]);
	}

	public function upload(\WP_REST_Request $req) {
		$file = $req->get_file_params()['package'] ?? null;
		if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (!is_uploaded_file((string) $file['tmp_name']) && !apply_filters('nimikh_lms_allow_nonhttp_upload', false))) {
			return new \WP_Error('nimikh_scorm_upload', __('Upload a SCORM zip file. If it is large, check the server upload limits.', 'nimikh-lms'), ['status' => 400]);
		}
		$meta = PackageStore::install((int) $req['id'], (string) $file['tmp_name']);
		return is_wp_error($meta) ? $meta : new \WP_REST_Response(['present' => true] + array_intersect_key($meta, array_flip(['version', 'title', 'launch', 'files', 'bytes', 'uploaded_at', 'mastery'])), 201);
	}

	public function remove(\WP_REST_Request $req) {
		PackageStore::remove((int) $req['id']);
		return rest_ensure_response(['present' => false]);
	}

	public function launch(\WP_REST_Request $req) {
		$r = $this->scorm->launch(get_current_user_id(), (int) $req['id']);
		return is_wp_error($r) ? $r : rest_ensure_response($r);
	}

	public function commit(\WP_REST_Request $req) {
		if (!RateLimiter::hit('scorm', (string) get_current_user_id(), 120, 60)) {
			return new \WP_Error('nimikh_rate', __('Too many requests.', 'nimikh-lms'), ['status' => 429]);
		}
		$body = $req->get_json_params() ?: [];
		$r    = $this->scorm->commit(get_current_user_id(), (int) $req['id'], $body['data'] ?? [], !empty($body['finish']));
		return is_wp_error($r) ? $r : rest_ensure_response($r);
	}
}
