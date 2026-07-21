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

final class EmailNormalizer
{
    /** Normalize an email for duplicate grouping. Returns '' if invalid. */
    public static function normalize(string $email): string
    {
        $email = strtolower(trim($email));
        if (!str_contains($email, '@') || substr_count($email, '@') !== 1) {
            return '';
        }
        [$local, $host] = explode('@', $email, 2);
        if ($local === '' || $host === '') {
            return '';
        }
        if (in_array($host, ['gmail.com', 'googlemail.com'], true)) {
            $local = strtok($local, '+');   // drop +alias
            $local = str_replace('.', '', $local); // drop dots
        }
        return $local . '@' . $host;
    }
}
