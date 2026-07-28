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
 * The allowlist of fc_subscribers columns a merge may write on the survivor, plus
 * the emptiness and labelling rules that go with them. Single source of truth for
 * the Merger (which builds per-field candidates), the gateway (which writes them),
 * the REST controller (which snapshots them) and the Restorer (which reverts them),
 * so those four can never drift apart on what "a standard field" means.
 *
 * WHY AN ALLOWLIST AND NOT "every fillable column".
 * fc_subscribers has 30 columns in FluentCRM 2.9.87 and Subscriber::$fillable covers
 * 28 of them, but most of the remainder are owned by something other than the contact
 * record itself. Writing them from a merge would either corrupt state the merge has no
 * business touching or, worse, silently succeed and desynchronise a related table. The
 * columns below are the ones that are (a) in $fillable, (b) sanitized with
 * sanitize_text_field in FluentCRM's own Sanitize::contact() - which is why this class
 * sanitizes them the same way instead of calling that internal class - and (c) entered
 * by a person rather than derived by FluentCRM.
 *
 * DELIBERATELY NEVER WRITTEN, with the reason for each:
 *   id               - primary key.
 *   hash             - rewritten to md5(email) by the model's own `saving` hook on every
 *                      save, so any value we wrote would be discarded anyway.
 *   email            - UNIQUE key and the identity anchor of the whole merge. Changing it
 *                      would change which contact this even is.
 *   user_id          - the WordPress user link. FluentCRM NULLs it on every other
 *                      subscriber when it is set, so a raw write can detach an unrelated
 *                      contact from its WP user.
 *   contact_owner    - not in $fillable, therefore physically unwritable through the model
 *                      (see the getDirty() note below); a write is a silent no-op.
 *   company_id       - must go through attachCompanies(); a raw write desynchronises the
 *                      fc_subscriber_pivot rows that mirror it.
 *   status           - CONSENT. Already protected by the uniform-status refusal in
 *                      DuplicateKey::uniformStatus(); a merge must never move a contact
 *                      between mailable and suppressed.
 *   contact_type     - maintained by FluentCRM's commerce integration (lead/customer).
 *   source           - write-once provenance. FluentCRM itself strips it on update.
 *   total_points     - counter maintained by the gamification/points integration.
 *   life_time_value  - counter maintained by the commerce integration.
 *   created_at       - audit timestamp; rewriting it would falsify the record's age, which
 *                      is also the input to the "oldest" survivor strategy.
 *   last_activity    - maintained by FluentCRM's activity tracking.
 *   updated_at       - maintained by the ORM.
 *   avatar, ip       - derived / request provenance, out of scope for this sub-project.
 *
 * A NOTE ON SILENT NO-OPS. Subscriber::getDirty() is overridden in FluentCRM to filter
 * its result down to $fillable, and the ORM builds its UPDATE from getDirty(). Setting a
 * non-fillable attribute therefore produces no SQL at all: no error, no warning, no write.
 * That is precisely why this allowlist is enforced here rather than trusted to the model.
 */
final class StandardFields
{
    /**
     * The 12 writable columns, in the fixed order the field-candidate list uses.
     * Order is part of the wire contract (standard fields first, in this order),
     * so reordering this constant changes what the admin UI shows.
     *
     * latitude and longitude are deliberately NOT here, for two reasons. They are
     * derived geolocation that FluentCRM writes alongside `ip` when it logs contact
     * activity, so they belong with the provenance columns above rather than with
     * operator-entered profile data - keeping them while excluding `ip` was
     * inconsistent. And they are DECIMAL(10,8) NULL, so a restore putting an
     * originally-NULL value back would write '' into a decimal column: WordPress
     * strips STRICT_TRANS_TABLES from the session sql_mode, so MySQL coerces that to
     * 0.00000000 instead of rejecting it. The survivor would be left on the equator,
     * the restore would still report success, and 0.00000000 is not empty, so the
     * bogus coordinate would go on to compete as a real value in the next merge.
     */
    public const COLUMNS = [
        'prefix',
        'first_name',
        'last_name',
        'phone',
        'timezone',
        'address_line_1',
        'address_line_2',
        'postal_code',
        'city',
        'state',
        'country',
        'date_of_birth',
    ];

    /**
     * Per-request cache of the allowlist intersected with the columns that really
     * exist, keyed by table name so a test (or a multisite switch_to_blog) that
     * changes the prefix gets its own probe instead of a stale answer.
     *
     * @var array<string, list<string>>
     */
    private static array $existingColumns = [];

    /**
     * Human labels for the field picker, in COLUMNS order.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'prefix'         => __('Prefix', 'dedup-and-merge-for-fluentcrm'),
            'first_name'     => __('First name', 'dedup-and-merge-for-fluentcrm'),
            'last_name'      => __('Last name', 'dedup-and-merge-for-fluentcrm'),
            'phone'          => __('Phone', 'dedup-and-merge-for-fluentcrm'),
            'timezone'       => __('Timezone', 'dedup-and-merge-for-fluentcrm'),
            'address_line_1' => __('Address line 1', 'dedup-and-merge-for-fluentcrm'),
            'address_line_2' => __('Address line 2', 'dedup-and-merge-for-fluentcrm'),
            'postal_code'    => __('Postal code', 'dedup-and-merge-for-fluentcrm'),
            'city'           => __('City', 'dedup-and-merge-for-fluentcrm'),
            'state'          => __('State', 'dedup-and-merge-for-fluentcrm'),
            'country'        => __('Country', 'dedup-and-merge-for-fluentcrm'),
            'date_of_birth'  => __('Date of birth', 'dedup-and-merge-for-fluentcrm'),
        ];
    }

    /**
     * Label for one allowlisted column, falling back to a humanised column name so an
     * unlabelled column can still be displayed rather than rendering as an empty row.
     */
    public static function label(string $column): string
    {
        return self::labels()[$column] ?? self::humanize($column);
    }

    /**
     * Turn a machine key into something displayable. Used as the FALLBACK label for a
     * custom field whose configured label cannot be read - see
     * FluentCrmGateway::customFieldLabels(), which is the primary source. A humanised
     * slug is honest and cannot break, so it is what we show when FluentCRM's own
     * definition list is unavailable or has no entry for the slug.
     */
    public static function humanize(string $key): string
    {
        $label = trim(str_replace(['_', '-'], ' ', $key));
        return $label === '' ? $key : ucfirst($label);
    }

    /**
     * The allowlist intersected with the columns fc_subscribers really has.
     *
     * FluentCRM's migrations only run dbDelta when the table does not already exist, so a
     * long-lived install can genuinely be missing a column that the 2.9.87 CREATE statement
     * declares. Naming such a column in a SELECT or an UPDATE raises "Unknown column" - a
     * fatal in the middle of a destructive merge. Probing once per request and writing only
     * what exists turns that fatal into a field quietly not being merged.
     *
     * If the probe itself fails or returns nothing, this returns an empty list: no standard
     * field is read or written at all. That is the deliberate choice - skipping the optional
     * part of a merge is recoverable, guessing at the schema is not.
     *
     * @param  \wpdb $wpdb WordPress DB layer, or a test double exposing prefix and get_col().
     * @return list<string>
     */
    public static function existingColumns(object $wpdb): array
    {
        $table = $wpdb->prefix . 'fc_subscribers';
        if (isset(self::$existingColumns[$table])) {
            return self::$existingColumns[$table];
        }
        // The table name derives from $wpdb->prefix; nothing user-controlled is interpolated,
        // and SHOW COLUMNS takes no bindable parameters so there is nothing to prepare.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, Generic.Files.LineLength.TooLong
        $found = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        $have  = array_map('strval', $found);

        self::$existingColumns[$table] = array_values(array_intersect(self::COLUMNS, $have));
        return self::$existingColumns[$table];
    }

    /**
     * Whether a value counts as "no value" when collecting merge candidates.
     *
     * Emptiness is decided here rather than with empty() because empty() also swallows
     * the string "0", and "0" is a legitimate value for several of these columns (a
     * postal code, a phone extension). Losing it would silently drop real data.
     *
     * The date_of_birth special case is FluentCRM's own convention: it writes the
     * sentinel '0000-00-00' when a date of birth is cleared rather than NULL, so that
     * sentinel means empty and must not be offered as a value to merge.
     *
     * @param string $column The allowlisted column name, or '' for a custom field.
     * @param mixed  $value  Raw value as read from the row.
     */
    public static function isEmptyValue(string $column, mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_array($value)) {
            return $value === [];
        }
        if (!is_scalar($value)) {
            return true;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return true;
        }
        if ($column === 'date_of_birth' && ($text === '0000-00-00' || $text === '0000-00-00 00:00:00')) {
            return true;
        }
        return false;
    }
}
