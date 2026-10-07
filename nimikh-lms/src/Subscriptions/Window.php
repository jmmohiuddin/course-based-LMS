<?php
declare(strict_types=1);

namespace Nimikh\LMS\Subscriptions;

final class Window {

	/** Renewals extend from the current expiry if it is still in the future, else from now. */
	public static function newExpiry(?string $currentExpiryGmt, string $nowGmt, int $days): string {
		$now  = new \DateTimeImmutable($nowGmt, new \DateTimeZone('UTC'));
		$base = $now;
		if ($currentExpiryGmt !== null && $currentExpiryGmt !== '') {
			$cur = new \DateTimeImmutable($currentExpiryGmt, new \DateTimeZone('UTC'));
			if ($cur > $now) {
				$base = $cur;
			}
		}
		return $base->modify('+' . max(1, $days) . ' days')->format('Y-m-d H:i:s');
	}

	public static function isActive(string $status, string $expiresGmt, string $nowGmt): bool {
		return $status === 'active' && $expiresGmt > $nowGmt; // same-format strings compare chronologically
	}
}
