<?php
declare(strict_types=1);

namespace Nimikh\LMS\Progress;

use Nimikh\LMS\Support\Installer;
use Nimikh\LMS\Support\Time;

final class ProgressRepository {

	public function find(int $userId, int $lessonId): ?array {
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare(
			'SELECT * FROM ' . Installer::table('watch_progress') . ' WHERE user_id = %d AND lesson_id = %d',
			$userId,
			$lessonId
		), ARRAY_A);
		if (!$row) {
			return null;
		}
		$row['furthest_second'] = (int) $row['furthest_second'];
		$row['last_second']     = (int) $row['last_second'];
		$row['percent']         = (float) $row['percent'];
		$row['ranges']          = json_decode((string) $row['watched_ranges'], true) ?: [];
		return $row;
	}

	/** Create the row on first player load so heartbeat timing has a baseline. */
	public function touch(int $userId, int $lessonId): void {
		global $wpdb;
		$now = Time::nowGmt();
		$wpdb->query($wpdb->prepare(
			'INSERT INTO ' . Installer::table('watch_progress')
			. ' (user_id, lesson_id, watched_ranges, updated_at) VALUES (%d, %d, %s, %s)
			   ON DUPLICATE KEY UPDATE updated_at = %s',
			$userId,
			$lessonId,
			'[]',
			$now,
			$now
		));
	}

	/**
	 * Single upsert (blueprint 6.6). Literals are repeated instead of VALUES() (deprecated in
	 * MySQL 8) and CASE is used instead of GREATEST/COALESCE so the statement is portable ANSI SQL. completed_at is written as a real NULL: wpdb::prepare would turn a PHP null into ''.
	 *
	 * @param array<int, array{0:int,1:int}> $ranges
	 */
	public function save(int $userId, int $lessonId, array $ranges, int $furthest, int $lastSecond, float $percent, bool $completed): void {
		global $wpdb;
		$now         = Time::nowGmt();
		$completedAt = $completed ? "'" . $now . "'" : 'NULL'; // $now is a fixed-format gmdate() string
		// Never reset an existing completion; only touch the column when completing now.
		$completeSql = $completed ? "completed_at = CASE WHEN completed_at IS NULL THEN $completedAt ELSE completed_at END," : '';
		$json        = wp_json_encode($ranges);

		$wpdb->query($wpdb->prepare(
			'INSERT INTO ' . Installer::table('watch_progress')
			. " (user_id, lesson_id, furthest_second, last_second, watched_ranges, percent, completed_at, updated_at)
			   VALUES (%d, %d, %d, %d, %s, %f, $completedAt, %s)
			   ON DUPLICATE KEY UPDATE
			     furthest_second = CASE WHEN furthest_second > %d THEN furthest_second ELSE %d END,
			     last_second = %d,
			     watched_ranges = %s,
			     percent = %f,
			     $completeSql
			     updated_at = %s",
			$userId,
			$lessonId,
			$furthest,
			$lastSecond,
			$json,
			$percent,
			$now,
			$furthest,
			$furthest,
			$lastSecond,
			$json,
			$percent,
			$now
		));
	}

	/**
	 * @param int[] $lessonIds
	 * @return int number of lessons completed by the user
	 */
	public function countCompleted(int $userId, array $lessonIds): int {
		global $wpdb;
		if (!$lessonIds) {
			return 0;
		}
		$ids = implode(',', array_map('intval', $lessonIds));
		return (int) $wpdb->get_var($wpdb->prepare(
			'SELECT COUNT(*) FROM ' . Installer::table('watch_progress')
			. " WHERE user_id = %d AND completed_at IS NOT NULL AND lesson_id IN ($ids)",
			$userId
		));
	}

	/** @return array<int, array<string, mixed>> all learners' rows for a lesson (reports) */
	public function forLesson(int $lessonId): array {
		global $wpdb;
		return $wpdb->get_results($wpdb->prepare(
			'SELECT user_id, percent, completed_at, watched_ranges FROM ' . Installer::table('watch_progress') . ' WHERE lesson_id = %d',
			$lessonId
		), ARRAY_A) ?: [];
	}
}
