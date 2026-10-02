=== WP Easy WhatsApp Chat ===
Contributors: Awais-Ali
Stable tag: 3.1.0
Requires at least: 6.0
Requires PHP: 7.4
License: GPLv2 or later

== Description ==
Production WordPress website chat that relays visitor messages to an administrator WhatsApp number through 360dialog and returns native WhatsApp replies to the website conversation.

The live build does not use ngrok, LocalWP, PowerShell, Task Scheduler, or a desktop process. The live WordPress site's own HTTPS REST endpoint is the webhook destination.

== Production setup ==
1. Install and activate the plugin.
2. Enter the administrator WhatsApp destination number with country code.
3. If an API key is already stored in WordPress or wp-config.php, the plugin uses it automatically.
4. The plugin automatically configures the live WordPress webhook and no ngrok, PowerShell, LocalWP, or Task Scheduler is used.
5. The runtime auto-detects a production versus existing Sandbox key by retrying the alternate 360dialog endpoint after HTTP 401.
6. Test a website message and reply to the WhatsApp notification using WhatsApp's native Reply action.

== Important WhatsApp rule ==
Business-initiated free-form text messages are subject to WhatsApp's customer-service window. For production notifications outside an open service window, configure an approved WhatsApp template in the plugin and select Template mode.

== Health endpoint ==
GET /wp-json/wewc/v1/health

== Webhook endpoint ==
POST /wp-json/wewc/v1/360dialog/inbound

== Security ==
The inbound webhook is protected by a private X-WEWC-Webhook-Secret header configured automatically with 360dialog. Visitor session tokens are random 64-character values and only their SHA-256 hashes are stored.
