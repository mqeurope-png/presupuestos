=== Bomedia Quote Wizard ===
Contributors: bomedia
Tags: quote, wizard, leads, agilecrm, woocommerce, multi-step form
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multi-step quote request wizard for UV-LED printers and lasers. Brand-agnostic, multi-site, integrated with AgileCRM.

== Description ==

Bomedia Quote Wizard adds a configurable multi-step form (a "quote wizard") that captures leads for printer/laser product catalogs and pushes them to AgileCRM. Built to be brand-agnostic so the same install can serve multiple sites (boprint.net, bomedia.net, artisjet-printers.eu, mboprinters.com).

Features:

* Multi-step wizard with product → application → details → confirmation flow
* WooCommerce-aware: pulls categories and products from WooCommerce
* AgileCRM REST API integration (contact + note)
* Internal email notifications with full lead detail
* Custom post type `bqw_lead` for audit and manual retry of failed sends
* Honeypot + minimum fill time anti-bot protection
* WordPress nonces and full server-side validation
* Mobile-first, accessible, no jQuery, no React

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from the Plugins screen.
3. Make sure WooCommerce is active.
4. Go to **Settings → Bomedia Quote Wizard** and fill in your AgileCRM credentials.
5. Pick the WooCommerce categories the wizard should expose.
6. Add the shortcode `[bomedia_quote_wizard]` to any page.

== Shortcode ==

`[bomedia_quote_wizard]`

Optional attributes:

* `category="artisjet"` — slug of a `product_cat`. Forces a specific category and skips the brand selection step.
* `product_id="123"` — preselects a product and skips category/product selection.

== Frequently Asked Questions ==

= How are credentials stored? =
The AgileCRM API key is encrypted with `openssl_encrypt` (AES-256-CBC) using a key derived from your `AUTH_KEY` constant.

= What happens if AgileCRM is down? =
The lead is stored in a `bqw_lead` custom post type with status `failed`. You'll get the email notification flagged as error and can retry from wp-admin → Quote Leads.

= Can I override the templates? =
Yes. Copy `templates/wizard.php` or `templates/thanks.php` to `your-theme/bomedia-quote-wizard/`.

== Changelog ==

= 1.0.0 =
* Initial release.
