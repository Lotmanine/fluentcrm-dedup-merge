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

namespace OLDEV\FcDedup\Admin;

defined('ABSPATH') || exit;

use OLDEV\FcDedup\Lib\{
    DuplicateDetector,
    DuplicateKey,
    FieldChoiceException,
    FluentCrmGateway,
    Merger,
    ScanFilter,
    ScanFilterException,
    SnapshotException,
    StandardFields,
    UndoJournal,
    WooRepointException,
    WooRepointHandler
};

/**
 * Free-tier REST surface. Not final: the Pro controller (RestControllerPro, a
 * premium-only file stripped from the free build) extends this to add the bulk
 * and restore routes, reusing the protected merge core below.
 */
class RestController
{
    /**
     * Ceiling on how many tags or lists the filter builder is offered. A choice list this
     * long is already past the point of being usable in a dropdown, and the cap exists so
     * one admin request cannot serialise an unbounded table.
     */
    protected const FILTER_TERM_LIMIT = 500;

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            $perm = static fn () => current_user_can(OLDEV_FCDEDUP_CAP);
            $ns = OLDEV_FCDEDUP_REST_NS;
            // One route, two methods. GET is the free scan and is unchanged: same callback,
            // same permission, and (because scan() only ever looks at `filters` on a POST)
            // the same SQL and the same response body it has always returned. POST is the
            // Pro filtered scan. Registering them together rather than as two routes keeps
            // a single handler, so the two can never drift into answering differently.
            //
            // No `args` entry for `filters`, for the reason spelled out on fieldAwareArgs():
            // WordPress answers its own rest_invalid_param body, and this plugin's client
            // expects the `{error: ...}` shape. The payload is validated in ScanFilter, which
            // produces exactly that shape via a 400 and can explain WHICH part was refused.
            register_rest_route($ns, '/scan', [
                'methods'             => ['GET', 'POST'],
                'callback'            => [$this, 'scan'],
                'permission_callback' => $perm,
            ]);
            register_rest_route($ns, '/filter-options', [
                'methods'             => 'GET',
                'callback'            => [$this, 'filterOptions'],
                'permission_callback' => $perm,
            ]);
            register_rest_route($ns, '/preview', [
                'methods'             => 'POST',
                'callback'            => [$this, 'preview'],
                'permission_callback' => $perm,
                'args'                => self::fieldAwareArgs(),
            ]);
            register_rest_route($ns, '/merge', [
                'methods'             => 'POST',
                'callback'            => [$this, 'merge'],
                'permission_callback' => $perm,
                'args'                => self::fieldAwareArgs(),
            ]);
            register_rest_route($ns, '/undo', ['methods' => 'POST', 'callback' => [$this, 'undo'], 'permission_callback' => $perm]);
            register_rest_route($ns, '/journal', ['methods' => 'GET', 'callback' => [$this, 'journal'], 'permission_callback' => $perm]);
            register_rest_route($ns, '/meta', ['methods' => 'GET', 'callback' => [$this, 'meta'], 'permission_callback' => $perm]);
        });
    }

    /**
     * REST `args` for the two NEW field-aware parameters, and nothing else.
     *
     * WordPress has recommended declaring validate_callback / sanitize_callback per
     * argument since 5.5, so the new parameters get them. The parameters that were
     * already accepted (set, ids, reason, strategy, include_woo, journal_id, limit)
     * deliberately get no entry here: adding one would run WP's own validation over
     * payloads that today reach the callback untouched and are validated inside it,
     * which could start rejecting requests that currently succeed. Nothing is marked
     * required, for the same reason - both of these are optional by contract, and a
     * free client never sends either.
     *
     * The validation here is STRUCTURAL only ("is this a positive number", "is this an
     * object"). Whether the ids inside actually belong to the merge is decided in the
     * callback, because that is where the `{error: ...}` response shape this plugin's
     * client expects can be produced. A structurally impossible value (a string where a
     * contact id belongs) is refused by WordPress itself, which answers 400 with its own
     * rest_invalid_param body rather than that shape. That is a deliberate trade: on an
     * endpoint that deletes contacts, an unreadable survivor id has to be refused loudly
     * rather than quietly ignored and merged under a different survivor than the one the
     * operator picked. Free callers never reach any of this: the parameters are dropped
     * before use (see fieldAwareParams), so a free merge cannot be failed by either one.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function fieldAwareArgs(): array
    {
        return [
            'survivor_id' => [
                'required'          => false,
                'validate_callback' => static fn ($value): bool => is_numeric($value) && (int) $value > 0,
                'sanitize_callback' => static fn ($value): int => (int) $value,
            ],
            'fields' => [
                'required'          => false,
                // An empty object is valid and meaningful (field-aware mode, no overrides),
                // so the only structural requirement is that it decoded to an array at all.
                'validate_callback' => static fn ($value): bool => is_array($value),
                'sanitize_callback' => static fn ($value): array => self::sanitizeFieldChoices($value),
            ],
        ];
    }

    /**
     * Normalise the field-choice map to `field id => contact id`.
     *
     * Field ids are `std:<column>` / `cf:<slug>` from the wire contract, so sanitize_key()
     * cannot be used: it would eat the colon and collapse `std:city` and `cf:city` into the
     * same key - exactly the collision the prefixes exist to prevent. The character class
     * below keeps the colon and drops everything a column name or a FluentCRM slug can
     * never contain.
     *
     * A non-numeric contact id is normalised to 0 rather than dropped, so it fails the
     * "must be one of the submitted contacts" check and the caller gets a clean 400.
     * Dropping it would silently turn a malformed override into "no override" and merge
     * the wrong value.
     *
     * @param  mixed $value
     * @return array<string, int>
     */
    protected static function sanitizeFieldChoices($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $fieldId => $contactId) {
            // `%` is allowed because a FluentCRM custom-field slug legitimately contains it.
            // FluentCRM builds slugs with sanitize_title($label, 'custom_field', 'view'), and
            // because the context is 'view' rather than 'save', WordPress does NOT run
            // remove_accents(): sanitize_title_with_dashes() percent-encodes the UTF-8 and its
            // final character class, /[^%a-z0-9 _-]/, keeps the percent signs. So a field
            // labelled "Societe" with an accent has the slug soci%c3%a9t%c3%a9.
            //
            // Stripping `%` here silently rewrote that key, the rewritten key matched no
            // candidate, and the operator's per-field choice was dropped without a word - the
            // merge then applied the survivor's value instead of the one they picked, and
            // deleted the contact that held the picked value. Any install whose custom-field
            // labels are not pure ASCII was affected.
            //
            // The key is only ever used as an array lookup against server-generated candidate
            // ids; custom field values are written through FluentCRM's model, never through a
            // query built from this string.
            $key = (string) preg_replace('/[^a-z0-9_:%\-]/', '', strtolower(trim((string) $fieldId)));
            if ($key === '') {
                continue;
            }
            $out[$key] = is_numeric($contactId) ? (int) $contactId : 0;
        }
        return $out;
    }

    /**
     * Extract the Pro-only survivor override and field-choice map from a request.
     *
     * Both are silently IGNORED when the site is not Pro. Not rejected: a free client that
     * somehow sends them (a stale bundle after a downgrade, a hand-rolled request) gets
     * exactly the free result rather than an error it cannot act on, which is what the
     * contract specifies.
     *
     * has_param() rather than get_param() is what separates an omitted `fields` key from an
     * empty object `{}`. Per the contract `{}` means "field-aware mode, resolve everything
     * automatically, show me the candidates", while omitting the key keeps the free
     * gap-fill response. get_param() returns null for both and so cannot tell them apart.
     *
     * @return array{0: int|null, 1: array<string, int>|null} [survivor id, field choices]
     */
    protected function fieldAwareParams(\WP_REST_Request $r): array
    {
        if (!(new LicenseGate())->isPro()) {
            return [null, null];
        }

        $survivorId = null;
        $raw = $r->get_param('survivor_id');
        if (is_numeric($raw) && (int) $raw > 0) {
            $survivorId = (int) $raw;
        }

        $fieldChoices = null;
        if ($r->has_param('fields')) {
            $fieldChoices = self::sanitizeFieldChoices($r->get_param('fields'));
        }

        return [$survivorId, $fieldChoices];
    }

    private function detector(): DuplicateDetector
    {
        global $wpdb;
        return new DuplicateDetector($wpdb, $wpdb->prefix . 'fc_subscribers');
    }

    protected function merger(): Merger
    {
        global $wpdb;
        return new Merger(new FluentCrmGateway(), new UndoJournal($wpdb));
    }

    /**
     * The `woo` section of a /preview response, or null when there is none.
     *
     * FREE TIER: ALWAYS NULL, and that is the whole implementation. The WooCommerce order
     * re-point is Pro, and the Freemius free build physically deletes every file that
     * implements it, so the free controller cannot so much as name those classes. Overriding
     * this in RestControllerPro (which the free build also deletes, along with the fence
     * that loads it) is what keeps the two tiers from sharing a code path that only one of
     * them can execute.
     *
     * @param  array<string, mixed> $set The duplicate set, with server-read rows.
     * @return array<string, mixed>|null
     */
    protected function wooPreviewSection(array $set, int $survivorId): ?array
    {
        unset($set, $survivorId);
        return null;
    }

    /**
     * The WooCommerce re-point step for one merge, or null when this merge does not include
     * one - which is every merge on the free tier, and every Pro merge where the operator
     * did not tick the box.
     *
     * FREE TIER: ALWAYS NULL. See wooPreviewSection() for why the branch lives in an
     * overridable method rather than in an `if`.
     *
     * @param  array<string, mixed> $set
     * @throws WooRepointException When include_woo was asked for on a set that refuses it.
     */
    protected function wooMergeHandler(array $set, int $survivorId, bool $includeWoo): ?WooRepointHandler
    {
        unset($set, $survivorId, $includeWoo);
        return null;
    }

    /**
     * Duplicate sets, optionally narrowed by a (Pro) filter.
     *
     * THE FREE PATH IS THE FIRST BRANCH AND NOTHING ELSE RUNS ON IT. A GET, a free
     * install, or a POST carrying no `filters` key all take it, and it is the v1.2 code
     * verbatim: one detect() with no filter (so DuplicateDetector issues the identical
     * unfiltered statement), the same per-row enrichment, and a body with exactly the one
     * `sets` key. The extra keys the contract adds are only ever added on the second
     * branch, so a free client's parser sees nothing new.
     *
     * A free client that somehow sends `filters` (a stale bundle after a downgrade, a
     * hand-rolled request) is not refused. It is silently given the free scan, which is
     * what the contract specifies and what the field-aware merge parameters already do:
     * an error a free UI has no way to act on is worse than the correct free answer.
     */
    public function scan(\WP_REST_Request $r): \WP_REST_Response
    {
        $payload = $this->scanFilterPayload($r);
        if ($payload === null) {
            return new \WP_REST_Response(['sets' => $this->enrichSets($this->detector()->detect())], 200);
        }

        global $wpdb;
        try {
            $filter = ScanFilter::compile(
                $payload,
                $wpdb->prefix,
                // esc_like is handed in as a callable rather than reached for inside
                // ScanFilter, which is what lets the whole compiler be unit-tested without
                // a database - and the SQL correctness is the entire risk of this feature.
                static fn (string $text): string => $wpdb->esc_like($text),
                $this->customFieldMultiMap(),
                self::pivotObjectTypeMap()
            );
        } catch (ScanFilterException $e) {
            // Every validation failure lands here as a clean 400 in the `{error}` shape,
            // never a 500 and never a quietly dropped condition. The message is already
            // translated and carries no part of the payload.
            return new \WP_REST_Response(['error' => $e->getMessage()], 400);
        }

        $cap    = (int) apply_filters('fcdm_scan_candidate_cap', DuplicateDetector::DEFAULT_CANDIDATE_CAP);
        $result = $this->detector()->scan($filter, $cap);

        $body = ['sets' => $this->enrichSets($result['sets'])];

        // The scan-level keys describe a CAPPED, NARROWED query, so they are added only
        // when one actually ran. A filter that compiles to no narrowing (an empty object,
        // empty groups, or groups holding no conditions) takes the unfiltered branch in
        // the detector, which applies no LIMIT at all - reporting `cap` beside it produced
        // the self-contradiction `scanned: 12000, capped: false, cap: 5000`, where a
        // consumer comparing the two numbers would conclude the result was truncated when
        // nothing was. The contract also says a filter that narrows nothing must behave
        // exactly like an unfiltered scan, and the response body is part of behaving.
        if ($filter !== null && !$filter->isEmpty()) {
            // Reported so the UI can say the result is partial instead of implying that
            // these are all the duplicates on the site.
            $body['scanned'] = $result['scanned'];
            $body['capped']  = $result['capped'];
            $body['cap']     = $result['cap'];
        }

        return new \WP_REST_Response($body, 200);
    }

    /**
     * The raw `filters` value for this request, or null when filters do not apply.
     *
     * Three gates, in this order and all of them deliberate:
     *
     * 1. POST ONLY. The contract states that `GET /scan` is unchanged and carries no
     *    filters. Keying off the method rather than off the presence of the parameter is
     *    what enforces it: WP_REST_Request::get_param() also reads the query string, so
     *    without this check a `?filters=` on a GET would narrow the free tier's own route
     *    on a Pro install.
     *    One honest caveat, so nobody reads more into this than it says: WordPress applies
     *    the `_method` / X-HTTP-Method-Override escape hatch to any request, so a
     *    `GET ...?_method=POST&filters[...]=` IS dispatched as a POST and does reach this
     *    branch. That is core's documented behaviour and the request is semantically a
     *    POST by then; it still has to clear the capability check and the Pro gate, so it
     *    grants nobody anything they could not already do by sending a real POST.
     * 2. PRO ONLY, silently. See scan() for why this ignores rather than errors.
     * 3. has_param() rather than get_param(), so an omitted key is distinguishable from a
     *    present one. A present-but-null `filters` returns null here and takes the free
     *    branch, which is the same outcome the contract gives an absent key.
     */
    protected function scanFilterPayload(\WP_REST_Request $r): mixed
    {
        if ($r->get_method() !== 'POST') {
            return null;
        }
        if (!(new LicenseGate())->isPro()) {
            return null;
        }
        if (!$r->has_param('filters')) {
            return null;
        }
        return $r->get_param('filters');
    }

    /**
     * Attach the per-contact detail the scan payload carries, in place.
     *
     * Lifted verbatim out of scan() so the filtered and unfiltered branches enrich
     * identically. Both the shape and the per-row query count are unchanged from v1.2.
     *
     * @param  list<array{key: string, reason: string, rows: list<array<string, mixed>>}> $sets
     * @return list<array{key: string, reason: string, rows: list<array<string, mixed>>}>
     */
    protected function enrichSets(array $sets): array
    {
        foreach ($sets as &$set) {
            foreach ($set['rows'] as &$row) {
                $row['tag_ids']        = $this->pivotIds((int) $row['id'], 'tag');
                $row['list_ids']       = $this->pivotIds((int) $row['id'], 'list');
                $row['custom_fields']  = $this->customFields((int) $row['id']);
                $row['activity_score'] = 0;
            }
            unset($row);
        }
        unset($set);
        return $sets;
    }

    /**
     * (Pro) The choices the filter builder offers: statuses, tags, lists and custom fields.
     *
     * Free gets a 403 in the existing `{error}` shape rather than an empty payload, so a
     * downgraded install's UI can tell "you cannot use this" apart from "you have no tags".
     */
    public function filterOptions(): \WP_REST_Response
    {
        if (!(new LicenseGate())->isPro()) {
            return new \WP_REST_Response(
                ['error' => __('Scan filters are a Pro feature.', 'dedup-and-merge-for-fluentcrm')],
                403
            );
        }

        return new \WP_REST_Response([
            'statuses' => $this->filterStatuses(),
            'tags'     => $this->filterTerms('tag'),
            'lists'    => $this->filterTerms('list'),
            'fields'   => $this->customFieldOptions(),
        ], 200);
    }

    /**
     * FluentCRM's contact statuses.
     *
     * fluentcrm_subscriber_statuses() is FluentCRM's own public helper and returns the
     * plain slug list (verified against 2.9.87: subscribed, pending, unsubscribed,
     * transactional, bounced, complained, spammed, all passed through its
     * fluent_crm/contact_statuses filter). It is called behind function_exists() and
     * falls back to the same seven, following the precedent set by
     * FluentCrmGateway::customFieldLabels() for third-party helpers.
     *
     * The labels are OURS, not FluentCRM's: theirs are translated in the fluent-crm text
     * domain, and reusing them would put untranslated strings into this plugin's UI on a
     * site that only ships our .mo files. An unrecognised status (a third party added one
     * through the filter) falls back to a humanised slug so it still renders.
     *
     * @return list<array{value: string, label: string}>
     */
    protected function filterStatuses(): array
    {
        $statuses = function_exists('fluentcrm_subscriber_statuses')
            ? (array) \fluentcrm_subscriber_statuses()
            : ['subscribed', 'pending', 'unsubscribed', 'transactional', 'bounced', 'complained', 'spammed'];

        $labels = [
            'subscribed'    => __('Subscribed', 'dedup-and-merge-for-fluentcrm'),
            'pending'       => __('Pending', 'dedup-and-merge-for-fluentcrm'),
            'unsubscribed'  => __('Unsubscribed', 'dedup-and-merge-for-fluentcrm'),
            'transactional' => __('Transactional', 'dedup-and-merge-for-fluentcrm'),
            'bounced'       => __('Bounced', 'dedup-and-merge-for-fluentcrm'),
            'complained'    => __('Complained', 'dedup-and-merge-for-fluentcrm'),
            'spammed'       => __('Spammed', 'dedup-and-merge-for-fluentcrm'),
        ];

        $out = [];
        foreach ($statuses as $status) {
            if (!is_string($status) || $status === '') {
                continue;
            }
            $out[] = [
                'value' => $status,
                'label' => $labels[$status] ?? StandardFields::humanize($status),
            ];
        }
        return $out;
    }

    /**
     * Every tag, or every list, as {value, label} pairs.
     *
     * WHY A DIRECT TABLE READ RATHER THAN FluentCRM's API. The two tables and the two
     * columns used here are verified facts (fc_tags and fc_lists are both
     * `id, title, slug, description, ...` in FluentCRM 2.9.87's own migrations), while the
     * listing API goes through model internals this plugin has deliberately never depended
     * on - the same reason pivotIds() reads fc_subscriber_pivot directly. A direct read
     * also degrades safely: if a future release renames something, this returns an empty
     * list and the filter builder simply offers no tags, where a call into a renamed
     * internal would be a fatal on an admin screen.
     *
     * $objectType is an internal literal ('tag' or 'list') chosen by filterOptions(), never
     * a request parameter, so nothing user-controlled reaches the table name.
     *
     * @return list<array{value: int, label: string}>
     */
    protected function filterTerms(string $objectType): array
    {
        global $wpdb;
        $table = $wpdb->prefix . ($objectType === 'tag' ? 'fc_tags' : 'fc_lists');
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, Generic.Files.LineLength.TooLong
        // Bounded, because this is the only unbounded read the filter builder would
        // otherwise perform: an install with tens of thousands of tags would serialise the
        // whole table into one admin response and one <select>. The limit is an integer
        // literal from a constant, so nothing is interpolated that could be influenced.
        $limit = self::FILTER_TERM_LIMIT;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, Generic.Files.LineLength.TooLong
        $rows = $wpdb->get_results("SELECT id, title FROM {$table} ORDER BY title ASC LIMIT {$limit}");

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_object($row)) {
                continue;
            }
            $r  = get_object_vars($row);
            $id = (int) ($r['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $title = (string) ($r['title'] ?? '');
            $out[] = [
                'value' => $id,
                'label' => $title !== '' ? $title : StandardFields::humanize((string) $id),
            ];
        }
        return $out;
    }

    /**
     * The custom fields the filter builder may offer, in FluentCRM's own order.
     *
     * Read through fluentcrm_get_custom_contact_fields() behind a function_exists()
     * guard, the same way FluentCrmGateway::customFieldLabels() reads it. That helper is
     * the documented accessor for the definitions stored in fc_meta under
     * object_type 'option', key 'contact_custom_fields'.
     *
     * `multi` branches on the definition's `type` and NOT on its `field_key`: the two
     * differ for these fields (`multi-select` vs `select-multi`), and FluentCRM's own
     * array detection reads `type`. Getting this backwards is not a cosmetic bug - it
     * decides whether an `equals` compiles to a plain equality or to a serialized-token
     * match, and the wrong one silently matches nothing.
     *
     * `options` is always present, as an empty list for the non-choice types. The contract
     * describes it as being there only for choice types but its example carries
     * `"options": []` on a text field; always emitting the key is the reading that
     * satisfies both, and it spares the client an existence check.
     *
     * Malformed definitions are skipped rather than tripped over, because these come out
     * of a serialized option that a long-lived install can carry partial rows in.
     *
     * @return list<array{value: string, label: string, type: string, multi: bool, options: list<string>}>
     */
    protected function customFieldOptions(): array
    {
        if (!function_exists('fluentcrm_get_custom_contact_fields')) {
            return [];
        }

        $out = [];
        foreach ((array) \fluentcrm_get_custom_contact_fields() as $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $slug = (string) ($definition['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $label = (string) ($definition['label'] ?? '');
            $type  = (string) ($definition['type'] ?? '');

            $options = [];
            foreach ((array) ($definition['options'] ?? []) as $option) {
                // FluentCRM stores these as a plain list of strings and uses each string as
                // both the stored value and the displayed label, so they are passed through
                // as strings rather than dressed up as {value,label} pairs: the string IS
                // the value a condition has to send back.
                if (is_scalar($option)) {
                    $options[] = (string) $option;
                }
            }

            $out[] = [
                'value'   => ScanFilter::CUSTOM_FIELD_PREFIX . $slug,
                'label'   => $label !== '' ? $label : StandardFields::humanize($slug),
                'type'    => $type,
                'multi'   => in_array($type, ScanFilter::MULTI_VALUE_TYPES, true),
                'options' => $options,
            ];
        }
        return $out;
    }

    /**
     * Custom field slug => is it a multi-value (serialized) type.
     *
     * Derived from customFieldOptions() rather than read a second time, so the fields the
     * builder offers and the fields the compiler accepts are the same set by construction.
     * A slug missing from this map is an unknown field and ScanFilter refuses it, which is
     * also what happens when FluentCRM's accessor is unavailable: no field can be
     * filtered on, rather than fields being filtered on with an unverified storage shape.
     *
     * @return array<string, bool>
     */
    protected function customFieldMultiMap(): array
    {
        $map = [];
        foreach ($this->customFieldOptions() as $field) {
            $slug = substr($field['value'], strlen(ScanFilter::CUSTOM_FIELD_PREFIX));
            // FIRST definition wins, deliberately, because that is what the builder does
            // when it de-duplicates the same list. If FluentCRM ever hands back two
            // definitions sharing a slug but differing in type, last-wins here would have
            // the dropdown offering the first definition's operators while the compiler
            // used the second definition's storage shape - an `equals` on what the UI
            // called a text field would compile to a serialized-token LIKE and match
            // nothing, with no error anywhere. Agreeing on which duplicate to believe
            // matters more than which one is correct.
            if (!array_key_exists($slug, $map)) {
                $map[$slug] = $field['multi'];
            }
        }
        return $map;
    }

    public function preview(\WP_REST_Request $r): \WP_REST_Response
    {
        /** @var array<string, mixed> $set */
        $set = (array) $r->get_param('set');
        if (!isset($set['rows']) || !is_array($set['rows']) || count($set['rows']) < 2) {
            return new \WP_REST_Response(
                ['error' => __('A preview requires a duplicate set of at least two contacts.', 'dedup-and-merge-for-fluentcrm')],
                400
            );
        }
        $strategy = sanitize_key((string) $r->get_param('strategy')) ?: 'oldest';
        [$survivorId, $fieldChoices] = $this->fieldAwareParams($r);

        if ($fieldChoices !== null) {
            // Field-aware mode needs every contact's FULL standard-field state, and the scan
            // payload the client echoes back only carries the handful of columns the scan
            // query selects. Re-read each contact server side so the candidate list is
            // complete AND is computed from the same authoritative source /merge will use,
            // which is what stops the operator from picking a value in the UI that the merge
            // then resolves differently. The free path never enters this branch, so its
            // response and its query count are untouched.
            $rows = [];
            foreach ($set['rows'] as $row) {
                $rowId = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
                $fresh = $rowId > 0 ? $this->readContactRow($rowId) : null;
                if ($fresh !== null) {
                    $rows[] = $fresh;
                }
            }
            if (count($rows) < 2) {
                return new \WP_REST_Response(
                    ['error' => __('These contacts are no longer a duplicate set. Please re-scan.', 'dedup-and-merge-for-fluentcrm')],
                    409
                );
            }
            $set['rows'] = $rows;
        }

        try {
            $body = $this->merger()->preview($set, $strategy, $survivorId, $fieldChoices);
            // The `woo` section is offered ONLY in field-aware mode, which only a Pro
            // request can enter (fieldAwareParams() returns nulls on free). The base
            // implementation of the hook returns null in any case, so the free build - where
            // every WooCommerce file has been stripped - answers exactly the body it answers
            // today, with no extra key and no extra query.
            if ($fieldChoices !== null) {
                $woo = $this->wooPreviewSection($set, (int) $body['survivor_id']);
                if ($woo !== null) {
                    $body['woo'] = $woo;
                }
            }
            return new \WP_REST_Response($body, 200);
        } catch (FieldChoiceException $e) {
            // A survivor or an override naming a contact outside this set. The operator can
            // fix it by re-picking, so it is a 400 with its own message rather than being
            // folded into the malformed-payload case below.
            return new \WP_REST_Response(
                ['error' => __(
                    'The selected contact is not part of this duplicate set. Please re-scan and try again.',
                    'dedup-and-merge-for-fluentcrm'
                )],
                400
            );
        } catch (\InvalidArgumentException $e) {
            // A malformed row (e.g. a scalar where a contact array is expected) would
            // otherwise fatal inside preview(); answer a clean 400 instead of a 500.
            return new \WP_REST_Response(
                ['error' => __('One or more rows in the set are malformed.', 'dedup-and-merge-for-fluentcrm')],
                400
            );
        }
    }

    public function merge(\WP_REST_Request $r): \WP_REST_Response
    {
        $ids        = $this->cleanIds((array) $r->get_param('ids'));
        $reason     = sanitize_key((string) $r->get_param('reason'));
        $strategy   = sanitize_key((string) $r->get_param('strategy')) ?: 'oldest';
        $includeWoo = (bool) $r->get_param('include_woo') && (new LicenseGate())->isPro();
        [$survivorId, $fieldChoices] = $this->fieldAwareParams($r);

        // Reject a survivor or an override that names a contact outside the SUBMITTED ids
        // before anything is read or written. The merge core validates again against the
        // rows it actually re-read, but doing it here first means the common client mistake
        // returns a clean 400 without touching the database at all.
        try {
            Merger::assertChoicesReferenceKnownContacts($ids, $survivorId, $fieldChoices);
        } catch (FieldChoiceException $e) {
            return new \WP_REST_Response(
                ['error' => __(
                    'The selected contact is not part of this duplicate set. Please re-scan and try again.',
                    'dedup-and-merge-for-fluentcrm'
                )],
                400
            );
        }

        $res = $this->mergeOneSet($ids, $reason, $strategy, $includeWoo, $survivorId, $fieldChoices);
        if (empty($res['ok'])) {
            return new \WP_REST_Response(
                ['error' => $res['error'] ?? __('Merge failed.', 'dedup-and-merge-for-fluentcrm')],
                $res['status'] ?? 500
            );
        }
        // Report exactly which contact was kept and which were removed. The merge
        // recomputes the survivor from fresh server reads, so this can differ from
        // the client's preview; returning it lets the UI state the real outcome
        // instead of a bare "done".
        return new \WP_REST_Response([
            'journal_id'  => $res['journal_id'] ?? 0,
            'survivor_id' => $res['survivor_id'] ?? 0,
            'loser_ids'   => $res['loser_ids'] ?? [],
        ], 200);
    }

    /**
     * @param array<int, mixed> $raw
     * @return list<int>
     */
    protected function cleanIds(array $raw): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $raw),
            static fn (int $id): bool => $id > 0
        )));
    }

    /**
     * Single-set server-side merge core shared by /merge and /merge-bulk: read
     * each contact fresh, re-verify the set still collides under the reason, then
     * merge. Never trusts the client's (possibly stale) scan payload.
     *
     * @param list<int>                 $ids
     * @param int|null                  $survivorId   Pro: explicit survivor, null = strategy picks.
     * @param array<string, int>|null   $fieldChoices Pro: field id => contact id, null = free gap-fill merge.
     * @return array{ok: bool, journal_id?: int, survivor_id?: int, loser_ids?: list<int>, error?: string, status?: int}
     */
    protected function mergeOneSet(
        array $ids,
        string $reason,
        string $strategy,
        bool $includeWoo,
        ?int $survivorId = null,
        ?array $fieldChoices = null
    ): array {
        if (count($ids) < 2 || !in_array($reason, ['email', 'phone', 'name'], true)) {
            return [
                'ok'     => false,
                'error'  => __('A merge requires at least two contact ids and a valid reason.', 'dedup-and-merge-for-fluentcrm'),
                'status' => 400,
            ];
        }

        try {
            $rows = [];
            foreach ($ids as $id) {
                $row = $this->readContactRow($id);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }

            // Re-verify EVERY remaining contact still shares one non-empty key. A row
            // that no longer qualifies (its phone cleared, a name field emptied, etc.)
            // invalidates the whole set, so it can never ride into the merge and be
            // deleted. array_filter over the KEYS alone would miss the disqualified row.
            if (DuplicateKey::sharedKey($rows, $reason) === '') {
                return [
                    'ok'     => false,
                    'error'  => __('These contacts are no longer a duplicate set. Please re-scan.', 'dedup-and-merge-for-fluentcrm'),
                    'status' => 409,
                ];
            }

            // Refuse a set whose members do not all share one subscription status. Merging
            // a subscribed contact with an unsubscribed/bounced/complained alias would delete
            // the record carrying the suppression and leave the survivor mailable to someone
            // who opted out - a consent incident. Enforced server side so the client cannot
            // override it; a uniformly-suppressed set (all unsubscribed, etc.) still merges.
            if (!DuplicateKey::uniformStatus($rows)) {
                return [
                    'ok'     => false,
                    'error'  => __(
                        'These contacts have different subscription statuses and will not be merged automatically.',
                        'dedup-and-merge-for-fluentcrm'
                    ),
                    'status' => 409,
                ];
            }

            // preview() is a pure, deterministic function of ($rows, $strategy); computing
            // it here yields the same survivor/losers the merge applies, so the response
            // can name the kept and removed contacts without a second authoritative read.
            $mergeSet = ['reason' => $reason, 'rows' => $rows];
            $preview  = $this->merger()->preview($mergeSet, $strategy, $survivorId, $fieldChoices);
            // Built before the merge and against the SERVER-READ rows, never against the
            // client's payload, so the eligibility decision and the orders that move are
            // computed from the same authoritative source the merge itself uses. Null on
            // free and on any Pro merge that did not ask for it; a refusal throws here,
            // before the journal row is written and before any contact is touched.
            $woo = $this->wooMergeHandler($mergeSet, (int) $preview['survivor_id'], $includeWoo);
            $journalId = $this->merger()->merge($mergeSet, $strategy, $includeWoo, $survivorId, $fieldChoices, $woo);
        } catch (SnapshotException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'status' => 500];
        } catch (WooRepointException $e) {
            // Caught ahead of the catch-all so the operator gets the specific refusal
            // instead of a masked 500 they cannot act on. The three families need three
            // different statuses (400 "you asked for something not allowed here", 409 "the
            // orders moved under you", 500 "a write failed"), and the exception carries the
            // right one because only the code that raised it knows which family it is in.
            // Every one of these messages is already translated and interpolates no part of
            // the payload, so it is safe to hand straight back.
            return ['ok' => false, 'error' => $e->getMessage(), 'status' => $e->getStatusCode()];
        } catch (FieldChoiceException $e) {
            // The survivor or an override survived the pre-flight check against the
            // submitted ids but does not match the rows that were actually re-read (the
            // contact was deleted in between). Nothing has been written: preview() runs
            // before the journal and before any gateway call. A 400 with a message the
            // operator can act on, never a masked 500.
            return [
                'ok'     => false,
                'error'  => __(
                    'The selected contact is not part of this duplicate set. Please re-scan and try again.',
                    'dedup-and-merge-for-fluentcrm'
                ),
                'status' => 400,
            ];
        } catch (\Throwable $e) {
            error_log('[fcdm] merge failed after snapshot: ' . $e->getMessage());
            return [
                'ok'     => false,
                'error'  => __('The merge could not be completed. See the site error log for details.', 'dedup-and-merge-for-fluentcrm'),
                'status' => 500,
            ];
        }

        return [
            'ok'          => true,
            'journal_id'  => $journalId,
            'survivor_id' => $preview['survivor_id'],
            'loser_ids'   => $preview['loser_ids'],
        ];
    }

    /**
     * Free tier: verifies only that an undo-journal snapshot exists; it never
     * changes data. The actual restore lives in the Pro controller's /restore
     * route (RestControllerPro), which is absent from the free build.
     */
    public function undo(\WP_REST_Request $r): \WP_REST_Response
    {
        global $wpdb;
        $journalId = (int) $r->get_param('journal_id');
        $payload   = (new UndoJournal($wpdb))->load($journalId);

        return new \WP_REST_Response([
            'restored'       => false,
            'snapshot_found' => $payload !== null,
        ], 200);
    }

    /**
     * Recent merge history for the merge-history panel. Available to any authorized
     * user because it exposes only metadata (ids, timestamp, restored flag); a free
     * user can list and verify snapshots, while restoring an entry stays Pro-gated in
     * undo(). This is what makes a bulk merge's entries reachable for undo at all.
     */
    public function journal(\WP_REST_Request $r): \WP_REST_Response
    {
        global $wpdb;
        $limit = (int) ($r->get_param('limit') ?: 50);
        return new \WP_REST_Response(['entries' => (new UndoJournal($wpdb))->recent($limit)], 200);
    }

    public function meta(): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'is_pro' => (new LicenseGate())->isPro(),
            'strategies' => ['oldest', 'most_active'],
        ], 200);
    }

    /**
     * Fresh authoritative read of one contact in the shape the Merger consumes, and the
     * source of the schema-3 undo snapshot.
     *
     * Reads the identity/context columns plus EVERY allowlisted standard column from
     * fc_subscribers by id, then tag/list ids and custom fields through the same helpers
     * scan uses, so a merge snapshot always matches what the scan showed. Capturing the
     * full standard-field state (rather than just the three identity fields v1.2 read) is
     * what lets a restore put a survivor's columns back and re-create a loser as a faithful
     * copy: restore can only ever return data the snapshot happens to contain.
     *
     * The SELECT names only columns this install actually has (see
     * StandardFields::existingColumns()), because a drifted table missing one of them would
     * turn this read into an "Unknown column" fatal on every merge. Any allowlisted column
     * that is absent is still present in the returned row as '', so callers always see one
     * stable shape and treat a missing column exactly like an empty one.
     *
     * Returns null if the contact is gone.
     *
     * @return array<string, mixed>|null
     */
    protected function readContactRow(int $id): ?array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'fc_subscribers';
        // Column names come from a hard-coded allowlist intersected with SHOW COLUMNS, so
        // nothing user-controlled reaches the SELECT list; the id is bound with prepare().
        $columns = array_values(array_unique(array_merge(
            ['id', 'email', 'status', 'created_at'],
            StandardFields::existingColumns($wpdb)
        )));
        $select = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $base = $wpdb->get_row($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT {$select} FROM {$t} WHERE id = %d",
            $id
        ));
        if (!is_object($base)) {
            return null;
        }
        $b = get_object_vars($base);
        $row = [
            'id'             => (int) ($b['id'] ?? 0),
            'email'          => (string) ($b['email'] ?? ''),
            // Captured so the merge can refuse mixed-status sets and a restore can bring
            // a loser back with its original subscription status (not a fresh default).
            'status'         => (string) ($b['status'] ?? ''),
            'created_at'     => (string) ($b['created_at'] ?? ''),
            'activity_score' => 0,
            'tag_ids'        => $this->pivotIds($id, 'tag'),
            'list_ids'       => $this->pivotIds($id, 'list'),
            'custom_fields'  => $this->customFields($id),
        ];
        // first_name / last_name / phone are part of this allowlist, so the duplicate
        // re-verification and the restore path keep reading them exactly where they did.
        foreach (StandardFields::COLUMNS as $column) {
            $row[$column] = isset($b[$column]) ? (string) $b[$column] : '';
        }
        return $row;
    }

    /**
     * FluentCRM stores model class names (not short labels) in
     * fc_subscriber_pivot.object_type. The map is filterable so it can be corrected
     * without a code release if a future release changes them.
     *
     * VERIFIED against FluentCRM 2.9.87, the version running on wp.oldev.site, three
     * independent ways: (1) the writer side, Subscriber::attachTags()/attachLists(), which
     * is what puts the literal into the column; (2) a hex dump of real fc_subscriber_pivot
     * rows from a production database, which rules out any escaping surprise in how the
     * value is stored; (3) FluentCRM's published developer documentation. The literals are
     * single-backslashed - the doubled backslashes below are PHP single-quoted escapes and
     * produce exactly `FluentCrm\App\Models\Tag` and `FluentCrm\App\Models\Lists`.
     * A third value, `FluentCrm\App\Models\Company`, exists and is mapped here so a caller
     * asking for company memberships gets them rather than an undefined index.
     *
     * It lives in its own method because the scan filter's tag/list predicates need the
     * same mapping. Declaring the defaults a second time there would mean a site that
     * corrected the map through the filter got the corrected value in one place and the
     * stale one in the other, so a filtered scan and the row detail below it would
     * disagree about what a tag even is.
     *
     * The result is normalised to string keys and non-empty string values, so callers can
     * treat a missing key as "not available" instead of guarding every read: the map is
     * filterable, and a third-party callback that drops or renames a key would otherwise
     * raise an undefined-index warning inside a read that feeds a destructive merge.
     *
     * @return array<string, string>
     */
    protected static function pivotObjectTypeMap(): array
    {
        $map = apply_filters('fcdm_pivot_object_type_map', [
            'tag'     => 'FluentCrm\\App\\Models\\Tag',
            'list'    => 'FluentCrm\\App\\Models\\Lists',
            'company' => 'FluentCrm\\App\\Models\\Company',
        ]);
        $out = [];
        foreach (is_array($map) ? $map : [] as $key => $value) {
            if (is_string($key) && is_string($value) && $value !== '') {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * The tag / list / company ids attached to one contact.
     *
     * An unknown object type yields no ids, which is the safe answer - the merge then
     * transfers no memberships of that kind rather than guessing at one. See
     * pivotObjectTypeMap() for how the object_type literals were verified.
     *
     * @return list<int>
     */
    protected function pivotIds(int $subscriberId, string $objectType): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'fc_subscriber_pivot';
        $subId = $subscriberId;
        $objVal = self::pivotObjectTypeMap()[$objectType] ?? '';
        if ($objVal === '') {
            return [];
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_col($wpdb->prepare("SELECT object_id FROM {$t} WHERE subscriber_id = %d AND object_type = %s", $subId, $objVal));
        return array_map('intval', (array) $rows);
    }

    /**
     * @return array<string, mixed>
     */
    protected function customFields(int $subscriberId): array
    {
        $c = \FluentCrmApi('contacts')->getContact($subscriberId);
        return $c ? (array) $c->custom_fields() : [];
    }
}
