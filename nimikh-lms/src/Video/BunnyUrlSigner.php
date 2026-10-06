<?php
declare(strict_types=1);

namespace Nimikh\LMS\Video;

/**
 * Bunny CDN token authentication (basic scheme):
 * token = base64url(sha256(key + path + expires [+ ip])), appended with `expires`.
 */
final class BunnyUrlSigner {

	public static function sign(string $host, string $path, string $key, int $expires, string $ip = ''): string {
		$path  = '/' . ltrim($path, '/');
		$raw   = hash('sha256', $key . $path . $expires . $ip, true);
		$token = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

		return sprintf('https://%s%s?token=%s&expires=%d', $host, $path, $token, $expires);
	}
}
