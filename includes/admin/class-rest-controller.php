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

use OLDEV\FcDedup\Lib\{DuplicateDetector, Merger, UndoJournal, FluentCrmGateway, SnapshotException, DuplicateKey};

/**
 * Free-tier REST surface. Not final: the Pro controller (RestControllerPro, a
 * premium-only file stripped from the free build) extends this to add the bulk
 * and restore routes, reusing the protected merge core below.
 */
class RestController
{
    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            $perm = static fn () => current_user_can(OLDEV_FCDEDUP_CAP);
            $ns = OLDEV_FCDEDUP_REST_NS;
            register_rest_route($ns, '/scan', ['methods' => 'GET', 'callback' => [$this, 'scan'], 'permission_callback' => $perm]);
            register_rest_route($ns, '/preview', ['methods' => 'POST', 'callback' => [$this, 'preview'], 'permission_callback' => $perm]);
            register_rest_route($ns, '/merge', ['methods' => 'POST', 'callback' => [$this, 'merge'], 'permission_callback' => $perm]);
            register_rest_route($ns, '/undo', ['methods' => 'POST', 'callback' => [$this, 'undo'], 'permission_callback' => $perm]);
            register_rest_route($ns, '/journal', ['methods' => 'GET', 'callback' => [$this, 'journal'], 'permission_callback' => $perm]);
            register_rest_route($ns, '/meta', ['methods' => 'GET', 'callback' => [$this, 'meta'], 'permission_callback' => $perm]);
        });
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

    public function scan(): \WP_REST_Response
    {
        $sets = $this->detector()->detect();
        foreach ($sets as &$set) {
            foreach ($set['rows'] as &$row) {
                $row['tag_ids']        = $this->pivotIds((int) $row['id'], 'tag');
                $row['list_ids']       = $this->pivotIds((int) $row['id'], 'list');
                $row['custom_fields']  = $this->customFields((int) $row['id']);
                $row['activity_score'] = 0;
            }
        }
        return new \WP_REST_Response(['sets' => $sets], 200);
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
        try {
            return new \WP_REST_Response($this->merger()->preview($set, $strategy), 200);
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

        $res = $this->mergeOneSet($ids, $reason, $strategy, $includeWoo);
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
     * @param list<int> $ids
     * @return array{ok: bool, journal_id?: int, survivor_id?: int, loser_ids?: list<int>, error?: string, status?: int}
     */
    protected function mergeOneSet(array $ids, string $reason, string $strategy, bool $includeWoo): array
    {
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
            $preview  = $this->merger()->preview($mergeSet, $strategy);
            $journalId = $this->merger()->merge($mergeSet, $strategy, $includeWoo);
        } catch (SnapshotException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'status' => 500];
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
     * Fresh authoritative read of one contact in the shape the Merger consumes.
     * Reads identity fields from fc_subscribers by id, plus tag/list ids and
     * custom fields through the same helpers scan uses, so a merge snapshot
     * always matches what the scan showed. Returns null if the contact is gone.
     *
     * @return array{
     *     id: int, email: string, first_name: string, last_name: string, phone: string,
     *     status: string, created_at: string, activity_score: int, tag_ids: list<int>,
     *     list_ids: list<int>, custom_fields: array<string, mixed>
     * }|null
     */
    protected function readContactRow(int $id): ?array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'fc_subscribers';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $base = $wpdb->get_row($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT id, email, first_name, last_name, phone, status, created_at FROM {$t} WHERE id = %d",
            $id
        ));
        if (!is_object($base)) {
            return null;
        }
        $b = get_object_vars($base);
        return [
            'id'             => (int) ($b['id'] ?? 0),
            'email'          => (string) ($b['email'] ?? ''),
            'first_name'     => (string) ($b['first_name'] ?? ''),
            'last_name'      => (string) ($b['last_name'] ?? ''),
            'phone'          => (string) ($b['phone'] ?? ''),
            // Captured so the merge can refuse mixed-status sets and a restore can bring
            // a loser back with its original subscription status (not a fresh default).
            'status'         => (string) ($b['status'] ?? ''),
            'created_at'     => (string) ($b['created_at'] ?? ''),
            'activity_score' => 0,
            'tag_ids'        => $this->pivotIds($id, 'tag'),
            'list_ids'       => $this->pivotIds($id, 'list'),
            'custom_fields'  => $this->customFields($id),
        ];
    }

    /**
     * FluentCRM stores model class names (not short labels) in
     * fc_subscriber_pivot.object_type. The map below is filterable so it can
     * be corrected without a code release if the confirmed values differ.
     *
     * Note: exact object_type values must be verified against a live
     * FluentCRM install on wp.oldev.site before relying on them in production
     * (Global Constraints unconfirmed-internals rule).
     *
     * @return list<int>
     */
    protected function pivotIds(int $subscriberId, string $objectType): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'fc_subscriber_pivot';
        $map = apply_filters('fcdm_pivot_object_type_map', [
            'tag'  => 'FluentCrm\\App\\Models\\Tag',
            'list' => 'FluentCrm\\App\\Models\\Lists',
        ]);
        $subId = $subscriberId;
        $objVal = $map[$objectType];
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
