<?php
declare(strict_types=1);

namespace Nimikh\LMS\Certificates;

/**
 * Random (never sequential) 10-char certificate codes. The alphabet drops look-alike
 * characters (0/O, 1/I/L) so codes survive being typed in from a printout.
 */
final class CodeGenerator {

	public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
	public const LENGTH   = 10;

	public static function generate(): string {
		$max  = strlen(self::ALPHABET) - 1;
		$code = '';
		for ($i = 0; $i < self::LENGTH; $i++) {
			$code .= self::ALPHABET[random_int(0, $max)];
		}
		return $code;
	}

	/** Normalise user input ("abcd-efgh 23") to the stored form; returns '' if it cannot be a code. */
	public static function normalise(string $input): string {
		$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');
		return preg_match('/^[' . self::ALPHABET . ']{' . self::LENGTH . '}$/', $code) === 1 ? $code : '';
	}
}
