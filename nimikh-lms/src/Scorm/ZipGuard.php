<?php
declare(strict_types=1);

namespace Nimikh\LMS\Scorm;

/**
 * Decides, from a zip's entry list alone, whether a SCORM package is safe to extract. Allowlist, not blocklist: any file
 * type we do not recognise is refused, so a stray .php/.phtml/.htaccess can never land in uploads. Pure.
 */
final class ZipGuard {

	public const MAX_ENTRIES    = 5000;
	public const MAX_TOTAL      = 314572800;  // 300 MB uncompressed
	public const MAX_FILE       = 157286400;  // 150 MB per file
	public const MAX_RATIO      = 200;        // uncompressed / compressed, refuses zip bombs

	public const ALLOWED = [
		'html', 'htm', 'xhtml', 'js', 'css', 'json', 'xml', 'xsd', 'dtd', 'txt', 'csv', 'md', 'map', 'vtt', 'srt',
		'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico', 'bmp',
		'mp3', 'm4a', 'ogg', 'oga', 'wav', 'mp4', 'm4v', 'webm', 'ogv',
		'woff', 'woff2', 'ttf', 'otf', 'eot', 'pdf',
	];

	/**
	 * @param array<int, array{name:string, size:int, compressed:int, symlink?:bool}> $entries
	 * @return array{ok:bool, error:string, detail:string, files:string[]} files = safe relative paths to extract
	 */
	public static function check(array $entries): array {
		$fail = static fn(string $code, string $detail = ''): array => ['ok' => false, 'error' => $code, 'detail' => $detail, 'files' => []];

		if (count($entries) > self::MAX_ENTRIES) {
			return $fail('too_many_files');
		}
		$files = [];
		$total = 0;
		$packed = 0;
		$hasManifest = false;

		foreach ($entries as $e) {
			$name = (string) $e['name'];
			if (str_ends_with($name, '/')) {
				continue; // directory entry
			}
			if (!empty($e['symlink'])) {
				return $fail('symlink', $name);
			}
			$clean = self::cleanPath($name);
			if ($clean === null) {
				return $fail('unsafe_path', $name);
			}
			$base = basename($clean);
			$ext  = strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
			if ($base[0] === '.' || !in_array($ext, self::ALLOWED, true)) {
				return $fail('file_type', $name);
			}
			if ($e['size'] > self::MAX_FILE) {
				return $fail('file_too_large', $name);
			}
			$total  += $e['size'];
			$packed += max(1, $e['compressed']);
			if (strtolower($clean) === 'imsmanifest.xml') {
				$hasManifest = true;
			}
			$files[] = $clean;
		}
		if ($total > self::MAX_TOTAL) {
			return $fail('too_large');
		}
		if ($total > 1048576 && $total / $packed > self::MAX_RATIO) {
			return $fail('zip_bomb');
		}
		if (!$hasManifest) {
			return $fail('no_manifest'); // imsmanifest.xml must be at the zip root
		}
		if (count($files) !== count(array_unique(array_map('strtolower', $files)))) {
			return $fail('duplicate_paths');
		}
		return ['ok' => true, 'error' => '', 'detail' => '', 'files' => $files];
	}

	/** Relative path with forward slashes, or null when it could escape the package folder. */
	public static function cleanPath(string $name): ?string {
		if ($name === '' || strlen($name) > 255 || str_contains($name, "\0") || str_contains($name, '\\')
			|| str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1 || preg_match('/[\x00-\x1f]/', $name) === 1) {
			return null;
		}
		foreach (explode('/', $name) as $part) {
			if ($part === '' || $part === '.' || $part === '..') {
				return null;
			}
		}
		return $name;
	}
}
