<?php
declare(strict_types=1);

namespace Nimikh\LMS\Privacy;

use Nimikh\LMS\Certificates\CertificateStorage;
use Nimikh\LMS\Support\Installer;

/**
 * NFR "Privacy": a learner can download and delete their data. Hooks into WordPress's own
 * Tools -> Export/Erase Personal Data, and also runs when a user account is deleted.
 *
 * Erasure policy (documented for the privacy policy page):
 *  - deleted: progress, MCQ attempts, notes, badges, streak days, live attendance, SMS log, institute membership
 *  - discussion: replies deleted; questions that others replied to are kept but anonymised
 *  - certificates: kept as anonymised, revoked records so the public ID cannot be tied to a person; the file is deleted
 *  - subscriptions: kept anonymised (payment/tax records)
 *  - Tutor LMS's own enrolment/quiz data is Tutor's to erase
 */
final class PrivacyService {

	public function register(): void {
		add_filter('wp_privacy_personal_data_exporters', [$this, 'addExporter']);
		add_filter('wp_privacy_personal_data_erasers', [$this, 'addEraser']);
		add_action('deleted_user', [$this, 'onUserDeleted']);
		add_action('admin_post_nimikh_privacy_request', [$this, 'handleRequest']);
	}

	public function addExporter(array $exporters): array {
		$exporters['nimikh-lms'] = ['exporter_friendly_name' => __('Nimikh LMS learning data', 'nimikh-lms'), 'callback' => [$this, 'export']];
		return $exporters;
	}

	public function addEraser(array $erasers): array {
		$erasers['nimikh-lms'] = ['eraser_friendly_name' => __('Nimikh LMS learning data', 'nimikh-lms'), 'callback' => [$this, 'erase']];
		return $erasers;
	}

	public function onUserDeleted(int $userId): void {
		$this->eraseUser($userId);
	}

	/** @return array{data: array<int, array<string, mixed>>, done: bool} */
	public function export(string $email, int $page = 1): array {
		$user = get_user_by('email', $email);
		if (!$user) {
			return ['data' => [], 'done' => true];
		}
		global $wpdb;
		$uid  = (int) $user->ID;
		$out  = [];
		$push = static function (string $group, string $label, $id, array $fields) use (&$out): void {
			$data = [];
			foreach ($fields as $name => $value) {
				$data[] = ['name' => $name, 'value' => (string) $value];
			}
			$out[] = ['group_id' => $group, 'group_label' => $label, 'item_id' => $group . '-' . $id, 'data' => $data];
		};
		$q = static fn(string $sql, ...$args): array => $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: [];
		$t = static fn(string $name): string => Installer::table($name);

		foreach ($q('SELECT * FROM ' . $t('watch_progress') . ' WHERE user_id = %d LIMIT 2000', $uid) as $r) {
			$push('nimikh-progress', __('Lesson progress', 'nimikh-lms'), $r['lesson_id'], [
				__('Lesson', 'nimikh-lms') => get_the_title((int) $r['lesson_id']), __('Percent watched', 'nimikh-lms') => $r['percent'],
				__('Completed', 'nimikh-lms') => $r['completed_at'] ?: '-', __('Last activity', 'nimikh-lms') => $r['updated_at'],
			]);
		}
		foreach ($q('SELECT a.*, q.stem FROM ' . $t('interaction_attempts') . ' a JOIN ' . $t('video_interactions') . ' i ON i.id = a.interaction_id JOIN ' . $t('questions') . ' q ON q.id = i.question_id WHERE a.user_id = %d ORDER BY a.id LIMIT 2000', $uid) as $r) {
			$push('nimikh-answers', __('In-video question answers', 'nimikh-lms'), $r['id'], [
				__('Question', 'nimikh-lms') => $r['stem'], __('Option chosen', 'nimikh-lms') => (int) $r['selected_index'] + 1,
				__('Correct', 'nimikh-lms') => $r['is_correct'] ? __('Yes', 'nimikh-lms') : __('No', 'nimikh-lms'), __('Answered at', 'nimikh-lms') => $r['answered_at'],
			]);
		}
		foreach ($q('SELECT * FROM ' . $t('certificates') . ' WHERE user_id = %d', $uid) as $r) {
			$push('nimikh-certificates', __('Certificates', 'nimikh-lms'), $r['id'], [
				__('Certificate ID', 'nimikh-lms') => $r['code'], __('Course', 'nimikh-lms') => get_the_title((int) $r['course_id']),
				__('Score', 'nimikh-lms') => $r['score'] ?? '-', __('Issued', 'nimikh-lms') => $r['issued_at'], __('Status', 'nimikh-lms') => $r['status'],
			]);
		}
		foreach ($q('SELECT * FROM ' . $t('notes') . ' WHERE user_id = %d ORDER BY id LIMIT 2000', $uid) as $r) {
			$push('nimikh-notes', __('Video notes', 'nimikh-lms'), $r['id'], [
				__('Lesson', 'nimikh-lms') => get_the_title((int) $r['lesson_id']), __('At second', 'nimikh-lms') => $r['at_second'], __('Note', 'nimikh-lms') => $r['body'],
			]);
		}
		foreach ($q('SELECT * FROM ' . $t('discussions') . ' WHERE user_id = %d ORDER BY id LIMIT 2000', $uid) as $r) {
			$push('nimikh-discussion', __('Discussion posts', 'nimikh-lms'), $r['id'], [
				__('Lesson', 'nimikh-lms') => get_the_title((int) $r['lesson_id']), __('Post', 'nimikh-lms') => $r['body'], __('Posted', 'nimikh-lms') => $r['created_at'],
			]);
		}
		foreach ($q('SELECT * FROM ' . $t('user_badges') . ' WHERE user_id = %d', $uid) as $r) {
			$push('nimikh-badges', __('Badges', 'nimikh-lms'), $r['badge'], [__('Badge', 'nimikh-lms') => $r['badge'], __('Awarded', 'nimikh-lms') => $r['awarded_at']]);
		}
		foreach ($q('SELECT s.*, p.name FROM ' . $t('subscriptions') . ' s LEFT JOIN ' . $t('plans') . ' p ON p.id = s.plan_id WHERE s.user_id = %d', $uid) as $r) {
			$push('nimikh-subscriptions', __('Subscriptions', 'nimikh-lms'), $r['id'], [
				__('Plan', 'nimikh-lms') => $r['name'], __('Starts', 'nimikh-lms') => $r['starts_at'], __('Expires', 'nimikh-lms') => $r['expires_at'], __('Status', 'nimikh-lms') => $r['status'],
			]);
		}
		foreach ($q('SELECT a.*, s.title FROM ' . $t('live_attendance') . ' a JOIN ' . $t('live_sessions') . ' s ON s.id = a.session_id WHERE a.user_id = %d', $uid) as $r) {
			$push('nimikh-live', __('Live class attendance', 'nimikh-lms'), $r['session_id'], [__('Class', 'nimikh-lms') => $r['title'], __('Joined', 'nimikh-lms') => $r['joined_at']]);
		}
		foreach ($q('SELECT * FROM ' . $t('sms_log') . ' WHERE user_id = %d ORDER BY id LIMIT 2000', $uid) as $r) {
			$push('nimikh-sms', __('SMS notifications', 'nimikh-lms'), $r['id'], [__('Number', 'nimikh-lms') => $r['phone'], __('Type', 'nimikh-lms') => $r['event'], __('Status', 'nimikh-lms') => $r['status'], __('Sent', 'nimikh-lms') => $r['created_at']]);
		}
		foreach ($q('SELECT m.*, o.name FROM ' . $t('org_members') . ' m JOIN ' . $t('orgs') . ' o ON o.id = m.org_id WHERE m.user_id = %d', $uid) as $r) {
			$push('nimikh-institutes', __('Institute memberships', 'nimikh-lms'), $r['org_id'], [__('Institute', 'nimikh-lms') => $r['name'], __('Role', 'nimikh-lms') => $r['role']]);
		}
		return ['data' => $out, 'done' => true];
	}

	/** @return array{items_removed:bool, items_retained:bool, messages:string[], done:bool} */
	public function erase(string $email, int $page = 1): array {
		$user = get_user_by('email', $email);
		if (!$user) {
			return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
		}
		$r = $this->eraseUser((int) $user->ID);
		$messages = [];
		if ($r['retained']) {
			$messages[] = __('Certificates and subscription records were kept in anonymised form (they no longer identify you). Their files were deleted.', 'nimikh-lms');
		}
		return ['items_removed' => $r['removed'] > 0, 'items_retained' => $r['retained'], 'messages' => $messages, 'done' => true];
	}

	/** @return array{removed:int, retained:bool} */
	public function eraseUser(int $uid): array {
		global $wpdb;
		$removed = 0;
		$t = static fn(string $name): string => Installer::table($name);

		foreach (['watch_progress', 'notes', 'user_badges', 'activity_days', 'live_attendance', 'sms_log', 'org_members'] as $table) {
			$removed += (int) $wpdb->delete($t($table), ['user_id' => $uid]);
		}
		$removed += (int) $wpdb->delete($t('interaction_attempts'), ['user_id' => $uid]);

		// Discussion: keep threads others replied to, anonymised; drop everything else.
		$d = $t('discussions');
		foreach ($wpdb->get_results($wpdb->prepare("SELECT id, parent_id FROM $d WHERE user_id = %d", $uid), ARRAY_A) ?: [] as $row) {
			$hasReplies = $row['parent_id'] == 0 && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $d WHERE parent_id = %d", (int) $row['id'])) > 0;
			if ($hasReplies) {
				$wpdb->update($d, ['user_id' => 0, 'body' => __('[removed by the author]', 'nimikh-lms')], ['id' => (int) $row['id']]);
			} else {
				$wpdb->delete($d, ['id' => (int) $row['id']]);
				$removed++;
			}
		}

		$retained = false;
		$c = $t('certificates');
		foreach ($wpdb->get_results($wpdb->prepare("SELECT id, pdf_key FROM $c WHERE user_id = %d", $uid), ARRAY_A) ?: [] as $row) {
			if (!empty($row['pdf_key'])) {
				$path = CertificateStorage::baseDir() . '/' . basename((string) $row['pdf_key']);
				if (is_file($path)) {
					@unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
			}
			$wpdb->update($c, ['user_id' => 0, 'status' => 'revoked', 'revoked_reason' => 'Learner data erased at the learner\'s request', 'pdf_key' => null, 'sha256' => null], ['id' => (int) $row['id']]);
			$retained = true;
		}
		if ($wpdb->update($t('subscriptions'), ['user_id' => 0], ['user_id' => $uid])) {
			$retained = true;
		}
		delete_user_meta($uid, 'nimikh_profile_public');
		delete_user_meta($uid, 'nimikh_phone');
		delete_user_meta($uid, 'nimikh_google_sub');
		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s", $uid, $wpdb->esc_like('nimikh_exam_last_attempt_') . '%')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return ['removed' => $removed, 'retained' => $retained];
	}

	/** Self-service: files a standard WordPress privacy request (the admin confirms it under Tools). */
	public function handleRequest(): void {
		check_admin_referer('nimikh_privacy_request');
		$user = wp_get_current_user();
		$type = ($_POST['type'] ?? '') === 'erase' ? 'remove_personal_data' : 'export_personal_data';
		if ($user->ID) {
			$id = wp_create_user_request($user->user_email, $type);
			if (!is_wp_error($id)) {
				wp_send_user_request($id); // emails the confirmation link
			}
		}
		wp_safe_redirect(add_query_arg('nimikh_privacy', 'sent', wp_get_referer() ?: home_url('/')));
		exit;
	}
}
