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
 * The single seam that touches FluentCRM. Mock this in tests.
 */
class FluentCrmGateway
{
    /**
     * @return \FluentCrm\App\Models\Subscriber|null
     */
    public function getContactById(int $id): ?object
    {
        $c = \FluentCrmApi('contacts')->getContact($id);
        return $c ?: null;
    }

    /**
     * @param array<int> $tagIds
     */
    public function attachTags(int $survivorId, array $tagIds): void
    {
        if ($tagIds === []) {
            return;
        }
        $this->getContactById($survivorId)?->attachTags(array_values(array_unique($tagIds)));
    }

    /**
     * @param array<int> $listIds
     */
    public function attachLists(int $survivorId, array $listIds): void
    {
        if ($listIds === []) {
            return;
        }
        $this->getContactById($survivorId)?->attachLists(array_values(array_unique($listIds)));
    }

    /**
     * @param array<int> $tagIds
     */
    public function detachTags(int $survivorId, array $tagIds): void
    {
        if ($tagIds === []) {
            return;
        }
        $this->getContactById($survivorId)?->detachTags(array_values(array_unique($tagIds)));
    }

    /**
     * @param array<int> $listIds
     */
    public function detachLists(int $survivorId, array $listIds): void
    {
        if ($listIds === []) {
            return;
        }
        $this->getContactById($survivorId)?->detachLists(array_values(array_unique($listIds)));
    }

    /**
     * @param array<string, mixed> $values
     */
    public function syncCustomFields(int $survivorId, array $values): void
    {
        if ($values === []) {
            return;
        }
        $this->getContactById($survivorId)?->syncCustomFieldValues($values, false);
    }

    /**
     * Re-create a contact from a getContactState()-shaped array: creates the
     * identity record, then reattaches tags/lists/custom fields via the
     * existing gateway methods. Used by the (Pro) restore feature to undo a
     * loser-contact deletion; not used by the v1 merge path.
     *
     * @param array<string, mixed> $state A getContactState()-shaped snapshot row.
     */
    public function recreateContact(array $state): int
    {
        $email = (string) ($state['email'] ?? '');
        if ($email === '') {
            return 0; // cannot safely re-create a contact with no email
        }
        // createOrUpdate is upsert-by-email: if another contact now owns this email
        // (for example it opted in again after the merge), re-creating would overwrite
        // that unrelated contact. Only proceed when the email is genuinely free.
        if (\FluentCrmApi('contacts')->getContact($email)) {
            return 0;
        }

        $payload = [
            'email'      => $email,
            'first_name' => (string) ($state['first_name'] ?? ''),
            'last_name'  => (string) ($state['last_name'] ?? ''),
            'phone'      => (string) ($state['phone'] ?? ''),
        ];
        // Bring the contact back with its original subscription status rather than a fresh
        // default, so restoring an unsubscribed/bounced loser does not resurrect it mailable.
        $status = (string) ($state['status'] ?? '');
        if ($status !== '') {
            $payload['status'] = $status;
        }

        $created = \FluentCrmApi('contacts')->createOrUpdate($payload);

        if (!$created || !isset($created->id)) {
            return 0;
        }

        // Defensive backstop: some FluentCRM versions normalize a newly created contact to
        // 'subscribed'/'pending' regardless of the supplied status. Re-assert the snapshot
        // status so a suppression is never silently dropped on restore. (Verify the exact
        // createOrUpdate status behavior on the live install; this holds either way.)
        if ($status !== '' && isset($created->status) && (string) $created->status !== $status) {
            $created->status = $status;
            if (method_exists($created, 'save')) {
                $created->save();
            }
        }

        $newId = (int) $created->id;
        $this->attachTags($newId, $state['tag_ids'] ?? []);
        $this->attachLists($newId, $state['list_ids'] ?? []);
        $this->syncCustomFields($newId, $state['custom_fields'] ?? []);

        return $newId;
    }

    public function deleteContact(int $id): void
    {
        $c = $this->getContactById($id);
        if ($c && method_exists($c, 'delete')) {
            $c->delete();
        }
    }
}
