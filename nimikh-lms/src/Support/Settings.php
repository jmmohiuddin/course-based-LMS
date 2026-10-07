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
		// Phase 2-3
		'sms_enabled'         => 0,
		'sms_gateway_url'     => '', // https URL with {to} {message} {sender} {api_key}
		'sms_method'          => 'GET',
		'sms_api_key'         => '',
		'sms_sender'          => 'Nimikh',
		'discussion_enabled'  => 1,
		'jitsi_host'          => 'meet.jit.si',
		'ai_enabled'          => 0,
		'ai_api_key'          => '',
		'ai_model'            => 'claude-sonnet-5-5',
		'pwa_enabled'         => 1,
		'pwa_start_url'       => '',
		'pwa_icon_url'        => '',
		'remove_data_on_uninstall' => 0, // certificates are records: never deleted unless an admin opts in
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
			'sms_enabled'         => empty($input['sms_enabled']) ? 0 : 1,
			'sms_gateway_url'     => self::httpsUrl((string) ($input['sms_gateway_url'] ?? '')),
			'sms_method'          => strtoupper((string) ($input['sms_method'] ?? 'GET')) === 'POST' ? 'POST' : 'GET',
			'sms_api_key'         => sanitize_text_field((string) ($input['sms_api_key'] ?? '')),
			'sms_sender'          => sanitize_text_field((string) ($input['sms_sender'] ?? 'Nimikh')),
			'discussion_enabled'  => empty($input['discussion_enabled']) ? 0 : 1,
			'jitsi_host'          => preg_replace('/[^a-z0-9.\-]/', '', strtolower((string) ($input['jitsi_host'] ?? 'meet.jit.si'))) ?: 'meet.jit.si',
			'ai_enabled'          => empty($input['ai_enabled']) ? 0 : 1,
			'ai_api_key'          => sanitize_text_field((string) ($input['ai_api_key'] ?? '')),
			'ai_model'            => preg_replace('/[^a-zA-Z0-9._\-]/', '', (string) ($input['ai_model'] ?? 'claude-sonnet-5-5')) ?: 'claude-sonnet-5-5',
			'pwa_enabled'         => empty($input['pwa_enabled']) ? 0 : 1,
			'pwa_start_url'       => esc_url_raw((string) ($input['pwa_start_url'] ?? '')),
			'pwa_icon_url'        => esc_url_raw((string) ($input['pwa_icon_url'] ?? '')),
			'remove_data_on_uninstall' => empty($input['remove_data_on_uninstall']) ? 0 : 1,
		];
	}

	/** Gateway URLs carry an API key in the query string, so refuse anything that is not https. */
	private static function httpsUrl(string $url): string {
		$url = trim($url);
		return str_starts_with($url, 'https://') ? esc_url_raw($url, ['https']) : '';
	}
}
