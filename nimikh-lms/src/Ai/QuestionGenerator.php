<?php
declare(strict_types=1);

namespace Nimikh\LMS\Ai;

use Nimikh\LMS\Support\RateLimiter;
use Nimikh\LMS\Support\Settings;

/**
 * Phase 3: AI-assisted question drafts via the Anthropic Messages API. Drafts only: nothing is saved
 * until an instructor reviews and adds it, and the server re-validates every field the model returns.
 * Captions/transcripts are sent to Anthropic, so the feature is off until an admin enables it.
 */
final class QuestionGenerator {

	public const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	/**
	 * @return array{questions: array<int, array<string, mixed>>, dropped: int}|\WP_Error
	 */
	public function suggest(int $userId, int $lessonId, string $transcript, int $count, string $language) {
		if (!Settings::get('ai_enabled') || (string) Settings::get('ai_api_key') === '') {
			return new \WP_Error('nimikh_ai_off', __('AI suggestions are not enabled. An administrator can turn them on in the settings.', 'nimikh-lms'), ['status' => 400]);
		}
		if (!RateLimiter::hit('ai', (string) $userId, 10, 3600)) {
			return new \WP_Error('nimikh_rate_limited', __('You have used all AI suggestions for this hour.', 'nimikh-lms'), ['status' => 429]);
		}
		$duration = (int) get_post_meta($lessonId, 'nimikh_video_duration', true);
		if ($duration < 90) {
			return new \WP_Error('nimikh_ai_duration', __('Open the video in the editor once so its length is known, and use a video of at least 90 seconds.', 'nimikh-lms'), ['status' => 409]);
		}

		$timed = trim($transcript) !== '' ? trim($transcript) : $this->captionsText($lessonId);
		if ($timed === '') {
			return new \WP_Error('nimikh_ai_transcript', __('Paste a transcript or add captions to this lesson first.', 'nimikh-lms'), ['status' => 400]);
		}

		$count  = max(1, min(10, $count));
		$prompt = PromptBuilder::build($timed, $count, $language, $duration);

		$res = wp_remote_post(self::ENDPOINT, [
			'timeout' => 60,
			'headers' => [
				'x-api-key'         => (string) Settings::get('ai_api_key'),
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			],
			'body'    => wp_json_encode([
				'model'      => (string) Settings::get('ai_model'),
				'max_tokens' => 4000,
				'system'     => $prompt['system'],
				'messages'   => [['role' => 'user', 'content' => $prompt['user']]],
			]),
		]);
		if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
			// Do not echo the provider's error body: it can contain request details.
			$code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
			error_log('[nimikh-lms] AI request failed (' . ($code ?: 'transport error') . ')');
			return new \WP_Error('nimikh_ai_failed', __('The AI service did not respond. Try again in a moment.', 'nimikh-lms'), ['status' => 502]);
		}
		$data = json_decode(wp_remote_retrieve_body($res), true);
		$text = '';
		foreach ((array) ($data['content'] ?? []) as $block) {
			if (($block['type'] ?? '') === 'text') {
				$text .= (string) $block['text'];
			}
		}
		return QuestionParser::parse($text, $duration, $count);
	}

	private function captionsText(int $lessonId): string {
		$tracks = get_post_meta($lessonId, 'nimikh_captions', true);
		foreach (is_array($tracks) ? $tracks : [] as $track) {
			$url = (string) ($track['url'] ?? '');
			if (!str_starts_with($url, 'https://')) {
				continue;
			}
			// wp_safe_remote_get refuses internal addresses (SSRF guard).
			$res = wp_safe_remote_get($url, ['timeout' => 15, 'limit_response_size' => 2 * MB_IN_BYTES]);
			if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
				return VttParser::toTimedText(VttParser::parse(wp_remote_retrieve_body($res)));
			}
		}
		return '';
	}
}
