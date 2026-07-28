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
     * Write custom field VALUES. Empty values in $values are a no-op, by design.
     *
     * FluentCRM's syncCustomFieldValues($values, $deleteOtherValues = false) filters
     * empty-string values out of the write set and skips its delete branch entirely, so
     * passing ['age' => ''] here changes nothing. That is exactly the semantics the merge
     * wants: a merge only ever fills a field in, it must never clear one. The flag is
     * therefore pinned to false and must stay false - see clearCustomFields() for the
     * separate, explicit path that does clear.
     *
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
     * Clear named custom fields on a contact, for real.
     *
     * This exists because syncCustomFields() above genuinely cannot do it. With
     * $deleteOtherValues = false FluentCRM drops empty values from the write set AND skips
     * the delete branch, which made the Pro restore of a gap-filled custom field a silent
     * no-op in production: the unit tests passed because they mock this gateway, while on a
     * real install the field kept the merged value forever. A restore that reports success
     * without reverting anything is the worst possible failure mode for an undo feature,
     * so the clear path is split out here instead of flipping the flag on the shared one.
     *
     * With $deleteOtherValues = true FluentCRM derives its delete list from the keys it was
     * PASSED, so only the fields named here are cleared; every other custom field on the
     * contact is untouched. That is why passing the exact keys to revert is safe, and why
     * this method takes a key list rather than a value map - there is no value to supply,
     * the only outcome is "these fields become empty".
     *
     * @param list<string> $keys Custom field slugs to clear.
     */
    public function clearCustomFields(int $contactId, array $keys): void
    {
        $keys = array_values(array_unique(array_filter($keys, static fn (string $k): bool => $k !== '')));
        if ($keys === []) {
            return;
        }
        $empties = array_fill_keys($keys, '');
        $this->getContactById($contactId)?->syncCustomFieldValues($empties, true);
    }

    /**
     * The operator-facing label for each custom field, keyed by slug.
     *
     * The field picker asks a human to choose between two competing values, so it has to
     * name the field the way that human named it in FluentCRM. A humanised slug is a poor
     * substitute: "cf_client_ref" reads as "Cf client ref", and picking the wrong value for
     * the wrong field is exactly the mistake this UI exists to prevent.
     *
     * fluentcrm_get_custom_contact_fields() is FluentCRM's own public helper for this. It
     * was verified against FluentCRM 2.9.87 (the version running on the test site) and
     * against a real database: the definitions live in fc_meta under object_type 'option',
     * key 'contact_custom_fields', and each definition carries a 'slug' and a 'label'.
     * Guarded by function_exists() anyway, because this is a third-party helper and the
     * caller must degrade to a humanised slug rather than fatal if it ever goes away.
     *
     * @return array<string, string>
     */
    public function customFieldLabels(): array
    {
        if (!function_exists('fluentcrm_get_custom_contact_fields')) {
            return [];
        }
        $labels = [];
        foreach ((array) \fluentcrm_get_custom_contact_fields() as $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $slug  = (string) ($definition['slug'] ?? '');
            $label = (string) ($definition['label'] ?? '');
            if ($slug !== '' && $label !== '') {
                $labels[$slug] = $label;
            }
        }
        return $labels;
    }

    /**
     * Write the survivor's own fc_subscribers columns (the Pro merge-all-fields path).
     *
     * Only the StandardFields allowlist is written, and only the part of it that this
     * install's table actually has (see StandardFields::existingColumns() for why a column
     * can be missing). Everything else in $values is dropped without comment: this method
     * is reached from a REST payload, so treating it as "write whatever you are handed"
     * would turn /merge into an arbitrary contact-writer.
     *
     * Values are sanitized with sanitize_text_field(), which is byte-identical to what
     * FluentCRM's own Sanitize::contact() applies to all 14 of these columns. That class is
     * an internal, so its behavior is reproduced here rather than called.
     *
     * The write goes through the model (fill -> getDirty -> save) rather than
     * FluentCrmApi('contacts')->createOrUpdate(), because createOrUpdate runs
     * array_filter(Arr::only($data, $fillable)) and array_filter drops every falsy value.
     * It therefore cannot write a literal "0" (a postal code of 0, a phone of 0) and cannot
     * clear a field to empty - and clearing to empty is exactly what a restore needs.
     *
     * Two documented side effects of save():
     *  - the model's `saving` hook unconditionally sets hash = md5(email) on every save,
     *    which is why `hash` is not on the allowlist: any value we wrote would be replaced.
     *  - save() does NOT fire FluentCRM's contact-updated hooks, so they are fired here.
     *    Omitting them would silently break every automation that listens for a contact
     *    update, and the user would have no way to tell that it had happened.
     *
     * @param array<string, mixed> $values Column => value, unfiltered.
     */
    public function updateStandardFields(int $survivorId, array $values): void
    {
        if ($values === []) {
            return;
        }
        // Null-safe like every other write here: a contact that vanished between the
        // authoritative read and now is not an error, it is simply nothing to write to.
        $contact = $this->getContactById($survivorId);
        if ($contact === null) {
            return;
        }

        global $wpdb;
        $data = [];
        foreach (StandardFields::existingColumns($wpdb) as $column) {
            if (!array_key_exists($column, $values)) {
                continue;
            }
            $raw   = $values[$column];
            $clean = sanitize_text_field(is_scalar($raw) ? (string) $raw : '');
            // date_of_birth is the one DATE column in the allowlist; every other column
            // here is a VARCHAR where '' is a perfectly good empty value. Writing '' to a
            // DATE lands as '0000-00-00', because WordPress strips NO_ZERO_DATE and the
            // strict modes from the session sql_mode, so MySQL coerces rather than
            // refuses. That matters most on restore: putting an originally-empty birthday
            // back would stamp the zero date instead of clearing the column. NULL is what
            // "no birthday" actually means, and the column is nullable.
            if ($column === 'date_of_birth' && StandardFields::isEmptyValue($column, $clean)) {
                $data[$column] = null;
                continue;
            }
            $data[$column] = $clean;
        }
        if ($data === []) {
            return;
        }

        $contact->fill($data);
        // getDirty() must be read BEFORE save(): saving re-syncs the model's original
        // attributes, after which getDirty() is empty and the hooks below would receive
        // an empty change set. It also filters itself down to $fillable, so this is the
        // authoritative answer to "what did this write actually change".
        $dirty = $contact->getDirty();
        if ($dirty === []) {
            return; // every requested value already matched; no SQL, no hooks.
        }
        $contact->save();

        do_action('fluentcrm_contact_updated', $contact, $dirty);
        do_action('fluent_crm/contact_updated', $contact, $dirty);
    }

    /**
     * Re-create a contact from a getContactState()-shaped array: creates the
     * identity record with its full standard-field set, then reattaches
     * tags/lists/custom fields via the existing gateway methods. Used by the (Pro)
     * restore feature to undo a loser-contact deletion; not used by the v1 merge path.
     *
     * NOTE on createOrUpdate: it runs array_filter() over the payload, so every falsy
     * value is dropped before the insert. For a re-creation that is harmless - a field
     * that was empty before the merge simply stays empty on the new contact - but it
     * does mean this method can never be used to CLEAR a field. Anything that needs to
     * clear must go through updateStandardFields() / clearCustomFields() instead.
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

        $payload = ['email' => $email];
        // Bring back every allowlisted standard field the snapshot carries, not just the
        // three identity fields v1.1 restored. A schema-2 snapshot only has first_name /
        // last_name / phone, so it degrades to the old behavior; a schema-3 snapshot has
        // all 14 and the re-created contact is a faithful copy of the deleted one.
        foreach (StandardFields::COLUMNS as $column) {
            $value = $state[$column] ?? '';
            if (!StandardFields::isEmptyValue($column, $value)) {
                $payload[$column] = is_scalar($value) ? (string) $value : '';
            }
        }
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
