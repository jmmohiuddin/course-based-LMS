<?php
// Unit tests cover the pure logic classes only, so no WordPress is loaded.
spl_autoload_register(static function (string $class): void {
	$prefix = 'Nimikh\\LMS\\';
	if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
		return;
	}
	$file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($file)) {
		require $file;
	}
});
