<?php
declare(strict_types=1);

namespace Nimikh\LMS\Scorm;

/** Reads what the player needs from imsmanifest.xml (SCORM 1.2 and 2004). No network access, no entities. Pure. */
final class Manifest {

	public const MAX_BYTES = 2097152;

	/**
	 * @return array{version:string, title:string, launch:string, query:string, mastery:?float}|null null when unsupported or unreadable
	 */
	public static function parse(string $xml): ?array {
		if ($xml === '' || strlen($xml) > self::MAX_BYTES || stripos($xml, '<!ENTITY') !== false) {
			return null; // entity declarations are how XML bombs and XXE work; real manifests never need them
		}
		$prev = libxml_use_internal_errors(true);
		$dom  = new \DOMDocument();
		$ok   = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		if (!$ok || !$dom->documentElement || $dom->documentElement->localName !== 'manifest') {
			return null;
		}
		$xp = new \DOMXPath($dom);

		$version = self::version($xp, $xml);
		if ($version === null) {
			return null;
		}

		$default = (string) $xp->evaluate("string(//*[local-name()='organizations']/@default)");
		$orgQ    = $default !== '' ? "//*[local-name()='organization'][@identifier='" . str_replace("'", '', $default) . "']" : "(//*[local-name()='organization'])[1]";
		$org     = $xp->query($orgQ)->item(0);
		if (!$org instanceof \DOMElement) {
			return null;
		}
		$item = $xp->query(".//*[local-name()='item'][@identifierref]", $org)->item(0);
		if (!$item instanceof \DOMElement) {
			return null;
		}
		$ref = str_replace("'", '', $item->getAttribute('identifierref'));
		$res = $xp->query("//*[local-name()='resource'][@identifier='" . $ref . "']")->item(0);
		if (!$res instanceof \DOMElement || $res->getAttribute('href') === '') {
			return null;
		}

		$base = '';
		foreach ([$dom->documentElement, $res->parentNode, $res] as $n) {
			if ($n instanceof \DOMElement && $n->hasAttributeNS('http://www.w3.org/XML/1998/namespace', 'base')) {
				$base .= $n->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'base');
			}
		}
		$href  = $base . $res->getAttribute('href');
		$split = preg_split('/[?#]/', $href, 2);
		$path  = rawurldecode((string) $split[0]);
		if (ZipGuard::cleanPath($path) === null) {
			return null;
		}
		$query = '';
		if (strlen($href) > strlen((string) $split[0])) {
			$query = substr($href, strlen((string) $split[0]));
		}
		$params = $item->getAttribute('parameters');
		if ($query === '' && $params !== '') {
			$query = ($params[0] === '?' || $params[0] === '#') ? $params : '?' . $params;
		}

		$title = trim((string) $xp->evaluate("string(*[local-name()='title'][1])", $org)) ?: trim((string) $xp->evaluate("string(*[local-name()='title'][1])", $item));
		return [
			'version' => $version,
			'title'   => mb_substr($title, 0, 200),
			'launch'  => $path,
			'query'   => self::safeQuery($query),
			'mastery' => self::mastery($xp, $item, $version),
		];
	}

	private static function version(\DOMXPath $xp, string $xml): ?string {
		$sv = strtolower(trim((string) $xp->evaluate("string(//*[local-name()='metadata']/*[local-name()='schemaversion'])")));
		if ($sv !== '') {
			if (str_contains($sv, '1.2')) {
				return '1.2';
			}
			if (str_contains($sv, '2004') || str_contains($sv, '1.3')) {
				return '2004';
			}
			return null;
		}
		if (str_contains($xml, 'adlcp_rootv1p2')) {
			return '1.2';
		}
		return str_contains($xml, 'adlcp_v1p3') ? '2004' : null;
	}

	/** Mastery score as a percentage, or null. */
	private static function mastery(\DOMXPath $xp, \DOMElement $item, string $version): ?float {
		if ($version === '1.2') {
			$m = trim((string) $xp->evaluate("string(*[local-name()='masteryscore'])", $item));
			return is_numeric($m) ? max(0.0, min(100.0, (float) $m)) : null;
		}
		$m = trim((string) $xp->evaluate("string(.//*[local-name()='minNormalizedMeasure'])", $item));
		return is_numeric($m) ? max(0.0, min(100.0, (float) $m * 100)) : null;
	}

	/** Only characters a launch query can legitimately carry; anything odd is dropped. */
	private static function safeQuery(string $q): string {
		return preg_match('/^[?#][A-Za-z0-9_\-.=&%+\/:,;]*$/', $q) === 1 ? $q : '';
	}
}
