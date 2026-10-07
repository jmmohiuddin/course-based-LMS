<?php
declare(strict_types=1);

namespace Nimikh\LMS\Ai;

/** Parses WebVTT captions into timed text cues. */
final class VttParser {

	/** @return array<int, array{start:int, text:string}> */
	public static function parse(string $vtt): array {
		$cues = [];
		$blocks = preg_split('/\R{2,}/', str_replace("\xEF\xBB\xBF", '', trim($vtt))) ?: [];
		$last = '';
		foreach ($blocks as $block) {
			$lines = preg_split('/\R/', trim($block)) ?: [];
			if (!$lines || str_starts_with($lines[0], 'WEBVTT') || str_starts_with($lines[0], 'NOTE')) {
				continue;
			}
			$timeIdx = null;
			foreach ($lines as $i => $line) {
				if (str_contains($line, '-->')) {
					$timeIdx = $i;
					break;
				}
			}
			if ($timeIdx === null) {
				continue;
			}
			$start = self::seconds(trim(explode('-->', $lines[$timeIdx])[0]));
			$text  = trim(preg_replace('/\s+/u', ' ', strip_tags(implode(' ', array_slice($lines, $timeIdx + 1)))) ?? '');
			if ($start === null || $text === '' || $text === $last) {
				continue; // skip empty and rolling-caption duplicates
			}
			$last   = $text;
			$cues[] = ['start' => $start, 'text' => html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')];
		}
		return $cues;
	}

	private static function seconds(string $stamp): ?int {
		if (preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{2})(?:[.,]\d+)?$/', $stamp, $m) !== 1) {
			return null;
		}
		return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
	}

	/**
	 * Group cues into "[m:ss] text" lines, one per window, for the prompt.
	 *
	 * @param array<int, array{start:int, text:string}> $cues
	 */
	public static function toTimedText(array $cues, int $window = 20): string {
		$lines = [];
		$bucket = null; $text = '';
		foreach ($cues as $cue) {
			$b = intdiv($cue['start'], $window);
			if ($bucket !== $b) {
				if ($text !== '') {
					$lines[] = $text;
				}
				$bucket = $b;
				$text   = sprintf('[%d:%02d] ', intdiv($cue['start'], 60), $cue['start'] % 60) . $cue['text'];
			} else {
				$text .= ' ' . $cue['text'];
			}
		}
		if ($text !== '') {
			$lines[] = $text;
		}
		return implode("\n", $lines);
	}
}
