<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Throwable;

/**
 * Column builders for the migration columns that reference HOST models
 * (`user_id`, `team_id`, morph ids, `invited_by`, …), typed after the model
 * they point to instead of hardcoding `unsignedBigInteger`.
 *
 * By default (`kinetix.key_types.* = 'auto'`) the type is detected from the
 * app's own models at migrate time: `HasUlids` → `ulid`, `HasUuids` → `uuid`,
 * a string `$keyType` → `string`, anything else → `bigint`. Hosts can pin a
 * type explicitly via config/env when detection can't see their setup.
 *
 * Morph columns (`commentable_id`, `subject_id`, …) can point at ANY model, so
 * they cannot be detected — they follow `kinetix.key_types.morph`, which
 * defaults to `bigint`.
 */
class HostKeys
{
    /** The key types a column can be pinned to via config. */
    protected const TYPES = ['bigint', 'uuid', 'ulid', 'string'];

    /**
     * A column referencing the app's User model (`user_id`, `invited_by`,
     * `created_by_id`, …).
     */
    public static function user(Blueprint $table, string $column = 'user_id'): ColumnDefinition
    {
        return static::column($table, $column, static::type('user'));
    }

    /**
     * A column referencing the app's Team model.
     */
    public static function team(Blueprint $table, string $column = 'team_id'): ColumnDefinition
    {
        return static::column($table, $column, static::type('team'));
    }

    /**
     * The id half of a morph pair (`commentable_id`, `taggable_id`,
     * `subject_id`, `causer_id`). Config-driven — a morph can target any model.
     */
    public static function morph(Blueprint $table, string $column): ColumnDefinition
    {
        return static::column($table, $column, static::type('morph'));
    }

    /**
     * The resolved key type for a kind: the configured value when pinned,
     * otherwise detected from the relevant host model.
     *
     * @return 'bigint'|'uuid'|'ulid'|'string'
     */
    public static function type(string $kind): string
    {
        $configured = (string) config(
            "kinetix.key_types.{$kind}",
            $kind === 'morph' ? 'bigint' : 'auto',
        );

        if (in_array($configured, static::TYPES, true)) {
            return $configured;
        }

        return match ($kind) {
            'user'  => static::detect(static::userModel()),
            'team'  => static::detect(static::teamModel()),
            default => 'bigint',
        };
    }

    protected static function column(Blueprint $table, string $column, string $type): ColumnDefinition
    {
        return match ($type) {
            'uuid'   => $table->uuid($column),
            'ulid'   => $table->ulid($column),
            'string' => $table->string($column),
            default  => $table->unsignedBigInteger($column),
        };
    }

    /**
     * Detect a model class's key type from its traits/`$keyType`. `HasUlids`
     * is checked first because it composes `HasUuids` internally.
     *
     * @return 'bigint'|'uuid'|'ulid'|'string'
     */
    protected static function detect(?string $model): string
    {
        if ($model === null || ! class_exists($model)) {
            return 'bigint';
        }

        try {
            $traits = class_uses_recursive($model);

            if (in_array(HasUlids::class, $traits, true)) {
                return 'ulid';
            }

            if (in_array(HasUuids::class, $traits, true)) {
                return 'uuid';
            }

            $instance = new $model;

            if ($instance instanceof Model && $instance->getKeyType() === 'string') {
                return 'string';
            }
        } catch (Throwable) {
            // A model that can't be inspected keeps the historical default.
        }

        return 'bigint';
    }

    /**
     * The app's User model, resolved the same way the runtime relations do
     * (default guard → provider → model).
     */
    protected static function userModel(): ?string
    {
        $guard    = config('auth.defaults.guard', 'web');
        $provider = config("auth.guards.{$guard}.provider", 'users');

        $model = config("auth.providers.{$provider}.model", 'App\\Models\\User');

        return is_string($model) ? $model : null;
    }

    /**
     * The app's Team model, derived from the User's teams relation (the same
     * relation `KinetixTeams` resolves at runtime). Detection failures fall
     * back to bigint — pin `kinetix.key_types.team` when the relation isn't
     * inspectable at migrate time.
     */
    protected static function teamModel(): ?string
    {
        $user = static::userModel();

        if ($user === null || ! class_exists($user)) {
            return null;
        }

        try {
            $relationName = (string) config('kinetix.team_switcher.teams_relation', 'teams');
            $instance     = new $user;

            if (! $instance instanceof Model || ! method_exists($instance, $relationName)) {
                return null;
            }

            $relation = $instance->{$relationName}();

            return $relation instanceof Relation
                ? $relation->getRelated()::class
                : null;
        } catch (Throwable) {
            return null;
        }
    }
}
