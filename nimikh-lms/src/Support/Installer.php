<?php
declare(strict_types=1);

namespace Nimikh\LMS\Support;

/**
 * Versioned dbDelta migrations. Each file in /migrations returns a callable that
 * receives ($wpdb prefix, charset collate) and returns SQL for dbDelta().
 */
final class Installer {

	public const VERSION_OPTION = 'nimikh_lms_db_version';

	/** @var array<int, string> schema version => migration file */
	private const MIGRATIONS = [
		1 => '001-initial.php',
		2 => '002-phase2-3.php',
		3 => '003-notes.php',
	];

	public static function migrate(): void {
		$installed = (int) get_option(self::VERSION_OPTION, 0);
		$latest    = max(array_keys(self::MIGRATIONS));
		if ($installed >= $latest) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach (self::MIGRATIONS as $version => $file) {
			if ($version <= $installed) {
				continue;
			}
			$migration = require NIMIKH_LMS_DIR . 'migrations/' . $file;
			dbDelta($migration($wpdb->prefix, $wpdb->get_charset_collate()));
			update_option(self::VERSION_OPTION, $version, false);
		}
	}

	public static function table(string $name): string {
		global $wpdb;
		return $wpdb->prefix . 'nimikh_' . $name;
	}
}
