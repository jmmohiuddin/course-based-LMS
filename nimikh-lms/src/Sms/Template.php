<?php
declare(strict_types=1);

namespace Nimikh\LMS\Sms;

final class Template {

	/** Replace {key} placeholders; unknown keys become empty; control characters are stripped. */
	public static function render(string $template, array $vars): string {
		$out = preg_replace_callback('/\{(\w+)\}/', static fn(array $m): string => (string) ($vars[$m[1]] ?? ''), $template) ?? '';
		return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $out) ?? '');
	}

	/** SMS segment estimate: GSM-7 style 160/153, anything else (e.g. Bangla) is UCS-2 70/67. */
	public static function segments(string $message): int {
		$len = mb_strlen($message);
		if ($len === 0) {
			return 0;
		}
		$ascii = preg_match('/^[\x20-\x7E\n\r]*$/', $message) === 1;
		[$single, $multi] = $ascii ? [160, 153] : [70, 67];
		return $len <= $single ? 1 : (int) ceil($len / $multi);
	}
}
