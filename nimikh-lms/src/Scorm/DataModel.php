<?php
declare(strict_types=1);

namespace Nimikh\LMS\Scorm;

/** The slice of the SCORM runtime data model we accept, and how a package's reported data becomes a completion verdict. Pure. */
final class DataModel {

	public const MAX_VALUE = 64000;   // characters per value (suspend_data limit in SCORM 2004)
	public const MAX_KEYS  = 400;

	/** Regexes (JS-compatible) for the keys a package may write, per version. @return string[] */
	public static function writablePatterns(string $version): array {
		if ($version === '1.2') {
			return [
				'^cmi\.core\.(lesson_location|lesson_status|exit|session_time)$',
				'^cmi\.core\.score\.(raw|min|max)$',
				'^cmi\.suspend_data$', '^cmi\.comments$',
				'^cmi\.objectives\.\d{1,3}\.(id|status)$', '^cmi\.objectives\.\d{1,3}\.score\.(raw|min|max)$',
				'^cmi\.interactions\.\d{1,3}\.(id|time|type|weighting|student_response|result|latency)$',
				'^cmi\.interactions\.\d{1,3}\.(objectives|correct_responses)\.\d{1,2}\.(id|pattern)$',
			];
		}
		return [
			'^cmi\.(location|completion_status|success_status|exit|session_time|progress_measure|suspend_data)$',
			'^cmi\.score\.(scaled|raw|min|max)$',
			'^cmi\.objectives\.\d{1,3}\.(id|success_status|completion_status|progress_measure|description)$',
			'^cmi\.objectives\.\d{1,3}\.score\.(scaled|raw|min|max)$',
			'^cmi\.interactions\.\d{1,3}\.(id|type|timestamp|weighting|learner_response|result|latency|description)$',
			'^cmi\.interactions\.\d{1,3}\.(objectives|correct_responses)\.\d{1,2}\.(id|pattern)$',
			'^cmi\.comments_from_learner\.\d{1,3}\.(comment|location|timestamp)$',
			'^adl\.nav\.request$',
		];
	}

	public static function isWritable(string $version, string $key): bool {
		if (strlen($key) > 120) {
			return false;
		}
		foreach (self::writablePatterns($version) as $p) {
			if (preg_match('/' . $p . '/', $key) === 1) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Keep only writable keys with string values within limits.
	 *
	 * @param mixed $incoming
	 * @return array<string, string>
	 */
	public static function sanitize(string $version, $incoming): array {
		if (!is_array($incoming)) {
			return [];
		}
		$out = [];
		foreach (array_slice($incoming, 0, self::MAX_KEYS, true) as $k => $v) {
			if (!is_string($k) || !(is_string($v) || is_int($v) || is_float($v)) || !self::isWritable($version, $k)) {
				continue;
			}
			$v = (string) $v;
			if (mb_strlen($v) <= self::MAX_VALUE) {
				$out[$k] = $v;
			}
		}
		return $out;
	}

	/** Seconds from a SCORM 1.2 "HHHH:MM:SS.SS" or a 2004 ISO 8601 "PT1H2M3S" duration; null if unreadable. */
	public static function seconds(string $v): ?int {
		$v = trim($v);
		if (preg_match('/^(\d{1,4}):(\d{2}):(\d{2})(?:\.\d+)?$/', $v, $m) === 1) {
			return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
		}
		if (preg_match('/^P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/', $v, $m) === 1 && $v !== 'P' && !str_ends_with($v, 'T')) {
			return (int) ((int) ($m[1] ?? 0) * 31536000 + (int) ($m[2] ?? 0) * 2592000 + (int) ($m[3] ?? 0) * 86400 + (int) ($m[4] ?? 0) * 3600 + (int) ($m[5] ?? 0) * 60 + (float) ($m[6] ?? 0));
		}
		return null;
	}

	/**
	 * @param array<string, string> $d the package's data
	 * @return array{completed:bool, passed:?bool, score:?float, status:string}
	 */
	public static function evaluate(string $version, array $d, ?float $mastery): array {
		if ($version === '1.2') {
			$status = strtolower($d['cmi.core.lesson_status'] ?? 'not attempted');
			$score  = self::score($d['cmi.core.score.raw'] ?? '', $d['cmi.core.score.min'] ?? '', $d['cmi.core.score.max'] ?? '');
			$passed = $status === 'passed' ? true : ($status === 'failed' ? false : null);
			if ($passed === null && $mastery !== null && $score !== null && in_array($status, ['completed'], true)) {
				$passed = $score >= $mastery; // a package that only says "completed" but has a mastery score
			}
			return ['completed' => in_array($status, ['completed', 'passed'], true), 'passed' => $passed, 'score' => $score, 'status' => $status];
		}
		$completion = strtolower($d['cmi.completion_status'] ?? 'unknown');
		$success    = strtolower($d['cmi.success_status'] ?? 'unknown');
		$scaled     = $d['cmi.score.scaled'] ?? '';
		$score      = is_numeric($scaled) ? round(max(-1.0, min(1.0, (float) $scaled)) * 100, 2) : self::score($d['cmi.score.raw'] ?? '', $d['cmi.score.min'] ?? '', $d['cmi.score.max'] ?? '');
		$passed     = $success === 'passed' ? true : ($success === 'failed' ? false : null);
		$completed  = $completion === 'completed' || (in_array($completion, ['unknown', 'not attempted', ''], true) && $success === 'passed');
		return ['completed' => $completed, 'passed' => $passed, 'score' => $score, 'status' => $completion !== 'unknown' ? $completion : $success];
	}

	/** Score as a percentage: normalised by min/max when both are given, else the raw value taken as a percentage. */
	private static function score(string $raw, string $min, string $max): ?float {
		if (!is_numeric($raw)) {
			return null;
		}
		$r = (float) $raw;
		if (is_numeric($min) && is_numeric($max) && (float) $max > (float) $min) {
			$r = ($r - (float) $min) / ((float) $max - (float) $min) * 100;
		}
		return round(max(0.0, min(100.0, $r)), 2);
	}

	/**
	 * Values the package can read at launch.
	 *
	 * @param array<string, string> $stored previously committed data
	 * @return array<string, string>
	 */
	public static function initial(string $version, int $learnerId, string $learnerName, array $stored, string $launchData, ?float $mastery, int $totalSeconds): array {
		$resume = ($stored['cmi.suspend_data'] ?? '') !== '' || ($stored['cmi.core.lesson_location'] ?? $stored['cmi.location'] ?? '') !== '';
		if ($version === '1.2') {
			$base = [
				'cmi.core._children' => 'student_id,student_name,lesson_location,credit,lesson_status,entry,score,total_time,lesson_mode,exit,session_time',
				'cmi.core.score._children' => 'raw,min,max',
				'cmi.core.student_id' => (string) $learnerId, 'cmi.core.student_name' => $learnerName,
				'cmi.core.credit' => 'credit', 'cmi.core.entry' => $resume ? 'resume' : 'ab-initio', 'cmi.core.lesson_mode' => 'normal',
				'cmi.core.total_time' => self::clock($totalSeconds), 'cmi.launch_data' => $launchData,
				'cmi.core.lesson_status' => 'not attempted',
				'cmi.student_data.mastery_score' => $mastery === null ? '' : (string) $mastery,
			];
		} else {
			$base = [
				'cmi._version' => '1.0',
				'cmi.learner_id' => (string) $learnerId, 'cmi.learner_name' => $learnerName,
				'cmi.credit' => 'credit', 'cmi.entry' => $resume ? 'resume' : 'ab-initio', 'cmi.mode' => 'normal',
				'cmi.total_time' => 'PT' . $totalSeconds . 'S', 'cmi.launch_data' => $launchData,
				'cmi.scaled_passing_score' => $mastery === null ? '' : (string) round($mastery / 100, 4),
				'cmi.completion_status' => 'unknown', 'cmi.success_status' => 'unknown',
				'cmi.time_limit_action' => 'continue,no message',
			];
		}
		return $stored + $base;
	}

	/** Keys the package must not overwrite. @return string[] */
	public static function readonlyKeys(string $version): array {
		return $version === '1.2'
			? ['cmi.core._children', 'cmi.core.score._children', 'cmi.core.student_id', 'cmi.core.student_name', 'cmi.core.credit', 'cmi.core.entry', 'cmi.core.lesson_mode', 'cmi.core.total_time', 'cmi.launch_data', 'cmi.student_data.mastery_score']
			: ['cmi._version', 'cmi.learner_id', 'cmi.learner_name', 'cmi.credit', 'cmi.entry', 'cmi.mode', 'cmi.total_time', 'cmi.launch_data', 'cmi.scaled_passing_score', 'cmi.time_limit_action'];
	}

	private static function clock(int $s): string {
		return sprintf('%04d:%02d:%02d.00', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
	}
}
