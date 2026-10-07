<?php
declare(strict_types=1);

namespace Nimikh\LMS\Sms;

final class PhoneNumber {

	/**
	 * Normalise a Bangladeshi mobile number to 8801XXXXXXXXX (no plus). Accepts Bangla digits,
	 * spaces/dashes, 01…, 8801…, +8801…. Other international numbers (+ and 10-15 digits) pass through.
	 */
	public static function normalise(string $input): ?string {
		$input = strtr(trim($input), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
		$plus   = str_starts_with($input, '+');
		$digits = preg_replace('/\D+/', '', $input) ?? '';

		if (preg_match('/^(?:880|0)(1[3-9]\d{8})$/', $digits, $m) === 1 && (!$plus || str_starts_with($digits, '880'))) {
			return '880' . $m[1];
		}
		if ($plus && preg_match('/^\d{10,15}$/', $digits) === 1) {
			return $digits;
		}
		return null;
	}
}
