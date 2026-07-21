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

namespace OLDEV\FcDedup\Lib;

// NOTE: this file is eager-loaded by Composer's "files" autoload the instant
// vendor/autoload.php is first touched (e.g. by vendor/bin/phpunit itself,
// before tests/bootstrap.php runs and defines OLDEV_FCDEDUP_TEST). The
// OLDEV_FCDEDUP_TEST check alone therefore cannot gate this file the way it
// gates lazily-autoloaded class files. PHP_SAPI === 'cli' is added because a
// direct-HTTP hit (the scenario this guard defends against) can never occur
// under the CLI SAPI, so this preserves the guard's intent without an
// exit-before-define race. See task-6-report.md for full analysis.
defined('ABSPATH') || defined('OLDEV_FCDEDUP_TEST') || PHP_SAPI === 'cli' || exit;
if (!function_exists('OLDEV\FcDedup\Lib\wp_json_encode_safe')) {
    /**
     * Encode the undo-journal payload, failing LOUDLY on encode failure.
     *
     * The journal is the safety net recorded before a destructive merge; a
     * silently empty snapshot that reports success would be worse than no
     * snapshot at all, so an unencodable payload aborts the merge instead.
     *
     * @throws SnapshotException When the payload cannot be JSON-encoded.
     */
    function wp_json_encode_safe(mixed $data): string
    {
        $json = function_exists('wp_json_encode') ? wp_json_encode($data) : json_encode($data);
        if (!is_string($json)) {
            throw new SnapshotException(
                'Failed to JSON-encode the undo-journal payload; aborting so no merge proceeds without a restorable snapshot.'
            );
        }
        return $json;
    }
}
