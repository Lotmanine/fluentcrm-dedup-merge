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

defined('ABSPATH') || defined('OLDEV_FCDEDUP_TEST') || exit;

/**
 * Thrown when the pre-merge undo snapshot cannot be created: the payload cannot
 * be JSON-encoded, or the journal row cannot be written to the database.
 *
 * The merge path lets this propagate so a destructive merge never runs without
 * a restorable snapshot. The REST layer catches THIS type specifically and
 * returns a clean error, without swallowing unrelated third-party exceptions
 * (whose messages can carry SQL and contact PII).
 */
final class SnapshotException extends \RuntimeException
{
}
