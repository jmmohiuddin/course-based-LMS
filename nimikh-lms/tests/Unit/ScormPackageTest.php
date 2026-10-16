<?php
declare(strict_types=1);

/**
 * Extracts real zip files with PackageStore. Minimal stand-ins for the few WordPress functions it calls, so this runs without WordPress.
 */
namespace {
	if (!class_exists('WP_Error')) {
		class WP_Error { public function __construct(public string $code = '', public string $message = '', public array $data = []) {} public function get_error_code(): string { return $this->code; } }
	}
	if (!function_exists('is_wp_error')) { function is_wp_error($v): bool { return $v instanceof WP_Error; } }
	if (!function_exists('__')) { function __(string $t, string $d = 'default'): string { return $t; } }
	if (!function_exists('wp_upload_dir')) { function wp_upload_dir(): array { return ['basedir' => $GLOBALS['nk_uploads']]; } }
	if (!function_exists('wp_mkdir_p')) { function wp_mkdir_p(string $d): bool { return is_dir($d) || mkdir($d, 0755, true); } }
	if (!function_exists('wp_slash')) { function wp_slash($v) { return $v; } }
	if (!function_exists('update_post_meta')) { function update_post_meta(int $id, string $k, $v): bool { $GLOBALS['nk_meta'][$id][$k] = $v; return true; } }
	if (!function_exists('get_post_meta')) { function get_post_meta(int $id, string $k, bool $single = false) { return $GLOBALS['nk_meta'][$id][$k] ?? ''; } }
	if (!function_exists('delete_post_meta')) { function delete_post_meta(int $id, string $k): bool { unset($GLOBALS['nk_meta'][$id][$k]); return true; } }
}

namespace {
	use Nimikh\LMS\Scorm\PackageStore;
	use PHPUnit\Framework\TestCase;

	final class ScormPackageTest extends TestCase {

		private string $tmp;

		protected function setUp(): void {
			$this->tmp = sys_get_temp_dir() . '/nk-scorm-' . bin2hex(random_bytes(4));
			mkdir($this->tmp);
			$GLOBALS['nk_uploads'] = $this->tmp . '/uploads';
			$GLOBALS['nk_meta']    = [];
		}

		protected function tearDown(): void {
			PackageStore::rrmdir($this->tmp);
		}

		private const MANIFEST = '<?xml version="1.0"?><manifest identifier="m" xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2" xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_rootv1p2"><metadata><schemaversion>1.2</schemaversion></metadata><organizations default="o"><organization identifier="o"><title>Demo</title><item identifier="i" identifierref="r"><title>t</title></item></organization></organizations><resources><resource identifier="r" type="webcontent" href="index.html"/></resources></manifest>';

		/** @param array<string,string> $files */
		private function zip(array $files, ?callable $tweak = null): string {
			$path = $this->tmp . '/p' . bin2hex(random_bytes(3)) . '.zip';
			$z = new \ZipArchive();
			$z->open($path, \ZipArchive::CREATE);
			foreach ($files as $name => $body) { $z->addFromString($name, $body); }
			if ($tweak) { $tweak($z); }
			$z->close();
			return $path;
		}

		public function test_a_good_package_is_extracted_and_described(): void {
			$p = $this->zip(['imsmanifest.xml' => self::MANIFEST, 'index.html' => '<html><head></head><body>Hi</body></html>', 'js/app.js' => 'var a=1;', 'img/logo.png' => 'PNG']);
			$meta = PackageStore::install(5, $p);
			$this->assertIsArray($meta);
			$this->assertSame(['1.2', 'Demo', 'index.html', 4], [$meta['version'], $meta['title'], $meta['launch'], $meta['files']]);
			$dir = PackageStore::dir($meta['id']);
			$this->assertFileExists($dir . '/js/app.js');
			$this->assertSame('var a=1;', file_get_contents($dir . '/js/app.js'));
			$this->assertSame($meta['id'], PackageStore::forLesson(5)['id']);
			$this->assertFileExists(PackageStore::baseDir() . '/.htaccess', 'direct web access is denied');
		}

		public function test_replacing_a_package_removes_the_old_files(): void {
			$a = PackageStore::install(5, $this->zip(['imsmanifest.xml' => self::MANIFEST, 'index.html' => 'a']));
			$b = PackageStore::install(5, $this->zip(['imsmanifest.xml' => self::MANIFEST, 'index.html' => 'b']));
			$this->assertDirectoryDoesNotExist(PackageStore::dir($a['id']));
			$this->assertDirectoryExists(PackageStore::dir($b['id']));
			PackageStore::remove(5);
			$this->assertDirectoryDoesNotExist(PackageStore::dir($b['id']));
			$this->assertNull(PackageStore::forLesson(5));
		}

		/** @dataProvider hostile */
		public function test_hostile_packages_leave_nothing_behind(array $files, string $code, ?callable $tweak = null): void {
			$r = PackageStore::install(5, $this->zip($files, $tweak));
			$this->assertInstanceOf(\WP_Error::class, $r);
			$this->assertSame($code, $r->get_error_code());
			$this->assertNull(PackageStore::forLesson(5));
			$leftover = is_dir(PackageStore::baseDir()) ? array_diff(scandir(PackageStore::baseDir()) ?: [], ['.', '..', '.htaccess', 'index.html']) : [];
			$this->assertSame([], array_values($leftover), 'no package folder remains');
			$this->assertFileDoesNotExist($this->tmp . '/evil.html');
		}

		public static function hostile(): array {
			$m = self::MANIFEST;
			return [
				'traversal'      => [['imsmanifest.xml' => $m, 'index.html' => 'x', '../../evil.html' => 'x'], 'nimikh_scorm_unsafe_path'],
				'php payload'    => [['imsmanifest.xml' => $m, 'index.html' => 'x', 'shell.php' => '<?php system($_GET[1]);'], 'nimikh_scorm_file_type'],
				'htaccess'       => [['imsmanifest.xml' => $m, 'index.html' => 'x', '.htaccess' => 'AddType application/x-httpd-php .html'], 'nimikh_scorm_file_type'],
				'no manifest'    => [['index.html' => 'x'], 'nimikh_scorm_no_manifest'],
				'launch missing' => [['imsmanifest.xml' => $m], 'nimikh_scorm_launch'],
				'bad manifest'   => [['imsmanifest.xml' => '<manifest', 'index.html' => 'x'], 'nimikh_scorm_manifest'],
				'xml entities'   => [['imsmanifest.xml' => '<?xml version="1.0"?><!DOCTYPE m [<!ENTITY a "b">]><manifest>&a;</manifest>', 'index.html' => 'x'], 'nimikh_scorm_manifest'],
				'symlink'        => [['imsmanifest.xml' => $m, 'index.html' => 'x', 'link.html' => '/etc/passwd'], 'nimikh_scorm_symlink', static function (\ZipArchive $z): void {
					$z->setExternalAttributesName('link.html', \ZipArchive::OPSYS_UNIX, 0120777 << 16);
				}],
			];
		}

		public function test_not_a_zip(): void {
			$f = $this->tmp . '/x.zip';
			file_put_contents($f, 'this is not a zip');
			$r = PackageStore::install(5, $f);
			$this->assertSame('nimikh_scorm_bad_zip', $r->get_error_code());
		}
	}
}
