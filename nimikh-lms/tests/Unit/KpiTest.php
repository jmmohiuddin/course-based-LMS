<?php
declare(strict_types=1);

use Nimikh\LMS\Metrics\Kpi;
use PHPUnit\Framework\TestCase;

final class KpiTest extends TestCase {

	public function test_percent(): void {
		$this->assertSame(35.0, Kpi::percent(7, 20));
		$this->assertSame(33.3, Kpi::percent(1, 3));
		$this->assertNull(Kpi::percent(0, 0));
		$this->assertSame(0.0, Kpi::percent(0, 5));
	}

	public function test_percentile_nearest_rank(): void {
		$this->assertNull(Kpi::percentile([], 75));
		$this->assertSame(7, Kpi::percentile([7], 75));
		$this->assertSame(3, Kpi::percentile([4, 1, 3, 2], 75), 'rank 3 of 4');
		$this->assertSame(10, Kpi::percentile(range(1, 10), 100));
		$this->assertSame(8, Kpi::percentile(range(1, 10), 75));
	}

	public function test_targets_from_the_prd(): void {
		$this->assertSame('met', Kpi::status('completion', 35.0));
		$this->assertSame('missed', Kpi::status('completion', 34.9));
		$this->assertSame('met', Kpi::status('mcq_answer', 90.0));
		$this->assertSame('missed', Kpi::status('mcq_answer', 89.9));
		$this->assertSame('missed', Kpi::status('first_pass', 59.9));
		$this->assertSame('met', Kpi::status('first_pass', 60.0));
		$this->assertSame('met', Kpi::status('first_pass', 80.0));
		$this->assertSame('watch', Kpi::status('first_pass', 80.1), 'too high = exam too easy');
		$this->assertSame('met', Kpi::status('verify_views', 20.0));
		$this->assertSame('missed', Kpi::status('verify_views', 19.9));
		$this->assertSame('met', Kpi::status('paid', 300.0));
		$this->assertSame('met', Kpi::status('video_start', 2999.0));
		$this->assertSame('missed', Kpi::status('video_start', 3000.0));
		$this->assertSame('met', Kpi::status('lcp', 2499.0));
		$this->assertSame('missed', Kpi::status('lcp', 2500.0));
		$this->assertSame('no_data', Kpi::status('completion', null));
		$this->assertSame('no_data', Kpi::status('unknown', 5.0));
	}
}
