<?php
declare(strict_types=1);

namespace Nimikh\LMS\Certificates;

/** Pure builders for "add to LinkedIn" / social share URLs (blueprint flow A, step 5). */
final class ShareLinks {

	/**
	 * LinkedIn "Add to profile" certification form.
	 *
	 * @param array{name:string, issuer:string, issued_at:string, code:string, url:string} $c issued_at is a GMT "Y-m-d H:i:s"
	 */
	public static function linkedin(array $c): string {
		$ts = strtotime($c['issued_at'] . ' UTC') ?: time();
		return 'https://www.linkedin.com/profile/add?' . http_build_query([
			'startTask'        => 'CERTIFICATION_NAME',
			'name'             => $c['name'],
			'organizationName' => $c['issuer'],
			'issueYear'        => (int) gmdate('Y', $ts),
			'issueMonth'       => (int) gmdate('n', $ts),
			'certUrl'          => $c['url'],
			'certId'           => $c['code'],
		], '', '&', PHP_QUERY_RFC3986);
	}

	public static function facebook(string $url): string {
		return 'https://www.facebook.com/sharer/sharer.php?' . http_build_query(['u' => $url], '', '&', PHP_QUERY_RFC3986);
	}

	/** WhatsApp is the most common way certificates are passed around in Bangladesh. */
	public static function whatsapp(string $text, string $url): string {
		return 'https://wa.me/?' . http_build_query(['text' => trim($text . ' ' . $url)], '', '&', PHP_QUERY_RFC3986);
	}
}
