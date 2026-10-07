<?php
declare(strict_types=1);

namespace Nimikh\LMS\Live;

final class SessionState {

	public const LEAD_MINUTES = 10;

	/** @return 'upcoming'|'open'|'live'|'ended'|'cancelled' */
	public static function state(int $startTs, int $durationMin, int $nowTs, string $status = 'scheduled'): string {
		if ($status === 'cancelled') {
			return 'cancelled';
		}
		if ($nowTs >= $startTs + $durationMin * 60) {
			return 'ended';
		}
		if ($nowTs >= $startTs) {
			return 'live';
		}
		return $nowTs >= $startTs - self::LEAD_MINUTES * 60 ? 'open' : 'upcoming';
	}

	public static function canJoin(string $state): bool {
		return $state === 'open' || $state === 'live';
	}

	public static function roomName(): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$room = 'nimikh-';
		for ($i = 0; $i < 14; $i++) {
			$room .= $alphabet[random_int(0, 35)];
		}
		return $room;
	}
}
