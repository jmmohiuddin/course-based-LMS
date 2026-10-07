<?php
declare(strict_types=1);

namespace Nimikh\LMS;

use Nimikh\LMS\Admin\AdminPages;
use Nimikh\LMS\Admin\Phase3Pages;
use Nimikh\LMS\Ai\QuestionGenerator;
use Nimikh\LMS\Badges\BadgeService;
use Nimikh\LMS\Discussion\DiscussionService;
use Nimikh\LMS\Frontend\Shortcodes;
use Nimikh\LMS\Live\LiveService;
use Nimikh\LMS\Orgs\OrgRepository;
use Nimikh\LMS\Orgs\OrgService;
use Nimikh\LMS\Orgs\PortalRoute;
use Nimikh\LMS\Pwa\Pwa;
use Nimikh\LMS\Rest\AiController;
use Nimikh\LMS\Rest\DiscussionController;
use Nimikh\LMS\Rest\LiveController;
use Nimikh\LMS\Rest\MeController;
use Nimikh\LMS\Rest\OrgController;
use Nimikh\LMS\Rest\SubscriptionController;
use Nimikh\LMS\Sms\SmsService;
use Nimikh\LMS\Subscriptions\SubscriptionService;
use Nimikh\LMS\Admin\Metaboxes;
use Nimikh\LMS\Certificates\CertificateRenderer;
use Nimikh\LMS\Certificates\CertificateRepository;
use Nimikh\LMS\Certificates\CertificateService;
use Nimikh\LMS\Certificates\VerifyRoute;
use Nimikh\LMS\Integrations\Tutor\TutorAdapter;
use Nimikh\LMS\Integrations\Tutor\TutorHooks;
use Nimikh\LMS\Profile\ProfileShortcodes;
use Nimikh\LMS\Progress\ProgressRepository;
use Nimikh\LMS\Progress\ProgressService;
use Nimikh\LMS\Questions\AttemptRepository;
use Nimikh\LMS\Questions\AttemptService;
use Nimikh\LMS\Questions\QuestionRepository;
use Nimikh\LMS\Reports\ReportService;
use Nimikh\LMS\Rest\AuthoringController;
use Nimikh\LMS\Rest\CertificateController;
use Nimikh\LMS\Rest\PlayerController;
use Nimikh\LMS\Rest\ReportController;
use Nimikh\LMS\Support\Installer;
use Nimikh\LMS\Support\Roles;
use Nimikh\LMS\Video\FrontendPlayer;
use Nimikh\LMS\Video\InteractionRepository;

/** Composition root: wires the object graph once and registers WordPress hooks. */
final class Plugin {

	private static ?self $instance = null;
	private bool $booted = false;

	public TutorAdapter $tutor;
	public CertificateService $certificates;
	public ProgressService $progress;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		if ($this->booted) {
			return;
		}
		$this->booted = true;

		// Cheap check on every request; no-op once the schema is current.
		Installer::migrate();
		load_plugin_textdomain('nimikh-lms', false, dirname(plugin_basename(NIMIKH_LMS_FILE)) . '/languages');

		$this->tutor = new TutorAdapter();

		$questions    = new QuestionRepository();
		$interactions = new InteractionRepository();
		$attemptsRepo = new AttemptRepository();
		$progressRepo = new ProgressRepository();
		$certRepo     = new CertificateRepository();

		$this->progress     = new ProgressService($progressRepo, $interactions, $attemptsRepo, $this->tutor);
		$this->certificates = new CertificateService($certRepo, new CertificateRenderer(), $progressRepo, $this->progress, $this->tutor);
		$attempts           = new AttemptService($interactions, $attemptsRepo, $this->progress, $this->tutor);
		$reports            = new ReportService($this->tutor, $progressRepo, $interactions, $certRepo, $this->certificates);

		// Phase 2-3 services
		$badges        = new BadgeService($certRepo, $this->progress, $this->tutor);
		$sms           = new SmsService();
		$discussion    = new DiscussionService($this->tutor);
		$subscriptions = new SubscriptionService($this->tutor);
		$orgRepo       = new OrgRepository();
		$orgs          = new OrgService($orgRepo, $this->tutor, $this->certificates, $certRepo);
		$live          = new LiveService($this->tutor);
		$ai            = new QuestionGenerator();

		$controllers = [
			new PlayerController($this->tutor, $interactions, $progressRepo, $this->progress, $attempts),
			new AuthoringController($this->tutor, $questions, $interactions),
			new CertificateController($this->tutor, $certRepo, $this->certificates),
			new ReportController($this->tutor, $reports),
			new MeController($this->tutor, $badges, $subscriptions),
			new DiscussionController($this->tutor, $discussion),
			new SubscriptionController($this->tutor, $subscriptions),
			new OrgController($this->tutor, $orgRepo, $orgs),
			new LiveController($this->tutor, $live),
			new AiController($this->tutor, $ai),
		];
		add_action('rest_api_init', static function () use ($controllers): void {
			foreach ($controllers as $c) {
				$c->registerRoutes();
			}
		});

		(new TutorHooks($this->tutor, $this->certificates, $progressRepo))->register();
		(new VerifyRoute($this->certificates, $certRepo))->register();
		(new FrontendPlayer($this->tutor, $interactions))->register();
		(new ProfileShortcodes($this->tutor, $certRepo, $this->certificates, $badges, $subscriptions))->register();
		(new Shortcodes($this->tutor))->register();
		$badges->register();
		$sms->register();
		$subscriptions->register();
		$live->register();
		(new PortalRoute($orgRepo))->register();
		(new Pwa())->register();

		add_action(CertificateService::RENDER_HOOK, [$this->certificates, 'render']);
		add_action('init', [$this, 'registerContentTypes']);

		if (is_admin()) {
			(new AdminPages($this->tutor, $certRepo, $this->certificates))->register();
			(new Metaboxes($this->tutor))->register();
			(new Phase3Pages($this->tutor, $subscriptions, $orgRepo, $orgs, $live))->register();
		}
		add_action('admin_init', static function (): void {
			// Roles are added on activation; this heals installs that skipped it (e.g. WP-CLI imports).
			if (!get_role('nimikh_instructor')) {
				Roles::register();
			}
		});
	}

	/** Certificate templates are a private post type whose body is HTML with {{placeholders}}. */
	public function registerContentTypes(): void {
		register_post_type('nimikh_cert_template', [
			'label'           => __('Certificate templates', 'nimikh-lms'),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => 'nimikh-lms',
			'supports'        => ['title', 'editor'],
			'capability_type' => 'post',
			'capabilities'    => ['create_posts' => Roles::CAP_MANAGE, 'edit_posts' => Roles::CAP_MANAGE],
			'map_meta_cap'    => true,
		]);
	}
}
