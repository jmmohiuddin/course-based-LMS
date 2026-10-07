<?php
declare(strict_types=1);

namespace Nimikh\LMS\Pwa;

/**
 * Pure builders for the PWA files so they can be tested without WordPress.
 * Caching policy: static plugin assets cache-first; navigations network-first with an offline page;
 * REST calls, video and anything authenticated are never cached.
 */
final class PwaAssets {

	/** @param array{name:string, short_name:string, start_url:string, scope:string, theme_color:string, background_color:string, icon_url:string} $c */
	public static function manifest(array $c): array {
		return [
			'name'             => $c['name'],
			'short_name'       => $c['short_name'],
			'start_url'        => $c['start_url'],
			'scope'            => $c['scope'],
			'display'          => 'standalone',
			'orientation'      => 'portrait',
			'theme_color'      => $c['theme_color'],
			'background_color' => $c['background_color'],
			'icons'            => [[
				'src' => $c['icon_url'], 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any',
			]],
		];
	}

	/**
	 * @param array{version:string, offline_url:string, precache:string[], static_prefixes:string[]} $c
	 */
	public static function serviceWorker(array $c): string {
		$cfg = json_encode([
			'cache'    => 'nimikh-' . $c['version'],
			'offline'  => $c['offline_url'],
			'precache' => array_merge([$c['offline_url']], $c['precache']),
			'static'   => $c['static_prefixes'],
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

		return <<<JS
/* Nimikh LMS service worker. Generated; do not edit. */
const CFG = $cfg;

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CFG.cache).then((c) => c.addAll(CFG.precache)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith('nimikh-') && k !== CFG.cache).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') { return; }
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) { return; }
  // Never cache the REST API, admin, login or media streams: they are per-user or large.
  if (/\\/wp-json\\/|\\/wp-admin\\/|wp-login|\\.(m3u8|ts|mp4|webm)(\\?|$)/.test(url.pathname + url.search) || req.headers.has('range')) { return; }

  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(() => caches.match(CFG.offline)));
    return;
  }
  if (CFG.static.some((p) => url.pathname.startsWith(p))) {
    event.respondWith(
      caches.match(req).then((hit) => hit || fetch(req).then((res) => {
        if (res.ok) { const copy = res.clone(); caches.open(CFG.cache).then((c) => c.put(req, copy)); }
        return res;
      }))
    );
  }
});
JS;
	}

	public static function offlineHtml(string $siteName, string $retryUrl, string $heading, string $body, string $button): string {
		$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>' . $e($siteName) . '</title><style>body{margin:0;font-family:system-ui,sans-serif;background:#fff;color:#0F172A;display:grid;place-items:center;min-height:100vh;padding:16px;text-align:center}'
			. '@media(prefers-color-scheme:dark){body{background:#0B1120;color:#E2E8F0}}a{display:inline-block;margin-top:16px;padding:12px 20px;min-height:44px;box-sizing:border-box;border-radius:8px;background:#1E4FD8;color:#fff;text-decoration:none;font-weight:600}</style></head>'
			. '<body><main><h1>' . $e($heading) . '</h1><p>' . $e($body) . '</p><a href="' . $e($retryUrl) . '">' . $e($button) . '</a></main></body></html>';
	}
}
