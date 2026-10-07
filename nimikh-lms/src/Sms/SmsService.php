<?php
declare(strict_types=1);

namespace Nimikh\LMS\Sms;

use Nimikh\LMS\Support\Installer;
use Nimikh\LMS\Support\Settings;
use Nimikh\LMS\Support\Time;

/**
 * FR-13 SMS. Provider-agnostic: Bangladeshi bulk-SMS gateways are plain HTTPS GET/POST APIs, so the
 * gateway is a configurable URL template. Messages are logged; users can opt out.
 */
final class SmsService {

	public const DEFAULT_TEMPLATES = [
		'enrolled'           => 'Nimikh: You are enrolled in {course}. Start learning: {url}',
		'certificate_issued' => 'Nimikh: Your certificate for {course} is ready. Verify: {url}',
		'exam_result'        => 'Nimikh: Your {course} exam score is {score}% ({result}).',
		'live_reminder'      => "Nimikh: Live class '{title}' starts at {time}. Join: {url}",
	];

	public function register(): void {
		add_action('nimikh_notify_sms', [$this, 'handle'], 10, 3);
	}

	public static function template(string $event): string {
		$custom = get_option('nimikh_sms_template_' . $event, '');
		return is_string($custom) && $custom !== '' ? $custom : (self::DEFAULT_TEMPLATES[$event] ?? '');
	}

	/** @param array<string, mixed> $vars */
	public function handle(int $userId, string $event, array $vars = []): void {
		if (!Settings::get('sms_enabled') || get_user_meta($userId, 'nimikh_sms_optout', true)) {
			return;
		}
		$template = self::template($event);
		if ($template === '') {
			return;
		}
		$phone = $this->phoneFor($userId);
		if ($phone === null) {
			return;
		}
		$message = Template::render($template, array_map('strval', $vars));
		if ($message === '') {
			return;
		}
		[$status, $response] = $this->deliver($phone, $message);
		$this->log($userId, $phone, $event, $status, $response);
	}

	/**
	 * Send one message to a number regardless of the notification opt-in (used for sign-in codes).
	 * The text is never logged, only the outcome.
	 */
	public function sendRaw(string $phone, string $message, string $event = 'otp'): bool {
		[$status, $response] = $this->deliver($phone, $message);
		$this->log(0, $phone, $event, $status, $response);
		return $status === 'sent';
	}

	public function phoneFor(int $userId): ?string {
		foreach (['nimikh_phone', 'billing_phone', 'phone'] as $key) {
			$raw = (string) get_user_meta($userId, $key, true);
			if ($raw !== '' && ($n = PhoneNumber::normalise($raw)) !== null) {
				return $n;
			}
		}
		return null;
	}

	/** @return array{0:string,1:string} [status, response snippet] */
	private function deliver(string $phone, string $message): array {
		/**
		 * Short-circuit for tests or custom providers. Return ['sent'|'failed', 'detail'] to handle delivery.
		 */
		$pre = apply_filters('nimikh_lms_sms_deliver', null, $phone, $message);
		if (is_array($pre)) {
			return [(string) $pre[0], (string) ($pre[1] ?? '')];
		}

		$url = (string) Settings::get('sms_gateway_url');
		if ($url === '' || !str_starts_with($url, 'https://')) {
			return ['failed', 'gateway not configured'];
		}
		$params = [
			'{to}'      => rawurlencode($phone),
			'{message}' => rawurlencode($message),
			'{sender}'  => rawurlencode((string) Settings::get('sms_sender')),
			'{api_key}' => rawurlencode((string) Settings::get('sms_api_key')),
		];
		if (Settings::get('sms_method') === 'POST') {
			$res = wp_remote_post((string) strtok($url, '?'), [
				'timeout' => 10,
				'body'    => ['to' => $phone, 'message' => $message, 'sender' => Settings::get('sms_sender'), 'api_key' => Settings::get('sms_api_key')],
			]);
		} else {
			$res = wp_remote_get(strtr($url, $params), ['timeout' => 10]);
		}
		if (is_wp_error($res)) {
			return ['failed', mb_substr($res->get_error_message(), 0, 200)];
		}
		$code = (int) wp_remote_retrieve_response_code($res);
		// Never log the body verbatim: some gateways echo credentials.
		return [$code >= 200 && $code < 300 ? 'sent' : 'failed', 'HTTP ' . $code];
	}

	private function log(int $userId, string $phone, string $event, string $status, string $response): void {
		global $wpdb;
		$wpdb->insert(Installer::table('sms_log'), [
			'user_id'    => $userId,
			'phone'      => substr($phone, 0, 20),
			'event'      => substr($event, 0, 40),
			'status'     => $status,
			'response'   => mb_substr($response, 0, 255),
			'created_at' => Time::nowGmt(),
		]);
	}
}
