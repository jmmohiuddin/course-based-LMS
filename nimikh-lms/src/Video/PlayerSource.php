<?php
declare(strict_types=1);

namespace Nimikh\LMS\Video;

use Nimikh\LMS\Support\Settings;

/**
 * Resolves the playable source for a lesson. WordPress never serves video bytes:
 * Bunny Stream HLS is token-signed; a plain URL meta is allowed for dev/other CDNs.
 */
final class PlayerSource {

	/** @return array{type:string, url:string, expires:int}|null */
	public static function forLesson(int $lessonId): ?array {
		$videoId = (string) get_post_meta($lessonId, 'nimikh_bunny_video_id', true);
		$host    = (string) Settings::get('bunny_host');
		$key     = (string) Settings::get('bunny_token_key');

		if ($videoId !== '' && $host !== '') {
			$expires = time() + (int) Settings::get('url_ttl');
			$path    = '/' . rawurlencode($videoId) . '/playlist.m3u8';
			$url     = $key !== ''
				? BunnyUrlSigner::sign($host, $path, $key, $expires)
				: 'https://' . $host . $path;
			return ['type' => 'hls', 'url' => $url, 'expires' => $expires];
		}

		$plain = (string) get_post_meta($lessonId, 'nimikh_video_url', true);
		if ($plain !== '') {
			return [
				'type'    => str_contains($plain, '.m3u8') ? 'hls' : 'mp4',
				'url'     => esc_url_raw($plain),
				'expires' => 0,
			];
		}
		return null;
	}

	/** @return array<int, array{lang:string,label:string,url:string}> */
	public static function captions(int $lessonId): array {
		$raw = get_post_meta($lessonId, 'nimikh_captions', true);
		$out = [];
		foreach (is_array($raw) ? $raw : [] as $track) {
			if (!empty($track['url'])) {
				$out[] = [
					'lang'  => sanitize_key((string) ($track['lang'] ?? 'en')),
					'label' => sanitize_text_field((string) ($track['label'] ?? '')),
					'url'   => esc_url_raw((string) $track['url']),
				];
			}
		}
		return $out;
	}
}
