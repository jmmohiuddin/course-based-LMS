<?php
declare(strict_types=1);

use Nimikh\LMS\Offline\Policy;
use PHPUnit\Framework\TestCase;

final class OfflinePolicyTest extends TestCase {

	private const NOW = 1_000_000;
	private const D1 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const D2 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
	private const D3 = 'cccccccccccccccccccccccccccccccc';

	private function lic(int $lesson, string $device, int $expires = 2_000_000, bool $revoked = false): array {
		return ['lesson_id' => $lesson, 'device_id' => $device, 'expires_at' => $expires, 'revoked' => $revoked];
	}

	public function test_first_download_is_allowed(): void {
		$this->assertSame(['allow' => true, 'action' => 'create', 'reason' => ''], Policy::decide([], 5, self::D1, 10, 2, self::NOW));
	}

	public function test_same_device_renews_even_at_the_limits(): void {
		$full = [$this->lic(5, self::D1), $this->lic(6, self::D1)];
		$this->assertSame('renew', Policy::decide($full, 5, self::D1, 2, 1, self::NOW)['action']);
	}

	public function test_expired_or_revoked_copies_can_be_renewed_on_the_same_device(): void {
		$this->assertSame('renew', Policy::decide([$this->lic(5, self::D1, self::NOW - 1)], 5, self::D1, 10, 2, self::NOW)['action']);
		$this->assertSame('renew', Policy::decide([$this->lic(5, self::D1, 2_000_000, true)], 5, self::D1, 10, 2, self::NOW)['action']);
	}

	public function test_device_limit_per_lesson(): void {
		$two = [$this->lic(5, self::D1), $this->lic(5, self::D2)];
		$r = Policy::decide($two, 5, self::D3, 10, 2, self::NOW);
		$this->assertFalse($r['allow']);
		$this->assertSame('too_many_devices', $r['reason']);
		$this->assertTrue(Policy::decide($two, 6, self::D3, 10, 2, self::NOW)['allow'], 'another lesson is a different count');
	}

	public function test_expired_and_revoked_licences_do_not_count_against_limits(): void {
		$old = [$this->lic(5, self::D1, self::NOW - 5), $this->lic(5, self::D2, 2_000_000, true)];
		$this->assertTrue(Policy::decide($old, 5, self::D3, 10, 2, self::NOW)['allow']);
		$this->assertTrue(Policy::decide([$this->lic(1, self::D1, self::NOW - 5), $this->lic(2, self::D1)], 3, self::D1, 2, 2, self::NOW)['allow'], 'only active lessons count');
	}

	public function test_lesson_limit(): void {
		$r = Policy::decide([$this->lic(1, self::D1), $this->lic(2, self::D1)], 3, self::D1, 2, 2, self::NOW);
		$this->assertFalse($r['allow']);
		$this->assertSame('too_many_lessons', $r['reason']);
		$this->assertTrue(Policy::decide([$this->lic(1, self::D1), $this->lic(2, self::D1)], 2, self::D2, 2, 2, self::NOW)['allow'], 'a lesson already counted does not need a new slot');
	}

	public function test_device_ids(): void {
		$this->assertTrue(Policy::validDevice(self::D1));
		foreach (['', 'abc', str_repeat('g', 32), str_repeat('a', 33), '../../etc'] as $bad) { $this->assertFalse(Policy::validDevice($bad), $bad); }
	}
}
