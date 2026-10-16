<?php
declare(strict_types=1);

namespace Nimikh\LMS\Offline;

use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Support\Installer;
use Nimikh\LMS\Support\Settings;
use Nimikh\LMS\Support\Time;
use Nimikh\LMS\Video\PlayerSource;

/**
 * Time-limited, device-limited permission to keep a lesson's video on a device.
 *
 * Honest limit: this is not DRM. A learner's browser holds the file, so a determined person can copy it while the licence is
 * valid. What it does give: opt-in per lesson, a fixed lifetime, a cap on devices and lessons, revocation on the next online
 * visit (or when enrolment ends), and the learner's watermark staying on screen. Use Bunny DRM when that is not enough.
 */
final class OfflineService {

	public const META = 'nimikh_offline_enabled';

	public function __construct(private TutorAdapter $tutor) {}

	public static function enabledFor(int $lessonId): bool {
		return get_post_meta($lessonId, self::META, true) === '1' && PlayerSource::offlineSource($lessonId) !== null;
	}

	/** @return array{allow:bool, reason:string, url:string, expires_at:int, title:string, days:int}|\WP_Error */
	public function grant(int $userId, int $lessonId, string $device) {
		global $wpdb;
		if (!self::enabledFor($lessonId)) {
			return new \WP_Error('nimikh_offline_off', __('Offline download is not available for this lesson.', 'nimikh-lms'), ['status' => 404]);
		}
		if (!Policy::validDevice($device)) {
			return new \WP_Error('nimikh_offline_device', __('Unknown device.', 'nimikh-lms'), ['status' => 400]);
		}
		$now      = time();
		$decision = Policy::decide($this->licenses($userId), $lessonId, $device, (int) Settings::get('offline_max_lessons'), (int) Settings::get('offline_max_devices'), $now);
		if (!$decision['allow']) {
			$msg = $decision['reason'] === 'too_many_devices'
				? __('This lesson is already downloaded on the maximum number of your devices. Remove it from another device first.', 'nimikh-lms')
				: __('You have reached the limit of downloaded lessons. Remove one first.', 'nimikh-lms');
			return new \WP_Error('nimikh_offline_limit', $msg, ['status' => 409]);
		}

		$days    = max(1, (int) Settings::get('offline_days'));
		$expires = $now + $days * DAY_IN_SECONDS;
		$t       = Installer::table('offline_licenses');
		$wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"INSERT INTO $t (user_id, lesson_id, device_id, issued_at, expires_at, revoked_at) VALUES (%d, %d, %s, %s, %s, NULL)
			 ON DUPLICATE KEY UPDATE issued_at = VALUES(issued_at), expires_at = VALUES(expires_at), revoked_at = NULL",
			$userId,
			$lessonId,
			$device,
			gmdate('Y-m-d H:i:s', $now),
			gmdate('Y-m-d H:i:s', $expires)
		));
		$src = PlayerSource::offlineSource($lessonId);
		return ['allow' => true, 'reason' => '', 'url' => (string) $src['url'], 'expires_at' => $expires, 'title' => get_the_title($lessonId), 'days' => $days];
	}

	public function release(int $userId, int $lessonId, string $device): void {
		global $wpdb;
		$wpdb->delete(Installer::table('offline_licenses'), ['user_id' => $userId, 'lesson_id' => $lessonId, 'device_id' => $device]);
	}

	/** Called when access ends: every copy of that course's lessons stops working at its next online check. */
	public function revokeForCourse(int $userId, int $courseId): void {
		global $wpdb;
		$lessons = $this->tutor->lessonIdsForCourse($courseId);
		if (!$lessons) {
			return;
		}
		$in = implode(',', array_map('intval', $lessons));
		$wpdb->query($wpdb->prepare('UPDATE ' . Installer::table('offline_licenses') . " SET revoked_at = %s WHERE user_id = %d AND lesson_id IN ($in) AND revoked_at IS NULL", Time::nowGmt(), $userId)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Licences this device may still use, for the client to reconcile its local copies against.
	 *
	 * @return array<int, array{lesson_id:int, expires_at:int}>
	 */
	public function activeFor(int $userId, string $device): array {
		$out = [];
		foreach ($this->licenses($userId) as $l) {
			if ($l['device_id'] === $device && !$l['revoked'] && $l['expires_at'] > time()
				&& $this->tutor->isEnrolled($userId, $this->tutor->courseIdForLesson($l['lesson_id']))) {
				$out[] = ['lesson_id' => $l['lesson_id'], 'expires_at' => $l['expires_at']];
			}
		}
		return $out;
	}

	/** @return array<int, array{lesson_id:int, device_id:string, expires_at:int, revoked:bool}> */
	private function licenses(int $userId): array {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare('SELECT lesson_id, device_id, expires_at, revoked_at FROM ' . Installer::table('offline_licenses') . ' WHERE user_id = %d', $userId), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map(static fn(array $r): array => [
			'lesson_id'  => (int) $r['lesson_id'],
			'device_id'  => (string) $r['device_id'],
			'expires_at' => Time::toTimestamp((string) $r['expires_at']),
			'revoked'    => $r['revoked_at'] !== null,
		], $rows);
	}
}
