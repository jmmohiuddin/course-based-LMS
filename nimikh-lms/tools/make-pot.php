<?php
/**
 * Extracts translatable strings (text domain "nimikh-lms") into languages/nimikh-lms.pot using PHP's tokenizer.
 * Usage: php tools/make-pot.php [output.pot]
 */
declare(strict_types=1);

$root  = dirname(__DIR__);
$funcs = ['__' => 'single', '_e' => 'single', 'esc_html__' => 'single', 'esc_attr__' => 'single', 'esc_html_e' => 'single', 'esc_attr_e' => 'single', '_n' => 'plural', '_x' => 'context'];

function literal(string $token): string {
	$q = $token[0];
	$body = substr($token, 1, -1);
	if ($q === "'") {
		return strtr($body, ["\\\\" => "\\", "\\'" => "'"]);
	}
	// double quoted: handle the escapes WordPress strings actually use
	return strtr($body, ['\\n' => "\n", '\\t' => "\t", '\\"' => '"', '\\$' => '$', '\\\\' => '\\']);
}

$entries = []; // key => ['ctx','id','plural','refs'=>[]]
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
	$path = $file->getPathname();
	if (!str_ends_with($path, '.php') || preg_match('#/(vendor|tests|tools|languages)/#', $path)) {
		continue;
	}
	$rel    = ltrim(substr($path, strlen($root)), '/');
	$tokens = array_values(array_filter(token_get_all((string) file_get_contents($path)), static fn($t) => !(is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))));
	for ($i = 0, $n = count($tokens); $i < $n; $i++) {
		if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING || !isset($funcs[$tokens[$i][1]]) || ($tokens[$i + 1] ?? null) !== '(') {
			continue;
		}
		$kind = $funcs[$tokens[$i][1]];
		$line = $tokens[$i][2];
		$args = []; $j = $i + 2; $depth = 0;
		// collect the first few top-level arguments as literal strings (null when not a plain literal)
		$cur = [];
		for (; $j < $n; $j++) {
			$t = $tokens[$j];
			if ($t === '(' || $t === '[') { $depth++; }
			elseif ($t === ')' || $t === ']') { if ($depth === 0) { break; } $depth--; }
			if ($depth === 0 && $t === ',') { $args[] = $cur; $cur = []; continue; }
			$cur[] = $t;
		}
		$args[] = $cur;
		$lit = static fn(array $a): ?string => count($a) === 1 && is_array($a[0]) && $a[0][0] === T_CONSTANT_ENCAPSED_STRING ? literal($a[0][1]) : null;
		$domainIdx = ['single' => 1, 'plural' => 3, 'context' => 2][$kind];
		if (($lit($args[$domainIdx] ?? []) ?? '') !== 'nimikh-lms') {
			continue;
		}
		$id = $lit($args[0]);
		if ($id === null || $id === '') { continue; }
		$ctx = null; $plural = null;
		if ($kind === 'plural') { $plural = $lit($args[1]); }
		if ($kind === 'context') { $ctx = $lit($args[1]); }
		$key = ($ctx ?? '') . "\x04" . $id;
		$entries[$key] ??= ['ctx' => $ctx, 'id' => $id, 'plural' => $plural, 'refs' => []];
		$entries[$key]['refs'][] = $rel . ':' . $line;
	}
}
ksort($entries);

$esc = static function (string $s): string {
	$parts = preg_split('/(?<=\n)/', $s) ?: [$s];
	$out = array_map(static fn(string $p): string => '"' . addcslashes($p, "\"\\\n\t") . '"', $parts);
	$out = array_map(static fn(string $p): string => str_replace(['\\' . "\n", '\\n'], '\\n', $p), $out);
	return count($out) > 1 ? "\"\"\n" . implode("\n", $out) : $out[0];
};

$po  = "# Nimikh LMS translation template.\n#, fuzzy\nmsgid \"\"\nmsgstr \"\"\n\"Project-Id-Version: Nimikh LMS\\n\"\n\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n\"Plural-Forms: nplurals=2; plural=(n != 1);\\n\"\n\"X-Domain: nimikh-lms\\n\"\n\n";
foreach ($entries as $e) {
	$po .= '#: ' . implode(' ', array_slice(array_unique($e['refs']), 0, 3)) . "\n";
	if ($e['ctx'] !== null) { $po .= 'msgctxt ' . $esc($e['ctx']) . "\n"; }
	$po .= 'msgid ' . $esc($e['id']) . "\n";
	if ($e['plural'] !== null) {
		$po .= 'msgid_plural ' . $esc($e['plural']) . "\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n\n";
	} else {
		$po .= "msgstr \"\"\n\n";
	}
}
$out = $argv[1] ?? $root . '/languages/nimikh-lms.pot';
file_put_contents($out, $po);
fwrite(STDOUT, count($entries) . " strings written to " . $out . "\n");
