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
     * @param \wpdb $wpdb WordPress DB layer, or a test double exposing get_results(string $sql): array.
     */
    public function __construct(private object $wpdb, private string $subscribersTable)
    {
    }

    /**
     * @return list<array{key: string, reason: string, rows: list<array<string, mixed>>}>
     */
    public function detect(): array
    {
        // Table name is a constructor-supplied identifier, not user input; there are no query values to prepare.
        // status is selected so the scan payload and every snapshot carry it, letting the
        // merge path refuse mixed-status sets and restore rebuild a loser's original status.
        $result = $this->wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT id, email, first_name, last_name, phone, status, created_at FROM {$this->subscribersTable}"
        );
        $rows = is_array($result) ? $result : [];

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
