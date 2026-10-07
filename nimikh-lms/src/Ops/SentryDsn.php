<?php
declare(strict_types=1);

namespace Nimikh\LMS\Ops;

/** Parses a Sentry DSN (https://<key>@<host>/<project>). Pure. */
final class SentryDsn {

	/** @return array{key:string, host:string, project:string, endpoint:string}|null */
	public static function parse(string $dsn): ?array {
		$p = parse_url(trim($dsn));
		if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || empty($p['user']) || empty($p['host']) || empty($p['path'])) {
			return null;
		}
		$project = trim((string) $p['path'], '/');
		if (preg_match('/^\d+$/', $project) !== 1 || preg_match('/^[a-f0-9]{16,64}$/i', (string) $p['user']) !== 1) {
			return null;
		}
		$host = $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
		return [
			'key'      => (string) $p['user'],
			'host'     => $host,
			'project'  => $project,
			'endpoint' => sprintf('https://%s/api/%s/envelope/?sentry_key=%s&sentry_version=7', $host, $project, $p['user']),
		];
	}

	/** Builds the envelope body Sentry's ingest endpoint accepts for one error event. */
	public static function envelope(string $eventId, string $message, string $type, string $file, int $line, string $release, string $env, string $platform = 'php'): string {
		$event = [
			'event_id'    => $eventId,
			'timestamp'   => gmdate('c'),
			'platform'    => $platform,
			'level'       => 'error',
			'release'     => $release,
			'environment' => $env,
			'exception'   => ['values' => [[
				'type'       => $type,
				'value'      => mb_substr($message, 0, 1000),
				'stacktrace' => ['frames' => [['filename' => $file, 'lineno' => $line]]],
			]]],
		];
		$body = json_encode($event, JSON_UNESCAPED_SLASHES);
		return json_encode(['event_id' => $eventId, 'sent_at' => gmdate('c')]) . "\n"
			. json_encode(['type' => 'event', 'length' => strlen((string) $body)]) . "\n"
			. $body . "\n";
	}
}
