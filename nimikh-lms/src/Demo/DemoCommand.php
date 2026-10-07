<?php
declare(strict_types=1);

namespace Nimikh\LMS\Demo;

/** wp nimikh demo seed|remove|status */
final class DemoCommand {

	/** Load the demo world (institute, courses, instructors, learners, certificates...). */
	public function seed(): void {
		$res = (new DemoSeeder())->seed();
		if (is_wp_error($res)) {
			\WP_CLI::error($res->get_error_message());
		}
		\WP_CLI::success('Demo data loaded: ' . wp_json_encode($res['summary']));
		\WP_CLI::log('Logins (shown once):');
		foreach ($res['logins'] as $l) {
			\WP_CLI::log(sprintf('  %-10s %-16s %s / %s', $l['role'], $l['name'], $l['login'], $l['password']));
		}
	}

	/** Remove exactly what `seed` created. */
	public function remove(): void {
		$res = (new DemoSeeder())->remove();
		is_wp_error($res) ? \WP_CLI::error($res->get_error_message()) : \WP_CLI::success(sprintf('Removed %d users and %d posts.', $res['removed_users'], $res['removed_posts']));
	}

	public function status(): void {
		\WP_CLI::log(DemoSeeder::isSeeded() ? 'Demo data is loaded.' : 'No demo data.');
	}
}
