<?php
declare(strict_types=1);

namespace Nimikh\LMS\Ops;

use Nimikh\LMS\Support\Settings;

/**
 * Optional Sentry reporting (blueprint 6.8) with no SDK: fatal errors raised inside this plugin, and JavaScript errors
 * from the player. Off unless a DSN is saved in Settings. Sends no user data: only message, file, line and release.
 * Other plugins' errors are ignored so a broken third-party plugin cannot flood the project.
 */
final class ErrorReporter {

	private const FATAL = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];

	public function register(): void {
		if (self::dsn() === null) {
			return;
		}
		register_shutdown_function([$this, 'onShutdown']);
		add_action('nimikh_lms_report_error', [$this, 'report'], 10, 1);
		add_action('wp_enqueue_scripts', [$this, 'enqueueJs'], 20);
	}

	private static function dsn(): ?array {
		$dsn = (string) Settings::get('sentry_dsn');
		return $dsn === '' ? null : SentryDsn::parse($dsn);
	}

	public function onShutdown(): void {
		$e = error_get_last();
		if (!$e || !in_array($e['type'], self::FATAL, true) || !str_starts_with(wp_normalize_path($e['file']), wp_normalize_path(NIMIKH_LMS_DIR))) {
			return;
		}
		$this->send($e['message'], 'FatalError', str_replace(wp_normalize_path(ABSPATH), '', wp_normalize_path($e['file'])), (int) $e['line']);
	}

	/** @param \Throwable $t */
	public function report($t): void {
		if ($t instanceof \Throwable) {
			$this->send($t->getMessage(), get_class($t), str_replace(wp_normalize_path(ABSPATH), '', wp_normalize_path($t->getFile())), $t->getLine());
		}
	}

	private function send(string $message, string $type, string $file, int $line): void {
		$dsn = self::dsn();
		if ($dsn === null) {
			return;
		}
		wp_remote_post($dsn['endpoint'], [
			'timeout'  => 2,
			'blocking' => false,
			'headers'  => ['Content-Type' => 'application/x-sentry-envelope'],
			'body'     => SentryDsn::envelope(bin2hex(random_bytes(16)), $message, $type, $file, $line, NIMIKH_LMS_VERSION, wp_get_environment_type()),
		]);
	}

	public function enqueueJs(): void {
		$dsn = self::dsn();
		if ($dsn === null || !wp_script_is('nimikh-player', 'enqueued')) {
			return;
		}
		wp_enqueue_script('nimikh-errors', NIMIKH_LMS_URL . 'assets/js/errors.js', [], NIMIKH_LMS_VERSION, ['in_footer' => true, 'strategy' => 'defer']);
		wp_localize_script('nimikh-errors', 'NimikhErrors', ['endpoint' => $dsn['endpoint'], 'release' => NIMIKH_LMS_VERSION, 'environment' => wp_get_environment_type()]);
	}
}
