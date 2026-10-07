<?php
declare(strict_types=1);

namespace Nimikh\LMS\Rest;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Subscriptions\SubscriptionService;

final class SubscriptionController extends BaseController {

	public function __construct(TutorAdapter $tutor, private SubscriptionService $subs) {
		parent::__construct($tutor);
	}

	public function registerRoutes(): void {
		$this->route('/plans', [
			['methods' => 'GET', 'callback' => [$this, 'plans'], 'permission_callback' => '__return_true'],
			['methods' => 'POST', 'callback' => [$this, 'createPlan'], 'permission_callback' => [$this, 'canManage']],
		]);
		$this->route('/subscriptions', ['methods' => 'POST', 'callback' => [$this, 'grant'], 'permission_callback' => [$this, 'canManage']]);
		$this->route('/subscriptions/(?P<id>\d+)/cancel', ['methods' => 'POST', 'callback' => [$this, 'cancel'], 'permission_callback' => [$this, 'canManage']]);
	}

	public function plans() {
		// Public catalogue: names, durations and price labels only, never other users' data.
		return rest_ensure_response(array_map(static fn(array $p): array => [
			'id' => $p['id'], 'name' => $p['name'], 'duration_days' => $p['duration_days'], 'price_label' => $p['price_label'], 'course_count' => count($p['course_ids']),
		], $this->subs->plans()));
	}

	public function createPlan(\WP_REST_Request $req) {
		$p = $req->get_json_params() ?: $req->get_params();
		$courseIds = array_filter(array_map('intval', (array) ($p['course_ids'] ?? [])), fn(int $id): bool => get_post_type($id) === TutorAdapter::COURSE_POST_TYPE);
		if (trim((string) ($p['name'] ?? '')) === '' || !$courseIds || (int) ($p['duration_days'] ?? 0) < 1) {
			return new \WP_Error('nimikh_plan_invalid', __('A plan needs a name, at least one course and a duration.', 'nimikh-lms'), ['status' => 400]);
		}
		$id = $this->subs->createPlan((string) $p['name'], $courseIds, (int) $p['duration_days'], (string) ($p['price_label'] ?? ''));
		return new \WP_REST_Response($this->subs->plan($id), 201);
	}

	public function grant(\WP_REST_Request $req) {
		$p    = $req->get_json_params() ?: $req->get_params();
		$user = !empty($p['email']) ? get_user_by('email', sanitize_email((string) $p['email'])) : get_userdata((int) ($p['user_id'] ?? 0));
		if (!$user) {
			return new \WP_Error('nimikh_user', __('User not found.', 'nimikh-lms'), ['status' => 404]);
		}
		$res = $this->subs->grant((int) $user->ID, (int) ($p['plan_id'] ?? 0), 'admin:' . get_current_user_id());
		return is_wp_error($res) ? $res : new \WP_REST_Response($res, 201);
	}

	public function cancel(\WP_REST_Request $req) {
		return $this->subs->cancel((int) $req['id'])
			? rest_ensure_response(['cancelled' => true])
			: new \WP_Error('nimikh_not_found', __('No active subscription with that ID.', 'nimikh-lms'), ['status' => 404]);
	}
}
