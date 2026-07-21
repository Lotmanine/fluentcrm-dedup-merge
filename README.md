# Dedup and Merge for FluentCRM

Find and merge duplicate [FluentCRM](https://fluentcrm.com/) contacts, reversibly. Detection is by normalized email, phone, and full name, and every merge writes a full snapshot to an undo journal before anything changes.

![Version](https://img.shields.io/badge/version-1.2.0-10b981)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-3b82f6)
![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-1e293b)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4)
![FluentCRM](https://img.shields.io/badge/requires-FluentCRM-10b981)

> This repository holds the **free** version. The Pro features (one-click restore and bulk merge) are distributed through [Freemius](https://checkout.freemius.com/plugin/35095/plan/57679/). See [Free vs Pro](#free-vs-pro) below.

**Landing page and full details:** https://fluentcrm-dedup.oldev.site

---

## Why

FluentCRM keeps one contact per email address, so the same person can still end up as several contacts: gmail dot and plus aliases, a second email, a shared phone number. Cleaning that up by hand means picking a survivor, moving its tags and lists, then deleting the rest, with no way to take it back. This plugin makes that operation safe and reversible.

## Safety first

Four guarantees run on every merge, in the free version and Pro alike.

- **A snapshot before anything changes.** A full snapshot of every contact in the set is written to an undo journal before the first record is touched. If the snapshot cannot be recorded, the merge is aborted.
- **Never merges across subscription status.** A subscribed contact and an unsubscribed, bounced, or complained alias are never merged automatically, so a cleanup cannot resurrect someone who opted out.
- **Re-read and re-verified at merge time.** The contacts are read fresh from the database and re-checked the moment you confirm, so a set that no longer qualifies is refused rather than merged on a stale preview.
- **A history you can walk back.** Every merge is listed. The free version verifies that its snapshot exists; Pro restores it with one click.

## How it finds duplicates

Every match is exact once the noise is normalized away. No fuzzy matching.

- **Email:** gmail dots and plus aliases collapse to one address, googlemail is treated like gmail, and casing and whitespace are handled.
- **Phone:** digits only, with placeholder and repeated-digit junk rejected.
- **Name:** first and last, both required, case-insensitive.

On merge, the survivor keeps the union of every tag and list, and empty custom fields are gap-filled from the records being removed.

## Free vs Pro

| | Free | Pro |
| --- | :---: | :---: |
| Scan by email, phone, and name | Yes | Yes |
| Single-set merge with tag, list, and field consolidation | Yes | Yes |
| Snapshot before every merge | Yes | Yes |
| Mixed-status merges refused | Yes | Yes |
| Merge history with snapshot verification | Yes | Yes |
| One-click restore of any merge | No | Yes |
| Bulk merge of many sets in one action | No | Yes |
| Updates and support | No | Yes |

Pro is 49 USD per year for a single site and 129 USD per year for up to ten sites, with a 14-day free trial and no card required. [Start a trial or view pricing.](https://checkout.freemius.com/plugin/35095/plan/57679/)

## Requirements

- WordPress 6.4 or newer
- PHP 8.2 or newer
- FluentCRM (the free version is enough) active on the site

## Installation

1. Download the latest free build from the [landing page](https://fluentcrm-dedup.oldev.site) or from this repository.
2. In WordPress, go to Plugins, Add New, Upload Plugin, and select the zip.
3. Activate it. The admin page appears under the FluentCRM menu as "Dedup &amp; Merge".

## Author

Built by **Lotfi O.** at **OLDEV**, a WordPress engineering, CRM, and email deliverability practice.

- Website: [oldev.site](https://oldev.site)
- Plugin page: [fluentcrm-dedup.oldev.site](https://fluentcrm-dedup.oldev.site)
- LinkedIn: [linkedin.com/in/lotfi-otmanine](https://www.linkedin.com/in/lotfi-otmanine/)
- Upwork: [upwork.com/freelancers/~01bd748fcb665b90ca](https://www.upwork.com/freelancers/~01bd748fcb665b90ca)
- GitHub: [github.com/Lotmanine](https://github.com/Lotmanine)

If you need a CRM cleaned up, migrated, or a custom FluentCRM build, I take that work through the links above.

## License

GPL-2.0-or-later. Copyright 2026 Lotfi O. (OLDEV). See [LICENSE](LICENSE).
