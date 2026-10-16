<?php
declare(strict_types=1);

namespace Nimikh\LMS\Scorm;

use Nimikh\LMS\Support\Installer;
use Nimikh\LMS\Support\Time;

final class TrackingRepository {

	/** @return array{version:string, data:array<string,string>, status:string, score:?float, total_seconds:int, session_seconds:int, started_at:?string, completed_at:?string}|null */
	public function find(int $userId, int $lessonId): ?array {
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Installer::table('scorm_tracking') . ' WHERE user_id = %d AND lesson_id = %d', $userId, $lessonId), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if (!$row) {
			return null;
		}
		$data = json_decode((string) $row['data'], true);
		return [
			'version'         => (string) $row['version'],
			'data'            => is_array($data) ? array_map('strval', $data) : [],
			'status'          => (string) $row['status'],
			'score'           => $row['score'] === null ? null : (float) $row['score'],
			'total_seconds'   => (int) $row['total_seconds'],
			'session_seconds' => (int) $row['session_seconds'],
			'started_at'      => $row['started_at'] ?: null,
			'completed_at'    => $row['completed_at'] ?: null,
		];
	}

	public function isCompleted(int $userId, int $lessonId): bool {
		$row = $this->find($userId, $lessonId);
		return $row !== null && $row['completed_at'] !== null;
	}

	/** Start of a launch: previous session time is folded into the total and the clock restarts. */
	public function startSession(int $userId, int $lessonId, string $version): void {
		global $wpdb;
		$t   = Installer::table('scorm_tracking');
		$now = Time::nowGmt();
		if ($this->find($userId, $lessonId) === null) {
			$wpdb->insert($t, ['user_id' => $userId, 'lesson_id' => $lessonId, 'version' => $version, 'data' => '{}', 'started_at' => $now, 'updated_at' => $now]);
			return;
		}
		$wpdb->query($wpdb->prepare("UPDATE $t SET total_seconds = total_seconds + session_seconds, session_seconds = 0, started_at = %s, version = %s WHERE user_id = %d AND lesson_id = %d", $now, $version, $userId, $lessonId)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @param array<string,string> $data */
	public function save(int $userId, int $lessonId, array $data, string $status, ?float $score, int $sessionSeconds, bool $completed): void {
		global $wpdb;
		$t   = Installer::table('scorm_tracking');
		$now = Time::nowGmt();
		$sql = "UPDATE $t SET data = %s, status = %s, score = %s, session_seconds = %d, updated_at = %s"
			. ($completed ? ', completed_at = COALESCE(completed_at, %s)' : '') . ' WHERE user_id = %d AND lesson_id = %d';
		$args = [(string) wp_json_encode($data), $status, $score === null ? null : (string) $score, $sessionSeconds, $now];
		if ($completed) {
			$args[] = $now;
		}
		$args[] = $userId;
		$args[] = $lessonId;
		$wpdb->query($wpdb->prepare($sql, ...$args)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
