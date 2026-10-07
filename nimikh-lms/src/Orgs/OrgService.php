<?php
declare(strict_types=1);

namespace Nimikh\LMS\Orgs;

use Nimikh\LMS\Certificates\CertificateRepository;
use Nimikh\LMS\Certificates\CertificateService;
use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Support\BatchEnrol;
use Nimikh\LMS\Support\Roles;

/**
 * Phase 3 institute portals. This is deliberately tenant-light: one WordPress, many institutes, with
 * branded portal + certificates and org admins who only ever see their own members. Full site isolation
 * (WordPress multisite) stays an option for the largest tenants.
 */
final class OrgService {

	public function __construct(
		private OrgRepository $orgs,
		private TutorAdapter $tutor,
		private CertificateService $certificates,
		private CertificateRepository $certs
	) {}

	public function isOrgAdmin(int $userId, int $orgId): bool {
		return $userId > 0 && (user_can($userId, Roles::CAP_MANAGE) || $this->orgs->role($orgId, $userId) === 'admin');
	}

	/** @return array<string, mixed>|\WP_Error */
	public function addMemberByEmail(int $orgId, string $email, string $role) {
		$user = get_user_by('email', sanitize_email($email));
		if (!$user) {
			return new \WP_Error('nimikh_user', __('No account with that email. Use batch enrolment to create accounts.', 'nimikh-lms'), ['status' => 404]);
		}
		$this->orgs->setMember($orgId, (int) $user->ID, $role);
		return ['user_id' => (int) $user->ID, 'role' => $role === 'admin' ? 'admin' : 'member'];
	}

	/** An org admin may assign only courses they can manage themselves (site admins: any). */
	public function canAssignCourse(int $userId, int $courseId): bool {
		return user_can($userId, Roles::CAP_MANAGE) || $this->tutor->canManageCourse($userId, $courseId);
	}

	/** @return array{enrolled:int, created:int, errors:string[]} */
	public function batchEnrol(int $orgId, int $courseId, array $rows): array {
		if (!in_array($courseId, $this->orgs->courseIds($orgId), true)) {
			return ['enrolled' => 0, 'created' => 0, 'errors' => [__('That course is not assigned to this institute.', 'nimikh-lms')]];
		}
		return (new BatchEnrol($this->tutor))->run($rows, $courseId, function (int $uid) use ($orgId): void {
			if ($this->orgs->role($orgId, $uid) === null) {
				$this->orgs->setMember($orgId, $uid, 'member');
			}
		});
	}

	/**
	 * Completion report for an institute: only its own members, per course. Never exposes other tenants' learners.
	 *
	 * @return array<string, mixed>
	 */
	public function report(int $orgId): array {
		$members = $this->orgs->memberIds($orgId);
		$courses = [];
		foreach ($this->orgs->courseIds($orgId) as $courseId) {
			$enrolled = array_values(array_intersect($this->tutor->enrolledUserIds($courseId), $members));
			$rows     = [];
			$done     = 0;
			foreach ($enrolled as $uid) {
				$u    = get_userdata($uid);
				$p    = $this->certificates->courseProgress($uid, $courseId);
				$cert = $this->certs->findValidFor($uid, $courseId);
				$done += $cert ? 1 : 0;
				$rows[] = [
					'user_id'           => $uid,
					'name'              => $u ? $u->display_name : '',
					'email'             => $u ? $u->user_email : '',
					'lessons_completed' => $p['lessons_completed'],
					'lessons_total'     => $p['lessons_total'],
					'exam_percent'      => $p['exam_percent'],
					'certificate'       => $cert['code'] ?? null,
				];
			}
			$courses[] = [
				'course_id'       => $courseId,
				'title'           => $this->tutor->courseTitle($courseId),
				'enrolled'        => count($enrolled),
				'completed'       => $done,
				'completion_rate' => $enrolled ? round($done / count($enrolled) * 100, 1) : 0.0,
				'learners'        => $rows,
			];
		}
		return ['org_id' => $orgId, 'members' => count($members), 'courses' => $courses];
	}
}
