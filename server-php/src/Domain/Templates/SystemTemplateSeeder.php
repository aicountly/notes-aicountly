<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Templates;

use Aicountly\Api\Database\Connection;
use Aicountly\Api\Support\Uuid;

/**
 * The templates this deployment ships with.
 *
 * Run from `php bin/migrate.php seed` after a deploy. It is **configuration
 * seeding and nothing else**: thirteen note skeletons, no demo notes, no
 * example customers, no sample meetings. A user's first login shows an empty
 * Notes app with a full template picker, which is the honest version of a new
 * account.
 *
 * Re-running is safe and is the intended way to ship a change to a template:
 * each row is matched on `template_key` and updated in place, so an existing
 * template keeps its id — the notes that recorded where they came from still
 * resolve, and a picker does not sprout duplicates on every deploy. The partial
 * unique index `note_templates_system_key_idx` is what makes that an upsert the
 * database enforces rather than a check this class hopes to have got right.
 *
 * A key that is no longer in this list is left alone rather than deleted:
 * removing a template someone uses is a decision for a migration, not a side
 * effect of running the seeder.
 *
 * The documents below carry no `blockId` attributes on purpose. They are minted
 * by {@see \Aicountly\Api\Domain\Notes\NoteDocument::sanitize()} when a note is
 * created, so every note started from a template gets block ids of its own
 * rather than inheriting one set shared by every copy.
 */
final class SystemTemplateSeeder
{
    /**
     * Insert or update every system template.
     *
     * @return int Rows written — the size of the catalogue, since each run
     *             ensures all of them.
     */
    public function run(): int
    {
        return Connection::transaction(function (): int {
            $written = 0;

            foreach (self::catalogue() as $index => $template) {
                $written += Connection::execute(
                    "INSERT INTO note_templates
                        (id, scope, template_key, name, description, icon, note_type,
                         title_template, document_json, default_tags, position)
                     VALUES
                        (:id, 'system', :key, :name, :description, :icon, :note_type,
                         :title_template, :document::jsonb, :default_tags::jsonb, :position)
                     ON CONFLICT (template_key) WHERE scope = 'system' AND deleted_at IS NULL
                     DO UPDATE SET
                        name = EXCLUDED.name,
                        description = EXCLUDED.description,
                        icon = EXCLUDED.icon,
                        note_type = EXCLUDED.note_type,
                        title_template = EXCLUDED.title_template,
                        document_json = EXCLUDED.document_json,
                        default_tags = EXCLUDED.default_tags,
                        position = EXCLUDED.position,
                        updated_at = now()",
                    [
                        'id' => Uuid::v4(),
                        'key' => $template['key'],
                        'name' => $template['name'],
                        'description' => $template['description'],
                        'icon' => $template['icon'],
                        'note_type' => $template['note_type'] ?? 'document',
                        'title_template' => $template['title_template'],
                        'document' => json_encode($template['document'], JSON_UNESCAPED_SLASHES),
                        'default_tags' => json_encode($template['tags'], JSON_UNESCAPED_SLASHES),
                        // Position comes from the order of this list, so
                        // re-ordering the picker is re-ordering the array.
                        'position' => ($index + 1) * 10,
                    ],
                );
            }

            return $written;
        });
    }

    /**
     * The catalogue, in picker order.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function catalogue(): array
    {
        return [
            [
                'key' => 'blank',
                'name' => 'Blank note',
                'description' => 'An empty page.',
                'icon' => 'note',
                'title_template' => null,
                'tags' => [],
                'document' => self::doc([self::p()]),
            ],

            [
                'key' => 'meeting-notes',
                'name' => 'Meeting notes',
                'description' => 'Agenda, discussion, decisions and who is doing what next.',
                'icon' => 'meeting',
                'note_type' => 'meeting',
                'title_template' => 'Meeting — {{date}}',
                'tags' => ['meeting'],
                'document' => self::doc([
                    self::h('Attendees'),
                    self::bullets(['', '']),
                    self::h('Agenda'),
                    self::numbered(['', '']),
                    self::h('Discussion'),
                    self::p(),
                    self::h('Decisions'),
                    self::bullets(['']),
                    self::h('Action items'),
                    self::tasks(['', '']),
                    self::callout('info', 'Every box ticked here becomes an action on this note, so write each one as something a named person can finish.'),
                ]),
            ],

            [
                'key' => 'daily-note',
                'name' => 'Daily note',
                'description' => 'One page per day: what matters today, what got done, what carries over.',
                'icon' => 'sun',
                'title_template' => '{{date}}',
                'tags' => ['daily'],
                'document' => self::doc([
                    self::h('Focus'),
                    self::p(),
                    self::h('Tasks'),
                    self::tasks(['', '', '']),
                    self::h('Notes'),
                    self::p(),
                    self::h('Carried to tomorrow'),
                    self::bullets(['']),
                ]),
            ],

            [
                'key' => 'weekly-review',
                'name' => 'Weekly review',
                'description' => 'Close the week: what shipped, what slipped, what next week is for.',
                'icon' => 'calendar',
                'title_template' => 'Weekly review — week of {{date}}',
                'tags' => ['review'],
                'document' => self::doc([
                    self::callout('info', 'Start by re-reading last week’s review and this week’s open actions.'),
                    self::h('What went well'),
                    self::bullets(['', '']),
                    self::h('What slipped, and why'),
                    self::bullets(['']),
                    self::h('Numbers that moved'),
                    self::bullets(['']),
                    self::h('Next week'),
                    self::tasks(['', '', '']),
                    self::h('Parked'),
                    self::bullets(['']),
                ]),
            ],

            [
                'key' => 'brainstorm',
                'name' => 'Brainstorm',
                'description' => 'Get everything down first, group it second, cut it third.',
                'icon' => 'pulse',
                'title_template' => 'Brainstorm — {{date}}',
                'tags' => ['idea'],
                'document' => self::doc([
                    self::h('The question'),
                    self::p(),
                    self::callout('info', 'No filtering in the first pass. Quantity now, judgement later — editing while generating kills both.'),
                    self::h('Ideas'),
                    self::bullets(['', '', '', '']),
                    self::h('Themes'),
                    self::bullets(['']),
                    self::h('Worth trying'),
                    self::tasks(['']),
                ]),
            ],

            [
                'key' => 'todo',
                'name' => 'To-do list',
                'description' => 'Today, this week, and what you are waiting on somebody else for.',
                'icon' => 'checklist',
                'note_type' => 'checklist',
                'title_template' => 'To-do — {{date}}',
                'tags' => ['todo'],
                'document' => self::doc([
                    self::h('Today'),
                    self::tasks(['', '', '']),
                    self::h('This week'),
                    self::tasks(['', '']),
                    self::h('Waiting on'),
                    self::bullets(['']),
                ]),
            ],

            [
                'key' => 'project-notes',
                'name' => 'Project notes',
                'description' => 'One home for a project: goal, scope, milestones, risks and open questions.',
                'icon' => 'notebook',
                'title_template' => null,
                'tags' => ['project'],
                'document' => self::doc([
                    self::h('Goal'),
                    self::p(),
                    self::h('In scope'),
                    self::bullets(['']),
                    self::h('Out of scope'),
                    self::bullets(['']),
                    self::h('Milestones'),
                    self::tasks(['', '']),
                    self::h('Risks'),
                    self::bullets(['']),
                    self::h('Open questions'),
                    self::bullets(['']),
                    self::h('Decisions'),
                    self::bullets(['']),
                ]),
            ],

            [
                'key' => 'research-note',
                'name' => 'Research note',
                'description' => 'A question, the sources that answer it, and what you concluded.',
                'icon' => 'search',
                'title_template' => null,
                'tags' => ['research'],
                'document' => self::doc([
                    self::h('Question'),
                    self::p(),
                    self::h('Sources'),
                    self::bullets(['', '']),
                    self::h('Findings'),
                    self::p(),
                    self::h('Open questions'),
                    self::bullets(['']),
                    self::h('Conclusion'),
                    self::p(),
                    self::callout('warning', 'Keep the source next to the claim it supports. A finding you cannot trace back is one you cannot defend later.'),
                ]),
            ],

            [
                'key' => 'decision-note',
                'name' => 'Decision record',
                'description' => 'What was decided, what it was weighed against, and what follows from it.',
                'icon' => 'check',
                'title_template' => 'Decision — {{date}}',
                'tags' => ['decision'],
                'document' => self::doc([
                    self::h('Decision'),
                    self::p(),
                    self::callout('info', 'Write the decision first, in one sentence. The context below is what makes it reviewable a year from now, when nobody remembers the meeting.'),
                    self::h('Context'),
                    self::p(),
                    self::h('Options considered'),
                    self::bullets(['', '']),
                    self::h('Consequences'),
                    self::bullets(['']),
                    self::h('Decided by'),
                    self::p(),
                    self::h('Revisit when'),
                    self::p(),
                ]),
            ],

            [
                'key' => 'client-meeting',
                'name' => 'Client meeting',
                'description' => 'What the client asked for, what was agreed, and what you owe them next.',
                'icon' => 'user',
                'note_type' => 'meeting',
                'title_template' => 'Client meeting — {{date}}',
                'tags' => ['client', 'meeting'],
                'document' => self::doc([
                    self::h('Client'),
                    self::p(),
                    self::h('Present'),
                    self::bullets(['', '']),
                    self::h('What they asked for'),
                    self::bullets(['']),
                    self::h('What we agreed'),
                    self::bullets(['']),
                    self::h('Commitments'),
                    self::tasks(['', '']),
                    self::callout('warning', 'Confirm scope, fees and dates in writing before the call ends. Anything left verbal is a dispute waiting to happen.'),
                    self::h('Next contact'),
                    self::p(),
                ]),
            ],

            [
                'key' => 'minutes-of-meeting',
                'name' => 'Minutes of meeting',
                'description' => 'The formal record: who was present, what was resolved, what happens next.',
                'icon' => 'clipboard',
                'note_type' => 'meeting',
                'title_template' => 'Minutes — {{date}}',
                'tags' => ['minutes', 'meeting'],
                'document' => self::doc([
                    self::h('Meeting'),
                    self::bullets(['Date and time: ', 'Location: ', 'Chair: ', 'Minute taker: ']),
                    self::h('Present'),
                    self::bullets(['', '']),
                    self::h('Apologies'),
                    self::bullets(['']),
                    self::h('Minutes of the previous meeting'),
                    self::p(),
                    self::h('Items discussed'),
                    self::numbered(['', '', '']),
                    self::h('Resolutions'),
                    self::bullets(['']),
                    self::h('Action items'),
                    self::tasks(['', '']),
                    self::h('Next meeting'),
                    self::p(),
                ]),
            ],

            [
                'key' => 'phone-call',
                'name' => 'Phone call',
                'description' => 'A quick record of a call, taken while it is still happening.',
                'icon' => 'comment',
                'title_template' => 'Call — {{date}} {{time}}',
                'tags' => ['call'],
                'document' => self::doc([
                    self::bullets(['Caller: ', 'Number: ', 'About: ']),
                    self::h('Notes'),
                    self::p(),
                    self::h('Agreed'),
                    self::tasks(['']),
                    self::h('Follow-up'),
                    self::p(),
                ]),
            ],

            [
                'key' => 'sop',
                'name' => 'Standard operating procedure',
                'description' => 'A procedure someone else can follow without asking you how.',
                'icon' => 'list',
                'title_template' => null,
                'tags' => ['sop'],
                'document' => self::doc([
                    self::h('Purpose'),
                    self::p(),
                    self::h('When this applies'),
                    self::p(),
                    self::h('Who owns it'),
                    self::p(),
                    self::h('Steps'),
                    self::numbered(['', '', '']),
                    self::h('Checks before finishing'),
                    self::tasks(['', '']),
                    self::callout('warning', 'A procedure nobody has followed end to end is a draft. Walk it once with someone new before you rely on it.'),
                    self::h('Last reviewed'),
                    self::p(),
                ]),
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Document builders
    //
    // Small on purpose: a ProseMirror tree written out by hand is unreadable,
    // and an unreadable template is one nobody will maintain.
    // -----------------------------------------------------------------------

    /** @param array<int, array<string, mixed>> $blocks */
    private static function doc(array $blocks): array
    {
        return ['type' => 'doc', 'content' => $blocks];
    }

    /** A heading. Level 2 throughout: the note's title is the level 1. */
    private static function h(string $text, int $level = 2): array
    {
        return [
            'type' => 'heading',
            'attrs' => ['level' => $level],
            'content' => [['type' => 'text', 'text' => $text]],
        ];
    }

    /** A paragraph; empty when no text is given, which is a place to type. */
    private static function p(string $text = ''): array
    {
        $node = ['type' => 'paragraph'];
        if ($text !== '') {
            $node['content'] = [['type' => 'text', 'text' => $text]];
        }

        return $node;
    }

    /** @param array<int, string> $items */
    private static function bullets(array $items): array
    {
        return [
            'type' => 'bulletList',
            'content' => array_map(
                static fn (string $text): array => ['type' => 'listItem', 'content' => [self::p($text)]],
                $items,
            ),
        ];
    }

    /** @param array<int, string> $items */
    private static function numbered(array $items): array
    {
        return [
            'type' => 'orderedList',
            'attrs' => ['start' => 1],
            'content' => array_map(
                static fn (string $text): array => ['type' => 'listItem', 'content' => [self::p($text)]],
                $items,
            ),
        ];
    }

    /** @param array<int, string> $items */
    private static function tasks(array $items): array
    {
        return [
            'type' => 'taskList',
            'content' => array_map(
                static fn (string $text): array => [
                    'type' => 'taskItem',
                    'attrs' => ['checked' => false],
                    'content' => [self::p($text)],
                ],
                $items,
            ),
        ];
    }

    /** Tones the editor renders: info, warning, success, danger. */
    private static function callout(string $tone, string $text): array
    {
        return [
            'type' => 'callout',
            'attrs' => ['tone' => $tone],
            'content' => [self::p($text)],
        ];
    }
}
