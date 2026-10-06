<?php
declare(strict_types=1);

namespace Nimikh\LMS\Support;

final class Time {

	public static function nowGmt(): string {
		return gmdate('Y-m-d H:i:s');
	}

	public static function toTimestamp(?string $gmt): int {
		if ($gmt === null || $gmt === '' || $gmt === '0000-00-00 00:00:00') {
			return 0;
		}
		return (int) strtotime($gmt . ' UTC');
	}
}
