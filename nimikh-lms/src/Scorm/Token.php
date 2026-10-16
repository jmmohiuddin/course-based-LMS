<?php
declare(strict_types=1);

namespace Nimikh\LMS\Scorm;

/**
 * Signed, expiring token carried in the content URL path. Sandboxed SCORM pages have an opaque origin and send no
 * cookies, so the URL itself must prove the learner was allowed to launch this package. Pure: the secret is passed in.
 */
final class Token {

	public static function make(int $lessonId, int $userId, string $packageId, int $expires, string $secret): string {
		return implode('.', [$lessonId, $userId, $expires, self::sig($lessonId, $userId, $packageId, $expires, $secret)]);
	}

	/** @return array{lesson:int, user:int, expires:int}|null */
	public static function verify(string $token, string $packageId, int $now, string $secret): ?array {
		if (preg_match('/^(\d{1,10})\.(\d{1,10})\.(\d{1,10})\.([a-f0-9]{64})$/', $token, $m) !== 1) {
			return null;
		}
		[$all, $lesson, $user, $exp, $sig] = $m;
		if ((int) $exp < $now || !hash_equals(self::sig((int) $lesson, (int) $user, $packageId, (int) $exp, $secret), $sig)) {
			return null;
		}
		return ['lesson' => (int) $lesson, 'user' => (int) $user, 'expires' => (int) $exp];
	}

	private static function sig(int $lessonId, int $userId, string $packageId, int $expires, string $secret): string {
		return hash_hmac('sha256', implode('|', ['scorm', $lessonId, $userId, $packageId, $expires]), $secret);
	}
}
