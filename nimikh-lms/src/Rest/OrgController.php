<?php
declare(strict_types=1);

namespace Nimikh\LMS\Rest;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Orgs\OrgRepository;
use Nimikh\LMS\Orgs\OrgService;
use Nimikh\LMS\Orgs\PortalRoute;

final class OrgController extends BaseController {

	public function __construct(TutorAdapter $tutor, private OrgRepository $orgs, private OrgService $service) {
		parent::__construct($tutor);
	}

	public function registerRoutes(): void {
		$this->route('/orgs', [
			['methods' => 'GET', 'callback' => [$this, 'list'], 'permission_callback' => [$this, 'loggedIn']],
			['methods' => 'POST', 'callback' => [$this, 'create'], 'permission_callback' => [$this, 'canManage']],
		]);
		$this->route('/orgs/(?P<id>\d+)/members', ['methods' => 'POST', 'callback' => [$this, 'addMember'], 'permission_callback' => [$this, 'canAdminOrg']]);
		$this->route('/orgs/(?P<id>\d+)/members/(?P<uid>\d+)', ['methods' => 'DELETE', 'callback' => [$this, 'removeMember'], 'permission_callback' => [$this, 'canAdminOrg']]);
		$this->route('/orgs/(?P<id>\d+)/courses', ['methods' => 'POST', 'callback' => [$this, 'assignCourse'], 'permission_callback' => [$this, 'canAdminOrg']]);
		$this->route('/orgs/(?P<id>\d+)/enrol', ['methods' => 'POST', 'callback' => [$this, 'enrol'], 'permission_callback' => [$this, 'canAdminOrg']]);
		$this->route('/orgs/(?P<id>\d+)/report', ['methods' => 'GET', 'callback' => [$this, 'report'], 'permission_callback' => [$this, 'canAdminOrg']]);
	}

	public function canAdminOrg(\WP_REST_Request $req) {
		if (!is_user_logged_in()) {
			return new \WP_Error('nimikh_auth', __('Please log in.', 'nimikh-lms'), ['status' => 401]);
		}
		if (!$this->orgs->find((int) $req['id'])) {
			return new \WP_Error('nimikh_not_found', __('Institute not found.', 'nimikh-lms'), ['status' => 404]);
		}
		return $this->service->isOrgAdmin(get_current_user_id(), (int) $req['id'])
			? true
			: new \WP_Error('nimikh_forbidden', __('Institute admin access required.', 'nimikh-lms'), ['status' => 403]);
	}

	public function list() {
		$uid  = get_current_user_id();
		$orgs = current_user_can(\Nimikh\LMS\Support\Roles::CAP_MANAGE) ? $this->orgs->all() : $this->orgs->forUser($uid);
		return rest_ensure_response(array_map(fn(array $o): array => $o + ['portal_url' => PortalRoute::url($o['slug']), 'course_ids' => $this->orgs->courseIds($o['id'])], $orgs));
	}

	public function create(\WP_REST_Request $req) {
		$p   = $req->get_json_params() ?: $req->get_params();
		$org = $this->orgs->create((string) ($p['name'] ?? ''), (string) ($p['slug'] ?? ''), (string) ($p['brand_color'] ?? ''), (string) ($p['logo_url'] ?? ''));
		if (is_wp_error($org)) {
			return $org;
		}
		if (!empty($p['admin_email'])) {
			$this->service->addMemberByEmail($org['id'], (string) $p['admin_email'], 'admin');
		}
		return new \WP_REST_Response($org + ['portal_url' => PortalRoute::url($org['slug'])], 201);
	}

	public function addMember(\WP_REST_Request $req) {
		$p   = $req->get_json_params() ?: $req->get_params();
		$res = $this->service->addMemberByEmail((int) $req['id'], (string) ($p['email'] ?? ''), (string) ($p['role'] ?? 'member'));
		return is_wp_error($res) ? $res : new \WP_REST_Response($res, 201);
	}

	public function removeMember(\WP_REST_Request $req) {
		$this->orgs->removeMember((int) $req['id'], (int) $req['uid']);
		return rest_ensure_response(['removed' => true]);
	}

	public function assignCourse(\WP_REST_Request $req) {
		$p        = $req->get_json_params() ?: $req->get_params();
		$courseId = (int) ($p['course_id'] ?? 0);
		if (get_post_type($courseId) !== TutorAdapter::COURSE_POST_TYPE) {
			return new \WP_Error('nimikh_not_found', __('Course not found.', 'nimikh-lms'), ['status' => 404]);
		}
		if (!$this->service->canAssignCourse(get_current_user_id(), $courseId)) {
			return new \WP_Error('nimikh_forbidden', __('You can only add courses you manage.', 'nimikh-lms'), ['status' => 403]);
		}
		$this->orgs->assignCourse((int) $req['id'], $courseId);
		return rest_ensure_response(['assigned' => true]);
	}

	public function enrol(\WP_REST_Request $req) {
		$p    = $req->get_json_params() ?: $req->get_params();
		$rows = [];
		foreach ((array) ($p['learners'] ?? []) as $l) {
			$rows[] = is_array($l) ? [(string) ($l['email'] ?? ''), (string) ($l['name'] ?? '')] : [(string) $l, ''];
		}
		return rest_ensure_response($this->service->batchEnrol((int) $req['id'], (int) ($p['course_id'] ?? 0), $rows));
	}

	public function report(\WP_REST_Request $req) {
		return rest_ensure_response($this->service->report((int) $req['id']));
	}
}
