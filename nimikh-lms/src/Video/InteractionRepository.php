<?php
declare(strict_types=1);

namespace Nimikh\LMS\Video;

use Nimikh\LMS\Support\Installer;

final class InteractionRepository {

	/** Interactions joined with their question. Answers are included; callers must strip them for learners. */
	public function forLesson(int $lessonId): array {
		global $wpdb;
		$i = Installer::table('video_interactions');
		$q = Installer::table('questions');
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT i.*, q.stem, q.options, q.correct_index, q.explanation, q.lang
			 FROM $i i JOIN $q q ON q.id = i.question_id
			 WHERE i.lesson_id = %d ORDER BY i.at_second ASC, i.id ASC",
			$lessonId
		), ARRAY_A) ?: [];
		return array_map([self::class, 'hydrate'], $rows);
	}

	public function find(int $id): ?array {
		global $wpdb;
		$i = Installer::table('video_interactions');
		$q = Installer::table('questions');
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT i.*, q.stem, q.options, q.correct_index, q.explanation, q.lang
			 FROM $i i JOIN $q q ON q.id = i.question_id WHERE i.id = %d",
			$id
		), ARRAY_A);
		return $row ? self::hydrate($row) : null;
	}

	/** @param array<string, mixed> $d */
	public function create(int $lessonId, int $questionId, array $d): int {
		global $wpdb;
		$wpdb->insert(Installer::table('video_interactions'), self::columns($d) + [
			'lesson_id'   => $lessonId,
			'question_id' => $questionId,
		]);
		return (int) $wpdb->insert_id;
	}

	/** @param array<string, mixed> $d */
	public function update(int $id, array $d): void {
		global $wpdb;
		$wpdb->update(Installer::table('video_interactions'), self::columns($d), ['id' => $id]);
	}

	public function delete(int $id): void {
		global $wpdb;
		$wpdb->delete(Installer::table('video_interactions'), ['id' => $id]);
		$wpdb->delete(Installer::table('interaction_attempts'), ['interaction_id' => $id]);
	}

	/** @param array<string, mixed> $d */
	private static function columns(array $d): array {
		$cols = [
			'at_second'   => max(0, (int) ($d['at_second'] ?? 0)),
			'required'    => empty($d['required']) ? 0 : 1,
			'allow_retry' => array_key_exists('allow_retry', $d) && !$d['allow_retry'] ? 0 : 1,
			'points'      => max(0, (int) ($d['points'] ?? 1)),
		];
		$rewind = $d['rewind_to_second'] ?? null;
		$cols['rewind_to_second'] = ($rewind === null || $rewind === '') ? null : max(0, (int) $rewind);
		return $cols;
	}

	/** @param array<string, mixed> $row */
	private static function hydrate(array $row): array {
		foreach (['id', 'lesson_id', 'question_id', 'at_second', 'points', 'correct_index'] as $k) {
			$row[$k] = (int) $row[$k];
		}
		$row['required']         = (bool) $row['required'];
		$row['allow_retry']      = (bool) $row['allow_retry'];
		$row['rewind_to_second'] = $row['rewind_to_second'] === null ? null : (int) $row['rewind_to_second'];
		$row['options']          = json_decode((string) $row['options'], true) ?: [];
		return $row;
	}
}
