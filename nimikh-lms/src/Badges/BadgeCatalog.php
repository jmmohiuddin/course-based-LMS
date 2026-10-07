<?php
declare(strict_types=1);

namespace Nimikh\LMS\Badges;

final class BadgeCatalog {

	/** @return array<string, array{label:string, description:string}> */
	public static function all(): array {
		return [
			'first_lesson'      => ['label' => __('First step', 'nimikh-lms'), 'description' => __('Completed your first lesson.', 'nimikh-lms')],
			'lessons_25'        => ['label' => __('On a roll', 'nimikh-lms'), 'description' => __('Completed 25 lessons.', 'nimikh-lms')],
			'streak_3'          => ['label' => __('3-day streak', 'nimikh-lms'), 'description' => __('Learned 3 days in a row.', 'nimikh-lms')],
			'streak_7'          => ['label' => __('Week streak', 'nimikh-lms'), 'description' => __('Learned 7 days in a row.', 'nimikh-lms')],
			'streak_30'         => ['label' => __('Month streak', 'nimikh-lms'), 'description' => __('Learned 30 days in a row.', 'nimikh-lms')],
			'first_certificate' => ['label' => __('Certified', 'nimikh-lms'), 'description' => __('Earned your first certificate.', 'nimikh-lms')],
			'certificates_3'    => ['label' => __('Triple certified', 'nimikh-lms'), 'description' => __('Earned 3 certificates.', 'nimikh-lms')],
			'sharp_mind'        => ['label' => __('Sharp mind', 'nimikh-lms'), 'description' => __('Scored 100% on in-video questions in a course.', 'nimikh-lms')],
		];
	}
}
