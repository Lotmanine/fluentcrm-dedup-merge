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
 * Raised when a Pro field-aware request names a contact that is not part of the
 * merge: an explicit survivor id outside the set, or a per-field override pointing
 * at a contact that was never submitted.
 *
 * It extends InvalidArgumentException on purpose. The REST layer already answers a
 * clean 400 for InvalidArgumentException (malformed rows), so an unpatched catch
 * block still degrades to a 400 rather than a 500. Having its own type only lets
 * the controller pick a more accurate user-facing message, and lets the merge core
 * distinguish "your selection is wrong" from "your payload is garbage" - the first
 * is a normal, recoverable user mistake, the second is a client bug.
 *
 * This distinction matters for a destructive endpoint: a rejected field choice must
 * never be confused with a partial write, so it is thrown before anything is read or
 * written and is always fatal to the request.
 */
final class FieldChoiceException extends \InvalidArgumentException
{
}
