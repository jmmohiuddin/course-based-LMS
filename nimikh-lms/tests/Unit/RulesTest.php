<?php
declare(strict_types=1);

use Nimikh\LMS\Certificates\CodeGenerator;
use Nimikh\LMS\Certificates\RuleEngine;
use Nimikh\LMS\Questions\Grader;
use Nimikh\LMS\Video\BunnyUrlSigner;
use PHPUnit\Framework\TestCase;

final class RulesTest extends TestCase {

	private function progress(array $over = []): array {
		return array_merge([
			'lessons_total' => 4, 'lessons_completed' => 4,
			'has_exam' => true, 'exam_percent' => 75.0, 'mcq_percent' => 80.0,
		], $over);
	}

	public function test_all_rules_met(): void {
		$r = RuleEngine::evaluate($this->progress(), ['exam_pass_percent' => 60.0, 'min_mcq_percent' => 70.0]);
		$this->assertTrue($r['passed']);
	}

	public function test_each_failure_is_reported(): void {
		$rules = ['exam_pass_percent' => 60.0, 'min_mcq_percent' => 70.0];
		$this->assertSame(['lessons_incomplete'], RuleEngine::evaluate($this->progress(['lessons_completed' => 3]), $rules)['failures']);
		$this->assertSame(['exam_not_passed'], RuleEngine::evaluate($this->progress(['exam_percent' => 59.9]), $rules)['failures']);
		$this->assertSame(['exam_not_passed'], RuleEngine::evaluate($this->progress(['exam_percent' => null]), $rules)['failures']);
		$this->assertSame(['mcq_score_too_low'], RuleEngine::evaluate($this->progress(['mcq_percent' => 50.0]), $rules)['failures']);
	}

	public function test_mcq_rule_is_optional_and_no_exam_course_works(): void {
		$p = $this->progress(['has_exam' => false, 'exam_percent' => null, 'mcq_percent' => 10.0]);
		$this->assertTrue(RuleEngine::evaluate($p, ['exam_pass_percent' => 60.0, 'min_mcq_percent' => 0.0])['passed']);
		$this->assertSame(10.0, RuleEngine::headlineScore($p));
	}

	public function test_grader_retry_flow(): void {
		$this->assertSame(['correct' => true, 'resolved' => true, 'reveal' => false], Grader::grade(2, 2, 1, true));
		$this->assertSame(['correct' => false, 'resolved' => false, 'reveal' => false], Grader::grade(2, 0, 1, true));
		$this->assertSame(['correct' => false, 'resolved' => true, 'reveal' => true], Grader::grade(2, 0, 2, true));
		$this->assertSame(['correct' => false, 'resolved' => true, 'reveal' => true], Grader::grade(2, 0, 1, false));
	}

	public function test_is_resolved(): void {
		$this->assertTrue(Grader::isResolved(true, 1, true));
		$this->assertFalse(Grader::isResolved(false, 1, true));
		$this->assertTrue(Grader::isResolved(false, 2, true));
		$this->assertTrue(Grader::isResolved(false, 1, false));
	}

	public function test_codes_are_random_valid_and_normalised(): void {
		$a = CodeGenerator::generate();
		$this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{10}$/', $a);
		$this->assertNotSame($a, CodeGenerator::generate());
		$this->assertSame($a, CodeGenerator::normalise(strtolower(substr($a, 0, 5) . '-' . substr($a, 5))));
		$this->assertSame('', CodeGenerator::normalise('short'));
		$this->assertSame('', CodeGenerator::normalise('0000000000'));
	}

	public function test_bunny_signature_matches_documented_algorithm(): void {
		$url   = BunnyUrlSigner::sign('vz-abc.b-cdn.net', 'vid/playlist.m3u8', 'secret', 1700000000);
		$token = rtrim(strtr(base64_encode(hash('sha256', 'secret/vid/playlist.m3u81700000000', true)), '+/', '-_'), '=');
		$this->assertSame("https://vz-abc.b-cdn.net/vid/playlist.m3u8?token=$token&expires=1700000000", $url);
	}
}
