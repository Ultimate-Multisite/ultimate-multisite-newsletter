# Newsletter Integration - Ultimate Multisite Addon

Automatically subscribe customers to self-hosted newsletter lists during Ultimate Multisite checkout, with product-based segmentation and flexible opt-in options.

The first supported provider is [**The Newsletter Plugin**](https://wordpress.org/plugins/newsletter/) by Stefano Lissa & The Newsletter Team. This addon is structured to grow into an umbrella integration for additional self-hosted providers (MailPoet, FluentCRM, Groundhogg, Noptin) behind a single settings UI.

## Why self-hosted?

Unlike the sibling `ultimate-multisite-mailchimp` addon (cloud-only) or `ultimate-multisite-mailster` (single proprietary plugin), this addon targets **free, self-hosted, AI-friendly** newsletter systems. Per-recipient hooks like Newsletter's `newsletter_message_text`, `newsletter_message_html`, and `newsletter_send_user` make per-subscriber AI personalization practical at scale.

## Features

- **Product-based Segmentation**: Assign customers to different lists based on the membership/product purchased
- **Flexible Timing**: Subscribe on order creation (immediate) or payment complete
- **Compliance-Friendly**: Automatic or checkbox opt-in modes for GDPR compliance
- **Double Opt-in**: Optional email confirmation before subscription
- **Field Mapping**: Automatically sync customer data (email, first/last name, country, region, city) to Newsletter
- **Multiple Lists**: Support for assigning customers to multiple lists per product
- **Graceful Error Handling**: Never blocks checkout if Newsletter API fails
- **Comprehensive Logging**: All operations logged for debugging via `wu_log_add()`

## Requirements

- WordPress 5.3 or higher
- PHP 7.4 or higher
- [Ultimate Multisite](https://ultimatemultisite.com) (active)
- [The Newsletter Plugin](https://wordpress.org/plugins/newsletter/) (active on the main site)

## Installation

1. Upload the addon to `wp-content/plugins/ultimate-multisite-newsletter/`
2. Run `composer install --no-dev` to install dependencies
3. Network-activate the plugin
4. Configure at **WP Ultimo > Settings > Newsletter Integration**

## Configuration

### Global Settings

Navigate to **WP Ultimo > Settings > Newsletter Integration**:

1. **Subscription Timing**:
   - Order Creation (Immediate) — subscribe when membership is created
   - Payment Complete — subscribe after payment is confirmed
2. **Opt-in Mode**:
   - Automatic — subscribe all customers automatically
   - Checkbox — require explicit consent via a checkout field
3. **Double Opt-in**: Send a confirmation email before activating the subscription
4. **Default Lists**: Global default lists for all customers (multi-select)
5. **Update Existing**: Whether to update lists/fields for already-subscribed emails
6. **Map Fields**: Enable/disable customer field mapping to Newsletter

### Product Settings

Edit any product → **Newsletter** tab:

- By default, products use the global default lists.
- Enable **Override Global Lists** to set product-specific lists.
- To disable Newsletter for a product, enable override and leave all lists unchecked.

### Checkout Field (Checkbox Mode Only)

If using "Checkbox" opt-in mode:

1. Edit your checkout form
2. Add the **Newsletter Opt-in Checkbox** field (type: `um_newsletter_optin`)
3. Customise the label and default-checked state
4. Save the form

The checkbox only renders when global opt-in mode is set to "Requires Checkbox Confirmation".

## Architecture

### Core Classes

- **`Newsletter_Main`** — bootstraps integration, registers hooks, orchestrates subscription flow
- **`Subscriber_Manager`** — wraps `NewsletterSubscription::instance()->subscribe2()` and list APIs
- **`Settings_Manager`** — registers settings section, renders list selector, persists choices
- **`Product_Integration`** — adds Newsletter tab to product editor
- **`Newsletter_Optin_Field`** — `Base_Signup_Field` subclass for the checkout checkbox

### File Structure

```
ultimate-multisite-newsletter/
├── ultimate-multisite-newsletter.php   # Main plugin file
├── inc/
│   ├── class-newsletter-main.php       # Main logic & hooks
│   ├── class-subscriber-manager.php    # Newsletter API wrapper
│   ├── class-settings-manager.php      # Settings registration
│   ├── class-product-integration.php   # Product page extension
│   └── checkout/
│       └── class-newsletter-optin-field.php # Custom checkout field
├── views/                              # PHP template partials
├── composer.json                       # PHP dependencies
├── .phpcs.xml.dist                     # WPCS ruleset
├── AGENTS.md                           # Agent/AI development notes
└── README.md                           # This file
```

## Hooks Used

### Actions

- `wu_membership_post_save` — subscribe on membership creation (order_creation timing)
- `wu_transition_payment_status` — subscribe on payment complete
- `wu_checkout_field_types` — register the custom field type
- `init` — late registration of field UI

### Filters

- `wu_settings_section_newsletter` — extends WP Ultimo settings UI
- `wu_pre_save_settings` — captures `newsletter_default_lists[]` posted from the multi-checkbox UI
- `wu_product_options_sections` — adds Newsletter tab to product editor

## Data Flow

### Order Creation Flow

```
Customer completes checkout
    ↓
Action: wu_membership_post_save
    ↓
Newsletter_Main::on_membership_created()
    ↓
Check: timing == 'order_creation'? Customer opted in?
    ↓
Resolve product list overrides → fallback to global defaults
    ↓
Map customer fields → TNP_Subscription
    ↓
Subscriber_Manager::subscribe()  →  Newsletter Plugin
    ↓
wu_log_add('newsletter', ...)
```

### Payment Complete Flow

```
Action: wu_transition_payment_status (status -> completed)
    ↓
Newsletter_Main::on_payment_completed()
    ↓
Check: timing == 'payment_complete'?
    ↓
[same as Order Creation from step 3]
```

## Mailster Sibling — Known Bug

The companion `ultimate-multisite-mailster` addon writes its checkout opt-in meta as `mailster_optin` but reads it back as `mailster_opted_in`, so checkbox-mode opt-ins never register. This addon **uses a single consistent key `um_newsletter_optin` for both write and read**.

## Namespace and Meta Key Naming

- PHP namespace: `Ultimate_Multisite\Newsletter\*` (not `WP_Ultimo\Newsletter\*`).
  Ultimate Multisite core ships a class `WP_Ultimo\Newsletter` at
  `ultimate-multisite/inc/class-newsletter.php` (unrelated user opt-in singleton),
  so this addon uses a sibling top-level namespace to avoid classmap conflicts.
- Customer meta key / checkout field id: `um_newsletter_optin` (prefixed to avoid
  the `newsletter_optin` slug already used by core's `WP_Ultimo\Newsletter`).
- Setting keys (`newsletter_optin_mode`, `newsletter_default_lists`,
  `newsletter_subscription_timing`, `newsletter_double_optin`,
  `newsletter_update_existing`, `newsletter_map_fields`,
  `newsletter_override_global`, `newsletter_lists`) keep the unprefixed
  `newsletter_*` form because they live in the addon's settings namespace and
  do not collide with anything in core.

## License

GPL v3 or later. (Inherits Ultimate Multisite licensing.)

## Changelog

### 0.1.0 (2026-05-07)

- Initial scaffold ported from `ultimate-multisite-mailster`
- The Newsletter Plugin (Stefano Lissa) as first supported provider
- Subscription on order creation or payment complete
- Automatic / checkbox opt-in modes with double-opt-in support
- Per-product list overrides
- Customer field mapping (email, first/last name, country, region, city)
