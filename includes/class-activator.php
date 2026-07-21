<?php

/**
 * FluentCRM Dedup & Merge
 *
 * @package   OLDEV\FcDedup
 * @author    Lotfi O. (OLDEV) <https://oldev.site>
 * @copyright 2026 Lotfi O. / OLDEV
 * @license   GPL-2.0-or-later
 * @link      https://oldev.site
 */

namespace OLDEV\FcDedup;

defined('ABSPATH') || exit;

/**
 * Runs once on plugin activation: creates the undo-journal table via
 * dbDelta so it exists before the plugin performs its first merge.
 * dbDelta is idempotent, so re-activation is safe.
 */
final class Activator
{
    public static function activate(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fcdm_undo_journal';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            survivor_id BIGINT UNSIGNED NOT NULL,
            loser_ids TEXT NOT NULL,
            payload LONGTEXT NOT NULL,
            restored TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Cleanup on plugin deletion. Registered on Freemius's `after_uninstall` hook
     * from the main file rather than via a standalone uninstall.php (which Freemius
     * disallows, since it needs to run its own uninstall alongside ours). Drops the
     * undo-journal table; Freemius loads the plugin before firing this, so the class
     * is available.
     */
    public static function uninstall(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fcdm_undo_journal';
        // Table name derives from $wpdb->prefix and a fixed constant suffix; nothing
        // user-controlled is interpolated, and table identifiers cannot be prepared.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }
}
