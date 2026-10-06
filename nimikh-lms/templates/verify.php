<?php
/**
 * Public verification page. Variables: $result (?array), $invalid (bool).
 * Self-contained so it renders identically regardless of the active theme.
 */
defined('ABSPATH') || exit;

$status = $result['status'] ?? null;
$labels = [
	'valid'            => __('Valid', 'nimikh-lms'),
	'revoked'          => __('Revoked', 'nimikh-lms'),
	'integrity_failed' => __('Cannot be verified', 'nimikh-lms'),
	'not_found'        => __('Not found', 'nimikh-lms'),
];
$icons = ['valid' => '✔', 'revoked' => '✖', 'integrity_failed' => '!', 'not_found' => '✖'];
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html__('Verify a certificate', 'nimikh-lms') . ' – ' . esc_html(get_bloginfo('name')); ?></title>
<link rel="stylesheet" href="<?php echo esc_url(NIMIKH_LMS_URL . 'assets/css/tokens.css?ver=' . NIMIKH_LMS_VERSION); ?>">
<link rel="stylesheet" href="<?php echo esc_url(NIMIKH_LMS_URL . 'assets/css/verify.css?ver=' . NIMIKH_LMS_VERSION); ?>">
</head>
<body class="nk-verify">
<main class="nk-verify__main">
	<h1><?php esc_html_e('Verify a certificate', 'nimikh-lms'); ?></h1>

	<form class="nk-verify__form" method="get" action="<?php echo esc_url(home_url('/verify/')); ?>">
		<label for="nk-code"><?php esc_html_e('Certificate ID', 'nimikh-lms'); ?></label>
		<div class="nk-verify__row">
			<input id="nk-code" name="code" type="text" autocomplete="off" inputmode="text" maxlength="14"
				value="<?php echo esc_attr($result['code'] ?? ''); ?>" required>
			<button type="submit"><?php esc_html_e('Verify', 'nimikh-lms'); ?></button>
		</div>
		<?php if (!empty($invalid)) : ?>
			<p class="nk-verify__hint" role="alert"><?php esc_html_e('That does not look like a certificate ID.', 'nimikh-lms'); ?></p>
		<?php endif; ?>
	</form>

	<?php if ($status) : ?>
		<section class="nk-card nk-card--<?php echo esc_attr($status); ?>" aria-live="polite">
			<p class="nk-badge nk-badge--<?php echo esc_attr($status); ?>">
				<span aria-hidden="true"><?php echo esc_html($icons[$status] ?? ''); ?></span>
				<?php echo esc_html($labels[$status] ?? ''); ?>
			</p>
			<?php if ($status === 'not_found') : ?>
				<p><?php esc_html_e('No certificate matches that ID. Check the code on the certificate and try again.', 'nimikh-lms'); ?></p>
			<?php elseif ($status === 'integrity_failed') : ?>
				<p><?php esc_html_e('This certificate exists but its file failed an integrity check. Please contact the issuer.', 'nimikh-lms'); ?></p>
			<?php else : ?>
				<dl>
					<dt><?php esc_html_e('Name', 'nimikh-lms'); ?></dt><dd><?php echo esc_html($result['name']); ?></dd>
					<dt><?php esc_html_e('Course', 'nimikh-lms'); ?></dt><dd><?php echo esc_html($result['course']); ?></dd>
					<dt><?php esc_html_e('Issued', 'nimikh-lms'); ?></dt><dd><?php echo esc_html($result['date']); ?></dd>
					<?php if ($result['score'] !== null) : ?>
						<dt><?php esc_html_e('Score', 'nimikh-lms'); ?></dt><dd><?php echo esc_html(rtrim(rtrim(number_format((float) $result['score'], 2), '0'), '.')); ?>%</dd>
					<?php endif; ?>
					<dt><?php esc_html_e('Issuer', 'nimikh-lms'); ?></dt><dd><?php echo esc_html($result['issuer']); ?></dd>
					<dt><?php esc_html_e('Certificate ID', 'nimikh-lms'); ?></dt><dd><?php echo esc_html($result['code']); ?></dd>
					<?php if ($status === 'revoked' && $result['reason'] !== '') : ?>
						<dt><?php esc_html_e('Reason', 'nimikh-lms'); ?></dt><dd><?php echo esc_html($result['reason']); ?></dd>
					<?php endif; ?>
				</dl>
			<?php endif; ?>
		</section>
	<?php endif; ?>
</main>
</body>
</html>
