<?php
/**
 * Privacy-minimized customer snapshot provider for AI Newsletter check-ins.
 *
 * @package Ultimate_Multisite_Newsletter
 * @since 0.2.0
 */

namespace Ultimate_Multisite\Newsletter;

defined('ABSPATH') || exit;

/**
 * Builds an allowlisted customer context without exposing direct identifiers.
 */
class Customer_Snapshot_Provider {

	/**
	 * Fixed labels for add-ons that can be detected as active on customer sites.
	 *
	 * Network activation is deliberately ignored because it proves only that an
	 * add-on is available to the platform, not that a customer uses it.
	 *
	 * @var array<string, string>
	 */
	private const ADDON_PLUGIN_LABELS = [
		'ultimate-multisite-admin-page-creator/ultimate-multisite-admin-page-creator.php' => 'Admin Page Creator',
		'ultimate-multisite-affiliatewp/ultimate-multisite-affiliatewp.php'             => 'AffiliateWP Integration',
		'ultimate-multisite-captcha/ultimate-multisite-captcha.php'                     => 'Captcha',
		'ultimate-multisite-content-sync/ultimate-multisite-content-sync.php'           => 'Content Sync',
		'ultimate-multisite-domain-seller/ultimate-multisite-domain-seller.php'         => 'Domain Seller',
		'ultimate-multisite-emails/ultimate-multisite-emails.php'                       => 'Emails',
		'ultimate-multisite-fluent-forms/ultimate-multisite-fluent-forms.php'           => 'Fluent Forms Integration',
		'ultimate-multisite-gocardless/ultimate-multisite-gocardless.php'               => 'GoCardless Gateway',
		'ultimate-multisite-language-selector/ultimate-multisite-language-selector.php' => 'Language Selector',
		'ultimate-multisite-loco-translate/ultimate-multisite-loco-translate.php'       => 'Loco Translate Integration',
		'ultimate-multisite-mailchimp/ultimate-multisite-mailchimp.php'                 => 'Mailchimp Integration',
		'ultimate-multisite-mailster/ultimate-multisite-mailster.php'                   => 'Mailster Integration',
		'ultimate-multisite-metered-plans/ultimate-multisite-metered-plans.php'         => 'Metered Plans',
		'ultimate-multisite-multi-currency/ultimate-multisite-multi-currency.php'       => 'Multi-Currency',
		'ultimate-multisite-multi-tenancy/ultimate-multisite-multi-tenancy.php'         => 'Multi-Tenancy',
		'ultimate-multisite-multinetwork/ultimate-multisite-multinetwork.php'           => 'Multinetwork',
		'ultimate-multisite-newsletter/ultimate-multisite-newsletter.php'               => 'Newsletter Integration',
		'ultimate-multisite-plugin-and-theme-manager/ultimate-multisite-plugin-and-theme-manager.php' => 'Plugin & Theme Manager',
		'ultimate-multisite-support-agents/ultimate-multisite-support-agents.php'       => 'Support Agents',
		'ultimate-multisite-support-tickets/ultimate-multisite-support-tickets.php'     => 'Support Tickets',
		'ultimate-multisite-vat/ultimate-multisite-vat.php'                             => 'European VAT',
		'ultimate-multisite-woocommerce/ultimate-multisite-woocommerce.php'             => 'WooCommerce Integration',
	];

	/**
	 * Single instance of the class.
	 *
	 * @var Customer_Snapshot_Provider|null
	 */
	protected static $instance = null;

	/**
	 * Main instance.
	 *
	 * @return Customer_Snapshot_Provider
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
	private function __construct() {
	}

	/**
	 * Add an Ultimate Multisite customer snapshot for a Newsletter subscriber.
	 *
	 * The returned context deliberately excludes email addresses, site URLs,
	 * site content, IP addresses, account activity, membership and payment data,
	 * and free-form customer data.
	 *
	 * @param array  $snapshot   Existing provider snapshot.
	 * @param object $subscriber Newsletter subscriber row.
	 * @return array Filtered snapshot.
	 */
	public function build_snapshot(array $snapshot, $subscriber): array {

		$customer = $this->get_customer($subscriber);

		if (! $customer) {
			$snapshot['ultimate_multisite'] = [
				'customer_found'  => false,
				'explicit_opt_in' => false,
			];

			return $snapshot;
		}

		$sites         = (array) $customer->get_sites();
		$user          = $customer->get_user();
		$active_addons = [];

		foreach ($sites as $site) {
			if (! is_object($site) || ! method_exists($site, 'get_blog_id')) {
				continue;
			}

			foreach ((array) get_blog_option(absint($site->get_blog_id()), 'active_plugins', []) as $plugin_file) {
				$plugin_file = plugin_basename((string) $plugin_file);

				if (isset(self::ADDON_PLUGIN_LABELS[ $plugin_file ])) {
					$active_addons[] = self::ADDON_PLUGIN_LABELS[ $plugin_file ];
				}
			}
		}

		$consent_value    = $customer->get_meta('um_newsletter_check_in_consent', false);
		$consent_evidence = $customer->get_meta('um_newsletter_check_in_consent_evidence', []);
		$consent_source   = is_array($consent_evidence) ? sanitize_key((string) ($consent_evidence['source'] ?? '')) : '';
		$consent_date     = is_array($consent_evidence) ? (string) ($consent_evidence['recorded_at'] ?? '') : '';
		$consent_time     = $consent_date ? strtotime($consent_date) : false;
		$explicit_opt_in  = in_array($consent_value, [true, 1, '1'], true)
			&& in_array($consent_source, ['direct_customer_request', 'verified_unchecked_opt_in'], true)
			&& $consent_time
			&& $consent_time <= time();

		$snapshot['ultimate_multisite'] = [
			'customer_found'  => true,
			'explicit_opt_in' => $explicit_opt_in,
			'consent_source'  => $explicit_opt_in ? 'verified_customer_meta' : 'none_recorded',
			'first_name'      => $user ? sanitize_text_field((string) $user->first_name) : '',
			'active_addons'   => array_values(array_unique($active_addons)),
		];

		return $snapshot;
	}

	/**
	 * Resolve an Ultimate Multisite customer from a Newsletter subscriber.
	 *
	 * @param object $subscriber Newsletter subscriber row.
	 * @return \WP_Ultimo\Models\Customer|false
	 */
	private function get_customer($subscriber) {

		if (! function_exists('wu_get_customer_by_user_id') || ! is_object($subscriber)) {
			return false;
		}

		$user_id = absint($subscriber->wp_user_id ?? 0);

		if (! $user_id && ! empty($subscriber->email)) {
			$user = get_user_by('email', sanitize_email($subscriber->email));

			$user_id = $user ? (int) $user->ID : 0;
		}

		return $user_id ? wu_get_customer_by_user_id($user_id) : false;
	}

}
