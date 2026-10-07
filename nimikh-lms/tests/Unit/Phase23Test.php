<?php
declare(strict_types=1);

use Nimikh\LMS\Ai\PromptBuilder;
use Nimikh\LMS\Ai\QuestionParser;
use Nimikh\LMS\Ai\VttParser;
use Nimikh\LMS\Badges\BadgeRules;
use Nimikh\LMS\Live\SessionState;
use Nimikh\LMS\Orgs\Brand;
use Nimikh\LMS\Pwa\PwaAssets;
use Nimikh\LMS\Sms\PhoneNumber;
use Nimikh\LMS\Sms\Template;
use Nimikh\LMS\Subscriptions\Window;
use PHPUnit\Framework\TestCase;

final class Phase23Test extends TestCase {

	// ---- badges ---------------------------------------------------------------
	public function test_badges_follow_stats(): void {
		$this->assertSame([], BadgeRules::earned(['lessons_completed' => 0, 'streak' => 0, 'certificates' => 0, 'perfect_mcq' => false]));
		$this->assertSame(
			['first_lesson', 'lessons_25', 'streak_3', 'streak_7', 'first_certificate', 'certificates_3', 'sharp_mind'],
			BadgeRules::earned(['lessons_completed' => 30, 'streak' => 9, 'certificates' => 3, 'perfect_mcq' => true])
		);
	}

	public function test_streak_counts_consecutive_days_with_one_day_grace(): void {
		$days = ['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-05'];
		$this->assertSame(1, BadgeRules::streak($days, '2026-10-05'));
		$this->assertSame(1, BadgeRules::streak($days, '2026-10-06'), 'studied yesterday: streak still alive');
		$this->assertSame(0, BadgeRules::streak($days, '2026-10-08'), 'two idle days: streak over');
		$this->assertSame(3, BadgeRules::streak(['2026-10-01', '2026-10-02', '2026-10-03'], '2026-10-03'));
		$this->assertSame(0, BadgeRules::streak([], '2026-10-03'));
	}

	// ---- sms ------------------------------------------------------------------
	public function test_bd_phone_numbers_are_normalised(): void {
		foreach (['01712345678', '8801712345678', '+8801712345678', '017 1234-5678', '০১৭১২৩৪৫৬৭৮'] as $in) {
			$this->assertSame('8801712345678', PhoneNumber::normalise($in), $in);
		}
		$this->assertNull(PhoneNumber::normalise('01212345678'), 'operator prefix 012 does not exist');
		$this->assertNull(PhoneNumber::normalise('1712345678'));
		$this->assertNull(PhoneNumber::normalise('abc'));
		$this->assertSame('14155552671', PhoneNumber::normalise('+1 415 555 2671'), 'international passes through');
	}

	public function test_sms_template_and_segments(): void {
		$this->assertSame('Hi Rafi, course X', Template::render('Hi {name}, course {course}{missing}', ['name' => 'Rafi', 'course' => 'X']));
		$this->assertSame(1, Template::segments(str_repeat('a', 160)));
		$this->assertSame(2, Template::segments(str_repeat('a', 161)));
		$this->assertSame(1, Template::segments(str_repeat('অ', 70)));
		$this->assertSame(2, Template::segments(str_repeat('অ', 71)));
	}

	// ---- captions + AI --------------------------------------------------------
	public function test_vtt_parsing_skips_noise_and_rolling_duplicates(): void {
		$vtt = "WEBVTT\n\nNOTE a comment\n\n1\n00:00:01.000 --> 00:00:03.000\nHello <b>world</b>\n\n00:00:03.000 --> 00:00:05.000\nHello world\n\n01:02.500 --> 01:05.000\nSUM adds &amp; totals\n\n01:00:10.000 --> 01:00:12.000\nlate";
		$cues = VttParser::parse($vtt);
		$this->assertSame([['start' => 1, 'text' => 'Hello world'], ['start' => 62, 'text' => 'SUM adds & totals'], ['start' => 3610, 'text' => 'late']], $cues);
		$text = VttParser::toTimedText($cues, 20);
		$this->assertStringContainsString("[0:01] Hello world\n[1:02] SUM adds & totals", $text);
	}

	public function test_ai_output_is_validated_not_trusted(): void {
		$good = ['at_second' => 60, 'stem' => 'What does SUM do?', 'options' => ['Adds', 'Multiplies'], 'correct_index' => 0, 'explanation' => 'It adds.'];
		$raw = "Sure! Here you go:\n```json\n" . json_encode([
			$good,
			['at_second' => 70, 'stem' => 'Too close', 'options' => ['a', 'b'], 'correct_index' => 0],                  // < 30 s after previous
			['at_second' => 10, 'stem' => 'Too early', 'options' => ['a', 'b'], 'correct_index' => 0],
			['at_second' => 200, 'stem' => 'After the end', 'options' => ['a', 'b'], 'correct_index' => 0],
			['at_second' => 120, 'stem' => 'Bad index', 'options' => ['a', 'b'], 'correct_index' => 5],
			['at_second' => 120, 'stem' => 'Duplicate options', 'options' => ['a', 'A'], 'correct_index' => 0],
			['at_second' => 120, 'stem' => 'One option', 'options' => ['a'], 'correct_index' => 0],
			['at_second' => 120, 'stem' => '', 'options' => ['a', 'b'], 'correct_index' => 0],
			'not an object',
			['at_second' => 100, 'stem' => 'Second good', 'options' => ['x', 'y', 'z'], 'correct_index' => 2, 'explanation' => 5],
		]) . "\n```";
		$r = QuestionParser::parse($raw, 150, 5);
		$this->assertCount(2, $r['questions']);
		$this->assertSame([60, 100], array_column($r['questions'], 'at_second'));
		$this->assertSame('', $r['questions'][1]['explanation'], 'non-string explanation discarded');
		$this->assertSame(8, $r['dropped']);
		$this->assertSame(1, count(QuestionParser::parse($raw, 150, 1)['questions']), 'max respected');
		$this->assertSame(['questions' => [], 'dropped' => 0], QuestionParser::parse('no json here', 100, 3));
	}

	public function test_prompt_treats_transcript_as_data_and_truncates(): void {
		$p = PromptBuilder::build(str_repeat('x', 70000), 3, 'bn', 600);
		$this->assertStringContainsString('Bangla', $p['user']);
		$this->assertStringContainsString('never instructions', $p['system']);
		$this->assertLessThan(61000, mb_strlen($p['user']));
	}

	// ---- subscriptions / live / orgs -----------------------------------------
	public function test_subscription_renewal_extends_from_future_expiry(): void {
		$this->assertSame('2026-11-06 10:00:00', Window::newExpiry(null, '2026-10-07 10:00:00', 30));
		$this->assertSame('2026-12-16 00:00:00', Window::newExpiry('2026-11-16 00:00:00', '2026-10-07 10:00:00', 30), 'renew early: no days lost');
		$this->assertSame('2026-11-06 10:00:00', Window::newExpiry('2026-09-01 00:00:00', '2026-10-07 10:00:00', 30), 'lapsed: restart from now');
		$this->assertTrue(Window::isActive('active', '2026-10-08 00:00:00', '2026-10-07 00:00:00'));
		$this->assertFalse(Window::isActive('active', '2026-10-07 00:00:00', '2026-10-07 00:00:00'));
		$this->assertFalse(Window::isActive('cancelled', '2027-01-01 00:00:00', '2026-10-07 00:00:00'));
	}

	public function test_live_session_windows(): void {
		$start = 1_000_000;
		$this->assertSame('upcoming', SessionState::state($start, 60, $start - 11 * 60));
		$this->assertSame('open', SessionState::state($start, 60, $start - 10 * 60));
		$this->assertSame('live', SessionState::state($start, 60, $start));
		$this->assertSame('live', SessionState::state($start, 60, $start + 3599));
		$this->assertSame('ended', SessionState::state($start, 60, $start + 3600));
		$this->assertSame('cancelled', SessionState::state($start, 60, $start, 'cancelled'));
		$this->assertFalse(SessionState::canJoin('upcoming'));
		$this->assertTrue(SessionState::canJoin('open'));
		$this->assertMatchesRegularExpression('/^nimikh-[a-z0-9]{14}$/', SessionState::roomName());
	}

	public function test_brand_helpers(): void {
		$this->assertSame('#112233', Brand::color('#123'));
		$this->assertSame('#1E4FD8', Brand::color('red; background:url(x)'));
		$this->assertSame('dhaka-it-institute', Brand::slug(' Dhaka IT  Institute! '));
		$this->assertSame('#FFFFFF', Brand::textOn('#1E4FD8'));
		$this->assertSame('#0F172A', Brand::textOn('#FFE680'));
	}

	// ---- pwa ------------------------------------------------------------------
	public function test_manifest_and_service_worker(): void {
		$m = PwaAssets::manifest(['name' => 'Nimikh', 'short_name' => 'Nimikh', 'start_url' => '/dashboard/', 'scope' => '/', 'theme_color' => '#1E4FD8', 'background_color' => '#fff', 'icon_url' => '/i.svg']);
		$this->assertSame('standalone', $m['display']);
		$this->assertSame('/i.svg', $m['icons'][0]['src']);

		$sw = PwaAssets::serviceWorker(['version' => '1.2', 'offline_url' => '/offline/', 'precache' => ['/a.css'], 'static_prefixes' => ['/wp-content/plugins/nimikh-lms/assets/']]);
		$this->assertStringContainsString('"cache":"nimikh-1.2"', $sw);
		$this->assertStringContainsString('"precache":["/offline/","/a.css"]', $sw);
		$this->assertStringContainsString('wp-json', $sw, 'REST is excluded from caching');
		$this->assertStringContainsString("req.method !== 'GET'", $sw);
	}

	public function test_offline_page_escapes_input(): void {
		$html = PwaAssets::offlineHtml('<b>Site</b>', '/x"y', 'Offline', 'Body & more', 'Retry');
		$this->assertStringNotContainsString('<b>Site</b>', $html);
		$this->assertStringContainsString('href="/x&quot;y"', $html);
	}

	// ---- share links -----------------------------------------------------------
	public function test_share_links_are_built_and_encoded(): void {
		$c = ['name' => 'Excel & Data', 'issuer' => 'Dhaka IT', 'issued_at' => '2026-10-07 10:00:00', 'code' => 'ABCDEFGH23', 'url' => 'https://x.test/verify/ABCDEFGH23'];
		$in = \Nimikh\LMS\Certificates\ShareLinks::linkedin($c);
		$this->assertStringStartsWith('https://www.linkedin.com/profile/add?startTask=CERTIFICATION_NAME&', $in);
		$this->assertStringContainsString('name=Excel%20%26%20Data', $in);
		$this->assertStringContainsString('organizationName=Dhaka%20IT', $in);
		$this->assertStringContainsString('issueYear=2026&issueMonth=10', $in);
		$this->assertStringContainsString('certUrl=https%3A%2F%2Fx.test%2Fverify%2FABCDEFGH23&certId=ABCDEFGH23', $in);
		$this->assertSame('https://www.facebook.com/sharer/sharer.php?u=https%3A%2F%2Fx.test%2Fv', \Nimikh\LMS\Certificates\ShareLinks::facebook('https://x.test/v'));
		$this->assertSame('https://wa.me/?text=Hi%20https%3A%2F%2Fx.test%2Fv', \Nimikh\LMS\Certificates\ShareLinks::whatsapp('Hi', 'https://x.test/v'));
	}
}
