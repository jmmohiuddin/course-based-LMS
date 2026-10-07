<?php
declare(strict_types=1);

namespace Nimikh\LMS\Certificates;

/**
 * Visual certificate layout (A4 landscape, positions in millimetres). The editor stores this JSON on the template;
 * toHtml() turns it into the same {{placeholder}} HTML the renderer already understands, so escaping, QR, logo and
 * institute branding work exactly as for hand-written templates. Pure: no WordPress calls.
 */
final class Layout {

	public const PAGE_W = 297.0;
	public const PAGE_H = 210.0;
	public const MAX_ELEMENTS = 40;

	/** type => [placeholder or null, label] */
	public const FIELDS = [
		'name'   => '{{name}}',
		'course' => '{{course}}',
		'date'   => '{{date}}',
		'score'  => '{{score}}',
		'code'   => '{{code}}',
		'url'    => '{{url}}',
		'issuer' => '{{issuer}}',
	];
	public const OTHER = ['text', 'qr', 'logo', 'image', 'line'];

	/**
	 * Clean untrusted JSON-decoded input into a layout the renderer can trust. Returns null when there is nothing usable.
	 *
	 * @param mixed $input
	 * @return array{v:int, bg:string, frame_color:string, frame_width:float, elements:array<int, array<string, mixed>>}|null
	 */
	public static function sanitize($input): ?array {
		if (!is_array($input) || !isset($input['elements']) || !is_array($input['elements'])) {
			return null;
		}
		$elements = [];
		foreach (array_slice($input['elements'], 0, self::MAX_ELEMENTS) as $e) {
			if (!is_array($e)) {
				continue;
			}
			$type = (string) ($e['type'] ?? '');
			if (!isset(self::FIELDS[$type]) && !in_array($type, self::OTHER, true)) {
				continue;
			}
			$w = self::num($e['w'] ?? 60, 3, self::PAGE_W);
			$x = self::num($e['x'] ?? 0, 0, self::PAGE_W - min($w, self::PAGE_W));
			$el = [
				'type'  => $type,
				'x'     => $x,
				'y'     => self::num($e['y'] ?? 0, 0, self::PAGE_H),
				'w'     => $w,
				'size'  => self::num($e['size'] ?? 18, 6, 120),
				'color' => self::color((string) ($e['color'] ?? '#0F172A'), '#0F172A', true),
				'align' => in_array($e['align'] ?? '', ['left', 'center', 'right'], true) ? (string) $e['align'] : 'center',
				'bold'  => !empty($e['bold']),
			];
			if ($type === 'text') {
				$text = trim(str_replace(['{', '}'], '', strip_tags((string) ($e['text'] ?? ''))));
				if ($text === '') {
					continue;
				}
				$el['text'] = mb_substr($text, 0, 200);
			}
			if ($type === 'image') {
				$src = trim((string) ($e['src'] ?? ''));
				if (!str_starts_with($src, 'https://') || preg_match('/["\'<>\s]/', $src) === 1 || strlen($src) > 500) {
					continue;
				}
				$el['src'] = $src;
			}
			$elements[] = $el;
		}
		if (!$elements) {
			return null;
		}
		return [
			'v'           => 1,
			'bg'          => self::color((string) ($input['bg'] ?? '#FFFFFF'), '#FFFFFF', false),
			'frame_color' => self::color((string) ($input['frame_color'] ?? 'brand'), 'brand', true),
			'frame_width' => self::num($input['frame_width'] ?? 2, 0, 8),
			'elements'    => $elements,
		];
	}

	/** Same look as templates/certificate-default.php, as a starting point in the editor. */
	public static function defaultLayout(): array {
		$e = static fn(string $type, float $x, float $y, float $w, float $size, string $color = '#0F172A', bool $bold = false, string $text = ''): array =>
			['type' => $type, 'x' => $x, 'y' => $y, 'w' => $w, 'size' => $size, 'color' => $color, 'align' => 'center', 'bold' => $bold] + ($text !== '' ? ['text' => $text] : []);
		return [
			'v' => 1, 'bg' => '#FFFFFF', 'frame_color' => 'brand', 'frame_width' => 2,
			'elements' => [
				$e('issuer', 30, 24, 237, 14, '#475569'),
				$e('text', 30, 38, 237, 34, 'brand', true, 'Certificate of Completion'),
				$e('text', 30, 64, 237, 14, '#475569', false, 'This certifies that'),
				$e('name', 30, 76, 237, 32, '#0F172A', true),
				$e('text', 30, 98, 237, 14, '#475569', false, 'has successfully completed'),
				$e('course', 30, 110, 237, 22, '#0F172A'),
				$e('score', 30, 130, 118, 13, '#475569'),
				$e('date', 149, 130, 118, 13, '#475569'),
				$e('qr', 30, 150, 32, 12),
				$e('url', 70, 156, 197, 11, '#475569', false),
				$e('code', 70, 166, 197, 12, '#15803D', true),
			],
		];
	}

	/** @param array<string, mixed> $layout a value returned by sanitize() */
	public static function toHtml(array $layout): string {
		$css = [];
		$out = [];
		foreach ($layout['elements'] as $e) {
			$pos = sprintf('position:absolute;left:%smm;top:%smm;width:%smm;', self::f($e['x']), self::f($e['y']), self::f($e['w']));
			$type = $e['type'];
			if ($type === 'line') {
				$out[] = sprintf('<div style="%sborder-top:0.4mm solid %s;height:0"></div>', $pos, self::cssColor($e['color']));
				continue;
			}
			if ($type === 'qr') {
				$out[] = sprintf('<div class="qr" style="%sheight:%smm">{{qr}}</div>', $pos, self::f($e['w']));
				continue;
			}
			if ($type === 'logo') {
				$out[] = sprintf('<div style="%stext-align:%s">{{logo_img}}</div>', $pos, $e['align']);
				continue;
			}
			if ($type === 'image') {
				$out[] = sprintf('<img src="%s" alt="" style="%s">', htmlspecialchars($e['src'], ENT_QUOTES), $pos);
				continue;
			}
			$content = $type === 'text' ? htmlspecialchars($e['text'], ENT_QUOTES) : self::FIELDS[$type];
			$out[]   = sprintf(
				'<div style="%sfont-size:%spx;color:%s;text-align:%s;font-weight:%s;line-height:1.2">%s</div>',
				$pos,
				self::f($e['size']),
				self::cssColor($e['color']),
				$e['align'],
				$e['bold'] ? '700' : '400',
				$content
			);
		}
		$frame = $layout['frame_width'] > 0
			? sprintf('<div style="position:absolute;left:6mm;top:6mm;width:%smm;height:%smm;border:%smm double %s"></div>', self::f(self::PAGE_W - 12 - 2 * $layout['frame_width']), self::f(self::PAGE_H - 12 - 2 * $layout['frame_width']), self::f($layout['frame_width']), self::cssColor($layout['frame_color']))
			: '';

		return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Certificate {{code}}</title><style>'
			. '@page{size:A4 landscape;margin:0}'
			. "body{margin:0;font-family:'Hind Siliguri','Inter',sans-serif;color:#0F172A}"
			. '.page{position:relative;width:297mm;height:210mm;background:' . $layout['bg'] . ';overflow:hidden}'
			. '.qr img{width:100%;height:auto}'
			. '</style></head><body><div class="page">' . $frame . implode('', $out) . '</div></body></html>';
	}

	private static function cssColor(string $c): string {
		return $c === 'brand' ? '{{brand_color}}' : $c;
	}

	private static function f(float $n): string {
		return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') ?: '0';
	}

	/** @param mixed $v */
	private static function num($v, float $min, float $max): float {
		return round(max($min, min($max, is_numeric($v) ? (float) $v : $min)), 2);
	}

	private static function color(string $c, string $fallback, bool $allowBrand): string {
		if ($allowBrand && $c === 'brand') {
			return 'brand';
		}
		return preg_match('/^#[0-9a-fA-F]{6}$/', $c) === 1 ? strtoupper($c) : $fallback;
	}
}
