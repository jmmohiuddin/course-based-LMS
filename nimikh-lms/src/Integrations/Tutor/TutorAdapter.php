<?php
declare(strict_types=1);

namespace Nimikh\LMS\Integrations\Tutor;

/**
 * The only class that talks to Tutor LMS (blueprint 6.4). Switching LMS core later
 * means rewriting this adapter. Every call is guarded so the plugin degrades
 * gracefully (and stays testable) when Tutor is missing or its API shifts.
 *
 * Tutor storage conventions relied on here:
 *  - post types: courses, topics, lesson, tutor_quiz, tutor_enrolled
 *  - lesson -> course: post meta _tutor_course_id_for_lesson
 *  - lesson completion: user meta _tutor_completed_lesson_id_{id}
 *  - quiz attempts: {prefix}tutor_quiz_attempts
 *  - instructors: user meta _tutor_instructor_course_id (one row per course)
 */
class TutorAdapter {

	public const COURSE_POST_TYPE = 'courses';
	public const LESSON_POST_TYPE = 'lesson';

	public function isActive(): bool {
		return function_exists('tutor_utils') || post_type_exists(self::COURSE_POST_TYPE);
	}

	public function courseIdForLesson(int $lessonId): int {
		$course = (int) get_post_meta($lessonId, '_tutor_course_id_for_lesson', true);
		if ($course > 0) {
			return $course;
		}
		// Fall back to walking lesson -> topic -> course.
		$parent = (int) wp_get_post_parent_id($lessonId);
		for ($depth = 0; $parent > 0 && $depth < 3; $depth++) {
			if (get_post_type($parent) === self::COURSE_POST_TYPE) {
				return $parent;
			}
			$parent = (int) wp_get_post_parent_id($parent);
		}
		return 0;
	}

	/** @return int[] */
	public function lessonIdsForCourse(int $courseId): array {
		$ids = get_posts([
			'post_type'      => self::LESSON_POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => '_tutor_course_id_for_lesson',
			'meta_value'     => $courseId,
		]);
		return array_map('intval', $ids);
	}

	public function isEnrolled(int $userId, int $courseId): bool {
		$enrolled = null;
		if (function_exists('tutor_utils')) {
			$enrolled = (bool) tutor_utils()->is_enrolled($courseId, $userId);
		}
		/**
		 * Lets tests and alternative enrolment sources decide.
		 *
		 * @param bool|null $enrolled
		 */
		return (bool) apply_filters('nimikh_lms_is_enrolled', $enrolled ?? false, $userId, $courseId);
	}

	public function isCourseInstructor(int $userId, int $courseId): bool {
		if ($courseId <= 0 || $userId <= 0) {
			return false;
		}
		if ((int) get_post_field('post_author', $courseId) === $userId) {
			return true;
		}
		$courses = array_map('intval', (array) get_user_meta($userId, '_tutor_instructor_course_id'));
		return in_array($courseId, $courses, true);
	}

	public function canManageCourse(int $userId, int $courseId): bool {
		return user_can($userId, 'manage_options') || user_can($userId, \Nimikh\LMS\Support\Roles::CAP_MANAGE)
			|| $this->isCourseInstructor($userId, $courseId);
	}

	public function isLessonComplete(int $userId, int $lessonId): bool {
		return (bool) get_user_meta($userId, '_tutor_completed_lesson_id_' . $lessonId, true);
	}

	/** Mark a lesson complete the way Tutor does, so native course progress stays correct. */
	public function completeLesson(int $userId, int $lessonId): void {
		if ($this->isLessonComplete($userId, $lessonId)) {
			return;
		}
		if (function_exists('tutor_utils') && method_exists(tutor_utils(), 'mark_lesson_complete')) {
			tutor_utils()->mark_lesson_complete($lessonId, $userId);
			return;
		}
		update_user_meta($userId, '_tutor_completed_lesson_id_' . $lessonId, time());
		do_action('tutor_lesson_completed_after', $lessonId, $userId);
	}

	/** The course's final exam: course meta override, else the last quiz in the course. */
	public function examQuizId(int $courseId): int {
		$explicit = (int) get_post_meta($courseId, 'nimikh_exam_quiz_id', true);
		if ($explicit > 0) {
			return $explicit;
		}
		$quizzes = get_posts([
			'post_type'      => 'tutor_quiz',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'meta_key'       => '_tutor_course_id_for_quiz',
			'meta_value'     => $courseId,
		]);
		return $quizzes ? (int) $quizzes[0] : 0;
	}

	/** Best finished attempt as a percentage, or null if the learner has none. */
	public function bestQuizPercent(int $userId, int $quizId): ?float {
		global $wpdb;
		if ($quizId <= 0) {
			return null;
		}
		$table = $wpdb->prefix . 'tutor_quiz_attempts';
		$row   = $wpdb->get_row($wpdb->prepare(
			"SELECT MAX(CASE WHEN total_marks > 0 THEN earned_marks / total_marks * 100 END) AS pct
			 FROM $table WHERE user_id = %d AND quiz_id = %d AND attempt_status = 'attempt_ended'",
			$userId,
			$quizId
		), ARRAY_A);
		return isset($row['pct']) && $row['pct'] !== null ? round((float) $row['pct'], 2) : null;
	}

	/** @return array{user_id:int, course_id:int, quiz_id:int}|null */
	public function attempt(int $attemptId): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'tutor_quiz_attempts';
		$row   = $wpdb->get_row($wpdb->prepare("SELECT user_id, course_id, quiz_id FROM $table WHERE attempt_id = %d", $attemptId), ARRAY_A);
		return $row ? ['user_id' => (int) $row['user_id'], 'course_id' => (int) $row['course_id'], 'quiz_id' => (int) $row['quiz_id']] : null;
	}

	/** @return int[] user ids with an active enrolment */
	public function enrolledUserIds(int $courseId): array {
		global $wpdb;
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT post_author FROM {$wpdb->posts}
			 WHERE post_type = 'tutor_enrolled' AND post_parent = %d AND post_status = 'completed'",
			$courseId
		));
		return array_map('intval', $ids ?: []);
	}

	/** Enrol (used by CSV batch enrolment). Returns false if Tutor's API is unavailable. */
	public function enrol(int $userId, int $courseId): bool {
		if ($this->isEnrolled($userId, $courseId)) {
			return true;
		}
		if (function_exists('tutor_utils') && method_exists(tutor_utils(), 'do_enroll')) {
			return (bool) tutor_utils()->do_enroll($courseId, 0, $userId);
		}
		return false;
	}

	public function courseTitle(int $courseId): string {
		return html_entity_decode(get_the_title($courseId), ENT_QUOTES, 'UTF-8');
	}
}
