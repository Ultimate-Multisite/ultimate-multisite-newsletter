# Ultimate Multisite: Newsletter Integration

Integrate with self-hosted WordPress newsletter plugins (starting with [The Newsletter Plugin](https://wordpress.org/plugins/newsletter/)) during Ultimate Multisite checkout to subscribe customers to mailing lists.

This addon is the umbrella integration for self-hosted newsletter providers. The first supported provider is **The Newsletter Plugin** by Stefano Lissa. Additional providers (MailPoet, FluentCRM, Groundhogg, Noptin) may be added later behind a provider-strategy interface.

This is an addon for [Ultimate Multisite](https://ultimatemultisite.com), the WordPress multisite management plugin. It requires the main Ultimate Multisite plugin to be active.

## Build Commands

```bash
composer install                        # Install PHP dependencies
npm install                             # Install Node tooling (when package.json is added)
npm run build                           # Production build (minify + makepot + archive)
npm run makepot                         # Regenerate translation .pot file
```

## Lint / Static Analysis

```bash
vendor/bin/phpcs                        # Run PHP_CodeSniffer (config: .phpcs.xml.dist)
vendor/bin/phpstan analyse              # Run PHPStan static analysis (when configured)
vendor/bin/rector --dry-run             # Preview Rector refactoring changes (when configured)
```

## Testing

```bash
vendor/bin/phpunit                      # Run PHPUnit test suite (when added)
vendor/bin/phpunit --filter ClassName   # Run a single test class
```

## Project Structure

```
ultimate-multisite-newsletter.php  # Main plugin bootstrap
inc/                               # PHP classes (autoloaded via Composer classmap)
  class-newsletter-main.php        # Main logic & hooks
  class-subscriber-manager.php     # Newsletter Plugin API wrapper
  class-settings-manager.php       # Settings registration
  class-product-integration.php    # Product page extension
  checkout/
    class-newsletter-optin-field.php # Custom checkout field
views/                             # PHP template partials
composer.json                      # PHP dependencies and autoloading
.phpcs.xml.dist                    # PHP_CodeSniffer ruleset
```

## Code Style

- **Standard**: WordPress Coding Standards (WPCS)
- **Indentation**: Tabs, not spaces
- **PHP Compatibility**: 7.4+
- **Functions**: `snake_case` — e.g. `wu_get_setting()`
- **Classes**: `PascalCase` — e.g. `Ultimate_Multisite\Newsletter\Newsletter_Main`
- **Class files**: `class-kebab-case.php` — e.g. `class-newsletter-main.php`
- **Test files**: `PascalCase_Test.php`
- **Hooks**: prefixed `wu_` — e.g. `wu_checkout_completed`
- **Yoda conditions**: `if ( 'value' === $var )`
- **Short arrays**: `[]` not `array()`
- **Short ternary**: `$a ?: $b` is allowed
- **Global prefixes**: `wu_`, `wp_ultimo` (legacy; preserved for backward compatibility)
- **Text domain**: `ultimate-multisite-newsletter`

## Security

Every PHP file must start with:

```php
defined('ABSPATH') || exit;
```

## i18n

All user-facing strings must be translatable:

```php
__('Text', 'ultimate-multisite-newsletter')
_e('Text', 'ultimate-multisite-newsletter')
```

## Error Handling

Use the WordPress `WP_Error` pattern — not exceptions:

```php
$result = wu_some_operation();
if (is_wp_error($result)) {
    return $result;
}
```

The Newsletter Plugin's `NewsletterSubscription::subscribe2()` returns either a `TNP_User` object on success or a `WP_Error` on failure — surface these errors via `wu_log_add()` rather than throwing.

## Dependencies

The main Ultimate Multisite plugin (`ultimate-multisite`) must be network-activated. The Newsletter plugin must be installed and active on the main site (we follow Mailster's `switch_to_blog(get_main_site_id())` pattern).

## Namespace Choice

This addon uses the namespace **`Ultimate_Multisite\Newsletter\*`** (not `WP_Ultimo\Newsletter\*`) because Ultimate Multisite core already defines a class `WP_Ultimo\Newsletter` at `ultimate-multisite/inc/class-newsletter.php` (an unrelated user newsletter opt-in singleton). PHP cannot have a class `WP_Ultimo\Newsletter` AND classes under namespace `WP_Ultimo\Newsletter\*` coexisting cleanly under classmap autoloading, so we deliberately use a sibling top-level namespace.

The customer-meta and checkout-field-id key is **`um_newsletter_optin`** for the same reason: core's `WP_Ultimo\Newsletter::SETTING_FIELD_SLUG = 'newsletter_optin'` already reserves that slug. Setting keys (e.g. `newsletter_optin_mode`, `newsletter_default_lists`) are addon-only and keep the unprefixed `newsletter_*` form.

## Known Upstream Bug (Mailster Sibling)

The `ultimate-multisite-mailster` sibling addon has a key mismatch: `class-mailster-optin-field.php` writes the checkout meta as `mailster_optin` but `class-mailster-main.php` reads `mailster_opted_in`. **This addon deliberately uses the consistent key `um_newsletter_optin`** for both write and read. Report the Mailster bug upstream when convenient.

## Provider Architecture (Future)

When adding a second provider (MailPoet, FluentCRM, etc.), introduce a `Provider_Interface` with `subscribe()`, `get_lists()`, `is_available()` methods and have `Subscriber_Manager` route to the correct provider based on a setting. For now, the Newsletter Plugin is hardwired.

## Local Development Environment

This plugin lives inside the Bedrock parent at `~/tgc.church/site/web/app/plugins/ultimate-multisite-newsletter/`. The Bedrock `.gitignore` excludes `web/app/plugins/*`, so this addon is NOT tracked by the parent repo — it has its own git history (eventually published as `Ultimate-Multisite/ultimate-multisite-newsletter` on GitHub and consumed via Composer VCS).

After adding new PHP classes, regenerate the outer Bedrock classmap (NOT the local plugin classmap):

```bash
cd ~/tgc.church/site && composer dump-autoload --optimize --no-scripts
```

This addon currently has no Jetpack autoloader — class resolution happens via Bedrock's outer merged classmap.
