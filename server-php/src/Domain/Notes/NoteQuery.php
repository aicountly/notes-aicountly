<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Notes;

use Aicountly\Api\Http\ApiException;
use Aicountly\Api\Support\Str;
use Aicountly\Api\Support\Uuid;

/**
 * Filters → SQL, in one place.
 *
 * The notes list, keyword search and smart folders all ask the same kinds of
 * question ("tagged gst, has a PDF, modified this month"), so they share this
 * builder instead of growing three dialects of the same WHERE clause. It is
 * also what lets a smart folder's stored rules be executed safely: a rule names
 * a *field*, and only the fields below exist. An unknown field is rejected —
 * nothing a user stored ever reaches SQL as text.
 */
final class NoteQuery
{
    /** Filterable fields, and the operators each accepts. */
    public const FIELDS = [
        'notebook' => ['is', 'is_not', 'is_empty'],
        'tag' => ['is', 'is_not', 'is_empty'],
        'note_type' => ['is', 'is_not'],
        'color' => ['is', 'is_empty'],
        'is_pinned' => ['is'],
        'is_favourite' => ['is'],
        'is_archived' => ['is'],
        'is_shared' => ['is'],
        'has_attachment' => ['is'],
        'has_pdf' => ['is'],
        'has_audio' => ['is'],
        'has_reminder' => ['is'],
        'reminder_overdue' => ['is'],
        'has_open_actions' => ['is'],
        'owner' => ['is', 'is_not'],
        'entity' => ['is'],
        'created_at' => ['before', 'after', 'within_days'],
        'updated_at' => ['before', 'after', 'within_days'],
        'text' => ['contains'],
    ];

    /** @var array<int, string> */
    private array $clauses = [];

    /** @var array<string, mixed> */
    private array $bindings = [];

    private int $counter = 0;

    /**
     * Build from a validated rule tree.
     *
     * @param array{match?: string, conditions?: array<int, array<string, mixed>>} $rules
     */
    public static function fromRules(array $rules): self
    {
        $query = new self();
        $match = strtolower((string) ($rules['match'] ?? 'all'));
        if (!in_array($match, ['all', 'any'], true)) {
            throw ApiException::badRequest('`match` must be "all" or "any".');
        }

        $conditions = $rules['conditions'] ?? [];
        if (!is_array($conditions)) {
            throw ApiException::badRequest('`conditions` must be an array.');
        }
        if (count($conditions) > 25) {
            throw ApiException::badRequest('A smart folder may have at most 25 conditions.');
        }

        $parts = [];
        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            $clause = $query->compile(
                (string) ($condition['field'] ?? ''),
                (string) ($condition['operator'] ?? 'is'),
                $condition['value'] ?? null,
            );
            if ($clause !== null) {
                $parts[] = $clause;
            }
        }

        if ($parts !== []) {
            $query->clauses[] = '(' . implode($match === 'any' ? ' OR ' : ' AND ', $parts) . ')';
        }

        return $query;
    }

    public static function make(): self
    {
        return new self();
    }

    /** Add one condition, in the same vocabulary smart folders use. */
    public function where(string $field, string $operator, mixed $value): self
    {
        $clause = $this->compile($field, $operator, $value);
        if ($clause !== null) {
            $this->clauses[] = $clause;
        }

        return $this;
    }

    /**
     * @return string|null The SQL fragment, or null when the condition is a no-op.
     */
    private function compile(string $field, string $operator, mixed $value): ?string
    {
        if (!array_key_exists($field, self::FIELDS)) {
            throw ApiException::badRequest(sprintf('Unknown filter field `%s`.', Str::limit($field, 40)));
        }
        if (!in_array($operator, self::FIELDS[$field], true)) {
            throw ApiException::badRequest(
                sprintf('Operator `%s` is not valid for `%s`.', Str::limit($operator, 20), $field),
            );
        }

        return match ($field) {
            'notebook' => $this->notebookClause($operator, $value),
            'tag' => $this->tagClause($operator, $value),
            'note_type' => $this->enumClause('n.note_type', $operator, $value, [
                'document', 'checklist', 'voice', 'meeting', 'drawing', 'canvas', 'scan',
            ]),
            'color' => $operator === 'is_empty'
                ? 'n.color IS NULL'
                : 'n.color = ' . $this->bind($this->scalarString($value, 30)),
            'is_pinned' => 'n.is_pinned = ' . $this->bind($this->boolValue($value)),
            'is_favourite' => 'n.is_favourite = ' . $this->bind($this->boolValue($value)),
            'is_archived' => 'n.is_archived = ' . $this->bind($this->boolValue($value)),
            'is_shared' => $this->sharedClause($this->boolValue($value)),
            'has_attachment' => $this->existsClause(
                'SELECT 1 FROM note_attachments att WHERE att.note_id = n.id AND att.deleted_at IS NULL',
                $this->boolValue($value),
            ),
            'has_pdf' => $this->existsClause(
                "SELECT 1 FROM note_attachments att WHERE att.note_id = n.id AND att.deleted_at IS NULL AND att.kind = 'pdf'",
                $this->boolValue($value),
            ),
            'has_audio' => $this->existsClause(
                "SELECT 1 FROM note_attachments att WHERE att.note_id = n.id AND att.deleted_at IS NULL AND att.kind = 'audio'",
                $this->boolValue($value),
            ),
            'has_reminder' => $this->existsClause(
                "SELECT 1 FROM note_reminders r WHERE r.note_id = n.id AND r.deleted_at IS NULL AND r.status IN ('scheduled','snoozed')",
                $this->boolValue($value),
            ),
            'reminder_overdue' => $this->existsClause(
                "SELECT 1 FROM note_reminders r WHERE r.note_id = n.id AND r.deleted_at IS NULL
                   AND r.status IN ('scheduled','snoozed') AND coalesce(r.snoozed_until, r.due_at) < now()",
                $this->boolValue($value),
            ),
            'has_open_actions' => $this->existsClause(
                "SELECT 1 FROM note_actions a WHERE a.note_id = n.id AND a.deleted_at IS NULL AND a.status = 'open'",
                $this->boolValue($value),
            ),
            'owner' => ($operator === 'is_not' ? 'n.owner_user_id <> ' : 'n.owner_user_id = ')
                . $this->bind($this->scalarString($value, 64)),
            'entity' => $this->entityClause($value),
            'created_at' => $this->dateClause('n.created_at', $operator, $value),
            'updated_at' => $this->dateClause('n.updated_at', $operator, $value),
            'text' => $this->textClause($value),
            default => null,
        };
    }

    private function notebookClause(string $operator, mixed $value): string
    {
        if ($operator === 'is_empty') {
            return 'n.notebook_id IS NULL';
        }
        $id = $this->scalarString($value, 36);
        if (!Uuid::isValid($id)) {
            throw ApiException::badRequest('`notebook` must be a notebook id.');
        }
        $placeholder = $this->bind($id);

        // Matching a notebook matches its descendants too, so filtering by
        // "Projects" does not hide everything filed under "Projects / Notes".
        $sub = 'SELECT 1 FROM (
                    WITH RECURSIVE subtree AS (
                        SELECT id FROM notebooks WHERE id = ' . $placeholder . '::uuid
                        UNION ALL
                        SELECT c.id FROM notebooks c JOIN subtree s ON c.parent_id = s.id
                    ) SELECT id FROM subtree
                ) t WHERE t.id = n.notebook_id';

        return ($operator === 'is_not' ? 'NOT ' : '') . 'EXISTS (' . $sub . ')';
    }

    private function tagClause(string $operator, mixed $value): string
    {
        if ($operator === 'is_empty') {
            return 'NOT EXISTS (SELECT 1 FROM note_tags nt WHERE nt.note_id = n.id)';
        }
        $slug = Str::tagSlug($this->scalarString($value, 80));
        if ($slug === '') {
            throw ApiException::badRequest('`tag` must be a tag name.');
        }
        $sub = 'SELECT 1 FROM note_tags nt JOIN tags t ON t.id = nt.tag_id
                WHERE nt.note_id = n.id AND t.slug = ' . $this->bind($slug);

        return ($operator === 'is_not' ? 'NOT ' : '') . 'EXISTS (' . $sub . ')';
    }

    /** @param array<int, string> $allowed */
    private function enumClause(string $column, string $operator, mixed $value, array $allowed): string
    {
        $raw = $this->scalarString($value, 30);
        if (!in_array($raw, $allowed, true)) {
            throw ApiException::badRequest(sprintf('`%s` is not a valid value.', Str::limit($raw, 30)));
        }

        return $column . ($operator === 'is_not' ? ' <> ' : ' = ') . $this->bind($raw);
    }

    private function sharedClause(bool $shared): string
    {
        $sub = 'SELECT 1 FROM note_members nm WHERE nm.note_id = n.id';

        return ($shared ? 'EXISTS (' : 'NOT EXISTS (') . $sub . ')';
    }

    private function existsClause(string $sub, bool $expected): string
    {
        return ($expected ? 'EXISTS (' : 'NOT EXISTS (') . $sub . ')';
    }

    private function entityClause(mixed $value): string
    {
        $spec = is_array($value) ? $value : [];
        $type = $this->scalarString($spec['entity_type'] ?? '', 40);
        $id = $this->scalarString($spec['entity_id'] ?? '', 128);
        if ($type === '' || $id === '') {
            throw ApiException::badRequest('`entity` needs both entity_type and entity_id.');
        }

        return 'EXISTS (SELECT 1 FROM note_entity_links el WHERE el.note_id = n.id
                 AND el.entity_type = ' . $this->bind($type) . ' AND el.entity_id = ' . $this->bind($id) . ')';
    }

    private function dateClause(string $column, string $operator, mixed $value): string
    {
        if ($operator === 'within_days') {
            $days = (int) (is_scalar($value) ? $value : 0);
            if ($days < 1 || $days > 3650) {
                throw ApiException::badRequest('`within_days` must be between 1 and 3650.');
            }

            return $column . ' >= now() - make_interval(days => ' . $this->bind($days) . ')';
        }

        $iso = $this->scalarString($value, 40);
        try {
            $date = new \DateTimeImmutable($iso);
        } catch (\Throwable) {
            throw ApiException::badRequest('Date filters need an ISO-8601 timestamp.');
        }

        return $column . ($operator === 'before' ? ' < ' : ' > ')
            . $this->bind($date->format(\DateTimeInterface::RFC3339)) . '::timestamptz';
    }

    /**
     * Full-text match on the generated `search_vector`.
     *
     * `websearch_to_tsquery` is what makes quoted phrases and `-excluded`
     * behave the way a user expects from a search box, and it never throws on
     * malformed input the way `to_tsquery` does.
     */
    private function textClause(mixed $value): ?string
    {
        $term = trim($this->scalarString($value, 300));
        if ($term === '') {
            return null;
        }

        return 'n.search_vector @@ websearch_to_tsquery(\'english\', ' . $this->bind($term) . ')';
    }

    private function scalarString(mixed $value, int $max): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return Str::limit(trim((string) $value), $max);
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) (is_scalar($value) ? $value : '')), ['1', 'true', 'yes'], true);
    }

    /** Bind a value and return its placeholder. Values never enter the SQL text. */
    private function bind(mixed $value): string
    {
        $name = ':f' . $this->counter++;
        $this->bindings[$name] = $value;

        return $name;
    }

    public function sql(): string
    {
        return $this->clauses === [] ? '' : ' AND ' . implode(' AND ', $this->clauses);
    }

    /** @return array<string, mixed> */
    public function bindings(): array
    {
        // Placeholders were generated with a leading colon; PDO wants them
        // either way, but keeping them consistent avoids a mixed-style array.
        $out = [];
        foreach ($this->bindings as $key => $value) {
            $out[ltrim($key, ':')] = $value;
        }

        return $out;
    }

    public function isEmpty(): bool
    {
        return $this->clauses === [];
    }
}
