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

class UndoJournal
{
    /**
     * @param \wpdb $wpdb WordPress DB layer, or a test double exposing prefix, insert(), get_row(), insert_id.
     */
    public function __construct(private object $wpdb)
    {
    }

    private function table(): string
    {
        return $this->wpdb->prefix . 'fcdm_undo_journal';
    }

    /**
     * @param int[]                $losers
     * @param array<string, mixed> $movedPayload
     */
    public function record(int $survivorId, array $losers, array $movedPayload): int
    {
        $ok = $this->wpdb->insert($this->table(), [
            'survivor_id' => $survivorId,
            'loser_ids'   => implode(',', array_map('intval', $losers)),
            'payload'     => wp_json_encode_safe($movedPayload),
            'restored'    => 0,
        ]);
        $id = (int) $this->wpdb->insert_id;
        // wpdb::insert returns false (it does not throw) when the row cannot be written:
        // journal table missing (e.g. activation never ran on a multisite subsite), payload
        // over max_allowed_packet, or any DB error. On failure insert_id is not updated, so a
        // blind return would hand back 0 or a stale id and let the merge delete contacts with
        // no snapshot. Fail loudly instead, matching the encode-failure guard.
        if ($ok === false || $id < 1) {
            throw new SnapshotException(
                'Failed to write the undo-journal snapshot; aborting so no merge proceeds without a restorable snapshot.'
            );
        }
        return $id;
    }

    /**
     * Most-recent journal entries (metadata only, newest first) for the merge-history
     * panel. Deliberately omits the payload, which can be large; the id is enough to
     * drive a per-row restore through undo().
     *
     * @return list<array{id: int, survivor_id: int, loser_ids: list<int>, restored: bool, created_at: string}>
     */
    public function recent(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $rows = $this->wpdb->get_results(
            // Table name derives from $wpdb->prefix and $limit is clamped to 1-200;
            // nothing user-controlled is interpolated, matching load()/claimRestore.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT id, survivor_id, loser_ids, restored, created_at FROM {$this->table()} ORDER BY id DESC LIMIT {$limit}"
        );
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_object($row)) {
                continue;
            }
            $r = get_object_vars($row);
            $losers = array_values(array_filter(array_map('intval', explode(',', (string) ($r['loser_ids'] ?? '')))));
            $out[] = [
                'id'          => (int) ($r['id'] ?? 0),
                'survivor_id' => (int) ($r['survivor_id'] ?? 0),
                'loser_ids'   => $losers,
                'restored'    => (int) ($r['restored'] ?? 0) === 1,
                'created_at'  => (string) ($r['created_at'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(int $journalId): ?array
    {
        // Table name derives from $wpdb->prefix and $journalId is a typed int; nothing user-controlled is interpolated.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $this->wpdb->get_row("SELECT * FROM {$this->table()} WHERE id = {$journalId}");
        $payload = is_object($row) ? (get_object_vars($row)['payload'] ?? null) : null;
        if (!is_string($payload)) {
            return null;
        }
        $data = json_decode($payload, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Atomically claim the journal for restore: flips restored 0 -> 1 and returns
     * true only for the caller that won the claim. A concurrent or retried undo
     * gets false, so a restore runs at most once and can never duplicate contacts.
     */
    public function claimRestore(int $journalId): bool
    {
        // Table derives from $wpdb->prefix and $journalId is a typed int; nothing user-controlled is interpolated.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $affected = $this->wpdb->query("UPDATE {$this->table()} SET restored = 1 WHERE id = {$journalId} AND restored = 0");
        // wpdb::query returns false on a DB error and an int row-count otherwise. A bare
        // (int) cast collapses that false to 0, making a real database failure look
        // identical to "already claimed" (0 rows) - the caller would then tell the user
        // the merge was already restored when in truth no restore ran. Fail loudly so a
        // transient DB error is never silently swallowed as a no-op.
        if ($affected === false) {
            throw new SnapshotException(
                'Failed to claim the undo journal for restore (database error); aborting so the restore is not misreported as already done.'
            );
        }
        return (int) $affected === 1;
    }

    /**
     * Hand a claim back, for a restore that refused BEFORE writing anything.
     *
     * The claim exists so a restore runs at most once: once contacts have started coming
     * back a retry could duplicate them, so a mid-flight failure deliberately keeps the
     * claim and the entry stays spent. That reasoning does not apply to a refusal that
     * happens before the first write. The WooCommerce revert runs FIRST inside the Restorer
     * and can refuse outright - WooCommerce deactivated, or an order edited since the merge
     * - having changed nothing at all. Keeping the claim there burned the entry forever:
     * the operator fixed the cause, clicked Undo again, lost the claim, and was told the
     * merge had ALREADY been undone, while the contacts were still deleted and the orders
     * still sat on the survivor. That is the most misleading state this plugin can produce.
     *
     * Guarded on `restored = 1`, so this can only ever hand back a claim that is currently
     * held. A DB error is loud for the same reason it is loud in claimRestore(): silently
     * failing to release would strand the entry with nobody aware of it.
     */
    public function releaseRestore(int $journalId): void
    {
        // Table derives from $wpdb->prefix and $journalId is a typed int; nothing user-controlled is interpolated.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $affected = $this->wpdb->query("UPDATE {$this->table()} SET restored = 0 WHERE id = {$journalId} AND restored = 1");
        if ($affected === false) {
            throw new SnapshotException(
                'Failed to release the undo-journal claim after a restore that wrote nothing; the entry may be stranded.'
            );
        }
    }

    /**
     * Drop the `woo` section from an entry and put its schema back to 3.
     *
     * Called by the merge when the order re-point failed and PROVABLY put every order back.
     * The journal is written before the orders move, because a snapshot taken afterwards is
     * not a snapshot - so at that point the entry describes a re-point that did not survive.
     * Left alone, a later restore would read those order ids, find them at customer_id 0
     * rather than on the survivor, and refuse as drift forever: an entry unrestorable by
     * construction, over orders that are already exactly where the restore wanted to put
     * them.
     *
     * Only ever called when the rollback is known to have worked. If the put-back itself
     * failed, some orders really may still be on the survivor and the `woo` section is the
     * only record of which ones, so it stays.
     *
     * Failure here is logged and swallowed by the caller rather than thrown: the merge is
     * already failing for another reason, and that reason is the one the operator needs.
     */
    public function stripWooSection(int $journalId): void
    {
        $payload = $this->load($journalId);
        if ($payload === null || !array_key_exists('woo', $payload)) {
            return;
        }
        unset($payload['woo']);
        $payload['schema'] = 3;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ok = $this->wpdb->update(
            $this->table(),
            ['payload' => wp_json_encode_safe($payload)],
            ['id' => $journalId]
        );
        if ($ok === false) {
            throw new SnapshotException(
                'Failed to remove the woo section from the undo-journal entry after a rolled-back re-point.'
            );
        }
    }

    public function isRestored(int $journalId): bool
    {
        // Table name derives from $wpdb->prefix and $journalId is a typed int; nothing user-controlled is interpolated.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $this->wpdb->get_row("SELECT restored FROM {$this->table()} WHERE id = {$journalId}");
        if (!is_object($row)) {
            return false;
        }
        return (int) (get_object_vars($row)['restored'] ?? 0) === 1;
    }
}
