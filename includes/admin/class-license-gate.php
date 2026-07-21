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

final class LicenseGate
{
    /**
     * Whether the current user may use Pro features.
     *
     * Freemius is the source of truth: every distributed build bundles the SDK, so
     * `fdm_fs()->can_use_premium_code()` (a real license/trial check) is the only
     * thing that unlocks Pro in production. The old `fcdm_is_pro` filter can no longer
     * grant Pro there; it survives only as a dev/CI aid for environments where the SDK
     * is not loaded (unit tests, a source checkout without /freemius).
     *
     * Named isPro() (camelCase) per Global Constraints naming convention; the REST
     * responses still expose this as the JSON key "is_pro".
     */
    public function isPro(): bool
    {
        if (function_exists('fdm_fs')) {
            return fdm_fs()->can_use_premium_code();
        }

        return (bool) apply_filters('fcdm_is_pro', false);
    }
}
