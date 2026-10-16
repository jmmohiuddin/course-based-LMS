<?php
declare(strict_types=1);

use Nimikh\LMS\Scorm\DataModel;
use Nimikh\LMS\Scorm\Manifest;
use Nimikh\LMS\Scorm\Shim;
use Nimikh\LMS\Scorm\Token;
use Nimikh\LMS\Scorm\ZipGuard;
use PHPUnit\Framework\TestCase;

final class ScormTest extends TestCase {

	private function entry(string $name, int $size = 100, int $packed = 50, bool $symlink = false): array {
		return ['name' => $name, 'size' => $size, 'compressed' => $packed, 'symlink' => $symlink];
	}

	public function test_a_normal_package_is_accepted(): void {
		$r = ZipGuard::check([$this->entry('imsmanifest.xml'), $this->entry('index.html'), $this->entry('assets/'), $this->entry('assets/app.js'), $this->entry('assets/Logo.PNG')]);
		$this->assertTrue($r['ok'], $r['error']);
		$this->assertSame(['imsmanifest.xml', 'index.html', 'assets/app.js', 'assets/Logo.PNG'], $r['files']);
	}

	/** @dataProvider badPackages */
	public function test_unsafe_packages_are_refused(array $entries, string $error): void {
		$this->assertSame($error, ZipGuard::check($entries)['error']);
	}

	public static function badPackages(): array {
		$e = static fn(string $n, int $s = 10, int $c = 5, bool $l = false): array => ['name' => $n, 'size' => $s, 'compressed' => $c, 'symlink' => $l];
		$m = $e('imsmanifest.xml');
		return [
			'path traversal'      => [[$m, $e('../evil.html')], 'unsafe_path'],
			'nested traversal'    => [[$m, $e('a/../../evil.html')], 'unsafe_path'],
			'absolute path'       => [[$m, $e('/etc/passwd.txt')], 'unsafe_path'],
			'windows drive'       => [[$m, $e('C:evil.html')], 'unsafe_path'],
			'backslash'           => [[$m, $e('a\\b.html')], 'unsafe_path'],
			'php file'            => [[$m, $e('shell.php')], 'file_type'],
			'php hidden in html'  => [[$m, $e('x.php.html.phtml')], 'file_type'],
			'htaccess'            => [[$m, $e('.htaccess')], 'file_type'],
			'dotfile html'        => [[$m, $e('.hidden.html')], 'file_type'],
			'no extension'        => [[$m, $e('LICENSE')], 'file_type'],
			'exe'                 => [[$m, $e('setup.exe')], 'file_type'],
			'symlink'             => [[$m, $e('link.html', 10, 5, true)], 'symlink'],
			'no manifest'         => [[$e('index.html')], 'no_manifest'],
			'manifest not at root' => [[$e('pkg/imsmanifest.xml')], 'no_manifest'],
			'zip bomb'            => [[$m, $e('big.txt', 100 * 1048576, 1000)], 'zip_bomb'],
			'huge file'           => [[$m, $e('big.mp4', ZipGuard::MAX_FILE + 1, ZipGuard::MAX_FILE)], 'file_too_large'],
			'too big overall'     => [[$m, $e('a.mp4', 150000000, 150000000), $e('b.mp4', 150000000, 150000000), $e('c.mp4', 150000000, 150000000)], 'too_large'],
			'duplicate paths'     => [[$m, $e('a.html'), $e('A.html')], 'duplicate_paths'],
		];
	}

	public function test_too_many_entries(): void {
		$entries = [['name' => 'imsmanifest.xml', 'size' => 1, 'compressed' => 1]];
		for ($i = 0; $i < ZipGuard::MAX_ENTRIES + 1; $i++) { $entries[] = ['name' => "f$i.txt", 'size' => 1, 'compressed' => 1]; }
		$this->assertSame('too_many_files', ZipGuard::check($entries)['error']);
	}

	private const M12 = '<?xml version="1.0"?><manifest identifier="m" xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2" xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_rootv1p2">
		<metadata><schema>ADL SCORM</schema><schemaversion>1.2</schemaversion></metadata>
		<organizations default="o1"><organization identifier="o1"><title>Safety Basics</title>
		<item identifier="i1" identifierref="r1" parameters="?mode=a"><title>Lesson</title><adlcp:masteryscore>80</adlcp:masteryscore></item></organization></organizations>
		<resources><resource identifier="r1" type="webcontent" adlcp:scormtype="sco" href="content/start%20here.html"/></resources></manifest>';

	private const M2004 = '<?xml version="1.0"?><manifest identifier="m" xmlns="http://www.imsglobal.org/xsd/imscp_v1p1" xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_v1p3" xmlns:imsss="http://www.imsglobal.org/xsd/imsss">
		<metadata><schema>ADL SCORM</schema><schemaversion>2004 4th Edition</schemaversion></metadata>
		<organizations default="o"><organization identifier="o"><title>Ethics</title><item identifier="i" identifierref="r"><title>x</title>
		<imsss:sequencing><imsss:objectives><imsss:primaryObjective satisfiedByMeasure="true"><imsss:minNormalizedMeasure>0.7</imsss:minNormalizedMeasure></imsss:primaryObjective></imsss:objectives></imsss:sequencing></item></organization></organizations>
		<resources xml:base="res/"><resource identifier="r" adlcp:scormType="sco" href="index.html?x=1"/></resources></manifest>';

	public function test_manifest_scorm_12(): void {
		$m = Manifest::parse(self::M12);
		$this->assertSame(['version' => '1.2', 'title' => 'Safety Basics', 'launch' => 'content/start here.html', 'query' => '?mode=a', 'mastery' => 80.0], $m);
	}

	public function test_manifest_scorm_2004_with_base_and_mastery(): void {
		$m = Manifest::parse(self::M2004);
		$this->assertSame('2004', $m['version']);
		$this->assertSame('res/index.html', $m['launch']);
		$this->assertSame('?x=1', $m['query']);
		$this->assertEqualsWithDelta(70.0, $m['mastery'], 0.001);
	}

	public function test_manifest_rejects_bad_input(): void {
		$this->assertNull(Manifest::parse(''));
		$this->assertNull(Manifest::parse('not xml'));
		$this->assertNull(Manifest::parse('<?xml version="1.0"?><!DOCTYPE m [<!ENTITY a "aaaa">]><manifest>&a;</manifest>'), 'entities refused');
		$this->assertNull(Manifest::parse(str_replace('1.2</schemaversion>', 'cmi5</schemaversion>', self::M12)), 'unsupported standard');
		$this->assertNull(Manifest::parse(str_replace('content/start%20here.html', '../../etc/x.html', self::M12)), 'launch outside the package');
		$this->assertNull(Manifest::parse(str_replace('href="content/start%20here.html"', '', self::M12)), 'no launch file');
		$this->assertSame('', Manifest::parse(str_replace('?mode=a', '?x=a$b', self::M12))['query'], 'odd query dropped');
	}

	public function test_data_model_allowlist(): void {
		foreach (['cmi.core.lesson_status', 'cmi.suspend_data', 'cmi.interactions.3.student_response', 'cmi.core.score.raw'] as $k) { $this->assertTrue(DataModel::isWritable('1.2', $k), $k); }
		foreach (['cmi.core.student_id', 'cmi.core.total_time', 'cmi.launch_data', 'cmi.core.score._children', 'foo', 'cmi.interactions.1000.id', '__proto__'] as $k) { $this->assertFalse(DataModel::isWritable('1.2', $k), $k); }
		foreach (['cmi.completion_status', 'cmi.score.scaled', 'cmi.objectives.0.success_status'] as $k) { $this->assertTrue(DataModel::isWritable('2004', $k), $k); }
		$this->assertFalse(DataModel::isWritable('2004', 'cmi.learner_id'));
		$this->assertSame(['cmi.core.lesson_status' => 'completed', 'cmi.core.score.raw' => '90'], DataModel::sanitize('1.2', ['cmi.core.lesson_status' => 'completed', 'cmi.core.score.raw' => 90, 'cmi.core.student_id' => 'hacker', 'x' => 'y', 'cmi.suspend_data' => ['arr']]));
	}

	public function test_time_parsing(): void {
		$this->assertSame(3723, DataModel::seconds('0001:02:03.50'));
		$this->assertSame(3723, DataModel::seconds('PT1H2M3S'));
		$this->assertSame(90, DataModel::seconds('PT1M30S'));
		$this->assertSame(86400, DataModel::seconds('P1D'));
		$this->assertNull(DataModel::seconds('soon'));
		$this->assertNull(DataModel::seconds('P'));
	}

	public function test_completion_scorm_12(): void {
		$this->assertTrue(DataModel::evaluate('1.2', ['cmi.core.lesson_status' => 'passed'], null)['completed']);
		$this->assertTrue(DataModel::evaluate('1.2', ['cmi.core.lesson_status' => 'completed'], null)['completed']);
		$this->assertFalse(DataModel::evaluate('1.2', ['cmi.core.lesson_status' => 'incomplete'], null)['completed']);
		$f = DataModel::evaluate('1.2', ['cmi.core.lesson_status' => 'failed', 'cmi.core.score.raw' => '30'], 80.0);
		$this->assertFalse($f['completed']);
		$this->assertFalse($f['passed']);
		$this->assertSame(30.0, $f['score']);
		$m = DataModel::evaluate('1.2', ['cmi.core.lesson_status' => 'completed', 'cmi.core.score.raw' => '50'], 80.0);
		$this->assertTrue($m['completed']);
		$this->assertFalse($m['passed'], 'completed with a score under the mastery score is not a pass');
		$this->assertSame(25.0, DataModel::evaluate('1.2', ['cmi.core.score.raw' => '5', 'cmi.core.score.min' => '0', 'cmi.core.score.max' => '20'], null)['score']);
	}

	public function test_completion_scorm_2004(): void {
		$this->assertTrue(DataModel::evaluate('2004', ['cmi.completion_status' => 'completed'], null)['completed']);
		$this->assertTrue(DataModel::evaluate('2004', ['cmi.success_status' => 'passed'], null)['completed'], 'pass with no completion data counts');
		$this->assertFalse(DataModel::evaluate('2004', ['cmi.completion_status' => 'incomplete', 'cmi.success_status' => 'passed'], null)['completed']);
		$r = DataModel::evaluate('2004', ['cmi.completion_status' => 'completed', 'cmi.success_status' => 'failed', 'cmi.score.scaled' => '0.4'], null);
		$this->assertSame([true, false, 40.0], [$r['completed'], $r['passed'], $r['score']]);
	}

	public function test_token(): void {
		$t = Token::make(7, 3, 'pkgA', 2000, 'secret');
		$this->assertSame(['lesson' => 7, 'user' => 3, 'expires' => 2000], Token::verify($t, 'pkgA', 1999, 'secret'));
		$this->assertNull(Token::verify($t, 'pkgA', 2001, 'secret'), 'expired');
		$this->assertNull(Token::verify($t, 'pkgB', 1999, 'secret'), 'package replaced');
		$this->assertNull(Token::verify($t, 'pkgA', 1999, 'other'), 'wrong secret');
		$this->assertNull(Token::verify(str_replace('7.3.', '7.4.', $t), 'pkgA', 1999, 'secret'), 'user swapped');
		$this->assertNull(Token::verify('garbage', 'pkgA', 1999, 'secret'));
	}

	public function test_shim_is_valid_javascript_and_safe_to_embed(): void {
		$js = Shim::script('1.2', ['cmi.core.student_name' => 'Evil </script><img src=x onerror=alert(1)>'], 'https://lms.example');
		$this->assertStringNotContainsString('</script>', $js, 'learner data cannot close the script tag');
		$this->assertStringContainsString('window.API =', $js);
		$this->assertStringContainsString('API_1484_11', Shim::script('2004', [], 'https://x'));
		$html = Shim::inject('<html><head><title>x</title></head><body></body></html>', 'A()');
		$this->assertStringStartsWith('<html><head><script>A()</script><title>', $html);
		$this->assertStringStartsWith('<script>A()</script>', Shim::inject('<p>bare</p>', 'A()'));
	}
}
