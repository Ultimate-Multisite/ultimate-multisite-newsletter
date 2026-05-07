<?php
/**
 * Main functionality class for Newsletter Integration addon.
 *
 * @package Ultimate_Multisite_Newsletter
 * @since 0.1.0
 */

namespace Ultimate_Multisite\Newsletter;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
	exit;
}

/**
 * Main functionality class for Newsletter Integration.
 */
class Newsletter_Main {

	/**
	 * Single instance of the class.
	 *
	 * @var Newsletter_Main
	 */
	protected static $instance = null;

	/**
	 * Main instance.
	 *
	 * @return Newsletter_Main
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

		// Check dependencies before initialization
		if (! $this->check_dependencies()) {
			return;
		}

		$this->init_components();
		$this->init_hooks();
	}

	/**
	 * Check if required dependencies are active.
	 *
	 * The Newsletter Plugin (https://www.thenewsletterplugin.com) is detected by
	 * the presence of the `NewsletterSubscription` class. We check on the main
	 * site of the network because, like Mailster, this addon expects the
	 * newsletter provider to be centralised on the network's main site.
	 *
	 * @return bool True if dependencies are met.
	 */
	private function check_dependencies(): bool {

		$dependencies_met = true;

		// Check Newsletter (only needs to be active on main site, not network-wide).
		$newsletter_active = false;

		// Switch to main site to check if Newsletter is active.
		$main_site_id = get_main_site_id();
		switch_to_blog($main_site_id);

		if (class_exists('NewsletterSubscription')) {
			$newsletter_active = true;
		}

		restore_current_blog();

		if (! $newsletter_active) {
			add_action(
				'network_admin_notices',
				function () {

					echo '<div class="notice notice-error"><p>';
					printf(
						/* translators: %s: Main site URL */
						esc_html__('Ultimate Multisite: Newsletter Integration requires The Newsletter Plugin to be installed and active on the main site (%s).', 'ultimate-multisite-newsletter'),
						'<a href="' . esc_url(get_admin_url(get_main_site_id(), 'plugins.php')) . '">' . esc_html__('Manage Plugins', 'ultimate-multisite-newsletter') . '</a>'
					);
					echo '</p></div>';
				}
			);

			$dependencies_met = false;
		}

		return $dependencies_met;
	}

	/**
	 * Initialize addon components.
	 */
	private function init_components(): void {

		// Initialize Settings Manager.
		Settings_Manager::get_instance();

		// Initialize Product Integration.
		Product_Integration::get_instance();

		// Subscriber Manager is initialized on-demand.
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks(): void {

		// Register checkout field.
		add_filter('wu_checkout_field_types', [$this, 'register_checkout_field']);

		// Membership creation hook (for immediate timing).
		// Note: wu_membership_post_save fires after the membership is saved, so the product is available.
		// This replaces wu_customer_post_save which fires too early (before the membership linking
		// the customer to a product exists), causing product-specific lists to never be found.
		add_action('wu_membership_post_save', [$this, 'handle_membership_creation'], 10, 3);

		// Payment status change hook (for payment complete timing).
		// Note: wu_transition_payment_status fires when payment status changes.
		add_action('wu_transition_payment_status', [$this, 'handle_payment_status_change'], 10, 3);
	}

	/**
	 * Register Newsletter opt-in checkout field.
	 *
	 * @param array $fields Existing checkout field types.
	 * @return array Modified field types.
	 */
	public function register_checkout_field(array $fields): array {

		$fields['um_newsletter_optin'] = \Ultimate_Multisite\Newsletter\Checkout\Newsletter_Optin_Field::class;

		return $fields;
	}

	/**
	 * Handle membership creation event.
	 *
	 * Fires after a membership is saved. At this point both the customer
	 * and the product (plan) are available on the membership object,
	 * so product-specific Newsletter lists can be resolved.
	 *
	 * @param array                        $data       Membership data array.
	 * @param \WP_Ultimo\Models\Membership $membership Membership object.
	 * @param bool                         $is_new     True if membership is new, false if being updated.
	 */
	public function handle_membership_creation(array $data, $membership, bool $is_new): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		// Only process for new memberships (not updates).
		if (! $is_new) {
			return;
		}

		// Only process if timing is set to order_creation.
		if (wu_get_setting('newsletter_subscription_timing', 'order_creation') !== 'order_creation') {
			return;
		}

		// Get customer from membership.
		$customer = $membership->get_customer();

		if (! $customer) {
			wu_log_add('newsletter', sprintf('Membership %d has no associated customer', $membership->get_id()));

			return;
		}

		// Pass the membership directly so we can get the product from it
		// without relying on the indirect customer -> memberships -> plan lookup.
		$this->subscribe_customer($customer, null, $membership);
	}

	/**
	 * Handle payment status change event.
	 *
	 * @param string $old_status Previous payment status.
	 * @param string $new_status New payment status.
	 * @param int    $payment_id Payment ID.
	 */
	public function handle_payment_status_change(string $old_status, string $new_status, int $payment_id): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		// Only process if timing is set to payment_complete.
		if (wu_get_setting('newsletter_subscription_timing', 'order_creation') !== 'payment_complete') {
			return;
		}

		// Only process when payment becomes completed.
		if ('completed' !== $new_status) {
			return;
		}

		// Get payment object.
		$payment = wu_get_payment($payment_id);

		if (! $payment) {
			wu_log_add('newsletter', sprintf('Payment %d not found', $payment_id));

			return;
		}

		// Get customer from payment.
		$customer = $payment->get_customer();

		if (! $customer) {
			wu_log_add('newsletter', sprintf('Payment %d has no associated customer', $payment_id));

			return;
		}

		// Get the membership from the payment to pass directly.
		$membership = $payment->get_membership();

		$this->subscribe_customer($customer, $payment, $membership);
	}

	/**
	 * Subscribe customer to Newsletter lists.
	 *
	 * @param \WP_Ultimo\Models\Customer        $customer   Customer object.
	 * @param \WP_Ultimo\Models\Payment|null    $payment    Optional payment object.
	 * @param \WP_Ultimo\Models\Membership|null $membership Optional membership object.
	 */
	private function subscribe_customer($customer, $payment = null, $membership = null): void {

		// Check opt-in requirements.
		if (! $this->customer_opted_in($customer)) {
			wu_log_add(
				'newsletter',
				sprintf(
					'Customer %d (%s) has not opted in to Newsletter',
					$customer->get_id(),
					$customer->get_email_address()
				)
			);

			return;
		}

		// Get lists to subscribe to.
		$lists = $this->get_lists_for_customer($customer, $payment, $membership);

		if (empty($lists)) {
			wu_log_add(
				'newsletter',
				sprintf(
					'No lists configured for customer %d (%s)',
					$customer->get_id(),
					$customer->get_email_address()
				)
			);

			return;
		}

		// Get subscriber manager.
		$subscriber_manager = Subscriber_Manager::get_instance();

		// Map customer fields.
		$subscriber_data = $subscriber_manager->map_customer_fields($customer);

		// Get double opt-in setting.
		$double_optin = (bool) wu_get_setting('newsletter_double_optin', false);

		// Add subscriber.
		$result = $subscriber_manager->add_subscriber($subscriber_data, $lists, $double_optin);

		if (is_wp_error($result)) {
			wu_log_add(
				'newsletter',
				sprintf(
					'Failed to subscribe customer %d (%s): %s',
					$customer->get_id(),
					$customer->get_email_address(),
					$result->get_error_message()
				)
			);
		} else {
			wu_log_add(
				'newsletter',
				sprintf(
					'Successfully subscribed customer %d (%s) to lists: %s',
					$customer->get_id(),
					$customer->get_email_address(),
					implode(', ', $lists)
				)
			);
		}
	}

	/**
	 * Check if customer has opted in to Newsletter.
	 *
	 * @param \WP_Ultimo\Models\Customer $customer Customer object.
	 * @return bool True if customer opted in or opt-in not required.
	 */
	private function customer_opted_in($customer): bool {

		$optin_mode = wu_get_setting('newsletter_optin_mode', 'automatic');

		// If automatic mode, always return true.
		if ('automatic' === $optin_mode) {
			return true;
		}

		// Check customer meta for opt-in. The checkout field id is `um_newsletter_optin`,
		// which Ultimate Multisite saves as customer meta under the same key. We use
		// the `um_` prefix to disambiguate from core's WP_Ultimo\Newsletter class which
		// already reserves the field slug `newsletter_optin` for unrelated email opt-ins.
		// (The upstream Mailster addon reads `mailster_opted_in` here while saving
		// under `mailster_optin` - a latent bug we deliberately fix in this port.)
		return (bool) $customer->get_meta('um_newsletter_optin', false);
	}

	/**
	 * Get lists for customer based on product and global settings.
	 *
	 * @param \WP_Ultimo\Models\Customer        $customer   Customer object.
	 * @param \WP_Ultimo\Models\Payment|null    $payment    Optional payment object.
	 * @param \WP_Ultimo\Models\Membership|null $membership Optional membership object.
	 * @return array Array of list IDs.
	 */
	private function get_lists_for_customer($customer, $payment = null, $membership = null): array {

		$lists   = [];
		$product = null;

		// 1. Try to get product directly from the membership (most reliable).
		if ($membership) {
			$plan_id = $membership->get_plan_id();

			if ($plan_id) {
				$product = wu_get_product($plan_id);

				wu_log_add(
					'newsletter',
					sprintf(
						'Got product %d (%s) directly from membership %d for customer %d',
						$plan_id,
						$product ? $product->get_name() : 'NOT FOUND',
						$membership->get_id(),
						$customer->get_id()
					)
				);
			}
		}

		// 2. Fallback: try to get product from payment or customer memberships.
		if (! $product) {
			$product = $this->get_product_from_customer($customer, $payment);

			wu_log_add(
				'newsletter',
				sprintf(
					'Fallback product lookup for customer %d: %s',
					$customer->get_id(),
					$product ? sprintf('%d (%s)', $product->get_id(), $product->get_name()) : 'NOT FOUND'
				)
			);
		}

		if ($product) {
			$product_integration = Product_Integration::get_instance();
			$product_lists       = $product_integration->get_product_lists($product->get_id());

			wu_log_add(
				'newsletter',
				sprintf(
					'Product %d (%s) override=%s, lists=%s',
					$product->get_id(),
					$product->get_name(),
					$product->get_meta('newsletter_override_global', false) ? 'yes' : 'no',
					wp_json_encode($product->get_meta('newsletter_lists', []))
				)
			);

			// Check if product has Newsletter enabled and lists configured.
			if (! empty($product_lists)) {
				$lists = $product_lists;

				wu_log_add(
					'newsletter',
					sprintf(
						'Using product-specific lists for customer %d: %s',
						$customer->get_id(),
						implode(', ', $lists)
					)
				);
			}
		}

		// Fall back to global default lists if no product lists.
		if (empty($lists)) {
			$default_lists = wu_get_setting('newsletter_default_lists', []);

			if (! empty($default_lists) && is_array($default_lists)) {
				$lists = $default_lists;

				wu_log_add(
					'newsletter',
					sprintf(
						'Using default lists for customer %d: %s',
						$customer->get_id(),
						implode(', ', $lists)
					)
				);
			}
		}

		return array_filter(array_map('intval', $lists));
	}

	/**
	 * Get product from customer's membership or payment.
	 *
	 * @param \WP_Ultimo\Models\Customer     $customer Customer object.
	 * @param \WP_Ultimo\Models\Payment|null $payment Optional payment object.
	 * @return \WP_Ultimo\Models\Product|null Product object or null.
	 */
	private function get_product_from_customer($customer, $payment = null) {

		// Try to get product from payment first.
		if ($payment) {
			$membership = $payment->get_membership();

			if ($membership) {
				return $membership->get_plan();
			}
		}

		// Try to get from customer's memberships.
		$memberships = $customer->get_memberships();

		if (! empty($memberships)) {

			// Get the first active membership.
			foreach ($memberships as $membership) {
				if ($membership->is_active()) {
					return $membership->get_plan();
				}
			}

			// If no active membership, use the first one.
			return reset($memberships)->get_plan();
		}

		return null;
	}
}
