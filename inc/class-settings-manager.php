<?php
/**
 * Settings Manager - Handles addon settings registration.
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
 * Settings Manager class.
 *
 * Registers and manages addon settings.
 */
class Settings_Manager {

	/**
	 * Single instance of the class.
	 *
	 * @var Settings_Manager
	 */
	protected static $instance = null;

	/**
	 * Main instance.
	 *
	 * @return Settings_Manager
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

		add_action('init', [$this, 'register_settings']);
		add_filter('wu_pre_save_settings', [$this, 'save_newsletter_lists'], 10, 3);
		add_filter('wu_get_setting', [$this, 'filter_newsletter_lists_value'], 10, 4);
	}

	/**
	 * Register settings section and fields.
	 */
	public function register_settings(): void {

		// Register settings section.
		wu_register_settings_section(
			'newsletter',
			[
				'title' => __('Newsletter Integration', 'ultimate-multisite-newsletter'),
				'desc'  => __('Configure The Newsletter Plugin integration.', 'ultimate-multisite-newsletter'),
				'icon'  => 'dashicons-wu-email',
				'order' => 999,
				'addon' => true,
			]
		);

		// General Settings Header.
		wu_register_settings_field(
			'newsletter',
			'newsletter_header_general',
			[
				'type'  => 'header',
				'title' => __('General Settings', 'ultimate-multisite-newsletter'),
				'desc'  => __('Configure The Newsletter Plugin integration. The addon is active once The Newsletter Plugin is installed on the main site.', 'ultimate-multisite-newsletter'),
			]
		);

		// Subscription Timing.
		wu_register_settings_field(
			'newsletter',
			'newsletter_subscription_timing',
			[
				'type'    => 'select',
				'title'   => __('Subscription Timing', 'ultimate-multisite-newsletter'),
				'desc'    => __('When to add customers to Newsletter lists.', 'ultimate-multisite-newsletter'),
				'tooltip' => __('Choose "Order Creation" to subscribe immediately when the customer signs up. Choose "Payment Complete" to wait until payment is confirmed (recommended for paid plans).', 'ultimate-multisite-newsletter'),
				'options' => [
					'order_creation'   => __('On Order Creation (Immediate)', 'ultimate-multisite-newsletter'),
					'payment_complete' => __('On Payment Complete', 'ultimate-multisite-newsletter'),
				],
				'default' => 'order_creation',
			]
		);

		// Opt-in Mode.
		wu_register_settings_field(
			'newsletter',
			'newsletter_optin_mode',
			[
				'type'    => 'select',
				'title'   => __('Opt-in Mode', 'ultimate-multisite-newsletter'),
				'desc'    => __('How customers consent to email marketing.', 'ultimate-multisite-newsletter'),
				'tooltip' => __('Choose "Automatic" to subscribe all customers, or "Checkbox" to require explicit consent via a checkout field. Checkbox mode is recommended for GDPR compliance.', 'ultimate-multisite-newsletter'),
				'options' => [
					'automatic' => __('Automatic (No Checkbox)', 'ultimate-multisite-newsletter'),
					'checkbox'  => __('Requires Checkbox Confirmation', 'ultimate-multisite-newsletter'),
				],
				'default' => 'automatic',
			]
		);

		// Double Opt-in.
		wu_register_settings_field(
			'newsletter',
			'newsletter_double_optin',
			[
				'type'    => 'toggle',
				'title'   => __('Double Opt-in', 'ultimate-multisite-newsletter'),
				'desc'    => __('Require email confirmation before subscribing. Subscribers will receive a confirmation email from The Newsletter Plugin.', 'ultimate-multisite-newsletter'),
				'default' => false,
			]
		);

		// Default Lists Header.
		wu_register_settings_field(
			'newsletter',
			'newsletter_header_lists',
			[
				'type'  => 'header',
				'title' => __('List Settings', 'ultimate-multisite-newsletter'),
				'desc'  => __('Configure default Newsletter lists for new subscribers.', 'ultimate-multisite-newsletter'),
			]
		);

		// Default Lists (Multi-select rendered as checkboxes against The Newsletter Plugin's lists).
		wu_register_settings_field(
			'newsletter',
			'newsletter_default_lists',
			[
				'type'    => 'html',
				'title'   => __('Default Lists', 'ultimate-multisite-newsletter'),
				'desc'    => __('Global default lists to add customers to when no product-specific lists are set.', 'ultimate-multisite-newsletter'),
				'tooltip' => __('Select multiple lists by checking the boxes. Leave all unchecked to disable default subscriptions.', 'ultimate-multisite-newsletter'),
				'content' => $this->render_newsletter_lists_selector(),
			]
		);

		// Advanced Settings Header.
		wu_register_settings_field(
			'newsletter',
			'newsletter_header_advanced',
			[
				'type'  => 'header',
				'title' => __('Advanced Settings', 'ultimate-multisite-newsletter'),
				'desc'  => __('Advanced configuration options.', 'ultimate-multisite-newsletter'),
			]
		);

		// Update Existing Subscribers.
		wu_register_settings_field(
			'newsletter',
			'newsletter_update_existing',
			[
				'type'    => 'toggle',
				'title'   => __('Update Existing Subscribers', 'ultimate-multisite-newsletter'),
				'desc'    => __('Update subscriber data if the email already exists. New lists will be added to existing subscriptions.', 'ultimate-multisite-newsletter'),
				'default' => true,
			]
		);

		// Map Fields.
		wu_register_settings_field(
			'newsletter',
			'newsletter_map_fields',
			[
				'type'    => 'toggle',
				'title'   => __('Map Customer Fields', 'ultimate-multisite-newsletter'),
				'desc'    => __('Automatically map customer fields (first name, last name, billing country/region/city) to Newsletter subscriber fields.', 'ultimate-multisite-newsletter'),
				'default' => true,
			]
		);
	}

	/**
	 * Render Newsletter lists selector.
	 *
	 * The Newsletter Plugin does not expose a print_it() helper like Mailster
	 * does, so we render a simple checkbox grid against the lists returned by
	 * NewsletterSubscription::instance()->get_lists().
	 *
	 * @return string HTML for list selector.
	 */
	private function render_newsletter_lists_selector(): string {

		// Get currently selected lists from settings.
		$selected_lists = wu_get_setting('newsletter_default_lists', []);

		// Ensure it's an array.
		if (! is_array($selected_lists)) {
			$selected_lists = [];
		}

		// Switch to main site where The Newsletter Plugin is active.
		$main_site_id = get_main_site_id();
		$switched     = false;

		if (get_current_blog_id() !== $main_site_id) {
			switch_to_blog($main_site_id);
			$switched = true;
		}

		ob_start();

		if (class_exists('NewsletterSubscription')) {
			$lists = \NewsletterSubscription::instance()->get_lists();

			if (! empty($lists)) {
				echo '<div class="ultimate-multisite-newsletter-lists">';

				foreach ($lists as $list) {
					$list_id   = (int) $list->id;
					$list_name = isset($list->name) ? $list->name : sprintf('List %d', $list_id);
					$checked   = in_array($list_id, array_map('intval', $selected_lists), true) ? 'checked="checked"' : '';

					printf(
						'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="newsletter_default_lists[]" value="%d" %s> %s <span style="color:#888;font-size:11px;">(#%d)</span></label>',
						$list_id,
						$checked, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute fragment, fixed value.
						esc_html($list_name),
						$list_id
					);
				}

				echo '</div>';
				?>
				<p class="description">
					<?php esc_html_e('Customers will be added to these lists by default (unless a product overrides with its own lists).', 'ultimate-multisite-newsletter'); ?>
				</p>
				<?php
			} else {
				?>
				<p class="description" style="color: #d63638;">
					<?php
					printf(
						/* translators: %s: link to Newsletter lists admin */
						esc_html__('No lists found. Configure lists in %s.', 'ultimate-multisite-newsletter'),
						'<a href="' . esc_url(get_admin_url(get_main_site_id(), 'admin.php?page=newsletter_main_lists')) . '">' . esc_html__('Newsletter → Settings → Lists', 'ultimate-multisite-newsletter') . '</a>'
					);
					?>
				</p>
				<?php
			}
		} else {
			?>
			<p class="description" style="color: #d63638;">
				<?php esc_html_e('The Newsletter Plugin is not active on the main site. Please activate it to select lists.', 'ultimate-multisite-newsletter'); ?>
			</p>
			<?php
		}

		$output = ob_get_clean();

		// Restore blog if we switched.
		if ($switched) {
			restore_current_blog();
		}

		return $output;
	}

	/**
	 * Save newsletter_default_lists field from POST data.
	 *
	 * This filter intercepts the settings save process to manually handle
	 * the newsletter_default_lists field since it uses type => 'html'.
	 *
	 * @param array $settings         Settings being saved.
	 * @param array $settings_to_save Raw POST data.
	 * @param array $saved_settings   Currently saved settings.
	 * @return array Modified settings array.
	 */
	public function save_newsletter_lists(array $settings, array $settings_to_save, array $saved_settings): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		// Only process if we're on the newsletter settings tab.
		if (wu_request('tab') !== 'newsletter') {
			return $settings;
		}

		$newsletter_lists = wu_request('newsletter_default_lists', []);

		// Ensure it's an array and sanitize.
		if (! is_array($newsletter_lists)) {
			$newsletter_lists = [];
		}

		// Filter out empty values and convert to integers.
		$newsletter_lists = array_filter(array_map('intval', $newsletter_lists));

		$settings['newsletter_default_lists'] = $newsletter_lists;

		return $settings;
	}

	/**
	 * Filter the newsletter_default_lists value when retrieved.
	 *
	 * Ensures the value is always an array, even if it wasn't saved properly.
	 *
	 * @param mixed  $setting_value  Current setting value.
	 * @param string $setting        Setting name.
	 * @param mixed  $default_value  Default value.
	 * @param array  $settings       All settings.
	 * @return mixed Filtered setting value.
	 */
	public function filter_newsletter_lists_value($setting_value, string $setting, $default_value, array $settings) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		// Only filter the newsletter_default_lists setting.
		if ('newsletter_default_lists' !== $setting) {
			return $setting_value;
		}

		// Ensure it's always an array.
		if (! is_array($setting_value)) {
			return [];
		}

		return $setting_value;
	}
}
