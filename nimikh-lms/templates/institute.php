<?php
/** Branded institute portal. Variables: $org (array), $courses (array). */
defined('ABSPATH') || exit;
$bg = \Nimikh\LMS\Orgs\Brand::color($org['brand_color']);
$fg = \Nimikh\LMS\Orgs\Brand::textOn($bg);
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($org['name']); ?></title>
<link rel="stylesheet" href="<?php echo esc_url(NIMIKH_LMS_URL . 'assets/css/tokens.css?ver=' . NIMIKH_LMS_VERSION); ?>">
<link rel="stylesheet" href="<?php echo esc_url(NIMIKH_LMS_URL . 'assets/css/verify.css?ver=' . NIMIKH_LMS_VERSION); ?>">
<style>
	.nk-portal__hero { background: <?php echo esc_html($bg); ?>; color: <?php echo esc_html($fg); ?>; padding: 32px 16px; text-align: center; }
	.nk-portal__hero img { max-height: 64px; max-width: 70%; margin-bottom: 8px; }
	.nk-portal__hero h1 { margin: 0; font-size: 28px; line-height: 1.25; }
	.nk-portal__grid { display: grid; gap: 12px; margin-top: 16px; }
	@media (min-width: 640px) { .nk-portal__grid { grid-template-columns: 1fr 1fr; } }
	.nk-portal__card a { color: var(--nk-ink); text-decoration: none; font-weight: 700; }
	.nk-portal__card p { margin: 6px 0 0; color: var(--nk-ink-muted); }
	.nk-portal__cta { color: <?php echo esc_html($bg); ?>; font-weight: 600; }
</style>
</head>
<body class="nk-verify">
<header class="nk-portal__hero">
	<?php if ($org['logo_url'] !== '') : ?>
		<img src="<?php echo esc_url($org['logo_url']); ?>" alt="">
	<?php endif; ?>
	<h1><?php echo esc_html($org['name']); ?></h1>
</header>
<main class="nk-verify__main">
	<h2><?php esc_html_e('Courses', 'nimikh-lms'); ?></h2>
	<?php if (!$courses) : ?>
		<p><?php esc_html_e('No courses are published yet.', 'nimikh-lms'); ?></p>
	<?php else : ?>
		<div class="nk-portal__grid">
			<?php foreach ($courses as $c) : ?>
				<article class="nk-card nk-portal__card">
					<a href="<?php echo esc_url($c['url']); ?>"><?php echo esc_html($c['title']); ?></a>
					<?php if ($c['excerpt'] !== '') : ?><p><?php echo esc_html($c['excerpt']); ?></p><?php endif; ?>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
	<p><a class="nk-portal__cta" href="<?php echo esc_url(home_url('/verify/')); ?>"><?php esc_html_e('Verify a certificate', 'nimikh-lms'); ?></a></p>
</main>
</body>
</html>
