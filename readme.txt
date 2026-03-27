=== Return Requests for WooCommerce ===
Contributors: Jakub Misiak
Tags: woocommerce, returns, pdf, emails, refunds
Requires at least: 5.9
Tested up to: 6.9
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Allows customers to submit Return Requests for WooCommerce orders, with PDF generation and email notifications.

== Description ==

Return Requests for WooCommerce adds a fully featured, 100% free return (refund request) workflow to your WooCommerce store without premium tiers or locked features. It natively and securely handles returns for both logged-in users and guest customers. Customers can:

- Submit return requests from the "My Account" panel (for logged-in users).
- Use the email-verified return form (for guest users).
- Select individual products to return and provide their bank account number.
- Receive a PDF confirmation attached to an automated email.

The plugin sends HTML emails to both the customer and the store administrator. Subject lines, message bodies, and sender details are all configurable from the admin settings. Optionally enable Cloudflare Turnstile to protect forms from spam bots.

== Why choose Return Requests over heavier RMA plugins? (Key Advantages) ==

1. **100% Free & Open Source:** A fully native solution handling the end-to-end return pipeline without nagging for premium upgrades.
2. **Secure Guest Returns:** While competitors force user registration for returns, this plugin generates cryptographically secure, 1-hour email verification tokens, enabling seamless guest returns exactly tied to their original order.
3. **Automated "Goods Return Protocol" PDFs:** Dynamically creates standardized, region-compliant PDF protocols attached directly to notification emails.
4. **Native WooCommerce Integration (No DB Bloat):** Utilizes core WooCommerce statuses (`wc-return-pending`, etc.) and the native email templating engine, avoiding the bloated architecture of heavy RMA suites.
5. **Built-in Cloudflare Turnstile Spam Protection:** Modern, privacy-first spam blocking out-of-the-box.

== Features ==

1. Return submissions for logged-in users via "My Account".
2. Guest return form with e-mail verification flow.
3. Product-level item selection with bank account collection.
4. Bank account number validation (26-digit Polish format, IBAN, or disabled).
5. Dynamic Law Compliance text formatting for EU, UK, US, CA, AU/NZ and others, native support for custom declarations.
6. Master System Status Switch to toggle the return workflow on or off at will.
7. Return Status UI module with fast state switching (Pending, Completed, Issue).
8. Dedicated WooCommerce Order Meta Box allowing backend staff to flag return states and submit issue justifications dynamically acting as Customer Order Notes.
9. Secure session-based data handling — no sensitive data in URLs.
10. Seamlessly integrates with native WooCommerce email templates (headers & footers) for consistent store branding.
11. Styled HTML emails with verification buttons.
12. PDF generation (mPDF) attached to confirmation emails.
13. Configurable email subjects, bodies, and sender addresses.
13. Optional Cloudflare Turnstile CAPTCHA.
14. Admin data management: delete records older than one year or wipe all records.
15. Automatic required-page creation (return form, item selection, confirmation).
16. Security event logging with severity levels and admin log viewer.
17. Tabbed admin interface for clear settings organisation.

== Localization & Translation ==

This plugin is fully translatable on both the frontend and backend. English is the default base language, but Polish (`pl_PL`) is 100% pre-built out-of-the-box. The plugin automatically grabs your WordPress language settings to display the proper language if translations exist. Missing translations can be seamlessly added using any standard translation plugin like Loco Translate or the Open World plugin.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via the WordPress plugin installer.
2. Activate the plugin on the **Plugins** screen.
3. Make sure WooCommerce is active.
4. Go to **Returns** in the WordPress admin menu.
5. Click **Check and Create Required Pages** on the Pages tab.
6. Configure email settings (subjects, bodies, sender address) on the Email Settings tab.
7. Optionally enable Cloudflare Turnstile on the Security tab.

== Shortcodes ==

* `[return_form]` — Displays the return request form for guest users.
* `[return_items_form]` — Displays the product selection and bank account form.
* `[return_confirmation]` — Displays the return confirmation page.

*Note: Automatically created pages using these shortcodes are fully customizable. The shortcode must remain on the page, but you can add your own content below or above it as needed.*

== Requirements ==

* **WordPress**: 5.9 or newer
* **WooCommerce**: required (declare via Requires Plugins header)
* **PHP**: 8.1 or newer

== Dependencies ==

* **[mPDF](https://github.com/mpdf/mpdf)**: v8.3.1 (Bundled natively)
* **[setasign/fpdi](https://github.com/Setasign/FPDI)**: v2.6.6 (Bundled natively)

== Frequently Asked Questions ==

= Can I change the return window? =
Yes. The return window (default: 14 days) is configurable via the `woo_return_window_days` filter or the settings page (Phase 5).

= What data is stored? =
Return submissions are stored in a custom database table (`wp_woo_returns`). Security events are stored in `wp_woo_return_security_logs`. All data is removed on plugin deletion via `uninstall.php`.