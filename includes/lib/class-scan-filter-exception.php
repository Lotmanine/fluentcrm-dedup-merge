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
 * Raised when a scan-filter payload cannot be compiled into SQL: an unknown field, an
 * operator that field does not support, a value of the wrong type, a group that does not
 * combine with AND or OR, or nesting deeper than the one level the wire contract allows.
 *
 * WHY THIS IS AN EXCEPTION AND NOT A SKIPPED CONDITION. The obvious alternative is to
 * drop whatever cannot be understood and compile the rest. That would be the worst
 * possible behavior here: the operator would be shown a list of duplicate sets that does
 * not match the filter they built, on a screen whose next button deletes contacts. A
 * filter that narrows LESS than they asked for puts contacts in front of them that they
 * explicitly excluded. Every failure is therefore fatal to the request, and the REST
 * layer answers a clean 400 rather than a partial result.
 *
 * It extends InvalidArgumentException for the same reason FieldChoiceException does: a
 * caller that only catches InvalidArgumentException still degrades to a 400 instead of
 * letting the throw escape as a 500.
 *
 * WHY THE MESSAGES LIVE HERE, AS NAMED CONSTRUCTORS. They are user-facing and
 * translated, so they belong with the type that carries them rather than being scattered
 * through the compiler; the compiler then reads as `throw ScanFilterException::
 * unknownField()`, which says what went wrong without a wall of message text in the
 * middle of the SQL logic. It also keeps WPCS's escape-output sniff satisfied, which
 * treats any function call inside a `throw new ...()` as unescaped output - and the right
 * answer is emphatically NOT to run these through esc_html__(), because they are returned
 * as JSON, where HTML entities would show up literally in the operator's error message.
 *
 * NONE of these interpolate any part of the payload. The field id, operator and value in
 * a rejected condition are attacker-controlled text that would otherwise land in an error
 * log and, on a debug install, in an HTTP response.
 */
final class ScanFilterException extends \InvalidArgumentException
{
    /**
     * The payload is not shaped like a filter at all (a scalar where an object belongs, a
     * `groups` key that is not a list, a condition that is not an object).
     */
    public static function malformed(): self
    {
        return new self(__(
            'The filter could not be read. Please rebuild it and try again.',
            'dedup-and-merge-for-fluentcrm'
        ));
    }

    /**
     * A group inside a group, or a condition carrying its own conditions. The contract
     * allows exactly one level, and flattening the extra level would change what the
     * operator asked for without telling them.
     */
    public static function nesting(): self
    {
        return new self(__(
            'Filter groups cannot be nested more than one level deep.',
            'dedup-and-merge-for-fluentcrm'
        ));
    }

    /**
     * A field that is not on the allowlist, or a custom field slug FluentCRM does not
     * report. Refused rather than ignored: a dropped condition is a silently wider scan.
     */
    public static function unknownField(): self
    {
        return new self(__(
            'This filter refers to a field that is not available.',
            'dedup-and-merge-for-fluentcrm'
        ));
    }

    /**
     * A comparison that field does not support (for example `contains` on a date).
     */
    public static function unknownOperator(): self
    {
        return new self(__(
            'This filter uses a comparison that is not supported for that field.',
            'dedup-and-merge-for-fluentcrm'
        ));
    }

    /**
     * A `match` that is present but is neither AND nor OR.
     */
    public static function badMatch(): self
    {
        return new self(__(
            'A filter group must combine its conditions with AND or OR.',
            'dedup-and-merge-for-fluentcrm'
        ));
    }

    /**
     * The right field and operator, the wrong kind of value: a number where a search term
     * belongs, a string where a list of ids belongs, an empty id list.
     */
    public static function valueType(): self
    {
        return new self(__(
            'One of the filter conditions has a value of the wrong type.',
            'dedup-and-merge-for-fluentcrm'
        ));
    }

    /**
     * A value-taking operator with a blank value. Kept distinct from valueType() because
     * it is the one an operator hits by accident, and because the fix is different: the
     * empty case has its own operators (is_empty / is_not_empty).
     */
    public static function missingValue(): self
    {
        return new self(__(
            'One of the filter conditions is missing its value.',
            'dedup-and-merge-for-fluentcrm'
        ));
    }

    /**
     * A date that is not YYYY-MM-DD, or one that is not a real calendar date. Both are
     * refused rather than bound, because MySQL answers an invalid date with an empty
     * result and the operator would be left staring at zero duplicates with no reason why.
     */
    public static function badDate(): self
    {
        return new self(__(
            'A date filter needs a real date in YYYY-MM-DD format.',
            'dedup-and-merge-for-fluentcrm'
        ));
    }

    /**
     * A resource bound, not a correctness rule. See ScanFilter::MAX_CONDITIONS.
     */
    public static function tooManyConditions(): self
    {
        return new self(__(
            'This filter has too many conditions. Please simplify it and try again.',
            'dedup-and-merge-for-fluentcrm'
        ));
    }

    /**
     * A resource bound, not a correctness rule. See ScanFilter::MAX_VALUES_PER_CONDITION.
     */
    public static function tooManyValues(): self
    {
        return new self(__(
            'This filter selects too many tags or lists at once. Please narrow it and try again.',
            'dedup-and-merge-for-fluentcrm'
        ));
    }
}
