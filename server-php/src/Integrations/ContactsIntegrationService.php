<?php

declare(strict_types=1);

namespace Aicountly\Api\Integrations;

use Aicountly\Api\Features;
use Aicountly\Api\Support\Str;

/**
 * The one place this API talks to AICOUNTLY Contacts.
 *
 * Notes needs two things from the directory and nothing else: a way to find a
 * person while typing a participant list, and a way to turn ids it already
 * stored back into names. Both are read-only, both go out on the caller's own
 * ses_key, and neither copies the directory into this database — a participant
 * row keeps the contact **id** and a cached label, so the authoritative record
 * stays where it belongs and this product never becomes a stale second copy of
 * someone's contacts.
 *
 * With {@see Features::CONTACTS} off — the default, and the state of any
 * deployment without a CONTACTS_API_URL — every method here answers
 * FEATURE_DISABLED. Nothing returns invented people, and nothing pretends a
 * lookup succeeded: the caller either gets real names from the real directory
 * or is told the directory is not connected.
 */
final class ContactsIntegrationService
{
    /**
     * Contacts' own paths.
     *
     * **Must be confirmed against the Contacts API before the flag is switched
     * on.** They are written to the shape the other AICOUNTLY services use —
     * a collection with a `q` parameter, and a lookup that takes ids — but this
     * repository is not where that contract is defined, and a wrong path here
     * is a 404 the client sees as "no such contact".
     */
    private const SEARCH_PATH = '/contacts';
    private const LOOKUP_PATH = '/contacts/lookup';

    /** A type-ahead, not an export. */
    private const MAX_SEARCH_RESULTS = 25;

    /** One lookup per participant list, not per participant. */
    private const MAX_LOOKUP_IDS = 100;

    public function __construct(
        private readonly AicountlyClient $client = new AicountlyClient(
            'contacts',
            Features::CONTACTS,
            'CONTACTS_API_URL',
        ),
    ) {
    }

    /**
     * Find people to add to a meeting.
     *
     * @return array<int, array<string, mixed>> `{contact_id, name, email, company}`
     */
    public function search(string $sesKey, string $query, int $limit = 10): array
    {
        Features::require(Features::CONTACTS);

        $query = trim($query);
        if ($query === '') {
            // No request at all rather than an empty `q`, which some directory
            // APIs answer with the whole address book.
            return [];
        }

        $data = $this->client->send('GET', self::SEARCH_PATH, $sesKey, null, [
            'q' => Str::limit($query, 200),
            'limit' => (string) max(1, min(self::MAX_SEARCH_RESULTS, $limit)),
        ]);

        $rows = $data['contacts'] ?? $data['results'] ?? $data['items'] ?? $data;
        if (!is_array($rows)) {
            return [];
        }

        $contacts = [];
        foreach (array_slice(array_values($rows), 0, self::MAX_SEARCH_RESULTS) as $row) {
            $contact = is_array($row) ? self::contact($row) : null;
            if ($contact !== null) {
                $contacts[] = $contact;
            }
        }

        return $contacts;
    }

    /**
     * Current display names for contact ids this note already holds.
     *
     * The ids are what a note stores; these are the labels beside them. An id
     * the directory does not return is simply absent from the result — a
     * contact the caller may no longer see must not come back as a name, and
     * must not come back as a placeholder that reads like one either.
     *
     * @param array<int, string> $ids
     * @return array<string, string> contact id => display name
     */
    public function resolve(string $sesKey, array $ids): array
    {
        Features::require(Features::CONTACTS);

        $wanted = [];
        foreach ($ids as $id) {
            $id = trim((string) (is_scalar($id) ? $id : ''));
            if ($id !== '' && mb_strlen($id, 'UTF-8') <= 128) {
                $wanted[$id] = true;
            }
        }
        $wanted = array_slice(array_keys($wanted), 0, self::MAX_LOOKUP_IDS);
        if ($wanted === []) {
            return [];
        }

        $data = $this->client->send('POST', self::LOOKUP_PATH, $sesKey, ['ids' => $wanted]);

        $rows = $data['contacts'] ?? $data['results'] ?? $data['items'] ?? $data;
        if (!is_array($rows)) {
            return [];
        }

        $labels = [];
        foreach ($rows as $key => $row) {
            // Accepts both shapes a lookup endpoint tends to answer with: a
            // list of records, and a map of id => record or id => name.
            if (is_string($row)) {
                $labels[(string) $key] = Str::limit(trim($row), 400);
                continue;
            }
            $contact = is_array($row) ? self::contact($row) : null;
            if ($contact !== null && $contact['name'] !== null) {
                $labels[$contact['contact_id']] = $contact['name'];
            }
        }

        return $labels;
    }

    /**
     * One directory record, in this product's terms.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private static function contact(array $row): ?array
    {
        $id = self::text($row['contact_id'] ?? $row['id'] ?? $row['uuid'] ?? null, 128);
        if ($id === null) {
            // Without an id there is nothing to link to, and a name alone is
            // exactly what this product refuses to treat as an identity.
            return null;
        }

        $name = self::text($row['name'] ?? $row['display_name'] ?? $row['full_name'] ?? null, 400);
        $first = self::text($row['first_name'] ?? null, 200);
        $last = self::text($row['last_name'] ?? null, 200);
        if ($name === null && ($first !== null || $last !== null)) {
            $name = trim(($first ?? '') . ' ' . ($last ?? ''));
        }

        return [
            'contact_id' => $id,
            'name' => $name === null || $name === '' ? null : $name,
            'email' => self::text($row['email'] ?? $row['email_id'] ?? null, 320),
            'company' => self::text($row['company'] ?? $row['organisation'] ?? $row['organization'] ?? null, 200),
        ];
    }

    private static function text(mixed $value, int $max): ?string
    {
        if ($value === null || !is_scalar($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : Str::limit($trimmed, $max);
    }
}
