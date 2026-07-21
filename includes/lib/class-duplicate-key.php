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
 * The single source of truth for reducing a contact to a duplicate grouping key
 * per reason. Used by DuplicateDetector to form sets, and by the merge path to
 * re-verify server side that a set still collides before it deletes anything.
 * Keeping both on one implementation prevents the two from drifting apart.
 */
final class DuplicateKey
{
    /**
     * Returns the normalized grouping key for a contact under a reason, or ''
     * when the contact does not qualify for that reason (and so must not group).
     *
     * @param array<string, mixed> $contact Reads email / phone / first_name / last_name as available.
     */
    public static function keyFor(string $reason, array $contact): string
    {
        return match ($reason) {
            'email' => EmailNormalizer::normalize((string) ($contact['email'] ?? '')),
            'phone' => self::phone((string) ($contact['phone'] ?? '')),
            'name'  => self::name(
                (string) ($contact['first_name'] ?? ''),
                (string) ($contact['last_name'] ?? '')
            ),
            default => '',
        };
    }

    /**
     * Returns the single non-empty key shared by EVERY row under a reason, or ''
     * if the rows do not form one clean duplicate group: fewer than two rows,
     * any row that does not qualify for the reason, or more than one distinct
     * key. Used to re-verify a set server side before a destructive merge, so a
     * contact that no longer qualifies cannot ride into the merge and be deleted.
     *
     * @param array<int, mixed> $rows
     */
    public static function sharedKey(array $rows, string $reason): string
    {
        if (count($rows) < 2) {
            return '';
        }
        $keys = [];
        foreach ($rows as $row) {
            $key = self::keyFor($reason, is_array($row) ? $row : []);
            if ($key === '') {
                return ''; // a disqualified row invalidates the whole set
            }
            $keys[$key] = true;
        }
        return count($keys) === 1 ? (string) array_key_first($keys) : '';
    }

    /**
     * Returns true only when EVERY row carries the identical subscription status,
     * so a merge cannot silently change a contact's mailable/suppressed state. A set
     * that mixes statuses (e.g. a subscribed contact and an unsubscribed alias)
     * returns false and must be refused: merging it would delete the record holding
     * one status and leave the survivor on another, resurrecting an opt-out or losing
     * a suppression. A missing status counts as its own distinct value, so an
     * unknown-status row can never merge with a known one. Uniformly suppressed sets
     * (all unsubscribed, all bounced) are uniform and therefore allowed - the survivor
     * simply keeps that same suppressed state.
     *
     * @param array<int, mixed> $rows
     */
    public static function uniformStatus(array $rows): bool
    {
        $statuses = [];
        foreach ($rows as $row) {
            $r = is_array($row) ? $row : [];
            $statuses[(string) ($r['status'] ?? '')] = true;
        }
        return count($statuses) <= 1;
    }

    private static function phone(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);
        // At least 7 digits and not a single repeated digit, so placeholder
        // values like '0' or '0000000' do not group unrelated contacts.
        if (strlen($digits) < 7 || preg_match('/^(\d)\1+$/', $digits) === 1) {
            return '';
        }
        return $digits;
    }

    private static function name(string $first, string $last): string
    {
        $first = strtolower(trim($first));
        $last  = strtolower(trim($last));
        // Both fields required: a single shared field (e.g. two contacts named
        // only "Smith") is too weak a signal and invites bad merges.
        if ($first === '' || $last === '') {
            return '';
        }
        return $first . '|' . $last;
    }
}
