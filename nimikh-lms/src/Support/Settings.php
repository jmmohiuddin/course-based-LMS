<?php
declare(strict_types=1);

namespace Nimikh\LMS\Support;

final class Settings {

	public const OPTION = 'nimikh_lms_settings';

	public const DEFAULTS = [
		'bunny_host'          => '',     // e.g. vz-xxxx.b-cdn.net (pull zone hostname)
		'bunny_token_key'     => '',     // URL token authentication key
		'url_ttl'             => 14400,  // signed URL lifetime, 4 h
		'max_playback_rate'   => 2.0,    // anti-skip speed cap
		'heartbeat_tolerance' => 0.10,
		'heartbeat_interval'  => 10,
		'gotenberg_url'       => '',
		'issuer_name'         => 'Nimikh',
		'attempts_per_minute' => 30,
	];

	/** @return mixed */
	public static function get(string $key) {
		$stored = get_option(self::OPTION, []);
		$stored = is_array($stored) ? $stored : [];
		$value  = $stored[$key] ?? self::DEFAULTS[$key] ?? null;
		return apply_filters('nimikh_lms_setting_' . $key, $value);
	}

	/** @param array<string, mixed> $input */
	public static function sanitize(array $input): array {
		return [
			'bunny_host'          => sanitize_text_field((string) ($input['bunny_host'] ?? '')),
			'bunny_token_key'     => sanitize_text_field((string) ($input['bunny_token_key'] ?? '')),
			'url_ttl'             => max(300, (int) ($input['url_ttl'] ?? 14400)),
			'max_playback_rate'   => min(4.0, max(1.0, (float) ($input['max_playback_rate'] ?? 2.0))),
			'heartbeat_tolerance' => min(0.5, max(0.0, (float) ($input['heartbeat_tolerance'] ?? 0.10))),
			'heartbeat_interval'  => 10,
			'gotenberg_url'       => esc_url_raw((string) ($input['gotenberg_url'] ?? '')),
			'issuer_name'         => sanitize_text_field((string) ($input['issuer_name'] ?? 'Nimikh')),
			'attempts_per_minute' => max(5, (int) ($input['attempts_per_minute'] ?? 30)),
		];
	}
}
