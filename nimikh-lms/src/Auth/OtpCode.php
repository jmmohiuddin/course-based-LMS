<?php
declare(strict_types=1);

namespace Nimikh\LMS\Auth;

/** One-time code primitives. Pure: the secret (a WordPress salt) is passed in. */
final class OtpCode {

	public const LENGTH = 6;

	public static function generate(): string {
		return str_pad((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0', STR_PAD_LEFT);
	}

	/** Codes are stored hashed and bound to the phone, so a leaked transient cannot be replayed for another number. */
	public static function hash(string $code, string $phone, string $secret): string {
		return hash_hmac('sha256', $phone . '|' . $code, $secret);
	}

	public static function verify(string $code, string $phone, string $secret, string $storedHash): bool {
		$code = strtr(trim($code), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
		return preg_match('/^\d{' . self::LENGTH . '}$/', $code) === 1
			&& hash_equals($storedHash, self::hash($code, $phone, $secret));
	}
}
