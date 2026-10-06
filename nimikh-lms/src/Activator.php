<?php
declare(strict_types=1);

namespace Nimikh\LMS;

use Nimikh\LMS\Certificates\VerifyRoute;
use Nimikh\LMS\Support\Installer;
use Nimikh\LMS\Support\Roles;

final class Activator {

	public static function activate(): void {
		Installer::migrate();
		Roles::register();
		VerifyRoute::addRewriteRules();
		flush_rewrite_rules();
		wp_mkdir_p(\Nimikh\LMS\Certificates\CertificateStorage::baseDir());
		\Nimikh\LMS\Certificates\CertificateStorage::protectDir();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
