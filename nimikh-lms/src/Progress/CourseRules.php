<?php
declare(strict_types=1);

namespace Nimikh\LMS\Progress;

/** Completion rules stored as course meta (blueprint 5.3). */
final class CourseRules {

	public const DEFAULTS = [
		'min_watch_percent'       => 90.0,
		'min_mcq_percent'         => 0.0,   // 0 = rule off
		'exam_pass_percent'       => 60.0,
		'certificate_template_id' => 0,
	];

	public const META_PREFIX = 'nimikh_';

	/** @return array{min_watch_percent:float, min_mcq_percent:float, exam_pass_percent:float, certificate_template_id:int} */
	public static function forCourse(int $courseId): array {
		$rules = [];
		foreach (self::DEFAULTS as $key => $default) {
			$raw = $courseId > 0 ? get_post_meta($courseId, self::META_PREFIX . $key, true) : '';
			$rules[$key] = $raw === '' ? $default : (is_int($default) ? (int) $raw : (float) $raw);
		}
		return $rules;
	}

	/** @param array<string, mixed> $input */
	public static function save(int $courseId, array $input): void {
		foreach (self::DEFAULTS as $key => $default) {
			if (!isset($input[$key])) {
				continue;
			}
			$value = is_int($default) ? max(0, (int) $input[$key]) : min(100.0, max(0.0, (float) $input[$key]));
			update_post_meta($courseId, self::META_PREFIX . $key, $value);
		}
	}
}
