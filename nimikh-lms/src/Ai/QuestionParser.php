<?php
declare(strict_types=1);

namespace Nimikh\LMS\Ai;

/**
 * Validates model output into draft MCQs. Models drift (code fences, prose, bad indexes), so
 * nothing is trusted: every field is checked and anything unusable is dropped.
 */
final class QuestionParser {

	public const MIN_GAP_SECONDS = 30;
	public const FIRST_ALLOWED   = 30; // blueprint 7.4: never in the first 30 s

	/**
	 * @return array{questions: array<int, array<string, mixed>>, dropped: int}
	 */
	public static function parse(string $raw, int $duration, int $max): array {
		$items = self::extractJson($raw);
		$clean = [];
		$dropped = 0;

		foreach ($items as $item) {
			$q = self::clean($item, $duration);
			if ($q === null) {
				$dropped++;
				continue;
			}
			$clean[] = $q;
		}

		usort($clean, static fn(array $a, array $b): int => $a['at_second'] <=> $b['at_second']);
		$out = []; $lastAt = null;
		foreach ($clean as $q) {
			if ($lastAt !== null && $q['at_second'] - $lastAt < self::MIN_GAP_SECONDS) {
				$dropped++;
				continue;
			}
			if (count($out) >= $max) {
				$dropped++;
				continue;
			}
			$out[]  = $q;
			$lastAt = $q['at_second'];
		}
		return ['questions' => $out, 'dropped' => $dropped];
	}

	/** @return array<int, mixed> */
	private static function extractJson(string $raw): array {
		$raw = trim($raw);
		if (preg_match('/```(?:json)?\s*(.*?)```/s', $raw, $m) === 1) {
			$raw = trim($m[1]);
		}
		$start = strpos($raw, '[');
		$end   = strrpos($raw, ']');
		if ($start === false || $end === false || $end <= $start) {
			return [];
		}
		$decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
		return is_array($decoded) ? array_values($decoded) : [];
	}

	/** @param mixed $item */
	private static function clean($item, int $duration): ?array {
		if (!is_array($item)) {
			return null;
		}
		$stem = isset($item['stem']) && is_string($item['stem']) ? trim($item['stem']) : '';
		$opts = $item['options'] ?? null;
		$at   = $item['at_second'] ?? null;
		$idx  = $item['correct_index'] ?? null;

		if ($stem === '' || mb_strlen($stem) > 300 || !is_array($opts) || !is_numeric($at) || !is_numeric($idx)) {
			return null;
		}
		$options = [];
		foreach ($opts as $o) {
			if (!is_string($o) || trim($o) === '' || mb_strlen($o) > 200) {
				return null;
			}
			$options[] = trim($o);
		}
		if (count($options) < 2 || count($options) > 6 || count(array_unique(array_map('mb_strtolower', $options))) !== count($options)) {
			return null;
		}
		$idx = (int) $idx;
		if ($idx < 0 || $idx >= count($options)) {
			return null;
		}
		$at = (int) $at;
		if ($at < self::FIRST_ALLOWED || ($duration > 0 && $at >= $duration)) {
			return null;
		}
		$explanation = isset($item['explanation']) && is_string($item['explanation']) ? mb_substr(trim($item['explanation']), 0, 400) : '';

		return ['at_second' => $at, 'stem' => $stem, 'options' => $options, 'correct_index' => $idx, 'explanation' => $explanation];
	}
}
