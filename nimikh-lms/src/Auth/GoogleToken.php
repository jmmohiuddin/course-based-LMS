<?php
declare(strict_types=1);

namespace Nimikh\LMS\Auth;

/** Checks the claims of a Google ID token that Google's tokeninfo endpoint already signature-verified. Pure. */
final class GoogleToken {

	/**
	 * @param array<string, mixed> $claims decoded tokeninfo response
	 * @return array{email:string, name:string, sub:string}|null null when anything is wrong
	 */
	public static function validate(array $claims, string $clientId, int $now): ?array {
		if ($clientId === '' || ($claims['aud'] ?? '') !== $clientId) {
			return null;
		}
		if (!in_array($claims['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) {
			return null;
		}
		if ((int) ($claims['exp'] ?? 0) <= $now) {
			return null;
		}
		$verified = $claims['email_verified'] ?? false;
		if (!($verified === true || $verified === 'true')) {
			return null;
		}
		$email = strtolower((string) ($claims['email'] ?? ''));
		$sub   = (string) ($claims['sub'] ?? '');
		if ($sub === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			return null;
		}
		return ['email' => $email, 'name' => trim((string) ($claims['name'] ?? '')), 'sub' => $sub];
	}
}
