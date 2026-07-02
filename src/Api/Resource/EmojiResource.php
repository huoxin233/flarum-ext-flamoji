<?php

/*
 * This file is part of Flamoji.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace PianoTell\Flamoji\Api\Resource;

use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Foundation\ValidationException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Illuminate\Validation\Factory;
use Laminas\Diactoros\Response\JsonResponse;
use PianoTell\Flamoji\Models\Emoji;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * @extends AbstractDatabaseResource<Emoji>
 */
class EmojiResource extends AbstractDatabaseResource
{
    public function __construct(
        protected ConnectionInterface $db
    ) {
    }

    public function type(): string
    {
        return 'flamojis';
    }

    public function model(): string
    {
        return Emoji::class;
    }

    public function endpoints(): array
    {
        return [
            // Unpaginated dump of all emojis — used by the forum picker
            // (needs full set for emoji-mart's custom category) and the
            // admin export flow.
            Endpoint\Endpoint::make('all')
                ->route('GET', '/all')
                ->action(function (Context $context) {
                    return Emoji::orderBy('id', 'desc')->get()->all();
                }),

            Endpoint\Index::make()
                ->paginate(23, 50)
                ->defaultSort('-id'),

            Endpoint\Show::make(),

            Endpoint\Create::make()
                ->authenticated()
                ->admin(),

            Endpoint\Update::make()
                ->authenticated()
                ->admin(),

            Endpoint\Delete::make()
                ->authenticated()
                ->admin(),

            // Bulk import: validates all rows first (all-or-nothing),
            // then persists in a transaction.
            Endpoint\Endpoint::make('import')
                ->route('POST', '/import')
                ->authenticated()
                ->admin()
                ->action(function (Context $context) {
                    $data = Arr::get($context->body(), 'data', []);

                    return $this->handleImport($data);
                })
                ->response(fn (Context $context, mixed $data) => new JsonResponse([
                    'legacyShortcodes' => $data,
                ], 200)),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('title')
                ->writable()
                ->nullable(),

            Schema\Str::make('text_to_replace')
                ->writable()
                ->requiredOnCreate()
                ->rules(['regex:/^:[a-zA-Z0-9_+-]+:$/'], true)
                ->messages([
                    'regex' => 'The shortcode must be wrapped in colons and contain only letters, numbers, dashes, underscores or plus signs — e.g. :myemoji_party:.'
                ]),

            Schema\Str::make('category')
                ->writable()
                ->nullable()
                ->rules(['max:255'], true)
                ->messages([
                    'max' => 'The category must not be longer than 255 characters.'
                ]),

            Schema\Str::make('path')
                ->writable()
                ->requiredOnCreate()
                ->rules(['filled'], true),
        ];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('id'),
        ];
    }

    /**
     * Trim attributes before save.
     */
    public function saving(object $model, BaseContext $context): ?object
    {
        if ($model->isDirty('title')) {
            $model->title = trim((string) $model->title);
        }

        if ($model->isDirty('category')) {
            $category = trim((string) $model->category);
            $model->category = $category !== '' ? $category : null;
        }

        if ($model->isDirty('text_to_replace')) {
            $value = trim((string) $model->text_to_replace);
            
            // Check for duplicate trigger text, as Flarum fields don't natively support dynamic ID ignoring
            $existing = Emoji::where('text_to_replace', $value)
                ->where('id', '!=', $model->id ?? 0)
                ->first();
            if ($existing) {
                throw new ValidationException(['text_to_replace' => 'This shortcode is already used by another emoji.']);
            }
            
            $model->text_to_replace = $value;
        }

        if ($model->isDirty('path')) {
            $model->path = trim((string) $model->path);
        }

        return $model;
    }

    /**
     * All-or-nothing bulk import. Validates every row before persisting
     * any, and wraps persistence in a DB transaction.
     *
     * @return list<string> the non-canonical ("legacy") shortcodes that were
     *                      imported as-is, for a non-blocking admin notice
     */
    private function handleImport(array $data): array
    {
        // Legacy JSON might use `textToReplace` instead of `text_to_replace`.
        $data = array_map(function ($row) {
            if (is_array($row) && isset($row['textToReplace'])) {
                $row['text_to_replace'] = $row['textToReplace'];
            }
            return $row;
        }, $data);

        $validator = resolve(Factory::class)->make(['data' => $data], [
            'data' => 'required|array',
            'data.*' => 'required|array',
            'data.*.title' => 'nullable|string',
            'data.*.text_to_replace' => [
                'required',
                'string',
                'regex:/^\S+$/', // No whitespace (legacy floor)
                'distinct',
                'unique:custom_emojis,text_to_replace'
            ],
            'data.*.path' => 'required|string|filled',
            'data.*.category' => 'nullable|string|max:255',
        ], [
            'data.*.text_to_replace.regex' => 'The shortcode must not contain whitespace.',
            'data.*.text_to_replace.distinct' => 'Duplicate shortcode within import batch.',
            'data.*.text_to_replace.unique' => 'This shortcode is already used by another emoji.'
        ]);

        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->messages() as $key => $messages) {
                $errors[$key] = $messages[0];
            }
            throw new ValidationException($errors);
        }

        $legacyShortcodes = [];

        $this->db->transaction(function () use ($data, &$legacyShortcodes) {
            foreach ($data as $row) {
                $title = trim((string) ($row['title'] ?? ''));
                $textToReplace = trim((string) ($row['text_to_replace'] ?? ''));
                $path = trim((string) ($row['path'] ?? ''));
                $category = trim((string) ($row['category'] ?? ''));

                if (!preg_match('/^:[a-zA-Z0-9_+-]+:$/', $textToReplace)) {
                    $legacyShortcodes[] = $textToReplace;
                }

                $emoji = Emoji::build(
                    $title,
                    $textToReplace,
                    $path,
                    $category !== '' ? $category : null
                );
                $emoji->save();
            }
        });

        return $legacyShortcodes;
    }
}
