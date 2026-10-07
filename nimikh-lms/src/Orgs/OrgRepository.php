<?php
declare(strict_types=1);

namespace Nimikh\LMS\Orgs;

use Nimikh\LMS\Support\Installer;

final class OrgRepository {

	/** @return array<string, mixed>|\WP_Error */
	public function create(string $name, string $slug, string $color, string $logoUrl) {
		global $wpdb;
		$name = mb_substr(sanitize_text_field($name), 0, 120);
		$slug = Brand::slug($slug !== '' ? $slug : $name);
		if ($name === '' || $slug === '') {
			return new \WP_Error('nimikh_org_name', __('An institute needs a name.', 'nimikh-lms'), ['status' => 400]);
		}
		if ($this->bySlug($slug)) {
			return new \WP_Error('nimikh_org_slug', __('That web address is taken.', 'nimikh-lms'), ['status' => 409]);
		}
		$wpdb->insert(Installer::table('orgs'), [
			'slug'        => $slug,
			'name'        => $name,
			'brand_color' => Brand::color($color),
			'logo_url'    => str_starts_with($logoUrl, 'https://') ? esc_url_raw($logoUrl, ['https']) : '',
			'status'      => 'active',
		]);
		return $this->find((int) $wpdb->insert_id) ?? [];
	}

	/** @return array<string, mixed>|null */
	public function find(int $id): ?array {
		global $wpdb;
		return $this->hydrate($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Installer::table('orgs') . ' WHERE id = %d', $id), ARRAY_A));
	}

	public function bySlug(string $slug): ?array {
		global $wpdb;
		return $this->hydrate($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Installer::table('orgs') . " WHERE slug = %s AND status = 'active'", $slug), ARRAY_A));
	}

	/** @return array<int, array<string, mixed>> */
	public function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results('SELECT * FROM ' . Installer::table('orgs') . ' ORDER BY name ASC', ARRAY_A) ?: [];
		return array_values(array_filter(array_map([$this, 'hydrate'], $rows)));
	}

	/** @return array<int, array<string, mixed>> orgs the user belongs to, with their role */
	public function forUser(int $userId): array {
		global $wpdb;
		$m    = Installer::table('org_members');
		$o    = Installer::table('orgs');
		$rows = $wpdb->get_results($wpdb->prepare("SELECT o.*, m.role AS member_role FROM $o o JOIN $m m ON m.org_id = o.id WHERE m.user_id = %d AND o.status = 'active'", $userId), ARRAY_A) ?: [];
		return array_values(array_filter(array_map([$this, 'hydrate'], $rows)));
	}

	public function role(int $orgId, int $userId): ?string {
		global $wpdb;
		$role = $wpdb->get_var($wpdb->prepare('SELECT role FROM ' . Installer::table('org_members') . ' WHERE org_id = %d AND user_id = %d', $orgId, $userId));
		return $role === null ? null : (string) $role;
	}

	public function setMember(int $orgId, int $userId, string $role): void {
		global $wpdb;
		$role  = $role === 'admin' ? 'admin' : 'member';
		$table = Installer::table('org_members');
		if ($this->role($orgId, $userId) === null) {
			$wpdb->insert($table, ['org_id' => $orgId, 'user_id' => $userId, 'role' => $role]);
		} else {
			$wpdb->update($table, ['role' => $role], ['org_id' => $orgId, 'user_id' => $userId]);
		}
	}

	public function removeMember(int $orgId, int $userId): void {
		global $wpdb;
		$wpdb->delete(Installer::table('org_members'), ['org_id' => $orgId, 'user_id' => $userId]);
	}

	/** @return int[] */
	public function memberIds(int $orgId): array {
		global $wpdb;
		return array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT user_id FROM ' . Installer::table('org_members') . ' WHERE org_id = %d', $orgId)) ?: []);
	}

	public function assignCourse(int $orgId, int $courseId): void {
		global $wpdb;
		$table = Installer::table('org_courses');
		if (!$wpdb->get_var($wpdb->prepare("SELECT 1 FROM $table WHERE org_id = %d AND course_id = %d", $orgId, $courseId))) {
			$wpdb->insert($table, ['org_id' => $orgId, 'course_id' => $courseId]);
		}
	}

	public function unassignCourse(int $orgId, int $courseId): void {
		global $wpdb;
		$wpdb->delete(Installer::table('org_courses'), ['org_id' => $orgId, 'course_id' => $courseId]);
	}

	/** @return int[] */
	public function courseIds(int $orgId): array {
		global $wpdb;
		return array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT course_id FROM ' . Installer::table('org_courses') . ' WHERE org_id = %d', $orgId)) ?: []);
	}

	/** The institute that owns a course (first assignment wins), for certificate/verify branding. */
	public function forCourse(int $courseId): ?array {
		global $wpdb;
		$o = Installer::table('orgs');
		$c = Installer::table('org_courses');
		return $this->hydrate($wpdb->get_row($wpdb->prepare("SELECT o.* FROM $o o JOIN $c c ON c.org_id = o.id WHERE c.course_id = %d AND o.status = 'active' ORDER BY o.id ASC LIMIT 1", $courseId), ARRAY_A));
	}

	/** @param array<string, mixed>|null $row */
	private function hydrate(?array $row): ?array {
		if (!$row) {
			return null;
		}
		return [
			'id'          => (int) $row['id'],
			'slug'        => (string) $row['slug'],
			'name'        => (string) $row['name'],
			'brand_color' => (string) $row['brand_color'],
			'logo_url'    => (string) $row['logo_url'],
			'status'      => (string) $row['status'],
		] + (isset($row['member_role']) ? ['role' => (string) $row['member_role']] : []);
	}
}
