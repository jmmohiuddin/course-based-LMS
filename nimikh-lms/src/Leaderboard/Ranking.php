<?php
declare(strict_types=1);

namespace Nimikh\LMS\Leaderboard;

/** Scoring and ranking for the opt-in course leaderboard. Pure. */
final class Ranking {

	public const POINTS_PER_LESSON      = 10;
	public const POINTS_PER_CERTIFICATE = 100;

	public static function score(int $lessonsCompleted, int $mcqPoints, bool $hasCertificate): int {
		return $lessonsCompleted * self::POINTS_PER_LESSON + $mcqPoints + ($hasCertificate ? self::POINTS_PER_CERTIFICATE : 0);
	}

	/**
	 * Competition ranking (1, 2, 2, 4). Ties are shown as ties and ordered by id so output is stable.
	 *
	 * @param array<int, array{user_id:int, score:int}> $rows
	 * @return array<int, array{user_id:int, score:int, rank:int}>
	 */
	public static function rank(array $rows): array {
		usort($rows, static fn(array $a, array $b): int => [$b['score'], $a['user_id']] <=> [$a['score'], $b['user_id']]);
		$out  = [];
		$prev = null;
		$rank = 0;
		foreach ($rows as $i => $r) {
			if ($r['score'] !== $prev) {
				$rank = $i + 1;
				$prev = $r['score'];
			}
			$out[] = $r + ['rank' => $rank];
		}
		return $out;
	}

	/** "Rafi A." from "Rafi Ahmed": other learners see a first name and an initial, never a full name. */
	public static function publicName(string $displayName): string {
		$parts = preg_split('/\s+/u', trim($displayName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		if (!$parts) {
			return '—';
		}
		$first = mb_substr($parts[0], 0, 20);
		return count($parts) > 1 ? $first . ' ' . mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)) . '.' : $first;
	}
}
