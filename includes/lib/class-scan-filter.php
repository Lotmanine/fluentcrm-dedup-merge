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
 * Compiles a (Pro) scan-filter payload into one SQL WHERE fragment plus the list of
 * arguments that fragment's placeholders consume, so the caller can hand both to
 * $wpdb->prepare(). It is the only place in this plugin that turns operator input into
 * SQL, which makes it the only place a SQL injection could originate.
 *
 * WHY IT IS SHIPPED IN THE FREE BUILD EVEN THOUGH ONLY PRO CAN REACH IT.
 * Only a Pro request ever produces a filter payload, so on a free install this class is
 * never instantiated. It still has to be present, because DuplicateDetector type-hints
 * it in detect()/scan() and the Freemius free build physically deletes every file whose
 * name carries the __premium_only suffix. A stripped type hint is not a disabled
 * feature, it is a fatal on the free tier's own scan. This mirrors the field-aware code
 * in Merger: free-shipped, inert without a Pro caller.
 *
 * WHY IT TAKES NO $wpdb.
 * The SQL correctness is the entire risk of this feature, so the compiler has to be
 * exercisable without a database. Everything it needs from WordPress is passed in: the
 * table prefix, an esc_like callable, the custom-field definitions and the pivot
 * object-type map. Nothing here reads a global, applies a filter, or opens a
 * connection, so every operator, every escape and every emitted byte is unit-testable.
 *
 * WHY IT IS BOTH THE COMPILER AND THE RESULT.
 * compile() returns an instance holding the finished fragment and its arguments. The
 * instance is built exactly once, by that factory, and is never mutated afterwards, so
 * it behaves as an immutable value object; the private constructor is what enforces
 * that. Callers read it through sql() and args(), deliberately as two separate values
 * so that neither the tests nor DuplicateDetector can accidentally interpolate an
 * argument into the query text.
 *
 * SECURITY MODEL, in full, because this is the class where getting it wrong is fatal:
 *  - Every value that came from the payload leaves here as a bound ARGUMENT, never as
 *    query text. The fragment contains only placeholders, hard-coded SQL keywords,
 *    hard-coded column names and table names derived from the caller's prefix.
 *  - Column names come from the FIELD_OPERATORS allowlist below and are never taken
 *    from the payload; an unrecognised field is rejected, not passed through.
 *  - Custom-field slugs are checked against the definitions FluentCRM actually has AND
 *    bound as %s, so neither an unknown slug nor a crafted one can reach the SQL text.
 *  - Every LIKE operand is escaped with the caller's esc_like BEFORE being bound, so a
 *    literal % or _ in an operator's search term matches itself instead of acting as a
 *    wildcard.
 *  - Table names are built from the caller-supplied prefix only, which comes from
 *    $wpdb->prefix.
 */
final class ScanFilter
{
    /**
     * The alias the outer scan query must give fc_subscribers. Every fragment emitted
     * here correlates its subqueries against it, so the caller and this class have to
     * agree on one name; exposing it as a constant is what keeps them from drifting.
     */
    public const ALIAS = 's';

    /**
     * The field-id prefix for a custom field.
     *
     * This is deliberately the same literal as Merger::FIELD_PREFIX_CUSTOM rather than a
     * second convention: the filter builder offers the same `cf:<slug>` ids the merge
     * field picker already uses, so an operator sees one naming scheme across the whole
     * plugin. It is duplicated as a constant rather than referenced so that this file has
     * no load-order dependency on Merger, and ScanFilterTest asserts the two are equal so
     * they can never drift apart.
     */
    public const CUSTOM_FIELD_PREFIX = 'cf:';

    /**
     * The FluentCRM custom-field types whose value is stored PHP-serialized.
     *
     * Read from FluentCRM 2.9.87 (Services/Helper.php builds its segment options with
     * `in_array($field['type'], ['checkbox', 'select-multi'])`). The branch is on `type`
     * and NOT on `field_key`, because the two genuinely differ for these fields
     * (`multi-select` vs `select-multi`) and branching on the wrong one silently compiles
     * a plain equality against a serialized blob, which matches nothing.
     *
     * @var list<string>
     */
    public const MULTI_VALUE_TYPES = ['checkbox', 'select-multi'];

    /**
     * The operators a custom field accepts, per the wire contract.
     *
     * `contains` is offered for multi-value fields too, and it is APPROXIMATE there: the
     * value is a serialized array, so a `%needle%` match can also span the delimiters
     * between two elements (searching "Lyon" can be matched by an element ending in
     * "Ly" followed by one starting with "on"). The alternative was to refuse the
     * operator for multi fields, which would reject a request an operator has every
     * reason to make. It is offered instead, and /filter-options marks the field
     * `multi: true` so the UI can label it as approximate. Exact option matching is what
     * `equals` is for: on a multi field it matches the serialized token, which is precise.
     *
     * @var list<string>
     */
    public const CUSTOM_FIELD_OPERATORS = ['equals', 'contains', 'is_set', 'is_empty'];

    /**
     * The ONLY fields a filter may name, mapped to the ONLY operators each accepts.
     *
     * This is an allowlist in the strict sense: the keys double as the fc_subscribers
     * column names that get interpolated into the SQL (`tags` and `lists` are the two
     * exceptions and are handled through the pivot table instead). A field that is not a
     * key here never reaches the query builder at all, which is what makes it impossible
     * for a payload to name a column.
     *
     * Spelled out per field rather than composed programmatically so that the answer to
     * "can this field do that?" is one grep away.
     *
     * @var array<string, list<string>>
     */
    private const FIELD_OPERATORS = [
        'status'     => ['is', 'is_not'],
        'first_name' => ['is', 'is_not', 'contains', 'starts_with', 'is_empty', 'is_not_empty'],
        'last_name'  => ['is', 'is_not', 'contains', 'starts_with', 'is_empty', 'is_not_empty'],
        'email'      => ['is', 'is_not', 'contains', 'starts_with', 'is_empty', 'is_not_empty'],
        'phone'      => ['is', 'is_not', 'contains', 'starts_with', 'is_empty', 'is_not_empty'],
        'created_at' => ['before', 'after'],
        'tags'       => ['in', 'not_in'],
        'lists'      => ['in', 'not_in'],
    ];

    /**
     * Resource bounds, not correctness rules.
     *
     * The endpoint is already behind manage_options, so these are not a trust boundary.
     * They exist because $wpdb->prepare() builds one string per placeholder: a payload
     * carrying a hundred thousand tag ids would exhaust memory inside prepare() and
     * surface as a 500 on a screen that must never answer with one. Both limits are far
     * above anything a filter builder can produce by hand.
     */
    private const MAX_CONDITIONS = 100;

    private const MAX_VALUES_PER_CONDITION = 500;

    /**
     * The compiled WHERE fragment. Empty string means "no narrowing at all", which the
     * caller must treat as an unfiltered scan rather than as `WHERE ''`.
     */
    private string $sql = '';

    /**
     * The arguments the fragment's placeholders consume, in placeholder order.
     *
     * @var list<int|string>
     */
    private array $args = [];

    /**
     * Monotonic counter used to give every correlated subquery its own alias. Two
     * conditions on the same table at the same nesting level would otherwise both be
     * `m`, and while MySQL scopes subquery aliases independently, distinct aliases keep
     * the emitted SQL readable in a slow-query log and remove any doubt about which
     * subquery a predicate belongs to.
     */
    private int $subqueryIndex = 0;

    private int $conditionCount = 0;

    /**
     * @param \Closure(string): string $escLike          Usually $wpdb->esc_like(...).
     * @param array<string, bool>      $customFields     Custom field slug => is it a multi-value type.
     * @param array<string, string>    $pivotObjectTypes 'tag'/'list' => the class name stored in fc_subscriber_pivot.
     */
    private function __construct(
        private string $tablePrefix,
        private \Closure $escLike,
        private array $customFields,
        private array $pivotObjectTypes
    ) {
    }

    /**
     * Compile a filter payload, or throw.
     *
     * $payload is whatever arrived on the wire, deliberately typed `mixed`: validating a
     * hostile shape is this method's job, so refusing to accept one would just push the
     * same check onto every caller.
     *
     * @param  mixed                    $payload          The decoded `filters` object, or null for "no filter".
     * @param  string                   $tablePrefix      $wpdb->prefix. Never anything user-controlled.
     * @param  \Closure(string): string $escLike          $wpdb->esc_like(...), applied to every LIKE operand.
     * @param  array<string, bool>      $customFields     Known custom field slug => is it multi-value. A slug
     *                                                    that is not a key here is an UNKNOWN FIELD and is
     *                                                    rejected, so a filter can never be compiled against a
     *                                                    field whose storage shape we could not verify.
     * @param  array<string, string>    $pivotObjectTypes The fcdm_pivot_object_type_map map, reused rather than
     *                                                    re-declared so tags and lists resolve identically here
     *                                                    and in RestController::pivotIds().
     * @throws ScanFilterException When the payload cannot be compiled exactly as written.
     */
    public static function compile(
        mixed $payload,
        string $tablePrefix,
        \Closure $escLike,
        array $customFields = [],
        array $pivotObjectTypes = []
    ): self {
        $filter = new self($tablePrefix, $escLike, $customFields, $pivotObjectTypes);
        $filter->build($payload);
        return $filter;
    }

    /**
     * The neutral filter: compiles to nothing and narrows nothing. Handed to
     * DuplicateDetector it produces exactly the unfiltered query.
     */
    public static function none(): self
    {
        return new self('', static fn (string $text): string => $text, [], []);
    }

    /**
     * The WHERE fragment, without the WHERE keyword. Contains placeholders only, never
     * a value: see args() for those.
     */
    public function sql(): string
    {
        return $this->sql;
    }

    /**
     * The arguments for the fragment's placeholders, in the order they appear.
     *
     * Returned separately from sql() on purpose. The two are only ever brought together
     * by $wpdb->prepare(), which is what guarantees that no operator-supplied byte is
     * ever parsed as SQL.
     *
     * @return list<int|string>
     */
    public function args(): array
    {
        return $this->args;
    }

    /**
     * True when this filter narrows nothing, which happens for a null payload, an empty
     * `groups` array, or groups that all carry no conditions. The contract says all three
     * behave exactly like an unfiltered scan.
     */
    public function isEmpty(): bool
    {
        return $this->sql === '';
    }

    /**
     * @throws ScanFilterException
     */
    private function build(mixed $payload): void
    {
        if ($payload === null) {
            return;
        }
        if (!is_array($payload)) {
            throw ScanFilterException::malformed();
        }
        // A payload that carries conditions at the TOP level has forgotten to wrap them in
        // a group. Reading `groups` off it would find nothing, and "nothing" compiles to an
        // unfiltered scan - so every condition the operator wrote would be dropped without
        // a word and they would be shown the whole contact list as though it were their
        // filtered result. Refused explicitly instead.
        if (array_key_exists('conditions', $payload) || array_key_exists('field', $payload)) {
            throw ScanFilterException::malformed();
        }
        $match  = self::readMatch($payload);
        $groups = $payload['groups'] ?? [];
        if (!is_array($groups)) {
            throw ScanFilterException::malformed();
        }

        $fragments = [];
        foreach ($groups as $group) {
            $fragment = $this->buildGroup($group);
            // A group with no conditions contributes nothing and is skipped rather than
            // compiled to a tautology. Under a top-level OR a tautology would widen the
            // scan to every contact, which is the one direction a filter must never fail
            // in: it would show the operator contacts they explicitly excluded.
            if ($fragment !== '') {
                $fragments[] = $fragment;
            }
        }
        if ($fragments === []) {
            return; // no narrowing; sql() stays '' and the caller runs the unfiltered scan.
        }

        // Each group is already parenthesised. The whole join is wrapped as well so the
        // fragment stays safe to AND onto something else later without a precedence
        // surprise, even though today it is the entire WHERE clause.
        $this->sql = count($fragments) === 1
            ? $fragments[0]
            : '(' . implode(' ' . $match . ' ', $fragments) . ')';
    }

    /**
     * @throws ScanFilterException
     */
    private function buildGroup(mixed $group): string
    {
        if (!is_array($group)) {
            throw ScanFilterException::malformed();
        }
        // One level of grouping, exactly as the contract specifies. A `groups` key inside
        // a group is a second level and is refused rather than flattened, because
        // flattening would change what the operator asked for without telling them.
        if (array_key_exists('groups', $group)) {
            throw ScanFilterException::nesting();
        }
        // A bare condition sent where a group belongs. Its `conditions` key would be absent,
        // the group would compile to nothing, and the condition would vanish in silence -
        // the same class of failure as top-level conditions above, and the same answer.
        if (array_key_exists('field', $group) || array_key_exists('op', $group)) {
            throw ScanFilterException::malformed();
        }
        $match      = self::readMatch($group);
        $conditions = $group['conditions'] ?? [];
        if (!is_array($conditions)) {
            throw ScanFilterException::malformed();
        }

        $parts = [];
        foreach ($conditions as $condition) {
            $parts[] = $this->buildCondition($condition);
        }
        if ($parts === []) {
            return '';
        }
        return '(' . implode(' ' . $match . ' ', $parts) . ')';
    }

    /**
     * @throws ScanFilterException
     */
    private function buildCondition(mixed $condition): string
    {
        if (!is_array($condition)) {
            throw ScanFilterException::malformed();
        }
        if (array_key_exists('groups', $condition) || array_key_exists('conditions', $condition)) {
            throw ScanFilterException::nesting();
        }
        $this->conditionCount++;
        if ($this->conditionCount > self::MAX_CONDITIONS) {
            throw ScanFilterException::tooManyConditions();
        }

        $field = $condition['field'] ?? null;
        $op    = $condition['op'] ?? null;
        if (!is_string($field) || !is_string($op)) {
            throw ScanFilterException::malformed();
        }
        $field = trim($field);
        // Operators are matched case-insensitively so a client that sends "IS" is not
        // refused over a cosmetic difference; the field id is NOT lowercased, because a
        // custom field slug is case-sensitive and folding it would silently look up the
        // wrong field.
        $op    = strtolower(trim($op));
        $value = $condition['value'] ?? null;

        if (str_starts_with($field, self::CUSTOM_FIELD_PREFIX)) {
            return $this->customFieldCondition(substr($field, strlen(self::CUSTOM_FIELD_PREFIX)), $op, $value);
        }

        $operators = self::FIELD_OPERATORS[$field] ?? null;
        if ($operators === null) {
            throw ScanFilterException::unknownField();
        }
        if (!in_array($op, $operators, true)) {
            throw ScanFilterException::unknownOperator();
        }

        if ($field === 'tags' || $field === 'lists') {
            return $this->pivotCondition($field, $op, $value);
        }
        return $this->columnCondition($field, $op, $value);
    }

    /**
     * A predicate against one fc_subscribers column.
     *
     * $column is a key of FIELD_OPERATORS, so it is a hard-coded identifier by
     * construction and safe to interpolate; it is backticked anyway, so a future
     * allowlist entry that happens to be a reserved word cannot break the query.
     *
     * @throws ScanFilterException
     */
    private function columnCondition(string $column, string $op, mixed $value): string
    {
        $target = self::ALIAS . '.`' . $column . '`';

        switch ($op) {
            case 'is':
                $this->args[] = $this->textValue($value);
                return $target . ' = %s';
            case 'is_not':
                // NULL-tolerant on purpose. `col <> 'x'` is UNKNOWN for a NULL column, so a
                // contact with no first name at all would drop out of a "first name is not
                // John" filter. Every one of these columns is nullable in FluentCRM's
                // schema, and "has no value" plainly satisfies "is not John".
                $this->args[] = $this->textValue($value);
                return '(' . $target . ' IS NULL OR ' . $target . ' <> %s)';
            case 'contains':
                $this->args[] = '%' . $this->escapeLike($this->textValue($value)) . '%';
                return $target . ' LIKE %s';
            case 'starts_with':
                $this->args[] = $this->escapeLike($this->textValue($value)) . '%';
                return $target . ' LIKE %s';
            case 'is_empty':
                // Both storage shapes of "nothing here": FluentCRM writes '' for a cleared
                // text column on some paths and leaves NULL on others, and an operator
                // means the same thing by both.
                return '(' . $target . ' IS NULL OR ' . $target . " = '')";
            case 'is_not_empty':
                return '(' . $target . ' IS NOT NULL AND ' . $target . " <> '')";
            case 'before':
                // created_at is a DATETIME and the contract's value is a plain date, so the
                // day has to be resolved to an instant. `before` is the start of that day
                // and `after` is the end of it, which makes the two symmetric: neither
                // includes the named day, and a contact created at 09:00 on the named day
                // is returned by neither. Binding a bare 'YYYY-MM-DD' for `after` would
                // instead silently include most of that day.
                $this->args[] = $this->dateValue($value) . ' 00:00:00';
                return $target . ' < %s';
            case 'after':
                $this->args[] = $this->dateValue($value) . ' 23:59:59';
                return $target . ' > %s';
        }

        // Unreachable: buildCondition() has already checked $op against FIELD_OPERATORS.
        // Kept so that adding an operator to the allowlist without adding its SQL here
        // fails loudly instead of compiling to nothing.
        throw ScanFilterException::unknownOperator();
    }

    /**
     * A tag or list membership predicate.
     *
     * `not_in` is NOT EXISTS rather than `EXISTS (... AND object_id NOT IN (...))`. The
     * latter reads as "has some other tag", so a contact carrying both the excluded tag
     * and an unrelated one would still match and would be offered for merging despite
     * the operator having excluded it.
     *
     * The object_type is BOUND rather than interpolated even though it is not operator
     * input: it comes from the fcdm_pivot_object_type_map filter, so a third-party
     * callback is free to put anything in it.
     *
     * @throws ScanFilterException
     */
    private function pivotCondition(string $field, string $op, mixed $value): string
    {
        $objectType = $this->pivotObjectTypes[$field === 'tags' ? 'tag' : 'list'] ?? '';
        if ($objectType === '') {
            // The map is filterable, so a callback that dropped the key leaves us unable to
            // scope the pivot query. Scoping is mandatory (the same table holds company
            // memberships), so the condition is refused rather than run unscoped.
            throw ScanFilterException::unknownField();
        }

        $ids   = $this->idListValue($value);
        $alias = 'p' . (++$this->subqueryIndex);
        $table = $this->tablePrefix . 'fc_subscriber_pivot';

        $this->args[] = $objectType;
        foreach ($ids as $id) {
            $this->args[] = $id;
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));

        $exists = 'EXISTS (SELECT 1 FROM ' . $table . ' ' . $alias
            . ' WHERE ' . $alias . '.subscriber_id = ' . self::ALIAS . '.id'
            . ' AND ' . $alias . '.object_type = %s'
            . ' AND ' . $alias . '.object_id IN (' . $placeholders . '))';

        return $op === 'in' ? $exists : 'NOT ' . $exists;
    }

    /**
     * A custom-field predicate, as a correlated EXISTS against fc_subscriber_meta.
     *
     * Every rule below is a verified storage fact, not a guess. See
     * docs/spike-s1-fluentcrm-storage.md and docs/spike-live-db-verification.md:
     *
     *  - Values live one row per field, `object_type = 'custom_field'`, `key` = the slug.
     *    The object_type predicate is MANDATORY: the same table holds internal rows such
     *    as _secure_hash and _last_login, which an unscoped filter would match.
     *  - `key` is a MySQL reserved word and must be backticked.
     *  - Multi-value fields (checkbox, select-multi) are stored PHP-serialized, so an
     *    exact option match has to match the serialized token `s:LEN:"VALUE";`. LEN is a
     *    BYTE length because serialize() writes byte lengths, which is why strlen() is
     *    used and mb_strlen() must never be: an accented value would produce a shorter
     *    length than the one on disk and match nothing, silently.
     *  - There are three distinct empty states (no row at all, because FluentCRM DELETES
     *    the row when a value is cleared; a row holding ''; and a row holding the
     *    serialized empty array 'a:0:{}'). is_empty covers all three and is_set is its
     *    exact negation, so the two always partition the contact list.
     *
     * The slug is validated against the definitions FluentCRM actually reports AND bound
     * as %s. Either alone would be enough to stop an injection; both are used because an
     * unknown slug is also a genuine operator error worth refusing.
     *
     * @throws ScanFilterException
     */
    private function customFieldCondition(string $slug, string $op, mixed $value): string
    {
        $slug = trim($slug);
        if ($slug === '' || !array_key_exists($slug, $this->customFields)) {
            throw ScanFilterException::unknownField();
        }
        if (!in_array($op, self::CUSTOM_FIELD_OPERATORS, true)) {
            throw ScanFilterException::unknownOperator();
        }

        $alias = 'm' . (++$this->subqueryIndex);
        $table = $this->tablePrefix . 'fc_subscriber_meta';
        // 'custom_field' is a hard-coded literal, never operator input, and is written
        // inline so the emitted SQL reads exactly like the predicate the spike verified.
        $head = 'SELECT 1 FROM ' . $table . ' ' . $alias
            . ' WHERE ' . $alias . '.subscriber_id = ' . self::ALIAS . '.id'
            . " AND " . $alias . ".object_type = 'custom_field'"
            . ' AND ' . $alias . '.`key` = %s';
        // The three empty states, as one reusable predicate so is_set and is_empty can
        // never disagree about what "has a value" means.
        $hasValue = $alias . '.`value` IS NOT NULL AND ' . $alias . ".`value` <> ''"
            . ' AND ' . $alias . ".`value` <> 'a:0:{}'";

        switch ($op) {
            case 'equals':
                $text         = $this->textValue($value);
                $this->args[] = $slug;
                if ($this->customFields[$slug]) {
                    $this->args[] = '%' . $this->escapeLike('s:' . strlen($text) . ':"' . $text . '";') . '%';
                    return 'EXISTS (' . $head . ' AND ' . $alias . '.`value` LIKE %s)';
                }
                $this->args[] = $text;
                return 'EXISTS (' . $head . ' AND ' . $alias . '.`value` = %s)';
            case 'contains':
                $text         = $this->textValue($value);
                $this->args[] = $slug;
                $this->args[] = '%' . $this->escapeLike($text) . '%';
                return 'EXISTS (' . $head . ' AND ' . $alias . '.`value` LIKE %s)';
            case 'is_set':
                $this->args[] = $slug;
                return 'EXISTS (' . $head . ' AND ' . $hasValue . ')';
            case 'is_empty':
                $this->args[] = $slug;
                return 'NOT EXISTS (' . $head . ' AND ' . $hasValue . ')';
        }

        // No fallthrough guard, deliberately: the in_array() check above narrows $op to
        // exactly the four CUSTOM_FIELD_OPERATORS entries, so the switch is provably
        // exhaustive and any guard here would be dead code. Adding a fifth operator to
        // that constant without adding its case makes this method able to fall off the
        // end, which static analysis reports as a missing return - a build failure rather
        // than an operator silently getting a condition that compiles to nothing.
    }

    /**
     * The AND/OR joiner for a payload or a group.
     *
     * Absent defaults to AND, which is the narrowing direction: a client that forgot the
     * key gets fewer contacts to consider, never more. Present but unusable is refused,
     * because at that point the client did express an intent and we cannot honour it.
     *
     * @param  array<mixed> $node
     * @throws ScanFilterException
     */
    private static function readMatch(array $node): string
    {
        if (!array_key_exists('match', $node)) {
            return 'AND';
        }
        $match = $node['match'];
        if (!is_string($match)) {
            throw ScanFilterException::badMatch();
        }
        $match = strtoupper(trim($match));
        if ($match !== 'AND' && $match !== 'OR') {
            throw ScanFilterException::badMatch();
        }
        return $match;
    }

    /**
     * A text operand.
     *
     * Strings only. A number or a boolean where a search term belongs is a client bug,
     * and coercing it would hide that bug behind a filter that quietly matches nothing.
     *
     * Surrounding whitespace is trimmed and an empty result is refused. An empty operand
     * is the dangerous case: `LIKE '%%'` matches every row, so a `contains` with a blank
     * value would silently widen the scan to the whole list while the UI still displayed
     * the condition as active. The empty-value question has its own operators
     * (is_empty / is_not_empty), which is where it belongs.
     *
     * @throws ScanFilterException
     */
    private function textValue(mixed $value): string
    {
        if (!is_string($value)) {
            throw ScanFilterException::valueType();
        }
        $text = trim($value);
        if ($text === '') {
            throw ScanFilterException::missingValue();
        }
        return $text;
    }

    /**
     * A YYYY-MM-DD operand, checked for shape AND for being a real calendar date.
     *
     * checkdate() is what stops 2026-02-31 from being bound: MySQL would compare it as an
     * invalid date and return nothing, so the operator would see an empty result with no
     * explanation instead of a message telling them the date is wrong.
     *
     * @throws ScanFilterException
     */
    private function dateValue(mixed $value): string
    {
        if (!is_string($value)) {
            throw ScanFilterException::valueType();
        }
        $date = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
            throw ScanFilterException::badDate();
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw ScanFilterException::badDate();
        }
        return $date;
    }

    /**
     * A list of positive integer ids for a tag/list membership test.
     *
     * Digit strings are accepted alongside integers because a JSON payload built from
     * form controls routinely carries "3" rather than 3, and the two mean the same id.
     * Anything else is refused: a float, a null or a nested array in an id list is a
     * client bug, and dropping the offending entry would narrow by fewer tags than the
     * operator selected without saying so.
     *
     * An empty list is refused for the same reason. `IN ()` is not valid SQL, and the
     * honest alternatives (match nothing, or ignore the condition) are both surprises.
     *
     * @return list<int>
     * @throws ScanFilterException
     */
    private function idListValue(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            throw ScanFilterException::valueType();
        }
        if (count($value) > self::MAX_VALUES_PER_CONDITION) {
            throw ScanFilterException::tooManyValues();
        }
        $ids = [];
        foreach ($value as $item) {
            if (is_int($item)) {
                $id = $item;
            } elseif (is_string($item) && ctype_digit(trim($item))) {
                $id = (int) trim($item);
            } else {
                throw ScanFilterException::valueType();
            }
            if ($id < 1) {
                throw ScanFilterException::valueType();
            }
            $ids[$id] = $id;
        }
        return array_values($ids);
    }

    /**
     * Escape a LIKE operand with the caller's esc_like.
     *
     * Every single LIKE operand this class emits goes through here. Without it, a search
     * for a value containing % or _ would be interpreted as a wildcard: filtering on a
     * postal code of "1_0" would also return "150", and an operator who then merged the
     * result would consolidate contacts that were never duplicates.
     */
    private function escapeLike(string $text): string
    {
        return ($this->escLike)($text);
    }
}
