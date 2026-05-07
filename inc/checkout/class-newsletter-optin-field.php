<?php
/**
 * Newsletter Opt-in Checkout Field.
 *
 * @package Ultimate_Multisite_Newsletter
 * @since 0.1.0
 */

namespace Ultimate_Multisite\Newsletter\Checkout;

use WP_Ultimo\Checkout\Signup_Fields\Base_Signup_Field;

// Exit if accessed directly.
defined('ABSPATH') || exit;

/**
 * Newsletter Opt-in Field class.
 *
 * Adds an opt-in checkbox to the Ultimate Multisite checkout form for
 * Newsletter subscriptions. Mirrors the Mailster addon's behaviour and
 * is rendered only when the addon's opt-in mode is set to "checkbox".
 */
class Newsletter_Optin_Field extends Base_Signup_Field {

	/**
	 * Returns the type of the field.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public function get_type() {

		return 'um_newsletter_optin';
	}

	/**
	 * Returns if this field should be present on the checkout flow or not.
	 *
	 * @since 0.1.0
	 * @return boolean
	 */
	public function is_required() {

		return false;
	}

	/**
	 * Is this a user-related field?
	 *
	 * If this is set to true, this field will be hidden
	 * when the user is already logged in.
	 *
	 * @since 0.1.0
	 * @return boolean
	 */
	public function is_user_field() {

		return false;
	}

	/**
	 * Requires the title of the field/element type.
	 *
	 * This is used on the Field/Element selection screen.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public function get_title() {

		return __('Newsletter Opt-in Checkbox', 'ultimate-multisite-newsletter');
	}

	/**
	 * Returns the description of the field/element.
	 *
	 * This is used as the title attribute of the selector.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public function get_description() {

		return __('Adds a checkbox for customers to opt-in to Newsletter email lists.', 'ultimate-multisite-newsletter');
	}

	/**
	 * Returns the tooltip of the field/element.
	 *
	 * This is used as the tooltip attribute of the selector.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public function get_tooltip() {

		return __('Allows customers to opt-in to your Newsletter lists during checkout. Only shown when opt-in mode is set to "Requires Checkbox Confirmation" in settings.', 'ultimate-multisite-newsletter');
	}

	/**
	 * Returns the icon to be used on the selector.
	 *
	 * Can be either a dashicon class or a wu-dashicon class.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public function get_icon() {

		return 'dashicons-wu-email';
	}

	/**
	 * Returns the default values for the field-elements.
	 *
	 * This is passed through a wp_parse_args before we send the values
	 * to the method that returns the actual fields for the checkout form.
	 *
	 * @since 0.1.0
	 * @return array
	 */
	public function defaults() {

		return [
			'checkbox_text'   => __('Yes, I want to receive email updates', 'ultimate-multisite-newsletter'),
			'default_checked' => true,
		];
	}

	/**
	 * List of keys of the default fields we want to display on the builder.
	 *
	 * @since 0.1.0
	 * @return array
	 */
	public function default_fields() {

		return [
			'name',
			'tooltip',
		];
	}

	/**
	 * If you want to force a particular attribute to a value, declare it here.
	 *
	 * @return array
	 */
	public function force_attributes(): array {

		return [
			'id' => 'um_newsletter_optin',
		];
	}

	/**
	 * Returns the list of additional fields specific to this type.
	 *
	 * @since 0.1.0
	 * @return array
	 */
	public function get_fields() {

		return [
			'checkbox_text'   => [
				'type'        => 'text',
				'title'       => __('Checkbox Label', 'ultimate-multisite-newsletter'),
				'desc'        => __('The text shown next to the checkbox.', 'ultimate-multisite-newsletter'),
				'placeholder' => __('Yes, I want to receive email updates', 'ultimate-multisite-newsletter'),
				'value'       => '',
				'order'       => 10,
			],
			'default_checked' => [
				'type'  => 'toggle',
				'title' => __('Checked by Default', 'ultimate-multisite-newsletter'),
				'desc'  => __('Whether the checkbox should be checked by default.', 'ultimate-multisite-newsletter'),
				'value' => 1,
				'order' => 11,
			],
		];
	}

	/**
	 * Returns the field/element actual field array to be used on the checkout form.
	 *
	 * @since 0.1.0
	 *
	 * @param array $attributes Attributes saved on the editor form.
	 * @return array An array of fields, not the field itself.
	 */
	public function to_fields_array($attributes) {

		// Only show if optin_mode is 'checkbox'.
		$optin_mode = wu_get_setting('newsletter_optin_mode', 'automatic');

		if ('checkbox' !== $optin_mode) {
			return [];
		}

		$checkout_fields = [];

		// Get checkbox text from attributes or use default.
		$checkbox_text = ! empty($attributes['checkbox_text'])
			? $attributes['checkbox_text']
			: __('Yes, I want to receive email updates', 'ultimate-multisite-newsletter');

		$checkout_fields[ $attributes['id'] ] = [
			'type'            => 'checkbox',
			'id'              => $attributes['id'],
			'name'            => $checkbox_text,
			'tooltip'         => $attributes['tooltip'],
			'wrapper_classes' => $attributes['element_classes'],
		];

		// Set default checked state.
		if (! empty($attributes['default_checked'])) {
			$checkout_fields[ $attributes['id'] ]['html_attr']['checked'] = 'checked';
		}

		// Check if value was already set (returning customer).
		$value = $this->get_value();

		if ('' !== $value && true === (bool) $value) {
			$checkout_fields[ $attributes['id'] ]['html_attr']['checked'] = 'checked';
		}

		return $checkout_fields;
	}
}
