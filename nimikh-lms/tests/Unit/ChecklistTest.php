<?php
declare(strict_types=1);

use Nimikh\LMS\Certificates\Checklist;
use PHPUnit\Framework\TestCase;

final class ChecklistTest extends TestCase {

	private const RULES = ['exam_pass_percent' => 60.0, 'min_mcq_percent' => 70.0];

	private function progress(array $over = []): array {
		return array_merge([
			'lessons_total' => 4, 'lessons_completed' => 2,
			'has_exam' => true, 'exam_percent' => null, 'mcq_percent' => 80.0,
		], $over);
	}

	public function test_lists_what_is_left(): void {
		$c = Checklist::build($this->progress(), self::RULES);
		$this->assertSame(['lessons', 'exam', 'mcq'], array_column($c['items'], 'key'));
		$this->assertSame([false, false, true], array_column($c['items'], 'done'));
		$this->assertSame(1, $c['done']);
		$this->assertSame(33, $c['percent']);
		$this->assertFalse($c['ready']);
	}

	public function test_ready_when_every_rule_is_met(): void {
		$c = Checklist::build($this->progress(['lessons_completed' => 4, 'exam_percent' => 61.0]), self::RULES);
		$this->assertTrue($c['ready']);
		$this->assertSame(100, $c['percent']);
	}

	public function test_agrees_with_the_rule_engine_on_edge_cases(): void {
		// MCQ rule only bites when the learner has answered something.
		$c = Checklist::build($this->progress(['lessons_completed' => 4, 'exam_percent' => 90.0, 'mcq_percent' => null]), self::RULES);
		$this->assertTrue($c['ready']);
		// No exam and the MCQ rule off: only lessons remain.
		$c = Checklist::build($this->progress(['has_exam' => false]), ['exam_pass_percent' => 60.0, 'min_mcq_percent' => 0.0]);
		$this->assertSame(['lessons'], array_column($c['items'], 'key'));
	}
}
