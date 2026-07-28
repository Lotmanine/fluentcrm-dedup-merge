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
 * Raised when the (Pro) WooCommerce order re-point refuses to run, refuses to finish, or
 * refuses to be reverted.
 *
 * WHY THIS IS ALWAYS FATAL TO THE REQUEST. This is the only part of the plugin that edits
 * financial records. Every refusal it can raise means one of two things: either the
 * operator asked for something that cannot be done safely, or the orders on disk are not
 * the orders the snapshot describes. In both cases the correct behaviour is to stop the
 * whole request. Applying the part we still understand and skipping the rest is precisely
 * the failure mode this build keeps eliminating, because it produces a success message
 * over a half-finished edit to someone's invoices.
 *
 * It extends RuntimeException rather than InvalidArgumentException because most of these
 * are state failures rather than bad input: the order changed under us, a write failed, a
 * rollback failed. The REST layer catches this type explicitly, ahead of its catch-all, so
 * the operator gets the specific wording below instead of a masked 500.
 *
 * WHY THIS CLASS IS FREE-SHIPPED (no `__premium_only` suffix) even though only the Pro
 * build can ever throw it: the shared merge core in the FREE RestController is where the
 * catch has to live, so the free build must be able to name the type. This is the same
 * arrangement ScanFilterException already has, and getting it wrong would fatal the free
 * tier's own merge route on a `catch` for a class that is not there.
 *
 * EVERY MESSAGE CARRIES A SUGGESTED HTTP STATUS, because the three families need different
 * ones: "you asked for something that is not allowed here" is a 400 the operator can fix by
 * unticking a box, "the orders moved under you" is a 409 they fix by re-scanning, and "a
 * write failed halfway" is a 500 that needs the error log. Folding all three into one
 * status would tell the operator to retry something they must not retry.
 *
 * NONE of these interpolate an email, an order id or any other part of the payload. They
 * land in an error log and, on a debug install, in an HTTP response.
 *
 * WHY THE LONGER MESSAGES ARE SEVERAL `__()` CALLS JOINED WITH A SPACE, rather than one
 * call over a concatenated string: gettext extracts the literal it is given, so a
 * concatenation inside `__()` produces a string that no translator ever sees. Each call
 * below therefore holds one complete sentence, which is extractable on its own and reads
 * as a unit in a .po file. Nothing is escaped, deliberately - these are returned as JSON,
 * where HTML entities would appear literally in the operator's error message.
 */
final class WooRepointException extends \RuntimeException
{
    /**
     * The HTTP status the REST layer should answer with. Carried on the exception rather
     * than inferred at the catch site so a new refusal cannot be added without deciding,
     * at the point it is written, whether it is the operator's mistake or the site's.
     */
    private int $status = 500;

    /**
     * Whether the orders are provably back where they started when this was thrown.
     *
     * The merge writes its journal entry - including the `woo` section listing the orders
     * it is about to move - BEFORE it moves them, because a snapshot taken after the write
     * is not a snapshot. So when the move then fails and rolls itself back, the journal is
     * left describing a re-point that did not survive, and a later restore would look at
     * those orders, find them at customer_id 0 instead of the survivor, and refuse as drift
     * forever. Marking the difference here lets the merge repair its own journal entry in
     * the one case where it safely can: a rollback that is known to have worked.
     *
     * False for rollbackFailed(), where some orders really may still be on the survivor and
     * the `woo` section is the only record of which ones.
     */
    private bool $ordersLeftUnmoved = false;

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function ordersLeftUnmoved(): bool
    {
        return $this->ordersLeftUnmoved;
    }

    private static function make(string $message, int $status, bool $ordersLeftUnmoved = false): self
    {
        $e = new self($message);
        $e->status = $status;
        $e->ordersLeftUnmoved = $ordersLeftUnmoved;
        return $e;
    }

    /**
     * `include_woo: true` arrived on a set that fails one of the eleven refusals.
     *
     * Refusing the whole merge is the contract, and the reason is worth restating: the
     * merge DELETES contacts. An operator who ticked the box asked for their orders to
     * follow the survivor. Merging without the re-point would delete those contacts under
     * a premise that was never true, and they would find out later, from their accounting.
     */
    public static function notEligible(?string $reason = null): self
    {
        $message = __('These WooCommerce orders cannot be moved, so the merge was not run.', 'dedup-and-merge-for-fluentcrm')
            . ' ' . __('Untick the order option to merge the contacts on their own.', 'dedup-and-merge-for-fluentcrm');
        // The specific refusal is appended when the caller knows it. It matters most in the
        // case that brings an operator here at all: the preview said eligible, they ticked
        // the box, and something changed before they confirmed. Without the reason they
        // would be looking at a screen that still shows the option they were just refused.
        // WooEligibility's reason strings are already translated and carry no part of the
        // payload, so they are safe to concatenate and hand back.
        if ($reason !== null && $reason !== '') {
            $message .= ' ' . $reason;
        }
        // Nothing was written: this refuses before the first order is touched.
        return self::make($message, 400, true);
    }

    /**
     * An order that was a guest order when the snapshot was taken now belongs to a real
     * account, or belonged to one all along.
     *
     * Refusal 3: only the direction `0 -> survivor` is ever written. A non-zero
     * `customer_id` is somebody's account, and FluentCRM itself declines to attribute such
     * an order to a contact by billing email alone. We are not entitled to be less careful
     * than the CRM we are extending.
     */
    public static function orderNoLongerGuest(): self
    {
        return self::make(
            __('At least one of these orders is no longer a guest order, so nothing was changed.', 'dedup-and-merge-for-fluentcrm')
            . ' ' . __('Re-scan and try again.', 'dedup-and-merge-for-fluentcrm'),
            409,
            true
        );
    }

    /**
     * `wc_get_order()` could not load an id the order query had just returned, or the row
     * came back as a type other than `shop_order`.
     *
     * Refusal 8: refunds are in `wc_get_orders()`'s default type set and `WC_Order_Refund`
     * has no `set_customer_id()`, so an unpinned loop fatals on the first refund it meets.
     * The query pins the type; this is the second lock, on the object that came back.
     */
    public static function orderUnreadable(): self
    {
        return self::make(
            __('At least one of these orders could not be read as a shop order, so nothing was changed.', 'dedup-and-merge-for-fluentcrm')
            . ' ' . __('Re-scan and try again.', 'dedup-and-merge-for-fluentcrm'),
            409,
            true
        );
    }

    /**
     * A write failed partway through the set and every order already written was put back.
     *
     * Refusal 10 says never partially apply. Detecting a bad order before writing covers
     * the ordinary case; this covers the one that cannot be detected in advance, where the
     * third or fourth `save()` is the one that fails. Rolling the earlier ones back is what
     * makes "all or nothing" true rather than merely intended.
     */
    public static function writeFailedAndRolledBack(): self
    {
        return self::make(
            __('A WooCommerce order could not be saved.', 'dedup-and-merge-for-fluentcrm')
            . ' ' . __('Every order this merge had already moved was put back, and no contact was removed.', 'dedup-and-merge-for-fluentcrm') // phpcs:ignore Generic.Files.LineLength.TooLong -- One translatable sentence.
            // Deliberately does NOT claim the contacts were untouched. By the time the
            // orders are written the survivor has already received the losers' tags, lists
            // and fields; only the deletion did not happen. Telling an operator nothing was
            // merged would send them looking for a change that is really there.
            . ' ' . __('The surviving contact may already carry the merged tags, lists and fields, so check it before running the merge again.', 'dedup-and-merge-for-fluentcrm') // phpcs:ignore Generic.Files.LineLength.TooLong -- One translatable sentence.
            . ' ' . __('See the site error log.', 'dedup-and-merge-for-fluentcrm'),
            500,
            true
        );
    }

    /**
     * The rollback itself failed. This is the loudest message in the plugin and it is meant
     * to be: some orders are now attributed to the survivor and some are not, and no
     * automatic path exists to finish the job. The operator has to be sent to the log with
     * the order ids, because only they can decide what the correct end state is.
     */
    public static function rollbackFailed(): self
    {
        return self::make(
            __('A WooCommerce order could not be saved and the automatic put-back also failed.', 'dedup-and-merge-for-fluentcrm')
            . ' ' . __('Some orders may have been moved and others not. The contacts were NOT merged.', 'dedup-and-merge-for-fluentcrm')
            . ' ' . __('Check the site error log for the affected order ids before doing anything else.', 'dedup-and-merge-for-fluentcrm'),
            500
        );
    }

    /**
     * A revert was asked for on an order whose `customer_id` is no longer the survivor's.
     *
     * Somebody edited that order after the merge. Writing the snapshot value back would
     * clobber their edit, and this feature has no way to know whether that edit mattered
     * more than the merge did. Refusing the WHOLE revert rather than skipping the drifted
     * order is deliberate: a revert that quietly leaves one invoice attributed to the wrong
     * customer, while reporting success, is worse than a revert that did not run.
     */
    public static function revertDrift(): self
    {
        return self::make(
            __('At least one of these orders has been edited or removed since the merge.', 'dedup-and-merge-for-fluentcrm')
            . ' ' . __('The revert was not run and nothing was changed.', 'dedup-and-merge-for-fluentcrm')
            . ' ' . __('Review those orders in WooCommerce and set them by hand.', 'dedup-and-merge-for-fluentcrm'),
            409,
            true
        );
    }

    /**
     * The snapshot moved WooCommerce orders, but WooCommerce cannot be reached now.
     *
     * Reverting the FluentCRM half and skipping the orders would report a successful
     * restore over a set of invoices still attributed to the survivor. Refuse instead, and
     * say what has to be true before the restore can run.
     */
    public static function revertUnavailable(): self
    {
        return self::make(
            __('This merge moved WooCommerce orders, and WooCommerce is not available to move them back.', 'dedup-and-merge-for-fluentcrm')
            . ' ' . __('Nothing was restored. Activate WooCommerce and try again.', 'dedup-and-merge-for-fluentcrm'),
            409,
            true
        );
    }

    /**
     * A write failed during a revert, after the verification pass had passed. Same shape as
     * writeFailedAndRolledBack(), on the way back.
     */
    /**
     * A schema-4 snapshot lists orders to put back but carries no destination to put them
     * back FROM, so the revert cannot verify a single one of them.
     *
     * Refused rather than reported as "nothing to revert". apply() already refuses exactly
     * this input, and the two paths answering differently is how a restore came to report
     * full success while every order stayed on the survivor. The schema ceiling does not
     * cover it: that guards against a snapshot from a NEWER version, not a damaged one from
     * this version.
     *
     * Nothing has been written when this is thrown, so the journal claim is handed back and
     * the entry stays restorable once whatever damaged it is understood.
     */
    public static function revertUnusableSnapshot(): self
    {
        return self::make(
            __('This merge recorded WooCommerce orders but not the account they were moved to, so they cannot be put back automatically.', 'dedup-and-merge-for-fluentcrm') // phpcs:ignore Generic.Files.LineLength.TooLong -- One translatable sentence.
            . ' ' . __('Nothing was changed. See the site error log before undoing this merge.', 'dedup-and-merge-for-fluentcrm'),
            409,
            true
        );
    }

    public static function revertWriteFailed(): self
    {
        return self::make(
            __('A WooCommerce order could not be saved while reverting.', 'dedup-and-merge-for-fluentcrm')
            . ' ' . __('Some orders may still be attributed to the surviving contact.', 'dedup-and-merge-for-fluentcrm')
            . ' ' . __('Nothing else was restored. Check the site error log for the order ids.', 'dedup-and-merge-for-fluentcrm'),
            500
        );
    }
}
