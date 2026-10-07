<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Guards the Bangla translation: stale files, broken placeholders, and an out-of-date .mo all fail CI. */
final class I18nTest extends TestCase {

	private const LANG = __DIR__ . '/../../languages/';

	/** Minimal GNU .mo reader. @return array<string, string> */
	private static function readMo(string $file): array {
		$d = (string) file_get_contents($file);
		$h = unpack('Vmagic/Vrev/Vn/Voff_o/Voff_t', $d);
		self::assertSame(0x950412de, $h['magic'], 'bad .mo magic number');
		$out = [];
		for ($i = 0; $i < $h['n']; $i++) {
			$o = unpack('Vlen/Voff', substr($d, $h['off_o'] + 8 * $i, 8));
			$t = unpack('Vlen/Voff', substr($d, $h['off_t'] + 8 * $i, 8));
			$out[substr($d, $o['off'], $o['len'])] = substr($d, $t['off'], $t['len']);
		}
		return $out;
	}

	/** @return string[] ids in the .pot (singular + plural forms) */
	private static function potIds(): array {
		$ids = [];
		preg_match_all('/^msgid(?:_plural)? ((?:".*"\R?)+)/m', (string) file_get_contents(self::LANG . 'nimikh-lms.pot'), $m);
		foreach ($m[1] as $chunk) {
			$ids[] = stripcslashes(implode('', array_map(static fn(string $l): string => substr(trim($l), 1, -1), preg_split('/\R/', trim($chunk)) ?: [])));
		}
		return array_filter($ids, static fn(string $s): bool => $s !== '');
	}

	private static function placeholders(string $s): array {
		preg_match_all('/%(?:\d+\$)?[sd]/', $s, $m);
		sort($m[0]);
		return $m[0];
	}

	public function test_translation_is_complete_and_consistent(): void {
		$mo = self::readMo(self::LANG . 'nimikh-lms-bn_BD.mo');
		$this->assertGreaterThan(150, count($mo));
		$this->assertStringContainsString('Plural-Forms:', $mo['']);
		$pot = array_flip(self::potIds());

		foreach ($mo as $id => $str) {
			if ($id === '') {
				continue;
			}
			$msgid = explode("\0", $id)[0];
			$this->assertArrayHasKey($msgid, $pot, "stale translation (not in .pot): $msgid");
			$forms = explode("\0", $str);
			foreach ($forms as $f) {
				$this->assertNotSame('', trim($f), "empty translation for: $msgid");
				$this->assertSame(self::placeholders($msgid), self::placeholders($f), "placeholder mismatch in: $msgid");
				$this->assertSame(str_ends_with($msgid, "\n"), str_ends_with($f, "\n"), "trailing newline mismatch in: $msgid");
			}
		}
	}

	public function test_mo_is_built_from_the_current_po(): void {
		$tmp = sys_get_temp_dir() . '/nk-' . bin2hex(random_bytes(4)) . '.mo';
		exec('php ' . escapeshellarg(__DIR__ . '/../../tools/compile-mo.php') . ' ' . escapeshellarg(self::LANG . 'nimikh-lms-bn_BD.po') . ' ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
		$this->assertSame(0, $code, implode("\n", $out));
		$this->assertSame(file_get_contents($tmp), file_get_contents(self::LANG . 'nimikh-lms-bn_BD.mo'), 'run: php tools/compile-mo.php languages/nimikh-lms-bn_BD.po');
		@unlink($tmp);
	}

	public function test_pot_matches_the_source_code(): void {
		$tmp = sys_get_temp_dir() . '/nk-' . bin2hex(random_bytes(4)) . '.pot';
		exec('php ' . escapeshellarg(__DIR__ . '/../../tools/make-pot.php') . ' ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
		$this->assertSame(0, $code, implode("\n", $out));
		$this->assertSame(file_get_contents($tmp), file_get_contents(self::LANG . 'nimikh-lms.pot'), 'a translatable string changed: run php tools/make-pot.php');
		@unlink($tmp);
	}

	public function test_every_learner_facing_js_string_has_a_translation(): void {
		// The player's i18n object is built from __() calls, so each must be in the compiled Bangla file.
		$src = (string) file_get_contents(__DIR__ . '/../../src/Video/FrontendPlayer.php');
		preg_match_all("/=> __\('((?:[^'\\\\]|\\\\.)*)', 'nimikh-lms'\)/", $src, $m);
		$mo = self::readMo(self::LANG . 'nimikh-lms-bn_BD.mo');
		$missing = array_filter(array_map(static fn(string $s): string => stripcslashes(str_replace("\\'", "'", $s)), $m[1]), static fn(string $s): bool => !isset($mo[$s]));
		$this->assertSame([], array_values($missing), 'untranslated player strings');
	}
}
