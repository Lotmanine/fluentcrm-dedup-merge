<?php

/**
 * Plugin Name:       Dedup and Merge for FluentCRM
 * Plugin URI:        https://github.com/Lotmanine/fluentcrm-dedup-merge
 * Description:       Detect and merge duplicate FluentCRM contacts, reversibly, consolidating tags, lists, and fields onto a survivor.
 * Version:           1.3.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Requires Plugins:  fluent-crm
 * Author:            Lotfi O.
 * Author URI:        https://oldev.site
 * License:           GPL-2.0-or-later
 * Text Domain:       dedup-and-merge-for-fluentcrm
 * Domain Path:       /languages
 *
 * @author    Lotfi O. (OLDEV) <https://oldev.site>
 * @copyright 2026 Lotfi O. / OLDEV
 * @license   GPL-2.0-or-later
 *
 * Changelog:
 * 1.3.0 - 2026-07-27
 *   Added (Pro): per-field merge. The preview now resolves every standard and custom
 *   field across the set, filling in anything only one contact has and asking only
 *   where they genuinely disagree, defaulting to the surviving contact's value. A
 *   choice names a contact, never a typed value, so a merge can only ever keep data
 *   one of these contacts already had.
 *   Added (Pro): choose which contacts to merge and which one to keep, instead of
 *   merging the whole set under an automatic survivor.
 *   Added (Pro): scan filters. Narrow a scan by status, name, email, phone, created
 *   date, tags, lists and custom fields, with one level of AND/OR groups, before any
 *   grouping happens. Large result sets are capped and the result says so.
 *   Added (Pro): optionally move a removed contact's guest WooCommerce orders onto the
 *   surviving contact's WordPress account. Off by default, offered only where it can be
 *   done safely and reversed, and refused outright otherwise.
 *   Added: duplicate sets are collapsed into an accordion so a long scan stays readable.
 *   Fixed: reverting a merge could not clear a custom field it had filled in. Restore
 *   reported success while the field kept the merged value.
 *   Fixed: a per-field choice was ignored for any custom field whose name is not plain
 *   ASCII, so the merge kept a different value than the one picked.
 *   Fixed: rescanning could re-include contacts that had been unticked, so Merge all
 *   could remove a contact that was excluded on screen.
 *   Fixed: a refused revert marked the merge as already reverted, leaving it impossible
 *   to revert afterwards.
 * 1.2.0 - 2026-07-21
 *   Added: merge history panel with per-row undo, so every merge (including each
 *   set from a bulk run) is reachable for restore, not just the last one.
 *   Changed: a duplicate set is refused (409) unless all members share one
 *   subscription status, so a merge can never delete the only unsubscribed or
 *   bounced record for an inbox; restore now re-creates a loser with its original
 *   status. i18n, GDPR/privacy disclosure, and plugin-header dependency metadata.
 *   Fixed: the admin page now appears under the FluentCRM menu (parent slug is
 *   fluentcrm-admin, not fluentcrm).
 * 1.1.0 - 2026-07-06
 *   Added: Pro one-click restore and Pro bulk merge; server-side re-read and re-verify
 *   at merge time; FluentCRM submenu placement and a matching restyle.
 * 1.0.0 - 2026-07-05
 *   Added: duplicate detection (normalized email, phone, name), reversible merge with undo journal, Vue admin, free/Pro split.
 */
defined( 'ABSPATH' ) || exit;
if ( function_exists( 'fdm_fs' ) ) {
    // A sibling copy (free or premium) already booted Freemius. Hand this file to the
    // SDK so it deactivates the redundant version, and do NOT load the plugin twice -
    // loading both would fatally redeclare the plugin's classes.
    fdm_fs()->set_basename( false, __FILE__ );
} else {
    if ( !function_exists( 'fdm_fs' ) ) {
        /**
         * Freemius SDK accessor (product 35095). The SDK is bundled in /freemius and is
         * the source of truth for Pro entitlement (see Admin\LicenseGate). No secret key
         * here: it belongs only in the deploy tool / a test site's wp-config, never in the
         * shipped plugin.
         *
         * @return \Freemius
         */
        function fdm_fs() {
            global $fdm_fs;
            if ( !isset( $fdm_fs ) ) {
                require_once __DIR__ . '/freemius/start.php';
                $fdm_fs = fs_dynamic_init( array(
                    'id'               => '35095',
                    'slug'             => 'dedup-and-merge-for-fluentcrm',
                    'premium_slug'     => 'dedup-and-merge-for-fluentcrm-premium',
                    'type'             => 'plugin',
                    'public_key'       => 'pk_68199d7859210ba40e71a1ff49571',
                    'is_premium'       => false,
                    'premium_suffix'   => 'Pro',
                    'has_addons'       => false,
                    'has_paid_plans'   => true,
                    'is_org_compliant' => true,
                    'trial'            => array(
                        'days'               => 14,
                        'is_require_payment' => false,
                    ),
                    'menu'             => array(
                        'slug'    => 'fcdm-dedup',
                        'support' => false,
                        'parent'  => array(
                            'slug' => 'fluentcrm-admin',
                        ),
                    ),
                    'is_live'          => true,
                ) );
            }
            return $fdm_fs;
        }

        fdm_fs();
        do_action( 'fdm_fs_loaded' );
    }
    /*
     * Deterministic runtime loader (deliberate, not an oversight).
     *
     * This plugin ships with zero Composer runtime dependencies: composer.json only
     * declares dev tooling (PHPUnit, PHPStan, WPCS) and vendor/ is never packaged into
     * the distributed zip. Requiring vendor/autoload.php here would fatal on a production
     * install. Instead every runtime file is require_once'd explicitly below, in
     * dependency order, so the plugin is a self-contained runtime with no autoloader of
     * its own. The classmap autoload in composer.json exists only for local tooling / CI.
     */
    require_once __DIR__ . '/includes/config.php';
    require_once __DIR__ . '/includes/lib/class-snapshot-exception.php';
    require_once __DIR__ . '/includes/lib/class-field-choice-exception.php';
    require_once __DIR__ . '/includes/lib/class-scan-filter-exception.php';
    // WooRepointException and the WooRepointHandler interface are free-shipped (no
    // __premium_only suffix) for the same reason ScanFilter is: only a Pro request ever
    // throws or implements them, but the FREE build has to be able to NAME them. Merger
    // type-hints the interface and the free RestController catches the exception, and the
    // generated free build DELETES premium-only files - a type hint or a catch on a deleted
    // class fatals the free tier's own merge route. Everything that actually touches
    // WooCommerce stays behind the fence below.
    require_once __DIR__ . '/includes/lib/class-woo-repoint-exception.php';
    require_once __DIR__ . '/includes/lib/interface-woo-repoint-handler.php';
    require_once __DIR__ . '/includes/lib/helpers.php';
    require_once __DIR__ . '/includes/lib/class-email-normalizer.php';
    require_once __DIR__ . '/includes/lib/class-survivor-selector.php';
    require_once __DIR__ . '/includes/lib/class-standard-fields.php';
    require_once __DIR__ . '/includes/lib/class-fluentcrm-gateway.php';
    require_once __DIR__ . '/includes/lib/class-undo-journal.php';
    require_once __DIR__ . '/includes/lib/class-duplicate-key.php';
    // ScanFilter is free-shipped (no __premium_only suffix) on purpose: only a Pro
    // request ever builds one, but DuplicateDetector type-hints it, and the generated
    // free build DELETES premium-only files. A stripped type hint would fatal the free
    // tier's own scan. Required before the detector so the chain reads in dependency order.
    require_once __DIR__ . '/includes/lib/class-scan-filter.php';
    require_once __DIR__ . '/includes/lib/class-duplicate-detector.php';
    require_once __DIR__ . '/includes/lib/class-merger.php';
    require_once __DIR__ . '/includes/admin/class-license-gate.php';
    require_once __DIR__ . '/includes/admin/class-rest-controller.php';
    require_once __DIR__ . '/includes/admin/class-admin-menu.php';
    require_once __DIR__ . '/includes/class-activator.php';
    require_once __DIR__ . '/includes/class-plugin.php';
    register_activation_hook( __FILE__, ['OLDEV\\FcDedup\\Activator', 'activate'] );
    // Freemius disallows a standalone uninstall.php (it runs its own uninstall), so
    // register our table cleanup on its after_uninstall hook instead.
    fdm_fs()->add_action( 'after_uninstall', ['OLDEV\\FcDedup\\Activator', 'uninstall'] );
    add_action( 'plugins_loaded', static function () : void {
        ( new OLDEV\FcDedup\Plugin(__FILE__) )->boot();
    } );
}