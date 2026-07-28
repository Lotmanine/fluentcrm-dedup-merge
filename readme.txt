=== Dedup and Merge for FluentCRM ===
Contributors: lotmanine
Tags: fluentcrm, crm, duplicates, deduplication, merge
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 8.2
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find and merge duplicate FluentCRM contacts. Consolidate tags, lists, and custom fields onto a surviving record, with a safety snapshot before every merge.

== Description ==
FluentCRM keeps one contact per email address, so the same person can still end up as several contacts: gmail dot and plus aliases, a second email, a shared phone number. This plugin finds those duplicates by normalized email, phone, and full name, then merges them onto a survivor while keeping tags, lists, and custom fields.

Safety first: every merge re-reads each contact from the database at merge time and writes a full snapshot to an undo journal BEFORE anything changes. If a contact is no longer a duplicate, or the snapshot cannot be recorded, the merge is aborted. It will also refuse a set whose contacts do not all share the same subscription status, so a merge can never delete the only unsubscribed or bounced record for an inbox and leave the survivor mailable to someone who opted out. Every merge is listed in a merge history. The free version records and verifies snapshots. Pro adds one-click restore that re-creates the removed contacts and reverts the survivor.

Free: manual single set merge with tag, list, and custom field consolidation, plus snapshot recording and verification, and a merge history that verifies each snapshot.
Pro: bulk merge of many sets in one action, and one-click restore from the merge history that re-creates the removed contacts and reverts the survivor, for any merge and not just the most recent.

== Frequently Asked Questions ==

= Does the free version undo a merge? =
The free version records a full snapshot before every merge and lets you verify it exists. Pro adds one-click restore that re-creates the removed contacts and reverts the survivor from that snapshot.

= Which duplicates are detected? =
Contacts whose emails normalize to the same address (gmail dots and plus aliases), contacts sharing a phone number, and contacts sharing both first and last name. No fuzzy matching: every match is exact after normalization.

= Does it change my data during a scan? =
No. Scanning and previewing are read only. Data changes only when you confirm a merge, and never before the undo snapshot is recorded.

= Will merging trigger my automations or sequences? =
Merging attaches the removed contacts' tags and lists to the survivor. If you run automations that start on a tag or list being applied, treat a merge as capable of enrolling the survivor. Test on a small set first, and pause tag or list based automations during a large cleanup if you are unsure.

= Does this need FluentCRM Pro? =
No. It works with free FluentCRM. FluentCRM must be active, or the plugin shows a notice and stays inactive.

= Where does my data go, and what about erasure requests? =
Nowhere external. Everything stays in your WordPress database. Note that every merge stores a snapshot of the merged contacts (see Privacy below), so a personal-data erasure request for a removed contact should also clear the matching undo-journal rows.

== Privacy ==
Every merge writes a snapshot to a database table (the undo journal) BEFORE any change, so the merge can be verified and, on Pro, reversed. That snapshot contains the merged contacts' personal data: email, first and last name, phone, address, date of birth, tag and list membership, and custom field values. If you use the Pro option to move WooCommerce orders, it also records the order ids involved and the billing email each one carried, so the move can be reversed. Snapshots are kept until you remove them or delete the plugin. Deleting the plugin (not just deactivating) drops the table and all snapshots. Automatic retention or purging of old snapshots is a later-version feature; until then, if you honor GDPR or CCPA erasure requests, remember that a removed contact's data also lives in the undo journal until the corresponding rows are cleared.

== Known limitations ==
A few things are intentionally scoped for a later release:

* Pro restore re-creates removed contacts from the snapshot as NEW records. Their original contact ids, and any history not held in the snapshot (email opens and clicks, automation position, timestamps), are not restored.
* Bulk merge is synchronous and capped per run (default 50 sets, filterable). Background processing of very large lists is a later-version feature.
* Pro can move a removed contact's GUEST WooCommerce orders onto the surviving contact's WordPress account. It is deliberately narrow: only sets matched on email, only orders placed as a guest, and only the customer on the order. The billing email is never rewritten, because it is printed on an issued invoice. It is refused outright when WooCommerce Subscriptions is active, because a renewal takes its customer from the subscription and not from the original order, so moving orders could not be undone.
* Reverting a WooCommerce move puts the order rows and download permissions back exactly. It does not put back analytics attribution for an order returning to guest, refund analytics rows, download log history, or anything another plugin did in response. The confirmation screen lists this before you commit.
* Scan filters are capped per run (default 5000 matching contacts, filterable). A capped result says so rather than looking complete.

== Changelog ==
= 1.3.0 =
* Added (Pro): per-field merge. The preview resolves every standard and custom field across the set, fills in anything only one contact has, and asks only where they genuinely disagree, defaulting to the surviving contact's value. A choice names a contact rather than a typed value, so a merge can only ever keep data one of these contacts already had.
* Added (Pro): choose which contacts in a set to merge and which one to keep, instead of merging the whole set under an automatic survivor.
* Added (Pro): scan filters. Narrow a scan by status, name, email, phone, created date, tags, lists and custom fields, using one level of AND/OR groups, before any grouping happens. Large scans are capped and the result says when it is partial.
* Added (Pro): optionally move a removed contact's guest WooCommerce orders onto the surviving contact's WordPress account. Off by default, offered only where it can be done safely and reversed, and refused outright otherwise. See Known limitations.
* Added: duplicate sets are collapsed into an accordion, so a long scan stays readable.
* Fixed (Pro): reverting a merge could not clear a custom field that the merge had filled in. The revert reported success while the field kept its merged value.
* Fixed (Pro): a per-field choice was ignored for any custom field whose name is not plain ASCII, so the merge kept a different value than the one chosen.
* Fixed (Pro): rescanning could re-include contacts that had been unticked, so Merge all could remove a contact that was excluded on screen.
* Fixed (Pro): a revert that was refused before changing anything still marked the merge as reverted, leaving it impossible to revert afterwards.
* Note: the free plan is unchanged in this release.

= 1.2.0 =
* Added: a merge history panel. Every merge is listed and can be verified (free) or restored (Pro), so a bulk merge's sets are each reachable for undo, not only the last merge.
* Added (safety): a duplicate set is refused unless every contact shares the same subscription status, so a merge cannot delete the only unsubscribed, bounced, or complained record for an inbox. A uniformly suppressed set still merges.
* Changed: restore now re-creates a removed contact with its original subscription status instead of a fresh default.
* Changed: user-facing strings are translatable; added privacy disclosure for the undo journal and plugin-header dependency metadata.
* Fixed: the admin page now appears under the FluentCRM menu (corrected the parent menu slug).

= 1.1.0 =
* Added (Pro): one-click restore that re-creates removed contacts and reverts the survivor from a snapshot.
* Added (Pro): bulk merge of many duplicate sets in one action (synchronous, capped).
* Changed: merges now re-read and re-verify each contact server side at merge time, so a set that is no longer a duplicate is refused instead of merged.
* Changed: the admin page now lives under the FluentCRM menu and is restyled to match.

= 1.0.0 =
* Initial release: duplicate detection (normalized email, phone, full name), merge with pre-merge safety snapshot, Vue admin, free and Pro split.

== Copyright ==
Dedup and Merge for FluentCRM is copyright 2026 Lotfi O. (OLDEV), https://oldev.site, and is licensed under GPL-2.0-or-later.
