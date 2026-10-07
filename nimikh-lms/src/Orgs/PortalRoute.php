<?php
declare(strict_types=1);

namespace Nimikh\LMS\Orgs;

/** Public branded landing page at /institute/{slug}. */
final class PortalRoute {

	public const QV = 'nimikh_org';

	public function __construct(private OrgRepository $orgs) {}

	public function register(): void {
		add_action('init', [self::class, 'addRewriteRules']);
		add_filter('query_vars', static fn(array $v): array => array_merge($v, [self::QV]));
		add_action('template_redirect', [$this, 'handle']);
	}

	public static function addRewriteRules(): void {
		add_rewrite_rule('^institute/([a-z0-9\-]+)/?$', 'index.php?' . self::QV . '=$matches[1]', 'top');
	}

	public static function url(string $slug): string {
		return home_url('/institute/' . $slug . '/');
	}

	public function handle(): void {
		$slug = (string) get_query_var(self::QV);
		if ($slug === '') {
			return;
		}
		$org = $this->orgs->bySlug($slug);
		if (!$org) {
			status_header(404);
			wp_die(esc_html__('Institute not found.', 'nimikh-lms'), '', ['response' => 404]);
		}
		$courses = [];
		foreach ($this->orgs->courseIds($org['id']) as $courseId) {
			if (get_post_status($courseId) === 'publish') {
				$courses[] = [
					'title'   => html_entity_decode(get_the_title($courseId), ENT_QUOTES, 'UTF-8'),
					'url'     => get_permalink($courseId),
					'excerpt' => wp_trim_words(wp_strip_all_tags((string) get_post_field('post_excerpt', $courseId)), 28),
				];
			}
		}
		header('Cache-Control: public, max-age=300');
		include NIMIKH_LMS_DIR . 'templates/institute.php';
		exit;
	}
}
