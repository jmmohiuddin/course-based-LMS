<?php
declare(strict_types=1);

use Nimikh\LMS\Certificates\Layout;
use PHPUnit\Framework\TestCase;

final class LayoutTest extends TestCase {

	public function test_default_layout_survives_sanitising_unchanged(): void {
		$def = Layout::defaultLayout();
		$out = Layout::sanitize($def);
		$this->assertNotNull($out);
		$this->assertCount(count($def['elements']), $out['elements']);
		$this->assertSame('brand', $out['frame_color']);
	}

	public function test_garbage_is_rejected_or_cleaned(): void {
		$this->assertNull(Layout::sanitize('x'));
		$this->assertNull(Layout::sanitize(['elements' => []]));
		$this->assertNull(Layout::sanitize(['elements' => [['type' => 'script', 'x' => 1]]]), 'unknown type dropped');
		$out = Layout::sanitize(['bg' => 'red;}</style>', 'elements' => [
			['type' => 'name', 'x' => -50, 'y' => 9999, 'w' => 99999, 'size' => 0, 'color' => 'url(javascript:x)', 'align' => 'weird'],
			['type' => 'text', 'text' => '<b>Hi</b> {{code}}'],
			['type' => 'text', 'text' => '   '],
			['type' => 'image', 'src' => 'http://insecure.example/a.png'],
			['type' => 'image', 'src' => 'https://ok.example/sig.png" onerror="x'],
			['type' => 'image', 'src' => 'https://ok.example/sig.png'],
		]]);
		$this->assertSame('#FFFFFF', $out['bg']);
		$this->assertCount(3, $out['elements'], 'name + text + valid image kept');
		$name = $out['elements'][0];
		$this->assertSame(0.0, $name['x']);
		$this->assertSame(Layout::PAGE_H, $name['y']);
		$this->assertSame(Layout::PAGE_W, $name['w']);
		$this->assertSame(6.0, $name['size']);
		$this->assertSame('#0F172A', $name['color']);
		$this->assertSame('center', $name['align']);
		$this->assertSame('Hi code', $out['elements'][1]['text'], 'markup and placeholder braces stripped');
	}

	public function test_element_count_is_capped(): void {
		$els = array_fill(0, 100, ['type' => 'name']);
		$this->assertCount(Layout::MAX_ELEMENTS, Layout::sanitize(['elements' => $els])['elements']);
	}

	public function test_html_uses_placeholders_and_escapes_text(): void {
		$html = Layout::toHtml(Layout::sanitize(['elements' => [
			['type' => 'name', 'x' => 10, 'y' => 20, 'w' => 100, 'size' => 30, 'color' => 'brand'],
			['type' => 'text', 'text' => 'Tom & "Jerry"', 'x' => 10, 'y' => 40],
			['type' => 'qr', 'x' => 5, 'y' => 150, 'w' => 30],
			['type' => 'image', 'src' => 'https://ok.example/s.png', 'x' => 200, 'y' => 150, 'w' => 40],
		]]));
		$this->assertStringContainsString('{{name}}', $html);
		$this->assertStringContainsString('color:{{brand_color}}', $html);
		$this->assertStringContainsString('{{qr}}', $html);
		$this->assertStringContainsString('left:10mm;top:20mm;width:100mm', $html);
		$this->assertStringContainsString('Tom &amp; &quot;Jerry&quot;', $html);
		$this->assertStringContainsString('src="https://ok.example/s.png"', $html);
		$this->assertStringNotContainsString('<script', $html);
	}
}
