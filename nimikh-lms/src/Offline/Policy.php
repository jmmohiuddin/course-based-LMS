<?php
declare(strict_types=1);

namespace Nimikh\LMS\Offline;

/**
 * Who may keep a lesson offline. Pure: given a learner's current licences and a request, decide.
 * Limits exist because an offline copy cannot be made un-copyable; they bound how far one account's copies can spread.
 */
final class Policy {

	/**
	 * @param array<int, array{lesson_id:int, device_id:string, expires_at:int, revoked:bool}> $licenses the learner's licences (any state)
	 * @return array{allow:bool, action:string, reason:string} action = renew|create (when allowed)
	 */
	public static function decide(array $licenses, int $lessonId, string $device, int $maxLessons, int $maxDevices, int $now): array {
		$active = array_filter($licenses, static fn(array $l): bool => !$l['revoked'] && $l['expires_at'] > $now);

		foreach ($licenses as $l) {
			if ($l['lesson_id'] === $lessonId && $l['device_id'] === $device) {
				return ['allow' => true, 'action' => 'renew', 'reason' => ''];  // same device again: extend (also revives an expired or revoked row)
			}
		}
		$lessons = array_unique(array_column($active, 'lesson_id'));
		if (!in_array($lessonId, $lessons, true) && count($lessons) >= $maxLessons) {
			return ['allow' => false, 'action' => '', 'reason' => 'too_many_lessons'];
		}
		$devices = array_unique(array_column(array_filter($active, static fn(array $l): bool => $l['lesson_id'] === $lessonId), 'device_id'));
		if (count($devices) >= $maxDevices) {
			return ['allow' => false, 'action' => '', 'reason' => 'too_many_devices'];
		}
		return ['allow' => true, 'action' => 'create', 'reason' => ''];
	}

	/** Device ids are random hex made by the browser; anything else is refused. */
	public static function validDevice(string $id): bool {
		return preg_match('/^[a-f0-9]{32}$/', $id) === 1;
	}
}
