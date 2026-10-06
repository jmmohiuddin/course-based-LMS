<?php
declare(strict_types=1);

use Nimikh\LMS\Progress\HeartbeatValidator;
use Nimikh\LMS\Progress\RangeMerger;
use PHPUnit\Framework\TestCase;

final class ProgressTest extends TestCase {

	public function test_merge_joins_overlapping_and_touching_ranges(): void {
		$this->assertSame([[0, 30], [40, 50]], RangeMerger::merge([[20, 30], [0, 10], [10, 25], [40, 50]]));
	}

	public function test_sanitize_clamps_and_drops_garbage(): void {
		$raw = [[-5, 10], ['a', 3], [5], [90, 500], 'x', [20, 20]];
		$this->assertSame([[0, 10], [90, 100]], RangeMerger::sanitize($raw, 100));
		$this->assertSame([], RangeMerger::sanitize('nope', 100));
	}

	public function test_covered_percent_and_furthest(): void {
		$r = [[0, 30], [60, 90]];
		$this->assertSame(60, RangeMerger::covered($r));
		$this->assertSame(60.0, RangeMerger::percent($r, 100));
		$this->assertSame(90, RangeMerger::furthest($r));
		$this->assertSame(0.0, RangeMerger::percent($r, 0));
	}

	public function test_clip_stops_progress_at_the_gate(): void {
		$this->assertSame([[0, 50]], RangeMerger::clip([[0, 70], [80, 90]], 50));
		$this->assertSame([[0, 70], [80, 90]], RangeMerger::clip([[0, 70], [80, 90]], null));
	}

	public function test_heartbeat_accepts_real_time_progress(): void {
		$res = HeartbeatValidator::validate([[0, 20]], [[20, 30]], 10.0, 1.0);
		$this->assertTrue($res['ok']);
		$this->assertSame(10, $res['gained']);
	}

	public function test_heartbeat_rejects_a_seek_jump(): void {
		// 10 s elapsed at 2x allows ~22 s + slack; claiming 300 s must fail.
		$res = HeartbeatValidator::validate([[0, 20]], [[20, 320]], 10.0, 2.0);
		$this->assertFalse($res['ok']);
	}

	public function test_heartbeat_allows_capped_speed_but_not_beyond(): void {
		$this->assertTrue(HeartbeatValidator::validate([], [[0, 60]], 30.0, 2.0)['ok']);
		$this->assertFalse(HeartbeatValidator::validate([], [[0, 400]], 30.0, 2.0)['ok']);
	}
}
