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
	 * site content, IP addresses, payment amounts, and free-form customer data.
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

		$memberships = (array) $customer->get_memberships();
		$sites       = (array) $customer->get_sites();
		$user        = $customer->get_user();

		$membership_statuses = [];
		$plan_names           = [];
		$network_ids          = [];
		$earliest_membership  = '';

		foreach ($memberships as $membership) {
			if (! is_object($membership)) {
				continue;
			}

			$status = sanitize_key((string) $membership->get_status());

			if ($status) {
				$membership_statuses[] = $status;
			}

			$plan = $membership->get_plan();

			if ($plan) {
				$plan_name = sanitize_text_field((string) $plan->get_name());

				if ($plan_name) {
					$plan_names[] = $plan_name;
				}
			}

			$network_id = absint($membership->get_meta('network_id', 0));

			if ($network_id) {
				$network_ids[] = $network_id;
			}

			$date_created = (string) $membership->get_date_created();

			if ($date_created && (! $earliest_membership || strtotime($date_created) < strtotime($earliest_membership))) {
				$earliest_membership = $date_created;
			}
		}

		$customer_network_id = method_exists($customer, 'get_network_id') ? absint($customer->get_network_id()) : 0;

		if ($customer_network_id) {
			$network_ids[] = $customer_network_id;
		}

		$last_site_update = '';
		$visit_total      = 0;

		foreach ($sites as $site) {
			if (! is_object($site)) {
				continue;
			}

			$date_modified = (string) $site->get_date_modified();

			if ($date_modified && (! $last_site_update || strtotime($date_modified) > strtotime($last_site_update))) {
				$last_site_update = $date_modified;
			}

			if (class_exists('\\WP_Ultimo\\Objects\\Visits')) {
				$visits       = new \WP_Ultimo\Objects\Visits($site->get_blog_id());
				$visit_total += $visits->get_visit_total('-30 days', 'now');
			}
		}

		$completed_payments = 0;

		foreach ((array) $customer->get_payments() as $payment) {
			if (is_object($payment) && 'completed' === $payment->get_status()) {
				++$completed_payments;
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
			'customer_found'          => true,
			'explicit_opt_in'         => $explicit_opt_in,
			'consent_source'          => $explicit_opt_in ? 'verified_customer_meta' : 'none_recorded',
			'first_name'              => $user ? sanitize_text_field((string) $user->first_name) : '',
			'membership_count'        => count($memberships),
			'membership_statuses'     => array_values(array_unique($membership_statuses)),
			'plan_names'              => array_values(array_unique($plan_names)),
			'membership_age_bucket'   => $this->age_bucket($earliest_membership),
			'completed_payment_count' => $completed_payments,
			'network_count'           => count(array_unique(array_filter($network_ids))),
			'site_count'              => count($sites),
			'last_site_update_bucket' => $this->age_bucket($last_site_update),
			'last_login_bucket'       => $this->age_bucket((string) $customer->get_last_login(false)),
			'usage_bucket_30_days'    => $this->usage_bucket($visit_total),
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

	/**
	 * Convert a timestamp into a coarse age bucket.
	 *
	 * @param string $date Date understood by strtotime().
	 * @return string
	 */
	private function age_bucket(string $date): string {

		$timestamp = $date ? strtotime($date) : false;

		if (! $timestamp) {
			return 'unknown';
		}

		$days = max(0, (int) floor((time() - $timestamp) / DAY_IN_SECONDS));

		if ($days < 30) {
			return 'under_30_days';
		}

		if ($days < 90) {
			return '30_to_89_days';
		}

		if ($days < 365) {
			return '90_to_364_days';
		}

		return 'one_year_or_more';
	}

	/**
	 * Convert a 30-day visit count into a coarse usage bucket.
	 *
	 * @param int $visits Visit total.
	 * @return string
	 */
	private function usage_bucket(int $visits): string {

		if ($visits <= 0) {
			return 'none';
		}

		if ($visits < 100) {
			return 'light';
		}

		if ($visits < 1000) {
			return 'active';
		}

		return 'high';
	}
}
