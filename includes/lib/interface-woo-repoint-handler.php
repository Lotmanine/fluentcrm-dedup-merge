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
 * The two steps the Merger needs from the (Pro) WooCommerce order re-point, and nothing
 * else. WooRepointer is the only implementation.
 *
 * WHY AN INTERFACE, AND WHY IT IS FREE-SHIPPED (no `__premium_only` suffix).
 * The re-point has to happen INSIDE the merge sequence, not around it: the snapshot must
 * be captured before the journal row is written, and the orders must be moved before any
 * contact is deleted, so that a failure leaves the losers intact. That means Merger has to
 * name the type - and Merger ships in the free build, where the Freemius generator has
 * deleted every `__premium_only` file. A type hint on a deleted class is a fatal on the
 * free tier's own merge route. So the SEAM is free-shipped and inert (nothing in the free
 * build ever constructs an implementation, and Merger is called with null), while the
 * implementation, the WooCommerce gateway and the eligibility rules all stay premium-only.
 * ScanFilter is free-shipped for exactly the same reason; this is that precedent, applied.
 *
 * WHY TWO METHODS AND NOT ONE. Capture is a pure read that must complete before anything
 * is written anywhere, because its result goes into the undo journal and the journal is
 * what makes the merge reversible at all. Apply is the write. Splitting them is what lets
 * Merger interleave them correctly with the journal write and the loser deletion; a single
 * "do it" method would force the snapshot and the write to happen at the same moment, and
 * there would then be no ordering in which the journal could contain the snapshot.
 */
interface WooRepointHandler
{
    /**
     * Read the orders that would move and return the undo-journal `woo` section, WITHOUT
     * writing anything. Called before the journal row is written, so a refusal here aborts
     * the merge with nothing changed at all.
     *
     * @return array<string, mixed>
     * @throws WooRepointException When the set is not eligible, or an order refuses.
     */
    public function capture(): array;

    /**
     * Move the captured orders to the survivor. All or nothing: the orders are re-verified
     * first, and anything already written is put back if a later write fails.
     *
     * @param  array<string, mixed> $snapshot The section capture() returned.
     * @throws WooRepointException When an order refuses, or a write fails.
     */
    public function apply(array $snapshot): void;
}
