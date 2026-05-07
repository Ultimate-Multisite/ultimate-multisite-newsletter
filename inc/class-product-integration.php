<?php
/**
 * Product Integration - Adds Newsletter options to product pages.
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
 * Product Integration class.
 *
 * Adds a Newsletter section to product (plan) edit pages.
 */
class Product_Integration {

	/**
	 * Single instance of the class.
	 *
	 * @var Product_Integration
	 */
	protected static $instance = null;

	/**
	 * Main instance.
	 *
	 * @return Product_Integration
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

		add_filter('wu_product_options_sections', [$this, 'add_product_section'], 10, 2);
		add_action('wu_save_product', [$this, 'save_product_meta']);
	}

	/**
	 * Add Newsletter section to product edit page.
	 *
	 * @param array                     $sections Existing sections.
	 * @param \WP_Ultimo\Models\Product $product Product object.
	 * @return array Modified sections.
	 */
	public function add_product_section(array $sections, $product): array {

		$sections['newsletter'] = [
			'title'  => __('Newsletter', 'ultimate-multisite-newsletter'),
			'desc'   => __('Configure Newsletter list subscriptions for this product. By default, customers will be added to the global default lists. Override to use product-specific lists or disable for this product.', 'ultimate-multisite-newsletter'),
			'icon'   => 'dashicons-wu-email',
			'state'  => [
				'newsletter_override_global' => $product->get_meta('newsletter_override_global', false),
			],
			'fields' => $this->get_product_fields($product),
		];

		return $sections;
	}

	/**
	 * Get product fields for Newsletter section.
	 *
	 * @param \WP_Ultimo\Models\Product $product Product object.
	 * @return array Field definitions.
	 */
	public function get_product_fields($product): array {

		$fields = [
			'newsletter_override_global' => [
				'type'      => 'toggle',
				'title'     => __('Override Global Lists', 'ultimate-multisite-newsletter'),
				'desc'      => __('Use product-specific lists instead of global defaults. Enable this to customize lists for this product or to disable Newsletter by unselecting all lists.', 'ultimate-multisite-newsletter'),
				'value'     => $product->get_meta('newsletter_override_global', false),
				'html_attr' => [
					'v-model' => 'newsletter_override_global',
				],
			],
		];

		// Render the list selector - only show when overriding.
		$fields['newsletter_lists'] = [
			'type'              => 'html',
			'title'             => __('Newsletter Lists', 'ultimate-multisite-newsletter'),
			'desc'              => __('Select which Newsletter lists customers should be added to when they purchase this product. Leave all unchecked to disable Newsletter for this product.', 'ultimate-multisite-newsletter'),
			'content'           => $this->render_newsletter_lists_selector($product),
			'wrapper_html_attr' => [
				'v-cloak' => '1',
				'v-show'  => 'require("newsletter_override_global", true)',
			],
		];

		return $fields;
	}

	/**
	 * Render Newsletter lists selector.
	 *
	 * @param \WP_Ultimo\Models\Product $product Product object.
	 * @return string HTML for list selector.
	 */
	private function render_newsletter_lists_selector($product): string {

		// Get selected lists from product meta.
		$selected_lists = $product->get_meta('newsletter_lists', []);

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
						'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="newsletter_lists[]" value="%d" %s> %s <span style="color:#888;font-size:11px;">(#%d)</span></label>',
						$list_id,
						$checked, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute fragment, fixed value.
						esc_html($list_name),
						$list_id
					);
				}

				echo '</div>';
				?>
				<p class="description">
					<?php esc_html_e('Customers who purchase this product will be added to these lists.', 'ultimate-multisite-newsletter'); ?>
				</p>
				<?php
			} else {
				?>
				<p class="description" style="color: #d63638;">
					<?php esc_html_e('No Newsletter lists found. Configure lists in the Newsletter admin first.', 'ultimate-multisite-newsletter'); ?>
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
	 * Save product meta when product is saved.
	 *
	 * @param object $admin_page The admin page object.
	 */
	public function save_product_meta($admin_page): void {

		// Get the product object from the admin page.
		$product = $admin_page->get_object();

		if (! $product) {
			return;
		}

		// Save newsletter_override_global toggle.
		$newsletter_override = wu_request('newsletter_override_global', false);
		$product->update_meta('newsletter_override_global', (bool) $newsletter_override);

		// Save newsletter_lists (array of list IDs from checkboxes).
		$newsletter_lists = wu_request('newsletter_lists', []);

		// Ensure it's an array and convert to integers.
		if (! is_array($newsletter_lists)) {
			$newsletter_lists = [];
		}

		// Filter out empty values and convert to integers.
		$newsletter_lists = array_filter(array_map('intval', $newsletter_lists));

		$product->update_meta('newsletter_lists', $newsletter_lists);
	}

	/**
	 * Get product lists for subscription.
	 *
	 * Returns product-specific lists only if override is enabled.
	 * Returns empty array otherwise (will fallback to global defaults).
	 *
	 * @param int $product_id Product ID.
	 * @return array Array of list IDs, or empty array to use global defaults.
	 */
	public function get_product_lists(int $product_id): array {

		$product = wu_get_product($product_id);

		if (! $product) {
			return [];
		}

		// Check if product is overriding global lists.
		if (! $product->get_meta('newsletter_override_global', false)) {
			// Not overriding - return empty to use global defaults.
			return [];
		}

		// Get product-specific lists.
		$lists = $product->get_meta('newsletter_lists', []);

		// Ensure it's an array.
		if (! is_array($lists)) {
			return [];
		}

		// Return the lists (could be empty if user wants to disable for this product).
		return array_filter(array_map('intval', $lists));
	}
}
