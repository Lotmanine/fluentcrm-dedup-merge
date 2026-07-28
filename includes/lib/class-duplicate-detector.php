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

final class DuplicateDetector
{
    /**
     * The default ceiling on how many contact rows a FILTERED scan will consider.
     *
     * Capping is not a nicety. fc_subscriber_meta has no index on `key` or `value` (only
     * on subscriber_id and object_type, and object_type has three values so it barely
     * selects), so a custom-field filter is effectively a table scan. Bounding the
     * candidate set is what keeps that scan from timing out on a large list, and the
     * spike's explicit instruction was to cap and paginate rather than add an index to
     * a table this plugin does not own.
     *
     * The free, unfiltered scan is NOT capped: it is unchanged from v1.2 in every
     * respect, and changing it would alter what a free user sees.
     */
    public const DEFAULT_CANDIDATE_CAP = 5000;

    /**
     * Ceiling for the filtered candidate cap. Well below PHP_INT_MAX on purpose: the
     * query asks for `cap + 1` rows, and near the integer limit that addition overflows
     * into a float which sprintf('%d') renders as a negative number, producing a LIMIT
     * MySQL rejects. Ten million is far past any list this plugin can usefully group in
     * one request, so the ceiling costs nothing real and removes the overflow entirely.
     */
    public const MAX_CANDIDATE_CAP = 10000000;

    /**
     * @param \wpdb $wpdb WordPress DB layer, or a test double exposing get_results(string $sql): array
     *                    and, for a filtered scan, prepare(string $sql, array $args): string.
     */
    public function __construct(private object $wpdb, private string $subscribersTable)
    {
    }

    /**
     * The duplicate sets, optionally narrowed by a (Pro) filter.
     *
     * Kept returning just the sets so every existing caller is untouched. A caller that
     * also needs to report how many contacts were considered and whether the cap
     * truncated them calls scan() instead, which this method is a thin wrapper over.
     *
     * @param  ScanFilter|null $filter A compiled filter, or null for the free unfiltered scan.
     * @return list<array{key: string, reason: string, rows: list<array<string, mixed>>}>
     */
    public function detect(?ScanFilter $filter = null, int $cap = self::DEFAULT_CANDIDATE_CAP): array
    {
        return $this->scan($filter, $cap)['sets'];
    }

    /**
     * The duplicate sets plus what the caller needs to describe the scan honestly.
     *
     * `scanned` is how many contact rows were actually grouped, and `capped` says whether
     * the cap cut the candidate set short. The UI needs both: without them a truncated
     * result reads as "these are all your duplicates", which on a screen whose next
     * button deletes contacts is the difference between a partial answer and a wrong one.
     *
     * @param  ScanFilter|null $filter
     * @return array{
     *     sets: list<array{key: string, reason: string, rows: list<array<string, mixed>>}>,
     *     scanned: int, capped: bool, cap: int
     * }
     */
    public function scan(?ScanFilter $filter = null, int $cap = self::DEFAULT_CANDIDATE_CAP): array
    {
        $cap    = self::sanitiseCap($cap);
        $capped = false;

        if ($filter === null || $filter->isEmpty()) {
            // THE FREE PATH, DELIBERATELY UNTOUCHED. This is byte-for-byte the v1.2 query:
            // no alias, no WHERE, no ORDER BY, no LIMIT and no call to prepare(), so a free
            // scan issues exactly the statement and exactly the number of queries it always
            // did. An empty filter (no groups, or groups with no conditions) lands here too,
            // which is what makes "no narrowing" genuinely identical to an unfiltered scan
            // rather than merely similar to it.
            //
            // Table name is a constructor-supplied identifier, not user input; there are no query values to prepare.
            // status is selected so the scan payload and every snapshot carry it, letting the
            // merge path refuse mixed-status sets and restore rebuild a loser's original status.
            $result = $this->wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT id, email, first_name, last_name, phone, status, created_at FROM {$this->subscribersTable}"
            );
            $rows = is_array($result) ? $result : [];
        } else {
            $rows = $this->filteredRows($filter, $cap);
            // One row more than the cap was asked for precisely so that "there is more"
            // can be detected without a second COUNT query. The extra row is discarded.
            if (count($rows) > $cap) {
                $capped = true;
                $rows   = array_slice($rows, 0, $cap);
            }
        }

        return [
            'sets'    => $this->group($rows),
            'scanned' => count($rows),
            'capped'  => $capped,
            'cap'     => $cap,
        ];
    }

    /**
     * Bring a filtered candidate cap into a range this class can actually honour.
     *
     * `fcdm_scan_candidate_cap` is a filter, so its value is whatever a site owner's code
     * returned, and two natural-looking returns used to break the scan silently:
     *
     * A cap of 0 - the obvious way to write "no cap" - or a negative, or anything
     * non-numeric that cast to 0, was clamped up to 1. Grouping needs at least two rows to
     * find a duplicate, so EVERY filtered scan on that install returned zero sets forever
     * while reporting a clean "1 contact matched". Falling back to the default is the
     * honest reading: this class cannot express "unlimited" (the cap exists because
     * fc_subscriber_meta has no index on `key` or `value`, so an unbounded custom-field
     * filter is a table scan), so an unusable request gets the documented default rather
     * than a number that quietly guarantees no results.
     *
     * At the other end, a cap near PHP_INT_MAX overflowed `$cap + 1` into a float, which
     * sprintf('%d') renders as a NEGATIVE integer - the query became `LIMIT
     * -9223372036854775808`, MySQL refused it, get_results() returned false, and the empty
     * result surfaced as "no duplicates found". A broken query must never read as a clean
     * answer, so the ceiling keeps `$cap + 1` inside integer range.
     */
    private static function sanitiseCap(int $cap): int
    {
        if ($cap < 1) {
            return self::DEFAULT_CANDIDATE_CAP;
        }
        return min($cap, self::MAX_CANDIDATE_CAP);
    }

    /**
     * Run the narrowed candidate query.
     *
     * The filter fragment is interpolated but carries only placeholders, hard-coded
     * identifiers and table names built from $wpdb->prefix (see ScanFilter, which is the
     * one place operator input is turned into SQL); every operator-supplied value travels
     * as an argument to prepare() below. The table name is the constructor-supplied
     * identifier the free path already interpolates.
     *
     * ORDER BY id is what makes the cap reproducible. Without it MySQL is free to return
     * a different arbitrary subset on each run, so two identical scans could offer two
     * different sets of duplicates and "capped" would describe something the operator
     * cannot reproduce. Ascending primary key is also the cheapest deterministic order
     * available here, since it is the table's clustered index.
     *
     * @return array<int, mixed>
     */
    private function filteredRows(ScanFilter $filter, int $cap): array
    {
        $sql = 'SELECT id, email, first_name, last_name, phone, status, created_at'
            . ' FROM ' . $this->subscribersTable . ' ' . ScanFilter::ALIAS
            . ' WHERE ' . $filter->sql()
            . ' ORDER BY ' . ScanFilter::ALIAS . '.id ASC'
            . ' LIMIT %d';

        $args   = $filter->args();
        $args[] = $cap + 1;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, Generic.Files.LineLength.TooLong
        $result = $this->wpdb->get_results($this->wpdb->prepare($sql, $args));
        return is_array($result) ? $result : [];
    }

    /**
     * @param  array<int, mixed> $rows
     * @return list<array{key: string, reason: string, rows: list<array<string, mixed>>}>
     */
    private function group(array $rows): array
    {
        // Key logic lives in DuplicateKey so the merge-time re-verification uses
        // the exact same rules as detection (no drift). Rows are cast to array
        // because the detector reads $wpdb objects while DuplicateKey is data-shape agnostic.
        $sets = [];
        $sets = array_merge($sets, $this->groupBy(
            $rows,
            'email',
            static fn ($r): string => DuplicateKey::keyFor('email', (array) $r),
            true
        ));
        $sets = array_merge($sets, $this->groupBy(
            $rows,
            'phone',
            static fn ($r): string => DuplicateKey::keyFor('phone', (array) $r),
            false
        ));
        $sets = array_merge($sets, $this->groupBy(
            $rows,
            'name',
            static fn ($r): string => DuplicateKey::keyFor('name', (array) $r),
            false
        ));

        return $sets;
    }

    /**
     * @param array<int, mixed>                            $rows
     * @param callable(mixed): string                      $keyFn
     * @return list<array{key: string, reason: string, rows: list<array<string, mixed>>}>
     */
    private function groupBy(array $rows, string $reason, callable $keyFn, bool $requireDistinctRawEmail): array
    {
        $buckets = [];
        foreach ($rows as $r) {
            $key = $keyFn($r);
            if ($key === '') {
                continue;
            }
            $buckets[$key][] = $r;
        }

        $out = [];
        foreach ($buckets as $key => $group) {
            if (count($group) < 2) {
                continue;
            }
            if ($requireDistinctRawEmail) {
                $raw = array_unique(array_map(static fn ($r) => strtolower((string) $r->email), $group));
                if (count($raw) < 2) {
                    continue; // identical raw email = FluentCRM already unique, skip
                }
            }
            $out[] = [
                'key' => (string) $key,
                'reason' => $reason,
                'rows' => array_map(static fn ($r) => (array) $r, $group),
            ];
        }

        return $out;
    }
}
