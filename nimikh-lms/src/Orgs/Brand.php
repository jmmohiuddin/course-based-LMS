<?php
declare(strict_types=1);

namespace Nimikh\LMS\Orgs;

final class Brand {

	public const DEFAULT_COLOR = '#1E4FD8';

	public static function color(string $input): string {
		$input = trim($input);
		if (preg_match('/^#([0-9a-fA-F]{6})$/', $input, $m) === 1) {
			return '#' . strtoupper($m[1]);
		}
		if (preg_match('/^#([0-9a-fA-F]{3})$/', $input, $m) === 1) {
			return '#' . strtoupper($m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2]);
		}
		return self::DEFAULT_COLOR;
	}

	public static function slug(string $input): string {
		$slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', trim($input)) ?? '');
		return substr(trim($slug, '-'), 0, 60);
	}

	/** Black or white, whichever reads better on the colour (WCAG relative luminance). */
	public static function textOn(string $hex): string {
		$hex = ltrim(self::color($hex), '#');
		$lin = static function (int $c): float {
			$c /= 255;
			return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
		};
		$l = 0.2126 * $lin(hexdec(substr($hex, 0, 2))) + 0.7152 * $lin(hexdec(substr($hex, 2, 2))) + 0.0722 * $lin(hexdec(substr($hex, 4, 2)));
		return $l > 0.179 ? '#0F172A' : '#FFFFFF';
	}
}
