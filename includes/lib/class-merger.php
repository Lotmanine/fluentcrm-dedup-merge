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
 * Orchestrates a merge: computes the survivor/loser union preview, records
 * it to the undo journal, then applies it via the FluentCRM gateway.
 *
 * TWO MODES, ONE METHOD. preview() answers the free question ("which contact
 * survives, and what tags/lists/custom fields does it inherit") and, when and only
 * when a field-choice map is supplied, the Pro question as well ("what is every
 * mergeable field's value on every contact, which one wins, and where do they
 * disagree"). The Pro half is strictly additive: with no map the returned array is
 * byte-for-byte what v1.2 returned, because the entire field-aware block is skipped
 * rather than computed and then hidden. That is what keeps the free tier inert.
 */
final class Merger
{
    /**
     * Field-id prefixes from the wire contract. Every mergeable field is addressed as
     * `std:<column>` or `cf:<slug>`, in responses and in the request's choice map alike.
     * The prefixes exist because a FluentCRM custom field slug is free to collide with a
     * fc_subscribers column name ("city" is the obvious one), and a merge that confused
     * the two would write a custom field's value into a real column.
     */
    public const FIELD_PREFIX_STANDARD = 'std:';

    public const FIELD_PREFIX_CUSTOM = 'cf:';

    public function __construct(private FluentCrmGateway $gw, private UndoJournal $journal)
    {
    }

    /**
     * @param array{reason?: string, rows: list<array<string, mixed>>} $set
     * @param int|null                                                 $requestedSurvivorId Pro: explicit survivor, null = strategy picks.
     * @param array<string, mixed>|null                                $fieldChoices        Pro: field id => contact id. NULL disables
     *                                                                                      field-aware mode entirely; an empty array
     *                                                                                      enables it with no overrides.
     * @return array{
     *     survivor_id: int, loser_ids: list<int>, tags: list<int>, lists: list<int>,
     *     fields: array<string, mixed>, standard_fields?: array<string, mixed>,
     *     field_candidates?: list<array<string, mixed>>
     * }
     */
    public function preview(
        array $set,
        string $strategy,
        ?int $requestedSurvivorId = null,
        ?array $fieldChoices = null
    ): array {
        $rows = $set['rows'];
        // Guard the row shape before it reaches the typed array_map closure below.
        // A scalar in $rows (e.g. a malformed client payload to /preview) would
        // otherwise raise an uncaught TypeError and surface as a WP critical-error
        // 500; an InvalidArgumentException lets the REST layer answer a clean 400.
        foreach ($rows as $r) {
            if (!is_array($r) || !isset($r['id'])) {
                throw new \InvalidArgumentException(
                    'Each row in a duplicate set must be an array carrying at least an id.'
                );
            }
        }

        // Validate the Pro selection against this exact set before anything else happens.
        // Both /preview and /merge funnel through here, so a survivor id or an override
        // naming a contact outside the merge can never reach the write path, no matter
        // which route it arrived on. Free callers pass nulls and this is a no-op.
        $contactIds = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        self::assertChoicesReferenceKnownContacts($contactIds, $requestedSurvivorId, $fieldChoices);

        $survivorId = SurvivorSelector::pick(array_map(
            /**
             * @param array<string, mixed> $r
             * @return array{id: int, created_at: string, activity_score: int}
             */
            static fn (array $r): array => [
                'id' => (int) $r['id'],
                // Guarded with is_scalar for the same reason the union below coerces its
                // lists: on the free path these rows are the client's own scan payload, and
                // casting an array to string raises a PHP warning that would be logged on
                // every request rather than being refused once.
                'created_at' => is_scalar($r['created_at'] ?? null) ? (string) $r['created_at'] : '',
                'activity_score' => (int) ($r['activity_score'] ?? 0),
            ],
            $rows
        ), $strategy);

        // A Pro caller may keep a contact the strategy would not have kept. The strategy
        // still runs first so its answer stays the default and the free path is untouched.
        if ($requestedSurvivorId !== null) {
            $survivorId = $requestedSurvivorId;
        }

        $survivor = null;
        $losers = [];
        foreach ($rows as $r) {
            if ((int) $r['id'] === $survivorId) {
                $survivor = $r;
            } else {
                $losers[] = $r;
            }
        }

        // Each of these is read straight off a client-supplied row on the free path, where
        // /preview trusts the scan payload it echoes back. A row carrying a string where a
        // list belongs used to reach array_merge() and raise a TypeError, which escapes the
        // controller's catch (TypeError extends Error, not Exception) and surfaces as a WP
        // critical-error 500. Coercing a non-array to an empty one keeps a structurally
        // impossible payload on the clean-4xx path the rest of this method already takes.
        $listOf = static fn (mixed $v): array => is_array($v) ? $v : [];

        $tags = $listOf($survivor['tag_ids'] ?? []);
        $lists = $listOf($survivor['list_ids'] ?? []);
        $fields = $listOf($survivor['custom_fields'] ?? []);
        foreach ($losers as $l) {
            $tags = array_merge($tags, $listOf($l['tag_ids'] ?? []));
            $lists = array_merge($lists, $listOf($l['list_ids'] ?? []));
            foreach ($listOf($l['custom_fields'] ?? []) as $k => $v) {
                if ((!isset($fields[$k]) || $fields[$k] === '') && $v !== '') {
                    $fields[$k] = $v;
                }
            }
        }

        $out = [
            'survivor_id' => $survivorId,
            'loser_ids'   => array_map(static fn ($l): int => (int) $l['id'], $losers),
            'tags'        => array_values(array_unique(array_map('intval', $tags))),
            'lists'       => array_values(array_unique(array_map('intval', $lists))),
            'fields'      => $fields,
        ];

        // THE FREE-PATH GUARD. Without a field-choice map the response ends here, with
        // exactly the five keys and the gap-fill semantics of v1.2. Everything below is
        // Pro-only and, crucially, is not merely omitted from the response but never
        // computed, so it cannot change the values above through a shared code path.
        if ($fieldChoices === null) {
            return $out;
        }

        $resolved = $this->resolveFields($rows, $survivorId, $fieldChoices);
        // In field-aware mode `fields` keeps its meaning (the custom fields applied to the
        // survivor) but is now a full resolution instead of gap-fill-only, per the contract.
        $out['fields']           = $resolved['custom'];
        $out['standard_fields']  = $resolved['standard'];
        $out['field_candidates'] = $resolved['candidates'];

        return $out;
    }

    /**
     * @param array{reason?: string, rows: list<array<string, mixed>>} $set
     * @param int|null                                                 $requestedSurvivorId See preview().
     * @param array<string, mixed>|null                                $fieldChoices        See preview().
     * @param WooRepointHandler|null                                   $woo                 Pro: the WooCommerce order
     *                                                                                      re-point, or null for every
     *                                                                                      merge that does not include
     *                                                                                      one - which is all of them
     *                                                                                      on the free tier.
     */
    public function merge(
        array $set,
        string $strategy,
        bool $includeWoo,
        ?int $requestedSurvivorId = null,
        ?array $fieldChoices = null,
        ?WooRepointHandler $woo = null
    ): int {
        $p = $this->preview($set, $strategy, $requestedSurvivorId, $fieldChoices);

        // What this merge will write to the survivor's own fc_subscribers columns. On the
        // free path preview() never returns the key at all, so this stays empty and the
        // gateway call further down is skipped: the free gateway call sequence is
        // byte-for-byte what it was, which the ordering test in MergerTest pins.
        $standardFields = $p['standard_fields'] ?? [];

        // The WooCommerce snapshot is taken FIRST, before the journal row and before any
        // write anywhere. It is a pure read, and a refusal from it (an ineligible set, an
        // order that is no longer a guest order) therefore aborts the merge with nothing
        // changed at all - no journal row, no tags moved, no contact deleted. Taking it
        // after the journal write would put the snapshot outside the record that is
        // supposed to contain it, which is the whole reason the journal exists.
        $wooSection = $woo?->capture();

        // schema 3 = the schema-2 guarantees (an authoritative server-side read at merge
        // time, complete enough to re-create losers and revert the survivor) PLUS the full
        // standard-field state of every contact and an explicit record of the standard
        // fields this merge actually applied. Restore reverts what was applied, not what
        // was proposed, so a free merge (which applies none) reverts none. schema 2
        // journals restore as before; schema 1 (v1.0) still cannot be restored at all.
        //
        // schema 4 = all of the above PLUS a `woo` section describing the WooCommerce
        // orders this merge re-pointed and the exact state each one was in beforehand. The
        // version is raised ONLY when such a section is present, so a merge that moved no
        // orders keeps writing schema 3 and keeps being restorable by anything that
        // understands 3. Raising it unconditionally would make every merge unrestorable by
        // a Restorer that had not been updated yet, which is the opposite of the guarantee.
        $payload = [
            'schema' => $wooSection === null ? 3 : 4,
            'set' => $set,
            'preview' => $p,
            'include_woo' => $includeWoo,
            'applied_standard_fields' => $standardFields,
        ];
        if ($wooSection !== null) {
            $payload['woo'] = $wooSection;
        }
        $journalId = $this->journal->record($p['survivor_id'], $p['loser_ids'], $payload);
        $this->gw->attachTags($p['survivor_id'], $p['tags']);
        $this->gw->attachLists($p['survivor_id'], $p['lists']);
        $this->gw->syncCustomFields($p['survivor_id'], $p['fields']);
        if ($standardFields !== []) {
            $this->gw->updateStandardFields($p['survivor_id'], $standardFields);
        }
        // Verify the survivor still exists before deleting any loser. If it vanished
        // between the authoritative merge-time read and now (a concurrent merge, or a
        // manual deletion in FluentCRM), the attach/sync calls above silently no-op via
        // the gateway's null-safe access, and deleting the losers would transfer their
        // tags, lists, and fields nowhere while still destroying them. Abort loudly so
        // the losers are left intact; the caller maps this to a masked 500 and the
        // journal row (written first) records the untouched pre-merge state.
        if ($this->gw->getContactById($p['survivor_id']) === null) {
            throw new \RuntimeException(
                'The survivor contact no longer exists; aborting before any deletion so no contact is removed without consolidation.'
            );
        }
        // The orders move BEFORE any contact is deleted, and that ordering is deliberate.
        // Deleting the losers is the only irreversible-in-practice step in this sequence
        // (restore re-creates them, but as new contacts with new ids), while the order
        // re-point is all-or-nothing and puts itself back on failure. Doing the orders
        // first means a refusal here leaves every contact intact, so the operator can fix
        // whatever the orders complained about and run the merge again. The other ordering
        // would delete contacts and then discover it could not do the thing the operator
        // ticked the box for - which is exactly the false premise the wire contract refuses
        // a merge over in the first place.
        if ($woo !== null && $wooSection !== null) {
            try {
                $woo->apply($wooSection);
            } catch (WooRepointException $e) {
                // Repair the journal before letting the failure out.
                //
                // The entry was written before the orders moved, because a snapshot taken
                // afterwards is not a snapshot - so right now it describes a re-point that
                // did not survive. If the orders are provably back where they started, the
                // `woo` section is not just useless but actively harmful: a later restore
                // would read those order ids, find them at customer_id 0 instead of on the
                // survivor, and refuse as drift forever. That would leave the operator
                // unable to undo a merge because of orders that are already exactly where
                // the undo wanted to put them.
                //
                // Only when the put-back is known to have worked. If the rollback itself
                // failed, some orders may still be on the survivor and this section is the
                // only record of which ones, so it stays and the restore refuses on purpose.
                if ($e->ordersLeftUnmoved()) {
                    try {
                        $this->journal->stripWooSection($journalId);
                    } catch (SnapshotException $repairError) {
                        // The re-point failure is the one the operator has to act on, so it
                        // is what propagates; a failed repair only costs restorability of an
                        // entry whose merge did not complete anyway.
                        error_log('[fcdm] could not strip the woo section after a rolled-back re-point: ' . $repairError->getMessage());
                    }
                }
                throw $e;
            }
        }
        foreach ($p['loser_ids'] as $id) {
            $this->gw->deleteContact($id);
        }
        return $journalId;
    }

    /**
     * Reject a Pro selection that points outside the merge set.
     *
     * An override names a CONTACT ID, never a raw value, and that contact must be one of
     * the ones being merged. This is the rule that stops /merge from degenerating into a
     * general "write arbitrary data to a contact" endpoint: whatever the client asks for,
     * the value that lands on the survivor is always a value one of these contacts already
     * had. Rejecting rather than ignoring an unknown id is deliberate - silently applying
     * a different value than the operator picked, on a destructive operation, is worse
     * than refusing the request.
     *
     * Static and public so the REST layer can run the same check against the ids the
     * client submitted, before any contact is even read.
     *
     * @param  list<int>                 $contactIds
     * @param  array<string, mixed>|null $fieldChoices
     * @throws FieldChoiceException When the survivor or any override names an unknown contact.
     */
    public static function assertChoicesReferenceKnownContacts(
        array $contactIds,
        ?int $requestedSurvivorId,
        ?array $fieldChoices
    ): void {
        if ($requestedSurvivorId !== null && !in_array($requestedSurvivorId, $contactIds, true)) {
            throw new FieldChoiceException(
                'The requested survivor is not one of the contacts in this duplicate set.'
            );
        }
        if ($fieldChoices === null) {
            return;
        }
        foreach ($fieldChoices as $fieldId => $contactId) {
            if (!is_numeric($contactId) || !in_array((int) $contactId, $contactIds, true)) {
                // The offending field id is deliberately NOT interpolated into the message.
                // It is attacker-controlled text that would end up in an error log and, on a
                // debug install, in an HTTP response; the caller replaces this message with a
                // translated one anyway, so the detail would buy nothing.
                throw new FieldChoiceException(
                    'A field choice names a contact that is not part of this duplicate set.'
                );
            }
        }
    }

    /**
     * Build the per-field candidate list and the resolved values for a field-aware merge.
     *
     * Resolution model (from the wire contract), applied identically to standard columns
     * and custom fields:
     *   0 non-empty values across the set -> the field is omitted entirely, so a merge can
     *     never write emptiness over anything.
     *   1 distinct non-empty value        -> auto-resolved, conflict false. Nothing for the
     *     operator to decide, so the UI does not ask.
     *   2+ distinct non-empty values      -> conflict true, resolved to the survivor's own
     *     value, or to the first non-empty value in ascending contact-id order when the
     *     survivor has none. Contact-id order is used rather than payload order so the
     *     default is stable and reproducible regardless of how the client ordered its rows.
     *
     * An override is honoured only when the named contact actually has a non-empty value
     * for that field. The UI can only ever offer contacts that appear in values[], which is
     * non-empty values only, so an override pointing at an empty one is out-of-band input;
     * falling back to the default resolution keeps the "a merge never clears a field"
     * invariant that the rest of this class depends on.
     *
     * @param  list<array<string, mixed>> $rows
     * @param  array<string, mixed>       $fieldChoices
     * @return array{standard: array<string, mixed>, custom: array<string, mixed>, candidates: list<array<string, mixed>>}
     */
    private function resolveFields(array $rows, int $survivorId, array $fieldChoices): array
    {
        /** @var array<int, array<string, mixed>> $byId */
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        ksort($byId, SORT_NUMERIC);

        /** @var list<array{id: string, key: string, scope: string, label: string}> $specs */
        $specs = [];
        // Standard columns first, in the fixed allowlist order; then custom fields
        // alphabetically by slug. Conflicts are deliberately NOT hoisted to the top:
        // a stable order lets the operator re-check the same field in the same place
        // across two previews, and the UI does its own highlighting.
        foreach (StandardFields::COLUMNS as $column) {
            $specs[] = [
                'id'    => self::FIELD_PREFIX_STANDARD . $column,
                'key'   => $column,
                'scope' => 'standard',
                'label' => StandardFields::label($column),
            ];
        }
        $slugs = [];
        foreach ($byId as $r) {
            $custom = is_array($r['custom_fields'] ?? null) ? $r['custom_fields'] : [];
            foreach (array_keys($custom) as $slug) {
                $slugs[(string) $slug] = true;
            }
        }
        $slugList = array_keys($slugs);
        sort($slugList, SORT_STRING);
        // Ask FluentCRM once for the labels the operator actually configured, rather than
        // once per field. A slug with no configured label (an orphan meta row left behind
        // by a deleted field definition, for instance) falls back to a humanised slug so
        // the row still renders with something readable instead of blank.
        $customLabels = $this->gw->customFieldLabels();
        foreach ($slugList as $slug) {
            $specs[] = [
                'id'    => self::FIELD_PREFIX_CUSTOM . $slug,
                'key'   => $slug,
                'scope' => 'custom',
                'label' => $customLabels[$slug] ?? StandardFields::humanize($slug),
            ];
        }

        // Every override must name a field this set actually has. An override that matched
        // no candidate used to be dropped in silence, so the merge quietly applied a
        // different value than the operator had picked and then deleted the contact that
        // held the picked one. On a destructive operation, "I do not recognise what you
        // asked for" has to fail the request. This makes the whole class of mismatch loud
        // rather than just the one instance that was found: any future drift between the
        // field ids the server publishes and the ids it accepts now fails the request
        // instead of merging the wrong data.
        $knownIds = [];
        foreach ($specs as $spec) {
            $knownIds[$spec['id']] = true;
        }
        foreach (array_keys($fieldChoices) as $choiceId) {
            if (!isset($knownIds[(string) $choiceId])) {
                throw new FieldChoiceException(
                    'A field choice names a field that is not part of this duplicate set.'
                );
            }
        }

        $standard = [];
        $custom = [];
        $candidates = [];
        foreach ($specs as $spec) {
            $isStandard = $spec['scope'] === 'standard';
            $values = [];
            $distinct = [];
            foreach ($byId as $contactId => $row) {
                $value = $this->rowValue($row, $spec['key'], $isStandard);
                if (StandardFields::isEmptyValue($isStandard ? $spec['key'] : '', $value)) {
                    continue;
                }
                $values[] = ['contact_id' => $contactId, 'value' => $value];
                $distinct[self::valueKey($value)] = true;
            }
            if ($values === []) {
                continue; // nobody has a value; there is nothing to merge or to ask about.
            }

            $resolvedFrom = $values[0]['contact_id'];
            foreach ($values as $entry) {
                if ($entry['contact_id'] === $survivorId) {
                    $resolvedFrom = $survivorId;
                    break;
                }
            }
            if (isset($fieldChoices[$spec['id']])) {
                $chosen = (int) $fieldChoices[$spec['id']];
                foreach ($values as $entry) {
                    if ($entry['contact_id'] === $chosen) {
                        $resolvedFrom = $chosen;
                        break;
                    }
                }
            }
            $resolvedValue = $this->rowValue($byId[$resolvedFrom] ?? [], $spec['key'], $isStandard);

            $candidates[] = [
                'id'             => $spec['id'],
                'key'            => $spec['key'],
                'scope'          => $spec['scope'],
                'label'          => $spec['label'],
                'values'         => $values,
                'resolved_value' => $resolvedValue,
                'resolved_from'  => $resolvedFrom,
                'conflict'       => count($distinct) > 1,
            ];

            if (!$isStandard) {
                $custom[$spec['key']] = $resolvedValue;
                continue;
            }
            // Only report a standard field the merge will actually change. Sending back a
            // column whose resolved value equals what the survivor already has would make
            // the merge journal claim a write that never happened, and restore would then
            // have a phantom field to revert.
            $current = $this->rowValue($byId[$survivorId] ?? [], $spec['key'], true);
            if (self::valueKey($current) !== self::valueKey($resolvedValue)) {
                $standard[$spec['key']] = $resolvedValue;
            }
        }

        return ['standard' => $standard, 'custom' => $custom, 'candidates' => $candidates];
    }

    /**
     * Read one field off a contact row, from the row itself for a standard column or from
     * its custom_fields map for a custom field. Returns '' for anything absent so callers
     * never have to distinguish "missing column" from "empty column" - a row read from an
     * install missing a column (see StandardFields::existingColumns()) looks exactly like
     * a row where the user never filled it in, which is the correct outcome either way.
     *
     * @param array<string, mixed> $row
     */
    private function rowValue(array $row, string $key, bool $isStandard): mixed
    {
        if ($isStandard) {
            return $row[$key] ?? '';
        }
        $custom = is_array($row['custom_fields'] ?? null) ? $row['custom_fields'] : [];
        return $custom[$key] ?? '';
    }

    /**
     * A stable string form of a value, used only for equality and distinctness tests.
     *
     * Custom fields can legitimately hold arrays (a multi-select), and comparing those with
     * === would treat two identical selections in a different order as a conflict. Encoding
     * them gives a deterministic comparison without changing what is sent on the wire, which
     * stays the raw value.
     */
    private static function valueKey(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        $json = json_encode($value);
        return is_string($json) ? $json : '';
    }
}
