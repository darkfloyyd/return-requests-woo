# Return Requests for WooCommerce

A comprehensive, **100% free** native return management plugin built for WooCommerce, designed to securely handle return requests from both authenticated users and guests without adding heavy database bloat. It registers the requests, verifies guest identity exactly to their order via secure 1-hour cryptographic tokens, collects selected products and refund methods, and outputs beautifully formatted **region-compliant PDF protocols** sent directly to both the customer and the store owner.

## Why choose Return Requests over heavier RMA plugins?

1. 🚀 **100% Free & Open Source:** A fully native solution handling the end-to-end return pipeline without nagging for premium upgrades.
2. 🛡️ **Secure Guest Returns:** While competitors force user registration for returns, this plugin generates cryptographically secure, 1-hour email verification tokens, enabling seamless guest returns exactly tied to their original order.
3. 📄 **Automated "Goods Return Protocol" PDFs:** Dynamically creates standardized, region-compliant PDF protocols (e.g. EU, UK, US, PL) attached directly to notification emails.
4. ⚡ **Native WooCommerce Integration (Zero DB Bloat):** Utilizes core WooCommerce order statuses (`wc-return-pending`, etc.) and the native email templating engine, avoiding the bloated architecture of expensive RMA suites.
5. 🔐 **Built-in Cloudflare Turnstile:** Modern, privacy-first CAPTCHA spam blocking out-of-the-box, protecting guest forms.

## How does it work?

### 1. Configuration and Initialization
1. **System Status:** By default after installation, the return system is active but can be individually disabled using the "Return System Status" toggle in the Information tab while you test and configure your setup.
2. **Automated Pages:** By clicking **"Check and Create Required Pages"** in the plugin settings, three essential routing pages utilizing shortcodes are automatically created:
   - **Return Form** (`[return_form]`): The primary entry point for guest users who do not have an account.
   - **Select Return Items** (`[return_items_form]`): The secure session-gated page where customers select the products they wish to return and submit their bank account.
   - **Return Confirmation** (`[return_confirmation]`): Success page displaying the generated PDF file link.
   
   *Tip: Automatically created pages are still fully customizable. The shortcode just needs to stay there, but you can confidently add your own content, banners, or additional text below or above it!*

3. **Law Compliance:** In the Law Compliance tab, select your operating region context to automatically load corresponding consumer protection disclaimers onto the frontend Return Form. Additionally, you can provide your own Custom legal disclaimer overriding the template engine.

4. **Return Confirmation:** In the **Return Confirmation** tab, configure the company name and shipping address displayed on the confirmation page after a customer submits a return. This page is secured — data is only shown to the authenticated customer via a short-lived HMAC token (guest) or a logged-in account email match (My Account), preventing unauthorized access to order information.

### Localization & Translation Support
The plugin is fully translatable on both the frontend and backend. English is the default base language, but Polish (`pl_PL`) is 100% pre-built out-of-the-box. The plugin automatically grabs your active WordPress language setting and applies translations if they exist. Any missing translations or custom languages can be effortlessly added using standard translation plugins like **Open World** or **Loco Translate**.

### 2. The Flow for Logged-In Customers
1. A logged-in user visits their **"My Account"** WooCommerce dashboard and navigates to **"Orders"**.
2. If an order has the status "Completed" and falls within the configurable statutory return window (default: 14 days), a **"Return"** button natively appears next to the order.
3. Once clicked, the plugin generates a secure **session token** directly on the server without needing an email verification step (since the user identity is already verified by WordPress).
4. The user is instantly redirected to the **Select Return Items** page with their authenticated session active.

### 3. The Flow for Guest Customers (Unregistered)
1. A guest user who bought an item without an account visits the **Return Form** page.
2. They input their **Order Number** and the **Billing Email Address** associated with that order.
3. The plugin cross-references the database and generates a cryptographically secure 1-hour **Return Token** tying their request to that specific order.
4. An automated email is dispatched to the user containing a one-time verification link.
5. Upon clicking the link, the user's browser establishes an authenticated system session using the token, proving they own the email address used during purchase. They are then securely redirected to the **Select Return Items** page.

### 4. Form Submission and PDF Generation
Once the user reaches the **Select Return Items** page (either via direct account injection or email verification):
1. The user selects the exact items they wish to return using checkboxes and inputs their bank account number for the refund (validated strictly by the plugin against the 26-digit format).
2. Optionally, a Cloudflare Turnstile gateway protects the form from automated abuse.
3. Upon clicking **"Return Selected Items"**, the request is officially recorded in the custom `wp_woo_returns` database table.
4. The `mPDF` generator engine constructs a **Goods Return Protocol** PDF, filling it automatically with the store's details, the customer's billing address, the selected items, and a GDPR/Consumer Rights compliance declaration.
5. The PDF is saved securely to `/wp-content/uploads/ReturnsPDF/`.
6. Two automated emails are dispatched:
   - A confirmation to the **Customer** including the attached PDF protocol.
   - A notification to the **Administrator** containing the same attached PDF to track the impending package logistically.
7. The customer's secure session is automatically flushed and they land on the final Confirmation page.

### 5. Email Notifications Overview
- **Verification Email (Guest only):** Sent immediately after a guest submits the initial Return Form to verify their identity via a secure one-time link.
- **Customer Confirmation:** Sent after successfully selecting items and generating the Return Protocol PDF. Contains the PDF as an attachment.
- **Administrator Notification:** Sent simultaneously to the store owner alerting them of the return, with the Return Protocol PDF attached.

*Note: The overall layout and styling of all these emails are seamlessly managed by WooCommerce's native template engine. This ensures the return emails perfectly match your store's active typography and branding, while the plugin securely injects the text contents configured in its settings.*

### 6. Return Status Management and Order Integration
- **Return List Backend:** All returns arrive with a "Pending" status in the main plugin table. Administrators can use quick action buttons to mark return as "Completed" or flag it as an "Issue" directly from the Returns list.
- **WooCommerce Order Meta Box:** When viewing a specific order from the WooCommerce store admin view, a dedicated side Meta Box securely exposes the associated Return. Store owners can seamlessly view the original protocol and alter the workflow status.
- **Issue Resolution Notes:** If a return is flagged as an "Issue" exclusively through the Order Meta Box, an explanation form surfaces. Submitting it dynamically relays the reason as a WooCommerce Customer Order Note, updating the buyer intuitively by utilizing built-in WooCommerce communication pipelines.
