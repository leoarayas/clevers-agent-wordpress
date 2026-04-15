=== Clevers Monitoring Agent ===
Contributors: clevers, cleversdev
Tags: monitoring, health, reporting
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Short Description: Sends periodic WordPress status reports to Clevers Monitoring.

== Description ==
Sends periodic reports from your WordPress site to Clevers Monitoring for visibility into core, plugins, themes, updates, and site health.

This package is the monitoring-only variant intended for WordPress-friendly distribution. It does not execute remote management commands or expose site control capabilities.

== Installation ==
1. Copy the `clevers-agent` folder into `wp-content/plugins/`.
2. Activate the plugin from the Plugins screen.
3. Go to Settings > Clevers Agent and configure the Agent Key, Endpoint URL, and interval.

== Frequently Asked Questions ==
= What does the agent send? =
WordPress and PHP versions, the list of plugins and themes, and a summary of available updates.

= How do I send a manual report? =
On the Settings > Clevers Agent screen, use the "Send now" button.

== Changelog ==
= 1.1.0 =
* Split the product line into monitoring-only and remote-management variants.
* Removed remote registration, polling, heartbeat, and command execution from this plugin.
* Simplified cron scheduling to telemetry reporting only.

= 1.0.4 =
* Removed remote plugin activation, deactivation, plugin updates, and theme updates.
* Restricted the status REST endpoint to administrators.
* Updated contributor metadata.

= 1.0.0 =
* Initial release.
