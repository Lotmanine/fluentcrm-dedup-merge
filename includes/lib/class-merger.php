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
 */
final class Merger
{
    public function __construct(private FluentCrmGateway $gw, private UndoJournal $journal)
    {
    }

    /**
     * @param array{reason?: string, rows: list<array<string, mixed>>} $set
     * @return array{survivor_id: int, loser_ids: list<int>, tags: list<int>, lists: list<int>, fields: array<string, mixed>}
     */
    public function preview(array $set, string $strategy): array
    {
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
        $survivorId = SurvivorSelector::pick(array_map(
            /**
             * @param array<string, mixed> $r
             * @return array{id: int, created_at: string, activity_score: int}
             */
            static fn (array $r): array => [
                'id' => (int) $r['id'],
                'created_at' => (string) $r['created_at'],
                'activity_score' => (int) ($r['activity_score'] ?? 0),
            ],
            $rows
        ), $strategy);

        $survivor = null;
        $losers = [];
        foreach ($rows as $r) {
            if ((int) $r['id'] === $survivorId) {
                $survivor = $r;
            } else {
                $losers[] = $r;
            }
        }

        $tags = $survivor['tag_ids'] ?? [];
        $lists = $survivor['list_ids'] ?? [];
        $fields = $survivor['custom_fields'] ?? [];
        foreach ($losers as $l) {
            $tags = array_merge($tags, $l['tag_ids'] ?? []);
            $lists = array_merge($lists, $l['list_ids'] ?? []);
            foreach (($l['custom_fields'] ?? []) as $k => $v) {
                if ((!isset($fields[$k]) || $fields[$k] === '') && $v !== '') {
                    $fields[$k] = $v;
                }
            }
        }

        return [
            'survivor_id' => $survivorId,
            'loser_ids'   => array_map(static fn ($l): int => (int) $l['id'], $losers),
            'tags'        => array_values(array_unique(array_map('intval', $tags))),
            'lists'       => array_values(array_unique(array_map('intval', $lists))),
            'fields'      => $fields,
        ];
    }

    /**
     * @param array{reason?: string, rows: list<array<string, mixed>>} $set
     */
    public function merge(array $set, string $strategy, bool $includeWoo): int
    {
        $p = $this->preview($set, $strategy);
        // schema 2 = the snapshot was built from an authoritative server-side read at
        // merge time (not the client's scan payload), so it is complete enough for the
        // Pro restore to re-create losers and revert the survivor. schema 1 journals
        // (v1.0) cannot be fully restored.
        $journalId = $this->journal->record($p['survivor_id'], $p['loser_ids'], [
            'schema' => 2, 'set' => $set, 'preview' => $p, 'include_woo' => $includeWoo,
        ]);
        $this->gw->attachTags($p['survivor_id'], $p['tags']);
        $this->gw->attachLists($p['survivor_id'], $p['lists']);
        $this->gw->syncCustomFields($p['survivor_id'], $p['fields']);
        // include_woo is reserved for a later version; no Woo re-pointing is wired yet.
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
        foreach ($p['loser_ids'] as $id) {
            $this->gw->deleteContact($id);
        }
        return $journalId;
    }
}
