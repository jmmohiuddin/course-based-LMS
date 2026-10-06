<?php
declare(strict_types=1);

namespace Nimikh\LMS\Questions;

use Nimikh\LMS\Support\Installer;
use Nimikh\LMS\Support\Time;

final class QuestionRepository {

	/**
	 * Validate and normalise question input. Returns array or WP_Error.
	 *
	 * @param array<string, mixed> $in
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function normalise(array $in) {
		$stem    = trim(sanitize_textarea_field((string) ($in['stem'] ?? '')));
		$options = array_values(array_filter(array_map(
			static fn($o): string => trim(sanitize_text_field((string) $o)),
			(array) ($in['options'] ?? [])
		), static fn(string $o): bool => $o !== ''));
		$correct = isset($in['correct_index']) ? (int) $in['correct_index'] : -1;

		if ($stem === '') {
			return new \WP_Error('nimikh_question_stem', __('A question needs text.', 'nimikh-lms'), ['status' => 400]);
		}
		if (count($options) < 2 || count($options) > 6) {
			return new \WP_Error('nimikh_question_options', __('A question needs 2 to 6 options.', 'nimikh-lms'), ['status' => 400]);
		}
		if ($correct < 0 || $correct >= count($options)) {
			return new \WP_Error('nimikh_question_correct', __('Mark one option as correct.', 'nimikh-lms'), ['status' => 400]);
		}

		return [
			'stem'          => $stem,
			'options'       => $options,
			'correct_index' => $correct,
			'explanation'   => isset($in['explanation']) ? sanitize_textarea_field((string) $in['explanation']) : '',
			'tags'          => sanitize_text_field((string) ($in['tags'] ?? '')),
			'lang'          => preg_replace('/[^a-zA-Z_\-]/', '', (string) ($in['lang'] ?? 'en')) ?: 'en',
		];
	}

	/** @param array<string, mixed> $q normalised */
	public function create(int $authorId, array $q): int {
		global $wpdb;
		$wpdb->insert(Installer::table('questions'), [
			'author_id'     => $authorId,
			'stem'          => $q['stem'],
			'options'       => wp_json_encode($q['options'], JSON_UNESCAPED_UNICODE),
			'correct_index' => $q['correct_index'],
			'explanation'   => $q['explanation'],
			'tags'          => $q['tags'],
			'lang'          => $q['lang'],
			'created_at'    => Time::nowGmt(),
		]);
		return (int) $wpdb->insert_id;
	}

	/** @param array<string, mixed> $q normalised */
	public function update(int $id, array $q): bool {
		global $wpdb;
		return false !== $wpdb->update(Installer::table('questions'), [
			'stem'          => $q['stem'],
			'options'       => wp_json_encode($q['options'], JSON_UNESCAPED_UNICODE),
			'correct_index' => $q['correct_index'],
			'explanation'   => $q['explanation'],
			'tags'          => $q['tags'],
			'lang'          => $q['lang'],
		], ['id' => $id]);
	}

	public function delete(int $id): bool {
		global $wpdb;
		$used = (int) $wpdb->get_var($wpdb->prepare(
			'SELECT COUNT(*) FROM ' . Installer::table('video_interactions') . ' WHERE question_id = %d',
			$id
		));
		if ($used > 0) {
			return false;
		}
		return false !== $wpdb->delete(Installer::table('questions'), ['id' => $id]);
	}

	public function find(int $id): ?array {
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Installer::table('questions') . ' WHERE id = %d', $id), ARRAY_A);
		return $row ? self::hydrate($row) : null;
	}

	/** @return array<int, array<string, mixed>> */
	public function search(int $authorId, bool $all, string $term = '', int $limit = 50, int $offset = 0): array {
		global $wpdb;
		$where  = ['1=1'];
		$params = [];
		if (!$all) {
			$where[]  = 'author_id = %d';
			$params[] = $authorId;
		}
		if ($term !== '') {
			$like     = '%' . $wpdb->esc_like($term) . '%';
			$where[]  = '(stem LIKE %s OR tags LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		$params[] = max(1, min(100, $limit));
		$params[] = max(0, $offset);
		$sql      = 'SELECT * FROM ' . Installer::table('questions') . ' WHERE ' . implode(' AND ', $where)
			. ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$rows     = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: [];
		return array_map([self::class, 'hydrate'], $rows);
	}

	/** @param array<string, mixed> $row */
	private static function hydrate(array $row): array {
		$row['id']            = (int) $row['id'];
		$row['author_id']     = (int) $row['author_id'];
		$row['correct_index'] = (int) $row['correct_index'];
		$row['options']       = json_decode((string) $row['options'], true) ?: [];
		return $row;
	}
}
