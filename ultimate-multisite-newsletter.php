<?php
/**
 * Plugin Name: Ultimate Multisite: Newsletter Integration
 * Description: Integrate with The Newsletter Plugin (thenewsletterplugin.com) during checkout to subscribe customers to mailing lists. Designed to be the umbrella addon for self-hosted newsletter providers.
 * Plugin URI: https://multisiteultimate.com
 * Text Domain: ultimate-multisite-newsletter
 * Version: 0.1.0
 * Author: David Stone - Multisite Ultimate
 * Author URI: https://multisiteultimate.com
 * Copyright: David Stone, Multisite Ultimate
 * Network: true
 * Requires Plugins: ultimate-multisite, newsletter
 * Requires at least: 5.3
 * Tested up to: 6.9
 * Requires PHP: 7.4
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

// Define addon constants.
const ULTIMATE_MULTISITE_NEWSLETTER_VERSION     = '0.1.0';
const ULTIMATE_MULTISITE_NEWSLETTER_PLUGIN_FILE = __FILE__;
define('ULTIMATE_MULTISITE_NEWSLETTER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ULTIMATE_MULTISITE_NEWSLETTER_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main addon class.
 */
class Ultimate_Multisite_Newsletter {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	public $version = '0.1.0';

	/**
	 * Single instance of the class.
	 *
	 * @var Ultimate_Multisite_Newsletter
	 */
	protected static $instance = null;

	/**
	 * Main instance.
	 *
	 * @return Ultimate_Multisite_Newsletter
	 */
	public static function get_instance() {

		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {

		add_action('plugins_loaded', [$this, 'init'], 11);
	}

	/**
	 * Initialize the addon.
	 */
	public function init() {

		// Check if Ultimate Multisite is active.
		if (! class_exists('WP_Ultimo') && ! function_exists('WP_Ultimo')) {
			add_action('network_admin_notices', [$this, 'ultimate_multisite_missing_notice']);

			return;
		}

		// Load plugin files.
		$this->load_dependencies();

		// Initialize hooks.
		$this->init_hooks();

		// Initialize updater.
		$this->init_updater();

		// Initialize main functionality.
		\Ultimate_Multisite\Newsletter\Newsletter_Main::get_instance();
	}

	/**
	 * Load required dependencies.
	 */
	private function load_dependencies() {

		// Skip plugin autoloader if Bedrock's root autoloader already loaded dependencies.
		if (! class_exists('Ultimate_Multisite\Newsletter\Newsletter_Main', false) && file_exists(ULTIMATE_MULTISITE_NEWSLETTER_PLUGIN_DIR . 'vendor/autoload.php')) {
			require_once ULTIMATE_MULTISITE_NEWSLETTER_PLUGIN_DIR . 'vendor/autoload.php';
		}

		// Fallback manual requires for environments without Composer autoload (e.g. fresh local dev clones).
		$base = ULTIMATE_MULTISITE_NEWSLETTER_PLUGIN_DIR . 'inc/';

		if (! class_exists('Ultimate_Multisite\Newsletter\Newsletter_Main', false)) {
			require_once $base . 'class-newsletter-main.php';
			require_once $base . 'class-settings-manager.php';
			require_once $base . 'class-subscriber-manager.php';
			require_once $base . 'class-product-integration.php';
			require_once $base . 'checkout/class-newsletter-optin-field.php';
		}
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {

		add_action('plugins_loaded', [$this, 'register_translation_updates']);
	}

	/**
	 * Initialize the updater.
	 */
	private function init_updater() {

		if (class_exists('\WP_Ultimo\Multisite_Ultimate_Updater')) {
			$updater = new \WP_Ultimo\Multisite_Ultimate_Updater('ultimate-multisite-newsletter', __FILE__);
			$updater->init();
		}
	}

	/**
	 * Register with Traduttore for automatic translation updates.
	 */
	public function register_translation_updates() {

		if (class_exists('\Required\Traduttore_Registry')) {
			\Required\Traduttore_Registry\add_project(
				'plugin',
				'ultimate-multisite-newsletter',
				'https://translate.ultimatemultisite.com/api/translations/ultimatemultisite/ultimate-multisite-newsletter/'
			);
		}
	}

	/**
	 * Display notice when Ultimate Multisite is not active.
	 */
	public function ultimate_multisite_missing_notice() {

		?>
		<div class="notice notice-error is-dismissible">
			<p>
			<?php
			printf(
				/* translators: %1$s: Plugin name, %2$s: Required plugin */
				esc_html__('%1$s requires %2$s to be installed and active.', 'ultimate-multisite-newsletter'),
				'<strong>' . esc_html__('Ultimate Multisite: Newsletter Integration', 'ultimate-multisite-newsletter') . '</strong>',
				'<strong>' . esc_html__('Ultimate Multisite', 'ultimate-multisite-newsletter') . '</strong>'
			);
			?>
			</p>
		</div>
		<?php
	}
}

// Initialize the addon.
Ultimate_Multisite_Newsletter::get_instance();
