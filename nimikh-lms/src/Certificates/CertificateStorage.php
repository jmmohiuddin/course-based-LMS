<?php
declare(strict_types=1);

namespace Nimikh\LMS\Certificates;

/**
 * Stores rendered certificates outside the public web path semantics: files live in a
 * directory with deny rules and are only ever streamed through the download route.
 * Replace via the `nimikh_lms_certificate_storage` filter to use R2/S3 (blueprint 5.5).
 */
final class CertificateStorage {

	public static function baseDir(): string {
		$uploads = wp_upload_dir(null, false);
		return trailingslashit($uploads['basedir']) . 'nimikh-certificates';
	}

	public static function protectDir(): void {
		$dir = self::baseDir();
		if (!is_dir($dir)) {
			return;
		}
		$files = [
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'index.html' => '',
		];
		foreach ($files as $name => $content) {
			if (!file_exists("$dir/$name")) {
				file_put_contents("$dir/$name", $content);
			}
		}
	}

	/** @return string storage key */
	public static function put(string $code, string $extension, string $bytes): string {
		wp_mkdir_p(self::baseDir());
		self::protectDir();
		$key = $code . '.' . $extension;
		file_put_contents(self::baseDir() . '/' . $key, $bytes);
		return $key;
	}

	public static function get(string $key): ?string {
		$key = basename($key);
		$path = self::baseDir() . '/' . $key;
		return is_readable($path) ? (string) file_get_contents($path) : null;
	}
}
