<?php
declare(strict_types=1);

use Nimikh\LMS\Video\Guidance;
use PHPUnit\Framework\TestCase;

final class GuidanceTest extends TestCase {

	public function test_a_well_paced_video_has_no_advice(): void {
		$this->assertSame([], Guidance::check([180, 400, 640], 800));
	}

	public function test_flags_each_problem(): void {
		$codes = static fn(array $w): array => array_column($w, 'code');
		$this->assertSame(['too_early'], $codes(Guidance::check([10, 200], 400)));
		$this->assertSame(['crowded'], $codes(Guidance::check([120, 150], 300)));
		$this->assertSame(['sparse'], $codes(Guidance::check([100, 500], 600)), '400 s with no question');
		$this->assertSame(['sparse'], $codes(Guidance::check([100], 600)), '500 s tail with no question');
	}

	public function test_order_does_not_matter_and_empty_is_quiet(): void {
		$this->assertSame(Guidance::check([100, 500], 600), Guidance::check([500, 100], 600));
		$this->assertSame([], Guidance::check([], 900), 'no questions yet: nothing to criticise');
		$this->assertSame([], Guidance::check([90, 200], 0), 'unknown duration: no tail check');
	}
}
