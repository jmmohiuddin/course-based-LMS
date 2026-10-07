<?php
/**
 * Plugin Name:       Nimikh LMS
 * Description:       Interactive video MCQ pop-ups, anti-skip progress and verifiable certificates on top of Tutor LMS.
 * Version:           0.1.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Nimikh
 * License:           GPL-2.0-or-later
 * Text Domain:       nimikh-lms
 * Domain Path:       /languages
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('NIMIKH_LMS_VERSION', '0.1.1');
define('NIMIKH_LMS_FILE', __FILE__);
define('NIMIKH_LMS_DIR', plugin_dir_path(__FILE__));
define('NIMIKH_LMS_URL', plugin_dir_url(__FILE__));

if (is_readable(NIMIKH_LMS_DIR . 'vendor/autoload.php')) {
	require_once NIMIKH_LMS_DIR . 'vendor/autoload.php';
} else {
	// Works without `composer install`; Composer is only needed for the optional PDF/QR libraries.
	spl_autoload_register(static function (string $class): void {
		$prefix = 'Nimikh\\LMS\\';
		if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
			return;
		}
		$file = NIMIKH_LMS_DIR . 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_readable($file)) {
			require_once $file;
		}
	});
}

register_activation_hook(__FILE__, [\Nimikh\LMS\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [\Nimikh\LMS\Activator::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
	\Nimikh\LMS\Plugin::instance()->boot();
});
