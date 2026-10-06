<?php
declare(strict_types=1);

namespace Nimikh\LMS\Support;

final class Roles {

	public const CAP_MANAGE = 'nimikh_manage';       // administrators: certificates, reports, batch tools
	public const CAP_AUTHOR = 'nimikh_author_video'; // instructors: questions + interactions

	public static function register(): void {
		add_role('nimikh_instructor', __('Nimikh Instructor', 'nimikh-lms'), [
			'read'              => true,
			self::CAP_AUTHOR    => true,
		]);
		add_role('nimikh_institute_admin', __('Nimikh Institute Admin', 'nimikh-lms'), [
			'read'              => true,
			self::CAP_AUTHOR    => true,
			self::CAP_MANAGE    => true,
		]);

		$admin = get_role('administrator');
		if ($admin) {
			$admin->add_cap(self::CAP_MANAGE);
			$admin->add_cap(self::CAP_AUTHOR);
		}
		// Tutor's own instructor role can also author interactions.
		$tutor = get_role('tutor_instructor');
		if ($tutor) {
			$tutor->add_cap(self::CAP_AUTHOR);
		}
	}
}
