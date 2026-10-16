<?php
declare(strict_types=1);

namespace Nimikh\LMS\Scorm;

/** Validates, extracts and removes SCORM packages. Files live under uploads/nimikh-scorm/{random id}/ and are only ever served by ContentRoute. */
final class PackageStore {

	public const META = 'nimikh_scorm';

	public static function baseDir(): string {
		$u = wp_upload_dir();
		return rtrim($u['basedir'], '/') . '/nimikh-scorm';
	}

	/** @return array{id:string, version:string, title:string, launch:string, query:string, mastery:?float, files:int, bytes:int, uploaded_at:string}|null */
	public static function forLesson(int $lessonId): ?array {
		$m = get_post_meta($lessonId, self::META, true);
		return is_array($m) && !empty($m['id']) && preg_match('/^[a-f0-9]{16}$/', (string) $m['id']) === 1 ? $m : null;
	}

	public static function dir(string $id): string {
		return self::baseDir() . '/' . $id;
	}

	/** @return array<string, mixed>|\WP_Error the saved package meta */
	public static function install(int $lessonId, string $zipPath) {
		if (!class_exists(\ZipArchive::class)) {
			return new \WP_Error('nimikh_scorm_zip', __('The PHP zip extension is not installed on this server.', 'nimikh-lms'), ['status' => 500]);
		}
		$zip = new \ZipArchive();
		if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
			return new \WP_Error('nimikh_scorm_bad_zip', __('That file is not a valid zip archive.', 'nimikh-lms'), ['status' => 400]);
		}
		$entries = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$st = $zip->statIndex($i);
			if ($st === false) {
				$zip->close();
				return new \WP_Error('nimikh_scorm_bad_zip', __('That file is not a valid zip archive.', 'nimikh-lms'), ['status' => 400]);
			}
			$symlink = false;
			if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX) {
				$symlink = ((($attr >> 16) & 0170000) === 0120000);
			}
			$entries[] = ['name' => (string) $st['name'], 'size' => (int) $st['size'], 'compressed' => (int) $st['comp_size'], 'symlink' => $symlink];
		}
		$check = ZipGuard::check($entries);
		if (!$check['ok']) {
			$zip->close();
			return new \WP_Error('nimikh_scorm_' . $check['error'], self::errorMessage($check['error'], $check['detail']), ['status' => 400]);
		}

		$manifest = Manifest::parse((string) $zip->getFromName('imsmanifest.xml'));
		if ($manifest === null) {
			$zip->close();
			return new \WP_Error('nimikh_scorm_manifest', __('The package has no readable imsmanifest.xml, or it is not SCORM 1.2 or SCORM 2004.', 'nimikh-lms'), ['status' => 400]);
		}
		if (!in_array($manifest['launch'], $check['files'], true)) {
			$zip->close();
			return new \WP_Error('nimikh_scorm_launch', __('The launch file named in the manifest is not in the package.', 'nimikh-lms'), ['status' => 400]);
		}

		$id  = bin2hex(random_bytes(8));
		$dir = self::dir($id);
		if (!wp_mkdir_p($dir)) {
			$zip->close();
			return new \WP_Error('nimikh_scorm_write', __('Could not create the package folder.', 'nimikh-lms'), ['status' => 500]);
		}
		// Belt and braces: the folder is never meant to be served directly, only through ContentRoute.
		file_put_contents(self::baseDir() . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
		file_put_contents(self::baseDir() . '/index.html', '');

		$bytes = 0;
		foreach ($check['files'] as $name) {
			$in = $zip->getStream($name);
			if ($in === false) {
				self::rrmdir($dir);
				$zip->close();
				return new \WP_Error('nimikh_scorm_read', __('Could not read a file inside the package.', 'nimikh-lms'), ['status' => 400]);
			}
			$dest = $dir . '/' . $name;
			wp_mkdir_p(dirname($dest));
			$out  = fopen($dest, 'wb');
			$size = 0;
			while (!feof($in)) {
				$chunk = fread($in, 65536);
				if ($chunk === false) {
					break;
				}
				$size += strlen($chunk);
				// The size in the zip header can lie; count what is really written.
				if ($size > ZipGuard::MAX_FILE || $bytes + $size > ZipGuard::MAX_TOTAL) {
					fclose($out);
					fclose($in);
					self::rrmdir($dir);
					$zip->close();
					return new \WP_Error('nimikh_scorm_too_large', self::errorMessage('too_large', ''), ['status' => 400]);
				}
				fwrite($out, $chunk);
			}
			fclose($out);
			fclose($in);
			$bytes += $size;
		}
		$zip->close();

		$old = self::forLesson($lessonId);
		$meta = $manifest + ['id' => $id, 'files' => count($check['files']), 'bytes' => $bytes, 'uploaded_at' => gmdate('Y-m-d H:i:s')];
		update_post_meta($lessonId, self::META, wp_slash($meta));
		if ($old) {
			self::rrmdir(self::dir($old['id']));
		}
		return $meta;
	}

	public static function remove(int $lessonId): void {
		$old = self::forLesson($lessonId);
		if ($old) {
			self::rrmdir(self::dir($old['id']));
		}
		delete_post_meta($lessonId, self::META);
	}

	public static function rrmdir(string $dir): void {
		if (!is_dir($dir) || is_link($dir)) {
			return;
		}
		foreach (scandir($dir) ?: [] as $f) {
			if ($f === '.' || $f === '..') {
				continue;
			}
			$p = $dir . '/' . $f;
			is_dir($p) && !is_link($p) ? self::rrmdir($p) : @unlink($p); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	private static function errorMessage(string $code, string $detail): string {
		$d = $detail !== '' ? ' (' . $detail . ')' : '';
		return match ($code) {
			'too_many_files'  => __('The package has too many files.', 'nimikh-lms'),
			'too_large', 'file_too_large' => __('The package is too large (limit 300 MB, 150 MB per file).', 'nimikh-lms') . $d,
			'zip_bomb'        => __('The package looks like a decompression bomb and was refused.', 'nimikh-lms'),
			'no_manifest'     => __('imsmanifest.xml must be at the top level of the zip.', 'nimikh-lms'),
			'unsafe_path'     => __('The package contains an unsafe file path and was refused.', 'nimikh-lms') . $d,
			'symlink'         => __('The package contains a symbolic link and was refused.', 'nimikh-lms') . $d,
			'duplicate_paths' => __('The package contains two files whose names differ only by capital letters.', 'nimikh-lms'),
			default           => __('The package contains a file type that is not allowed (for example PHP or executables).', 'nimikh-lms') . $d,
		};
	}
}
