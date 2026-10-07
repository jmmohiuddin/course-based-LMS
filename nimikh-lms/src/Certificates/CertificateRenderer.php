<?php
declare(strict_types=1);

namespace Nimikh\LMS\Certificates;

use Nimikh\LMS\Support\Settings;

/**
 * Builds certificate HTML from a template and turns it into a PDF using, in order:
 * mPDF (in-process), Gotenberg (headless Chromium container), else keeps print-ready HTML.
 */
final class CertificateRenderer {

	/**
	 * @param array<string, string> $vars placeholder => value (all values are escaped here)
	 */
	public function html(int $templateId, array $vars, string $qrDataUri): string {
		$body = '';
		if ($templateId > 0 && get_post_type($templateId) === 'nimikh_cert_template') {
			$body = (string) get_post_field('post_content', $templateId);
		}
		if ($body === '') {
			ob_start();
			include NIMIKH_LMS_DIR . 'templates/certificate-default.php';
			$body = (string) ob_get_clean();
		}

		$replace = [];
		foreach ($vars as $key => $value) {
			$replace['{{' . $key . '}}'] = esc_html($value);
		}
		// Brand colour is validated by Brand::color(); the logo is an https URL set by an admin.
		$replace['{{brand_color}}'] = esc_html(\Nimikh\LMS\Orgs\Brand::color($vars['brand_color'] ?? ''));
		$replace['{{logo_img}}']    = !empty($vars['logo']) ? '<img src="' . esc_url($vars['logo']) . '" alt="" style="max-height:60px">' : '';
		$replace['{{qr}}'] = $qrDataUri !== ''
			? '<img src="' . esc_attr($qrDataUri) . '" alt="" width="120" height="120">'
			: '';

		return strtr($body, $replace);
	}

	/** @return string PNG data URI, or '' when no QR library is installed. */
	public function qrDataUri(string $url): string {
		if (!class_exists('\Endroid\QrCode\Builder\Builder')) {
			return '';
		}
		try {
			$result = \Endroid\QrCode\Builder\Builder::create()->data($url)->size(240)->margin(4)->build();
			return $result->getDataUri();
		} catch (\Throwable $e) {
			return '';
		}
	}

	/** @return array{bytes:string, ext:string} */
	public function toFile(string $html): array {
		$pdf = $this->mpdf($html) ?? $this->gotenberg($html);
		return $pdf !== null ? ['bytes' => $pdf, 'ext' => 'pdf'] : ['bytes' => $html, 'ext' => 'html'];
	}

	private function mpdf(string $html): ?string {
		if (!class_exists('\Mpdf\Mpdf')) {
			return null;
		}
		try {
			$mpdf = new \Mpdf\Mpdf([
				'orientation' => 'L',
				'format'      => 'A4',
				'tempDir'     => get_temp_dir(),
			]);
			$mpdf->WriteHTML($html);
			return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
		} catch (\Throwable $e) {
			error_log('[nimikh-lms] mPDF failed: ' . $e->getMessage());
			return null;
		}
	}

	private function gotenberg(string $html): ?string {
		$base = (string) Settings::get('gotenberg_url');
		if ($base === '') {
			return null;
		}
		$boundary = wp_generate_password(24, false);
		$body     = "--$boundary\r\nContent-Disposition: form-data; name=\"files\"; filename=\"index.html\"\r\n"
			. "Content-Type: text/html\r\n\r\n$html\r\n"
			. "--$boundary\r\nContent-Disposition: form-data; name=\"landscape\"\r\n\r\ntrue\r\n"
			. "--$boundary\r\nContent-Disposition: form-data; name=\"printBackground\"\r\n\r\ntrue\r\n"
			. "--$boundary--\r\n";

		$res = wp_remote_post(trailingslashit($base) . 'forms/chromium/convert/html', [
			'timeout' => 30,
			'headers' => ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
			'body'    => $body,
		]);
		if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
			error_log('[nimikh-lms] Gotenberg render failed');
			return null;
		}
		return wp_remote_retrieve_body($res);
	}
}
