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

		return str_replace('__CFG__', $cfg, <<<'JS'
/* Nimikh LMS service worker. Generated; do not edit. */
const CFG = __CFG__;
const OFF = 'nimikh-offline';          // downloaded lessons; survives worker upgrades
const ORIGIN = self.location.origin;
const offKey = (id, name) => ORIGIN + '/nimikh-offline-media/' + id + '/' + name;

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CFG.cache).then((c) => c.addAll(CFG.precache)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith('nimikh-') && k !== CFG.cache && k !== OFF).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

/* ---- offline lessons -------------------------------------------------------------------------------------------- */

async function purge(id) {
  const c = await caches.open(OFF);
  const prefix = ORIGIN + '/nimikh-offline-media/' + id + '/';
  const keys = await c.keys();
  await Promise.all(keys.filter((k) => k.url.startsWith(prefix)).map((k) => c.delete(k)));
}

/** The lesson's licence if the copy is still valid; an expired one is deleted on the spot. */
async function license(id) {
  const c = await caches.open(OFF);
  const hit = await c.match(offKey(id, 'license.json'));
  if (!hit) { return null; }
  let lic = null;
  try { lic = await hit.json(); } catch (e) { return null; }
  if (!lic || !lic.expires_at || lic.expires_at * 1000 < Date.now()) { await purge(id); return null; }
  return lic;
}

async function serveVideo(req, id) {
  if (!(await license(id))) { return new Response('This offline copy has expired.', { status: 410 }); }
  const c = await caches.open(OFF);
  const hit = await c.match(offKey(id, 'video.mp4'));
  if (!hit) { return new Response('Not found', { status: 404 }); }
  const blob = await hit.blob();
  const size = blob.size;
  const type = hit.headers.get('Content-Type') || 'video/mp4';
  const range = req.headers.get('range');
  if (!range) {
    return new Response(blob, { status: 200, headers: { 'Content-Type': type, 'Content-Length': String(size), 'Accept-Ranges': 'bytes' } });
  }
  const m = /^bytes=(\d*)-(\d*)$/.exec(range);
  if (!m || (m[1] === '' && m[2] === '')) { return new Response(null, { status: 416, headers: { 'Content-Range': 'bytes */' + size } }); }
  const start = m[1] === '' ? Math.max(0, size - parseInt(m[2], 10)) : parseInt(m[1], 10);
  const end = (m[1] !== '' && m[2] !== '') ? Math.min(parseInt(m[2], 10), size - 1) : size - 1;
  if (start >= size || start > end) { return new Response(null, { status: 416, headers: { 'Content-Range': 'bytes */' + size } }); }
  return new Response(blob.slice(start, end + 1, type), {
    status: 206,
    headers: { 'Content-Type': type, 'Content-Length': String(end - start + 1), 'Content-Range': 'bytes ' + start + '-' + end + '/' + size, 'Accept-Ranges': 'bytes' }
  });
}

/** Lesson data for the player. For a downloaded lesson the video source is swapped for the local copy; the rest stays fresh when online. */
async function playerResponse(req, id) {
  const lic = await license(id);
  if (!lic) { return fetch(req); }
  const c = await caches.open(OFF);
  let body;
  try {
    const res = await fetch(req);
    if (!res.ok) { return res; }          // e.g. enrolment ended: the server's answer wins, the local copy is not used
    body = await res.text();
    await c.put(offKey(id, 'player.json'), new Response(body, { headers: { 'Content-Type': 'application/json' } }));
  } catch (e) {
    const hit = await c.match(offKey(id, 'player.json'));
    if (!hit) { return Response.error(); }
    body = await hit.text();
  }
  const data = JSON.parse(body);
  data.source = { type: 'mp4', url: '/nimikh-offline-media/' + id + '/video.mp4', expires: 0 };
  data.offline = true;
  data.offline_expires = lic.expires_at;
  return new Response(JSON.stringify(data), { headers: { 'Content-Type': 'application/json' } });
}

async function navigateFallback(req) {
  const c = await caches.open(OFF);
  const hit = await c.match(req.url);
  if (hit) {
    const id = hit.headers.get('x-nimikh-lesson');
    if (id && (await license(id))) { return hit; }
  }
  return caches.match(CFG.offline);
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);
  if (url.origin !== ORIGIN) { return; }
  let m;
  if (req.method === 'GET' && (m = /\/nimikh-offline-media\/(\d+)\/video\.(?:mp4|webm)$/.exec(url.pathname))) { event.respondWith(serveVideo(req, m[1])); return; }
  if (req.method === 'GET' && (m = /\/wp-json\/nimikh\/v1\/lessons\/(\d+)\/player\/?$/.exec(url.pathname))) { event.respondWith(playerResponse(req, m[1])); return; }
  if (req.method !== 'GET') { return; }
  // Never cache the REST API, admin, login or media streams: they are per-user or large.
  if (/\/wp-json\/|\/wp-admin\/|wp-login|\.(m3u8|ts|mp4|webm)(\?|$)/.test(url.pathname + url.search) || req.headers.has('range')) { return; }

  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(() => navigateFallback(req)));
    return;
  }
  if (CFG.static.some((p) => url.pathname.startsWith(p))) {
    event.respondWith(
      caches.match(req).then((hit) => hit || fetch(req).then((res) => {
        if (res.ok) { const copy = res.clone(); caches.open(CFG.cache).then((c) => c.put(req, copy)); }
        return res;
      }))
    );
    return;
  }
  // Files of a downloaded lesson page (theme CSS, scripts, images) are served from the offline cache when the network is gone.
  event.respondWith(fetch(req).catch(() => caches.open(OFF).then((c) => c.match(req.url)).then((hit) => hit || Response.error())));
});
JS);
	}

	public static function offlineHtml(string $siteName, string $retryUrl, string $heading, string $body, string $button): string {
		$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>' . $e($siteName) . '</title><style>body{margin:0;font-family:system-ui,sans-serif;background:#fff;color:#0F172A;display:grid;place-items:center;min-height:100vh;padding:16px;text-align:center}'
			. '@media(prefers-color-scheme:dark){body{background:#0B1120;color:#E2E8F0}}a{display:inline-block;margin-top:16px;padding:12px 20px;min-height:44px;box-sizing:border-box;border-radius:8px;background:#1E4FD8;color:#fff;text-decoration:none;font-weight:600}</style></head>'
			. '<body><main><h1>' . $e($heading) . '</h1><p>' . $e($body) . '</p><a href="' . $e($retryUrl) . '">' . $e($button) . '</a></main></body></html>';
	}
}
