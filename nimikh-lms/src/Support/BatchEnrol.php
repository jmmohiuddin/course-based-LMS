<?php
declare(strict_types=1);

namespace Nimikh\LMS\Support;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;

/** Shared by the admin CSV tool and institute admins (FR-14). */
final class BatchEnrol {

	public const MAX_ROWS = 5000;

	public function __construct(private TutorAdapter $tutor) {}

	/**
	 * @param array<int, array{0:string, 1?:string}> $rows [email, name?]
	 * @param callable(int):void|null $onUser called for every resolved user id (e.g. to add org membership)
	 * @return array{enrolled:int, created:int, errors:string[]}
	 */
	public function run(array $rows, int $courseId, ?callable $onUser = null): array {
		$report = ['enrolled' => 0, 'created' => 0, 'errors' => []];
		if (get_post_type($courseId) !== TutorAdapter::COURSE_POST_TYPE) {
			$report['errors'][] = __('Course not found.', 'nimikh-lms');
			return $report;
		}
		$line = 0;
		foreach (array_slice($rows, 0, self::MAX_ROWS) as $cols) {
			$line++;
			$email = sanitize_email(trim((string) ($cols[0] ?? '')));
			if (($line === 1 && strtolower($email) === 'email') || $email === '' && count(array_filter($cols)) === 0) {
				continue; // header or blank line
			}
			if (!is_email($email)) {
				$report['errors'][] = sprintf(__('Line %d: invalid email', 'nimikh-lms'), $line);
				continue;
			}
			$user = get_user_by('email', $email);
			if (!$user) {
				$uid = wp_insert_user([
					'user_login'   => $email,
					'user_email'   => $email,
					'display_name' => sanitize_text_field((string) ($cols[1] ?? '')) ?: strstr($email, '@', true),
					'user_pass'    => wp_generate_password(20),
					'role'         => 'subscriber',
				]);
				if (is_wp_error($uid)) {
					$report['errors'][] = sprintf(__('Line %1$d: %2$s', 'nimikh-lms'), $line, $uid->get_error_message());
					continue;
				}
				$report['created']++;
				wp_new_user_notification($uid, null, 'user');
				$user = get_userdata($uid);
			}
			if ($onUser) {
				$onUser((int) $user->ID);
			}
			if ($this->tutor->enrol($user->ID, $courseId)) {
				$report['enrolled']++;
			} else {
				$report['errors'][] = sprintf(__('Line %d: could not enrol (is Tutor LMS active?)', 'nimikh-lms'), $line);
			}
		}
		return $report;
	}
}
