<?php
declare(strict_types=1);

namespace Nimikh\LMS\Rest;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Reports\ReportService;

final class ReportController extends BaseController {

	public function __construct(TutorAdapter $tutor, private ReportService $reports) {
		parent::__construct($tutor);
	}

	public function registerRoutes(): void {
		$this->route('/reports/course/(?P<id>\d+)', [
			'methods' => 'GET', 'callback' => [$this, 'course'], 'permission_callback' => [$this, 'canReport'],
		]);
	}

	public function canReport(\WP_REST_Request $req) {
		$uid = get_current_user_id();
		if ($uid && $this->tutor->canManageCourse($uid, (int) $req['id'])) {
			return true;
		}
		return new \WP_Error('nimikh_forbidden', __('You do not manage this course.', 'nimikh-lms'), ['status' => 403]);
	}

	public function course(\WP_REST_Request $req) {
		$report = $this->reports->course((int) $req['id']);
		if ($req->get_param('format') === 'csv') {
			$csv = $this->reports->csv($report);
			$res = new \WP_REST_Response($csv);
			$res->header('Content-Type', 'text/csv; charset=utf-8');
			$res->header('Content-Disposition', 'attachment; filename="course-' . (int) $req['id'] . '-report.csv"');
			add_filter('rest_pre_serve_request', static function ($served, $result) use ($csv) {
				echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput -- CSV with formula-injection guard
				return true;
			}, 10, 2);
			return $res;
		}
		return rest_ensure_response($report);
	}
}
