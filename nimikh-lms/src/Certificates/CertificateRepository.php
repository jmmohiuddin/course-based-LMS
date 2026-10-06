<?php
declare(strict_types=1);

namespace Nimikh\LMS\Certificates;

use Nimikh\LMS\Support\Installer;
use Nimikh\LMS\Support\Time;

final class CertificateRepository {

	public const STATUS_VALID   = 'valid';
	public const STATUS_REVOKED = 'revoked';

	/** Insert with a fresh random code, retrying on the (astronomically unlikely) collision. */
	public function insert(int $userId, int $courseId, int $templateId, ?float $score): ?array {
		global $wpdb;
		$table = Installer::table('certificates');
		for ($try = 0; $try < 5; $try++) {
			$code = CodeGenerator::generate();
			$ok   = $wpdb->insert($table, [
				'code'        => $code,
				'user_id'     => $userId,
				'course_id'   => $courseId,
				'template_id' => $templateId,
				'score'       => $score,
				'issued_at'   => Time::nowGmt(),
				'status'      => self::STATUS_VALID,
			]);
			if ($ok) {
				return $this->findByCode($code);
			}
		}
		return null;
	}

	public function findByCode(string $code): ?array {
		global $wpdb;
		return $this->hydrate($wpdb->get_row($wpdb->prepare(
			'SELECT * FROM ' . Installer::table('certificates') . ' WHERE code = %s',
			$code
		), ARRAY_A));
	}

	public function findById(int $id): ?array {
		global $wpdb;
		return $this->hydrate($wpdb->get_row($wpdb->prepare(
			'SELECT * FROM ' . Installer::table('certificates') . ' WHERE id = %d',
			$id
		), ARRAY_A));
	}

	public function findValidFor(int $userId, int $courseId): ?array {
		global $wpdb;
		return $this->hydrate($wpdb->get_row($wpdb->prepare(
			'SELECT * FROM ' . Installer::table('certificates') . ' WHERE user_id = %d AND course_id = %d AND status = %s LIMIT 1',
			$userId,
			$courseId,
			self::STATUS_VALID
		), ARRAY_A));
	}

	/** @return array<int, array<string, mixed>> */
	public function forUser(int $userId): array {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			'SELECT * FROM ' . Installer::table('certificates') . ' WHERE user_id = %d ORDER BY issued_at DESC',
			$userId
		), ARRAY_A) ?: [];
		return array_map(fn($r) => $this->hydrate($r), $rows);
	}

	/** @return array<int, array<string, mixed>> */
	public function recent(int $limit = 100): array {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			'SELECT * FROM ' . Installer::table('certificates') . ' ORDER BY id DESC LIMIT %d',
			$limit
		), ARRAY_A) ?: [];
		return array_map(fn($r) => $this->hydrate($r), $rows);
	}

	public function attachFile(int $id, string $key, string $sha256): void {
		global $wpdb;
		$wpdb->update(Installer::table('certificates'), ['pdf_key' => $key, 'sha256' => $sha256], ['id' => $id]);
	}

	public function revoke(int $id, string $reason): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			Installer::table('certificates'),
			['status' => self::STATUS_REVOKED, 'revoked_reason' => mb_substr($reason, 0, 255)],
			['id' => $id]
		);
	}

	/** @param array<string, mixed>|null $row */
	private function hydrate(?array $row): ?array {
		if (!$row) {
			return null;
		}
		foreach (['id', 'user_id', 'course_id', 'template_id'] as $k) {
			$row[$k] = (int) $row[$k];
		}
		$row['score'] = $row['score'] === null ? null : (float) $row['score'];
		return $row;
	}
}
