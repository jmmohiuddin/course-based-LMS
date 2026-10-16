<?php
declare(strict_types=1);

namespace Nimikh\LMS\Scorm;

/**
 * Serves package files at /nimikh-scorm/{token}/{path}.
 *
 * Why it is built this way: a SCORM package is somebody else's JavaScript. It is served with a CSP `sandbox` header, which
 * gives every page an opaque origin: it cannot read WordPress cookies, call the REST API as the learner, or touch the
 * parent page. It also means requests carry no cookies, so the signed token in the URL is the credential. The only channel
 * back to the site is the postMessage bridge in Shim, which the player validates.
 */
final class ContentRoute {

	public const QV_TOKEN = 'nimikh_scorm_token';

	public function __construct(private ScormService $service) {}

	public function register(): void {
		add_action('init', [self::class, 'addRewriteRules']);
		add_filter('query_vars', static fn(array $v): array => array_merge($v, [self::QV_TOKEN]));
		add_action('template_redirect', [$this, 'handle'], 1);
		add_action('before_delete_post', static function (int $id): void {
			if (get_post_type($id) === 'lesson') {
				PackageStore::remove($id);
			}
		});
	}

	public static function addRewriteRules(): void {
		add_rewrite_rule('^nimikh-scorm/(\d+\.\d+\.\d+\.[a-f0-9]{64})/(.+)$', 'index.php?' . self::QV_TOKEN . '=$matches[1]', 'top');
	}

	public static function url(string $token, string $path, string $query = ''): string {
		$segments = array_map('rawurlencode', explode('/', $path));
		return home_url('/nimikh-scorm/' . $token . '/' . implode('/', $segments)) . $query;
	}

	/** Content-Security-Policy applied to everything served from a package. */
	public static function csp(): string {
		return "sandbox allow-scripts allow-forms allow-popups allow-modals; frame-ancestors 'self'";
	}

	/** @var array<string,string> */
	private const MIME = [
		'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8', 'xhtml' => 'text/html; charset=utf-8',
		'js' => 'application/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'json' => 'application/json; charset=utf-8',
		'xml' => 'application/xml; charset=utf-8', 'xsd' => 'application/xml', 'dtd' => 'application/xml-dtd', 'txt' => 'text/plain; charset=utf-8',
		'csv' => 'text/csv; charset=utf-8', 'md' => 'text/plain; charset=utf-8', 'map' => 'application/json', 'vtt' => 'text/vtt; charset=utf-8', 'srt' => 'text/plain; charset=utf-8',
		'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp', 'ico' => 'image/x-icon', 'bmp' => 'image/bmp',
		'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'wav' => 'audio/wav', 'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg',
		'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf', 'eot' => 'application/vnd.ms-fontobject', 'pdf' => 'application/pdf',
	];

	public function handle(): void {
		$token = (string) get_query_var(self::QV_TOKEN);
		if ($token === '') {
			return;
		}
		$uri = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if (preg_match('#/nimikh-scorm/[^/]+/(.+)$#', $uri, $m) !== 1) {
			$this->deny(404);
		}
		$path = ZipGuard::cleanPath(rawurldecode($m[1])); // decoded exactly once, then validated
		$lessonId = (int) explode('.', $token)[0];
		$pkg = PackageStore::forLesson($lessonId);
		$claims = $pkg ? Token::verify($token, $pkg['id'], time(), ScormService::secret()) : null;
		if ($path === null || !$pkg || !$claims || $claims['lesson'] !== $lessonId) {
			$this->deny(403);
		}
		$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if (!in_array($method, ['GET', 'HEAD'], true)) {
			$this->deny(405);
		}

		$base = realpath(PackageStore::dir($pkg['id']));
		$file = $base ? realpath($base . '/' . $path) : false;
		$ext  = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
		if (!$base || !$file || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file) || !isset(self::MIME[$ext])) {
			$this->deny(404);
		}

		nocache_headers();
		header('Content-Type: ' . self::MIME[$ext]);
		header('Content-Security-Policy: ' . self::csp());
		header('X-Content-Type-Options: nosniff');
		header('Referrer-Policy: no-referrer');
		header('X-Robots-Tag: noindex, nofollow');
		header('Access-Control-Allow-Origin: *');
		header('Cross-Origin-Resource-Policy: cross-origin');

		if (in_array($ext, ['html', 'htm', 'xhtml'], true)) {
			$init = $this->service->initialValues($claims['user'], $lessonId);
			$html = Shim::inject((string) file_get_contents($file), Shim::script($pkg['version'], $init, home_url()));
			header('Content-Length: ' . strlen($html));
			if ($method !== 'HEAD') {
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- third-party package content, delivered inside a CSP sandbox by design
			}
			exit;
		}
		header('Cache-Control: private, max-age=600');
		$this->sendFile($file, $method === 'HEAD');
	}

	private function sendFile(string $file, bool $headOnly): void {
		$size  = (int) filesize($file);
		$start = 0;
		$end   = $size - 1;
		header('Accept-Ranges: bytes');
		if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', (string) $_SERVER['HTTP_RANGE'], $r) === 1 && ($r[1] !== '' || $r[2] !== '')) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$start = $r[1] === '' ? max(0, $size - (int) $r[2]) : (int) $r[1];
			$end   = $r[1] !== '' && $r[2] !== '' ? min((int) $r[2], $size - 1) : $size - 1;
			if ($start > $end || $start >= $size) {
				status_header(416);
				header('Content-Range: bytes */' . $size);
				exit;
			}
			status_header(206);
			header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
		}
		header('Content-Length: ' . ($end - $start + 1));
		if (!$headOnly) {
			$fh = fopen($file, 'rb');
			fseek($fh, $start);
			$left = $end - $start + 1;
			while ($left > 0 && !feof($fh)) {
				$chunk = fread($fh, min(65536, $left));
				if ($chunk === false || $chunk === '') {
					break;
				}
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput -- binary file
				$left -= strlen($chunk);
			}
			fclose($fh);
		}
		exit;
	}

	private function deny(int $code): void {
		status_header($code);
		header('Content-Type: text/plain; charset=utf-8');
		header('X-Content-Type-Options: nosniff');
		exit;
	}
}
