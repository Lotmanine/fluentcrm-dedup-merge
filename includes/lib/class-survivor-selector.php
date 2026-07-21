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

final class SurvivorSelector
{
    /**
     * @param list<array{id: int, created_at: string, activity_score: int}> $rows
     */
    public static function pick(array $rows, string $strategy = 'oldest'): int
    {
        if ($rows === []) {
            throw new \InvalidArgumentException('Cannot pick a survivor from an empty set.');
        }

        usort($rows, static function (array $a, array $b) use ($strategy): int {
            if ($strategy === 'most_active') {
                $s = $b['activity_score'] <=> $a['activity_score'];
                if ($s !== 0) {
                    return $s;
                }
            }

            $c = strcmp($a['created_at'], $b['created_at']); // oldest first
            if ($c !== 0) {
                return $c;
            }

            return $a['id'] <=> $b['id'];
        });

        return (int) $rows[0]['id'];
    }
}
