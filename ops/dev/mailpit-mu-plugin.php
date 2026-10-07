<?php
/**
 * Dev only: send all wp_mail() through Mailpit (service "mailpit", port 1025). Mounted as a mu-plugin by ops/docker-compose.yml.
 */
add_action('phpmailer_init', static function ($phpmailer): void {
	$phpmailer->isSMTP();
	$phpmailer->Host       = 'mailpit';
	$phpmailer->Port       = 1025;
	$phpmailer->SMTPAuth   = false;
	$phpmailer->SMTPSecure = '';
	$phpmailer->SMTPAutoTLS = false;
});
