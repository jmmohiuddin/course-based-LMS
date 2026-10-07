<?php
declare(strict_types=1);

namespace Nimikh\LMS\Discussion;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Support\Installer;
use Nimikh\LMS\Support\RateLimiter;
use Nimikh\LMS\Support\Time;

/** Per-lesson Q&A: top-level questions with one level of replies, moderated by course staff. */
final class DiscussionService {

	public const MAX_LENGTH = 2000;
	public const PAGE_SIZE  = 20;

	public function __construct(private TutorAdapter $tutor) {}

	/** @return array<string, mixed>|\WP_Error */
	public function post(int $userId, int $lessonId, string $body, int $parentId = 0) {
		$body = trim(sanitize_textarea_field($body));
		if (mb_strlen($body) < 2) {
			return new \WP_Error('nimikh_discussion_short', __('Write a little more.', 'nimikh-lms'), ['status' => 400]);
		}
		if (mb_strlen($body) > self::MAX_LENGTH) {
			return new \WP_Error('nimikh_discussion_long', __('That is too long.', 'nimikh-lms'), ['status' => 400]);
		}
		if (!RateLimiter::hit('discussion', (string) $userId, 10)) {
			return new \WP_Error('nimikh_rate_limited', __('Too many posts. Please wait a minute.', 'nimikh-lms'), ['status' => 429]);
		}
		$courseId = $this->tutor->courseIdForLesson($lessonId);

		global $wpdb;
		$table = Installer::table('discussions');
		if ($parentId > 0) {
			$parent = $wpdb->get_row($wpdb->prepare("SELECT id, lesson_id, parent_id, status FROM $table WHERE id = %d", $parentId), ARRAY_A);
			if (!$parent || (int) $parent['lesson_id'] !== $lessonId || (int) $parent['parent_id'] !== 0 || $parent['status'] !== 'visible') {
				return new \WP_Error('nimikh_discussion_parent', __('You can only reply to a question in this lesson.', 'nimikh-lms'), ['status' => 400]);
			}
		}
		$wpdb->insert($table, [
			'lesson_id'  => $lessonId,
			'course_id'  => $courseId,
			'user_id'    => $userId,
			'parent_id'  => $parentId,
			'body'       => $body,
			'status'     => 'visible',
			'created_at' => Time::nowGmt(),
		]);
		$id = (int) $wpdb->insert_id;
		do_action('nimikh_discussion_posted', $id, $userId, $lessonId, $parentId);
		$this->notifyInstructor($userId, $lessonId, $courseId, $parentId, $body);
		return $this->find($id, $courseId);
	}

	/** @return array<int, array<string, mixed>> */
	public function thread(int $lessonId, int $viewerId, int $page = 1): array {
		global $wpdb;
		$table    = Installer::table('discussions');
		$courseId = $this->tutor->courseIdForLesson($lessonId);
		$mod      = $this->canModerate($viewerId, $courseId);
		$hidden   = $mod ? '' : " AND status = 'visible'";
		$offset   = max(0, $page - 1) * self::PAGE_SIZE;

		$tops = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM $table WHERE lesson_id = %d AND parent_id = 0$hidden ORDER BY id DESC LIMIT %d OFFSET %d",
			$lessonId,
			self::PAGE_SIZE,
			$offset
		), ARRAY_A) ?: [];
		if (!$tops) {
			return [];
		}
		$ids     = implode(',', array_map(static fn(array $r): int => (int) $r['id'], $tops));
		$replies = $wpdb->get_results("SELECT * FROM $table WHERE parent_id IN ($ids)$hidden ORDER BY id ASC", ARRAY_A) ?: [];

		$byParent = [];
		foreach ($replies as $r) {
			$byParent[(int) $r['parent_id']][] = $this->present($r, $courseId);
		}
		$out = [];
		foreach ($tops as $t) {
			$item            = $this->present($t, $courseId);
			$item['replies'] = $byParent[(int) $t['id']] ?? [];
			$out[]           = $item;
		}
		return $out;
	}

	public function canModerate(int $userId, int $courseId): bool {
		return $userId > 0 && $this->tutor->canManageCourse($userId, $courseId);
	}

	/** Own posts can be deleted by the author; staff can hide/delete any post in their course. */
	public function remove(int $userId, int $id): bool {
		global $wpdb;
		$table = Installer::table('discussions');
		$row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);
		if (!$row || ((int) $row['user_id'] !== $userId && !$this->canModerate($userId, (int) $row['course_id']))) {
			return false;
		}
		$wpdb->delete($table, ['id' => $id]);
		$wpdb->delete($table, ['parent_id' => $id]);
		return true;
	}

	public function setHidden(int $userId, int $id, bool $hidden): bool {
		global $wpdb;
		$table = Installer::table('discussions');
		$row   = $wpdb->get_row($wpdb->prepare("SELECT course_id FROM $table WHERE id = %d", $id), ARRAY_A);
		if (!$row || !$this->canModerate($userId, (int) $row['course_id'])) {
			return false;
		}
		$wpdb->update($table, ['status' => $hidden ? 'hidden' : 'visible'], ['id' => $id]);
		return true;
	}

	/** @return array<string, mixed>|null */
	private function find(int $id, int $courseId): ?array {
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Installer::table('discussions') . ' WHERE id = %d', $id), ARRAY_A);
		return $row ? $this->present($row, $courseId) + ['replies' => []] : null;
	}

	/** @param array<string, mixed> $row */
	private function present(array $row, int $courseId): array {
		$user = get_userdata((int) $row['user_id']);
		return [
			'id'         => (int) $row['id'],
			'parent_id'  => (int) $row['parent_id'],
			'user_id'    => (int) $row['user_id'],
			'author'     => $user ? $user->display_name : __('Former learner', 'nimikh-lms'),
			'is_staff'   => $this->tutor->canManageCourse((int) $row['user_id'], $courseId),
			'body'       => (string) $row['body'], // plain text; the client renders with textContent
			'status'     => (string) $row['status'],
			'created_at' => (string) $row['created_at'],
		];
	}

	private function notifyInstructor(int $userId, int $lessonId, int $courseId, int $parentId, string $body): void {
		if ($parentId > 0 || $this->canModerate($userId, $courseId)) {
			return; // only a learner's new top-level question pings the instructor
		}
		$authorId = (int) get_post_field('post_author', $courseId);
		$author   = $authorId ? get_userdata($authorId) : false;
		if (!$author) {
			return;
		}
		wp_mail(
			$author->user_email,
			sprintf(__('New question in %s', 'nimikh-lms'), get_the_title($lessonId)),
			sprintf("%s\n\n%s", mb_substr($body, 0, 300), (string) get_permalink($lessonId))
		);
	}
}
