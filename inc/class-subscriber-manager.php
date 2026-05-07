<?php
/**
 * Subscriber Manager - Handles The Newsletter Plugin API interactions.
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
 * Subscriber Manager class.
 *
 * Wrapper for The Newsletter Plugin's API with error handling.
 *
 * The Newsletter Plugin's primary subscription entry point is
 * NewsletterSubscription::instance()->subscribe2(TNP_Subscription) which
 * returns a TNP_User (success) or WP_Error.
 *
 * Newsletter list memberships are stored as `list_<id>` columns on the
 * subscriber row. Set columns to 1 to subscribe, 0 to unsubscribe.
 */
class Subscriber_Manager {

	/**
	 * Single instance of the class.
	 *
	 * @var Subscriber_Manager
	 */
	protected static $instance = null;

	/**
	 * Main instance.
	 *
	 * @return Subscriber_Manager
	 */
	public static function get_instance() {

		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Add a subscriber to The Newsletter Plugin.
	 *
	 * @param array $data Subscriber data (email, name, surname, country, ...).
	 * @param array $lists Array of list IDs to assign subscriber to.
	 * @param bool  $double_optin Whether to require email confirmation.
	 * @return int|\WP_Error Subscriber ID on success, WP_Error on failure.
	 */
	public function add_subscriber(array $data, array $lists, bool $double_optin) {

		// Switch to main site where The Newsletter Plugin is active.
		$main_site_id = get_main_site_id();
		$switched     = false;

		if (get_current_blog_id() !== $main_site_id) {
			switch_to_blog($main_site_id);
			$switched = true;
		}

		if (! class_exists('NewsletterSubscription')) {
			if ($switched) {
				restore_current_blog();
			}

			wu_log_add('newsletter', 'The Newsletter Plugin is not active on main site');

			return new \WP_Error('newsletter_inactive', __('The Newsletter Plugin is not active on the main site.', 'ultimate-multisite-newsletter'));
		}

		// Validate email.
		if (empty($data['email'])) {
			if ($switched) {
				restore_current_blog();
			}

			wu_log_add('newsletter', 'Cannot add subscriber: email is empty');

			return new \WP_Error('newsletter_empty_email', __('Email address is required.', 'ultimate-multisite-newsletter'));
		}

		try {
			$module = \NewsletterSubscription::instance();

			// Check if subscriber exists.
			$existing = $module->get_user_by_email($data['email']);

			if ($existing && ! wu_get_setting('newsletter_update_existing', true)) {
				wu_log_add('newsletter', sprintf('Subscriber %s already exists, skipping update', $data['email']));

				// Still ensure list memberships are set.
				$this->assign_to_lists((int) $existing->id, $lists, $double_optin);

				return (int) $existing->id;
			}

			// Build a TNP_Subscription object using the plugin's defaults so
			// language/welcome-email handling honours the site configuration.
			$subscription = $module->get_default_subscription();

			$subscription->data->email = $data['email'];

			if (! empty($data['name'])) {
				$subscription->data->name = $data['name'];
			}

			if (! empty($data['surname'])) {
				$subscription->data->surname = $data['surname'];
			}

			if (! empty($data['country'])) {
				$subscription->data->country = $data['country'];
			}

			if (! empty($data['region'])) {
				$subscription->data->region = $data['region'];
			}

			if (! empty($data['city'])) {
				$subscription->data->city = $data['city'];
			}

			if (! empty($data['language'])) {
				$subscription->data->language = $data['language'];
			}

			if (! empty($data['ip'])) {
				$subscription->data->ip = $data['ip'];
			}

			if (! empty($data['referrer'])) {
				$subscription->data->referrer = $data['referrer'];
			}

			// Pre-populate the requested lists on the subscription object.
			$valid_lists = $this->validate_lists($lists);

			foreach ($valid_lists as $list_id) {
				$subscription->data->lists['' . (int) $list_id] = 1;
			}

			// Honour the addon's double opt-in setting.
			$subscription->set_optin($double_optin ? 'double' : 'single');

			// For an existing user we still want subscribe2() to merge the new lists.
			if ($existing) {
				$subscription->if_exists = $double_optin
					? \TNP_Subscription::EXISTING_DOUBLE_OPTIN
					: \TNP_Subscription::EXISTING_SINGLE_OPTIN;
			}

			$result = $module->subscribe2($subscription);

			if (is_wp_error($result)) {
				wu_log_add(
					'newsletter',
					sprintf(
						'Failed to subscribe %s: %s',
						$data['email'],
						$result->get_error_message()
					)
				);

				return $result;
			}

			$subscriber_id = isset($result->id) ? (int) $result->id : 0;

			if ($subscriber_id <= 0) {
				wu_log_add('newsletter', sprintf('subscribe2() returned a user with no id for %s', $data['email']));

				return new \WP_Error('newsletter_no_id', __('Subscription succeeded but no subscriber ID was returned.', 'ultimate-multisite-newsletter'));
			}

			wu_log_add(
				'newsletter',
				sprintf(
					'%s subscriber %s (ID: %d)',
					$existing ? 'Updated' : 'Added',
					$data['email'],
					$subscriber_id
				)
			);

			// Defensive: re-assert list memberships after subscribe2() in case
			// the plugin's "if_exists" handling left some lists untouched.
			if (! empty($valid_lists)) {
				$this->assign_to_lists($subscriber_id, $valid_lists, $double_optin);
			}

			return $subscriber_id;
		} catch (\Exception $e) {
			wu_log_add(
				'newsletter',
				sprintf(
					'Exception adding subscriber %s: %s',
					$data['email'],
					$e->getMessage()
				)
			);

			return new \WP_Error('newsletter_exception', $e->getMessage());
		} finally {
			// Always restore blog if we switched.
			if ($switched) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Assign subscriber to lists.
	 *
	 * The Newsletter Plugin stores list memberships as `list_<id>` columns on
	 * the subscriber row. There is no batch "assign_lists" API; we set each
	 * list one at a time via NewsletterModule::set_user_list($user, $list, $value).
	 *
	 * @param int   $subscriber_id Subscriber ID.
	 * @param array $lists Array of list IDs to set to 1 (subscribed).
	 * @param bool  $double_optin Reserved for future use. The Newsletter Plugin
	 *                            does not differentiate confirmed/pending list
	 *                            memberships at the column level - subscriber
	 *                            confirmation status is held on the user record.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function assign_to_lists(int $subscriber_id, array $lists, bool $double_optin = false) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

		if (empty($lists)) {
			return true;
		}

		// Switch to main site where The Newsletter Plugin is active.
		$main_site_id = get_main_site_id();
		$switched     = false;

		if (get_current_blog_id() !== $main_site_id) {
			switch_to_blog($main_site_id);
			$switched = true;
		}

		if (! class_exists('NewsletterSubscription')) {
			if ($switched) {
				restore_current_blog();
			}

			return new \WP_Error('newsletter_inactive', __('The Newsletter Plugin is not active on the main site.', 'ultimate-multisite-newsletter'));
		}

		// Validate list IDs.
		$valid_lists = $this->validate_lists($lists);

		if (empty($valid_lists)) {
			if ($switched) {
				restore_current_blog();
			}

			wu_log_add('newsletter', sprintf('No valid lists found in: %s', implode(', ', $lists)));

			return true;
		}

		try {
			$module = \NewsletterSubscription::instance();
			$user   = $module->get_user($subscriber_id);

			if (! $user) {
				if ($switched) {
					restore_current_blog();
				}

				wu_log_add('newsletter', sprintf('Subscriber %d not found when assigning lists', $subscriber_id));

				return new \WP_Error('newsletter_user_missing', __('Subscriber not found.', 'ultimate-multisite-newsletter'));
			}

			foreach ($valid_lists as $list_id) {
				$module->set_user_list($user, (int) $list_id, 1);
			}

			wu_log_add(
				'newsletter',
				sprintf(
					'Assigned subscriber %d to lists: %s',
					$subscriber_id,
					implode(', ', $valid_lists)
				)
			);

			return true;
		} catch (\Exception $e) {
			wu_log_add(
				'newsletter',
				sprintf(
					'Exception assigning subscriber %d to lists: %s',
					$subscriber_id,
					$e->getMessage()
				)
			);

			return new \WP_Error('newsletter_exception', $e->getMessage());
		} finally {
			// Always restore blog if we switched.
			if ($switched) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Get available Newsletter lists.
	 *
	 * @return array Associative array of list_id => list_name.
	 */
	public function get_available_lists(): array {

		// Switch to main site where The Newsletter Plugin is active.
		$main_site_id = get_main_site_id();
		$switched     = false;

		if (get_current_blog_id() !== $main_site_id) {
			switch_to_blog($main_site_id);
			$switched = true;
		}

		if (! class_exists('NewsletterSubscription')) {
			if ($switched) {
				restore_current_blog();
			}

			return [];
		}

		try {
			$lists = \NewsletterSubscription::instance()->get_lists();

			if (empty($lists)) {
				return [];
			}

			$formatted = [];

			foreach ($lists as $list) {
				$formatted[ (int) $list->id ] = $list->name;
			}

			return $formatted;
		} catch (\Exception $e) {
			wu_log_add('newsletter', sprintf('Exception getting lists: %s', $e->getMessage()));

			return [];
		} finally {
			// Always restore blog if we switched.
			if ($switched) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Map customer fields to Newsletter subscriber data.
	 *
	 * @param \WP_Ultimo\Models\Customer $customer Customer object.
	 * @return array Mapped subscriber data using The Newsletter Plugin's field names.
	 */
	public function map_customer_fields(\WP_Ultimo\Models\Customer $customer): array {

		$data = [
			'email' => $customer->get_email_address(),
		];

		// Only map fields if setting is enabled.
		if (! wu_get_setting('newsletter_map_fields', true)) {
			return $data;
		}

		$user = $customer->get_user();

		// Map name fields. The Newsletter Plugin uses `name` (first) and `surname` (last).
		if ($user && $user->first_name) {
			$data['name'] = $user->first_name;
		}

		if ($user && $user->last_name) {
			$data['surname'] = $user->last_name;
		}

		// Map billing address if available.
		$billing_address = $customer->get_billing_address();

		if ($billing_address) {
			if ($billing_address->billing_country) {
				$data['country'] = $billing_address->billing_country;
			}

			if ($billing_address->billing_state) {
				// The Newsletter Plugin uses `region` rather than `state`.
				$data['region'] = $billing_address->billing_state;
			}

			if ($billing_address->billing_city) {
				$data['city'] = $billing_address->billing_city;
			}
		}

		return $data;
	}

	/**
	 * Validate list IDs against available lists.
	 *
	 * @param array $list_ids Array of list IDs to validate.
	 * @return array Array of valid list IDs.
	 */
	private function validate_lists(array $list_ids): array {

		$available_lists = $this->get_available_lists();

		if (empty($available_lists)) {
			return [];
		}

		$available_ids = array_keys($available_lists);

		return array_filter(
			array_map('intval', $list_ids),
			function ($id) use ($available_ids) {

				return in_array($id, $available_ids, true);
			}
		);
	}
}
