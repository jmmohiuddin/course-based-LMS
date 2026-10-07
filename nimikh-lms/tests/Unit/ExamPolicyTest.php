<?php
declare(strict_types=1);

use Nimikh\LMS\Exam\Cooldown;
use Nimikh\LMS\Exam\Rewatch;
use PHPUnit\Framework\TestCase;

// Cooldown::human() calls WordPress's __(); a pass-through is enough here.
if (!function_exists('__')) {
	function __(string $text, string $domain = 'default'): string { return $text; }
}

final class ExamPolicyTest extends TestCase {

	public function test_cooldown_counts_down_and_ends(): void {
		$last = 1_000_000;
		$this->assertSame(24 * 3600, Cooldown::remaining($last, 24, $last));
		$this->assertSame(3600, Cooldown::remaining($last, 24, $last + 23 * 3600));
		$this->assertSame(0, Cooldown::remaining($last, 24, $last + 24 * 3600));
		$this->assertSame(0, Cooldown::remaining($last, 24, $last + 99 * 3600));
	}

	public function test_no_cooldown_when_off_passed_or_first_attempt(): void {
		$this->assertSame(0, Cooldown::remaining(1_000_000, 0, 1_000_001), 'rule off');
		$this->assertSame(0, Cooldown::remaining(null, 24, 1_000_001), 'no earlier attempt');
		$this->assertSame(0, Cooldown::remaining(1_000_000, 24, 1_000_001, true), 'already passed');
	}

	public function test_human_text(): void {
		$this->assertSame('5 h 20 min', Cooldown::human(5 * 3600 + 20 * 60));
		$this->assertSame('1 min', Cooldown::human(10));
		$this->assertSame('1 h 0 min', Cooldown::human(3600));
	}

	public function test_rewatch_lists_weakest_lessons_first(): void {
		$lessons = [
			['lesson_id' => 1, 'mcq_percent' => 100.0, 'watch_percent' => 100.0],  // fine
			['lesson_id' => 2, 'mcq_percent' => 50.0,  'watch_percent' => 95.0],   // weak questions
			['lesson_id' => 3, 'mcq_percent' => 20.0,  'watch_percent' => 99.0],   // weakest
			['lesson_id' => 4, 'mcq_percent' => null,  'watch_percent' => 40.0],   // not watched, no questions
			['lesson_id' => 5, 'mcq_percent' => 90.0,  'watch_percent' => 60.0],   // not fully watched
		];
		$out = Rewatch::suggest($lessons, 90.0);
		$this->assertSame([3, 2, 5, 4], array_column($out, 'lesson_id'));
		$this->assertSame('weak_questions', $out[0]['reason']);
		$this->assertSame('not_fully_watched', $out[2]['reason']);
	}

	public function test_rewatch_respects_limit_and_nothing_weak(): void {
		$all = [];
		for ($i = 1; $i <= 9; $i++) { $all[] = ['lesson_id' => $i, 'mcq_percent' => 10.0 * $i, 'watch_percent' => 100.0]; }
		$this->assertCount(5, Rewatch::suggest($all, 90.0));
		$this->assertSame([], Rewatch::suggest([['lesson_id' => 1, 'mcq_percent' => 100.0, 'watch_percent' => 100.0]], 90.0));
	}
}
