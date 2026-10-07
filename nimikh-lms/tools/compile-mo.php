<?php
/**
 * Compiles a .po file into a .mo (GNU gettext binary format) without needing msgfmt.
 * Usage: php tools/compile-mo.php languages/nimikh-lms-bn_BD.po
 */
declare(strict_types=1);

if ($argc < 2) { fwrite(STDERR, "usage: php tools/compile-mo.php file.po [file.mo]\n"); exit(1); }
$src = $argv[1];
$dst = $argv[2] ?? preg_replace('/\.po$/', '.mo', $src);

function unquote(string $s): string {
	$s = trim($s);
	if ($s === '' || $s[0] !== '"') { return ''; }
	return stripcslashes(substr($s, 1, -1));
}

$entries = []; $cur = null; $field = null;
$flush = static function () use (&$entries, &$cur): void {
	if ($cur !== null && $cur['id'] !== null) {
		$msgstr = $cur['plural'] !== null ? implode("\0", $cur['str']) : ($cur['str'][0] ?? '');
		if (trim(str_replace("\0", '', $msgstr)) !== '' && !$cur['fuzzy'] || $cur['id'] === '') {
			$id = ($cur['ctx'] !== null ? $cur['ctx'] . "\x04" : '') . $cur['id'] . ($cur['plural'] !== null ? "\0" . $cur['plural'] : '');
			$entries[$id] = $msgstr;
		}
	}
	$cur = null;
};
$fuzzy = false;
foreach (file($src, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
	$line = rtrim($line);
	if ($line === '') { continue; }
	if ($line[0] === '#') { if (str_starts_with($line, '#,') && str_contains($line, 'fuzzy')) { $fuzzy = true; } continue; }
	if (str_starts_with($line, 'msgctxt ')) { $flush(); $cur = ['ctx' => unquote(substr($line, 8)), 'id' => null, 'plural' => null, 'str' => [], 'fuzzy' => $fuzzy]; $fuzzy = false; $field = 'ctx'; continue; }
	if (str_starts_with($line, 'msgid_plural ')) { $cur['plural'] = unquote(substr($line, 13)); $field = 'plural'; continue; }
	if (str_starts_with($line, 'msgid ')) {
		if ($cur === null || $cur['id'] !== null) { $flush(); $cur = ['ctx' => null, 'id' => null, 'plural' => null, 'str' => [], 'fuzzy' => $fuzzy]; $fuzzy = false; }
		$cur['id'] = unquote(substr($line, 6)); $field = 'id'; continue;
	}
	if (preg_match('/^msgstr(?:\[(\d+)\])? (.*)$/', $line, $m)) { $idx = $m[1] === '' ? 0 : (int) $m[1]; $cur['str'][$idx] = unquote($m[2]); $field = ['str', $idx]; continue; }
	if ($line[0] === '"' && $cur !== null) { // continuation
		$v = unquote($line);
		if ($field === 'id') { $cur['id'] .= $v; } elseif ($field === 'plural') { $cur['plural'] .= $v; } elseif ($field === 'ctx') { $cur['ctx'] .= $v; } elseif (is_array($field)) { $cur['str'][$field[1]] .= $v; }
	}
}
$flush();
ksort($entries, SORT_STRING);

$n = count($entries);
$ids = ''; $strs = ''; $idTable = []; $strTable = [];
foreach ($entries as $id => $str) {
	$idTable[]  = [strlen($id), strlen($ids)];   $ids  .= $id . "\0";
	$strTable[] = [strlen($str), strlen($strs)]; $strs .= $str . "\0";
}
$keysStart = 7 * 4 + 16 * $n;
$valsStart = $keysStart + strlen($ids);
$mo = pack('V7', 0x950412de, 0, $n, 28, 28 + 8 * $n, 0, 0);
foreach ($idTable as [$len, $off]) { $mo .= pack('V2', $len, $keysStart + $off); }
foreach ($strTable as [$len, $off]) { $mo .= pack('V2', $len, $valsStart + $off); }
file_put_contents($dst, $mo . $ids . $strs);
fwrite(STDOUT, "$n messages compiled to $dst\n");
