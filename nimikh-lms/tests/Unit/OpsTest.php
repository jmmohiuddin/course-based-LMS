<?php
declare(strict_types=1);

use Nimikh\LMS\Ops\HealthStatus;
use Nimikh\LMS\Ops\SentryDsn;
use PHPUnit\Framework\TestCase;

final class OpsTest extends TestCase {

	public function test_health_status(): void {
		$this->assertSame('ok', HealthStatus::evaluate(true, 0, 0));
		$this->assertSame('ok', HealthStatus::evaluate(true, 300, 0), 'exactly at the limit is fine');
		$this->assertSame('degraded', HealthStatus::evaluate(true, 301, 0), 'cron stopped');
		$this->assertSame('degraded', HealthStatus::evaluate(true, 0, 1), 'a failed job');
		$this->assertSame('down', HealthStatus::evaluate(false, 0, 0));
		$this->assertSame('down', HealthStatus::evaluate(false, 9999, 5), 'database down wins');
	}

	public function test_dsn_parsing(): void {
		$d = SentryDsn::parse('https://0123456789abcdef0123456789abcdef@o123.ingest.sentry.io/4501');
		$this->assertSame('4501', $d['project']);
		$this->assertSame('o123.ingest.sentry.io', $d['host']);
		$this->assertSame('https://o123.ingest.sentry.io/api/4501/envelope/?sentry_key=0123456789abcdef0123456789abcdef&sentry_version=7', $d['endpoint']);
		foreach (['', 'nonsense', 'http://0123456789abcdef0123456789abcdef@host/1', 'https://host/1', 'https://0123456789abcdef0123456789abcdef@host/', 'https://0123456789abcdef0123456789abcdef@host/abc', 'https://short@host/1'] as $bad) {
			$this->assertNull(SentryDsn::parse($bad), $bad);
		}
	}

	public function test_envelope_is_three_valid_json_lines_with_correct_length(): void {
		$env   = SentryDsn::envelope(str_repeat('a', 32), 'Boom', 'FatalError', 'wp-content/plugins/nimikh-lms/src/X.php', 12, '0.1.1', 'production');
		$lines = explode("\n", rtrim($env, "\n"));
		$this->assertCount(3, $lines);
		[$head, $item, $body] = array_map(static fn(string $l): array => json_decode($l, true), $lines);
		$this->assertSame(str_repeat('a', 32), $head['event_id']);
		$this->assertSame('event', $item['type']);
		$this->assertSame(strlen($lines[2]), $item['length']);
		$this->assertSame('Boom', $body['exception']['values'][0]['value']);
		$this->assertArrayNotHasKey('user', $body, 'no user data is sent');
	}
}
