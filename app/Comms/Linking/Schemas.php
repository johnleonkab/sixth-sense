<?php

namespace App\Comms\Linking;

/**
 * The output schema of every agent in the linking pipeline
 * (agent-definitions 3.1 to 3.7), in the format `ModelRouter::ask()` takes.
 * A change to a schema is a change to its prompt: bump the prompt version.
 */
final class Schemas
{
    /**
     * Task `segment`.
     *
     * @return array<string, mixed>
     */
    public static function segment(): array
    {
        return [
            'type' => 'object',
            'required' => ['segments'],
            'properties' => [
                'segments' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['message_ids', 'new_message_ids', 'participants', 'restated', 'unresolved_refs'],
                    'properties' => [
                        'message_ids' => self::strings(),
                        'new_message_ids' => self::strings(),
                        'participants' => self::strings(),
                        'restated' => ['type' => 'string'],
                        'unresolved_refs' => self::strings(),
                    ],
                ]],
            ],
        ];
    }

    /**
     * Task `link`. Parsed by LinkVote::fromOutput().
     *
     * @return array<string, mixed>
     */
    public static function link(): array
    {
        return [
            'type' => 'object',
            'required' => ['decision', 'quote', 'why'],
            'properties' => [
                'decision' => ['type' => 'string', 'enum' => array_map(fn (LinkChoice $choice): string => $choice->value, LinkChoice::cases())],
                'topic_id' => ['type' => 'string', 'nullable' => true],
                'intent' => [
                    'type' => 'object',
                    'nullable' => true,
                    'properties' => [
                        'decision' => ['type' => 'string', 'enum' => ['existing', 'no_existing_intent']],
                        'intent_id' => ['type' => 'string', 'nullable' => true],
                    ],
                ],
                'name' => ['type' => 'string', 'nullable' => true],
                'a' => ['type' => 'string', 'nullable' => true],
                'b' => ['type' => 'string', 'nullable' => true],
                'quote' => ['type' => 'string'],
                'why' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Task `extract` (the "match v2" of the design documents). Compared by
     * ExtractionAgreement.
     *
     * @return array<string, mixed>
     */
    public static function extract(): array
    {
        $step = [
            'type' => 'object',
            'required' => ['id', 'what', 'after'],
            'properties' => [
                'id' => ['type' => 'string'],
                'what' => ['type' => 'string'],
                'after' => self::strings(),
                'condition' => ['type' => 'string', 'nullable' => true],
            ],
        ];

        $operation = [
            'type' => 'object',
            'required' => ['op', 'quote'],
            'properties' => [
                'op' => ['type' => 'string', 'enum' => ['open', 'attach', 'settle', 'reopen', 'update', 'acknowledge', 'signal', 'noise']],
                'intent_id' => ['type' => 'string', 'nullable' => true],
                'kind' => ['type' => 'string', 'nullable' => true, 'enum' => ['request', 'commitment', 'question', 'decision_needed']],
                'what' => ['type' => 'string', 'nullable' => true],
                'requesters' => self::strings(),
                'responders' => self::strings(),
                'possible_responders' => self::strings(),
                'steps' => ['type' => 'array', 'items' => $step],
                'deadline' => [
                    'type' => 'object',
                    'nullable' => true,
                    'properties' => [
                        'date' => ['type' => 'string', 'nullable' => true],
                        'text' => ['type' => 'string', 'nullable' => true],
                    ],
                ],
                'expects' => ['type' => 'string', 'nullable' => true],
                'settle_reason' => ['type' => 'string', 'nullable' => true, 'enum' => ['delivery', 'decision', 'drop', 'taken_by_other']],
                'summary' => ['type' => 'string', 'nullable' => true],
                'flags' => self::strings(),
                'quote' => ['type' => 'string'],
            ],
        ];

        return [
            'type' => 'object',
            'required' => ['operations'],
            'properties' => [
                'operations' => ['type' => 'array', 'items' => $operation],
            ],
        ];
    }

    /**
     * Task `critic`. Read by CriticVerdict.
     *
     * @return array<string, mixed>
     */
    public static function critic(): array
    {
        $answer = [
            'type' => 'object',
            'required' => ['verdict'],
            'properties' => [
                'verdict' => ['type' => 'string', 'enum' => ['holds', 'refuted', 'cannot_tell']],
                'quote' => ['type' => 'string', 'nullable' => true],
            ],
        ];

        return [
            'type' => 'object',
            'required' => ['addressed_to_user', 'already_done', 'real_ask'],
            'properties' => [
                'addressed_to_user' => $answer,
                'already_done' => $answer,
                'real_ask' => $answer,
            ],
        ];
    }

    /**
     * Task `dossier_write`.
     *
     * @return array<string, mixed>
     */
    public static function dossier(): array
    {
        return [
            'type' => 'object',
            'required' => ['summary', 'state', 'new_terms', 'changed'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'state' => ['type' => 'string'],
                'new_terms' => self::strings(),
                'changed' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Task `reconcile`.
     *
     * @return array<string, mixed>
     */
    public static function reconcile(): array
    {
        return [
            'type' => 'object',
            'required' => ['changes'],
            'properties' => [
                'changes' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['op'],
                    'properties' => [
                        'op' => ['type' => 'string', 'enum' => ['merge', 'split', 'settle', 'confirm_link', 'relink', 'merge_topics']],
                        'a' => ['type' => 'string', 'nullable' => true],
                        'b' => ['type' => 'string', 'nullable' => true],
                        'intent_id' => ['type' => 'string', 'nullable' => true],
                        'message_ids' => self::strings(),
                        'from_intent' => ['type' => 'string', 'nullable' => true],
                        'to_intent' => ['type' => 'string', 'nullable' => true],
                        'reason' => ['type' => 'string', 'nullable' => true],
                        'quote' => ['type' => 'string', 'nullable' => true],
                        'why' => ['type' => 'string', 'nullable' => true],
                    ],
                ]],
            ],
        ];
    }

    /**
     * Task `topic_build`.
     *
     * @return array<string, mixed>
     */
    public static function topics(): array
    {
        return [
            'type' => 'object',
            'required' => ['topics'],
            'properties' => [
                'topics' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['name', 'summary', 'terms', 'people', 'channels', 'state', 'example_message_ids'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'summary' => ['type' => 'string'],
                        'terms' => self::strings(),
                        'people' => self::strings(),
                        'channels' => self::strings(),
                        'state' => ['type' => 'string'],
                        'example_message_ids' => self::strings(),
                    ],
                ]],
            ],
        ];
    }

    /**
     * Task `identity_resolve`.
     *
     * @return array<string, mixed>
     */
    public static function identity(): array
    {
        return [
            'type' => 'object',
            'required' => ['pairs'],
            'properties' => [
                'pairs' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['a', 'b', 'verdict', 'evidence'],
                    'properties' => [
                        'a' => ['type' => 'string'],
                        'b' => ['type' => 'string'],
                        'verdict' => ['type' => 'string', 'enum' => ['same', 'different', 'cannot_tell']],
                        'evidence' => ['type' => 'string'],
                    ],
                ]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function strings(): array
    {
        return ['type' => 'array', 'items' => ['type' => 'string']];
    }
}
