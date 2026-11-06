<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Fakes;

use Happones\Kinetix\Forms\Components\CheckboxList;
use Happones\Kinetix\Forms\Components\Fieldset;
use Happones\Kinetix\Forms\Components\Grid;
use Happones\Kinetix\Forms\Components\Section;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Components\Toggle;
use Happones\Kinetix\Membership\MemberFields;
use Happones\Kinetix\Membership\MemberFieldsContext;
use Happones\Kinetix\Membership\MemberProvision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * A host's member fields: a portal flag, a job title, an internal note and the branches (the provision's team) the member works in, kept on `branch_user`.
 */
class BranchMemberFields extends MemberFields
{
    /** @var array<int, array{0: array<string, mixed>, 1: string}> */
    public static array $applied = [];

    public static bool $fail = false;

    public static bool $lockTitle = false;

    /** Hides `internal_note` from the acting admin. */
    public static bool $hideNote = false;

    /** Wraps a required `restricted` field in a hidden layout: section, grid or fieldset. */
    public static ?string $layout = null;

    /** A key `values()` returns that the schema does not know. */
    public static ?string $legacy = null;

    /** The permission team id `apply()` ran under. */
    public static int|string|null $teamSeen = null;

    public static function reset(): void
    {
        static::$applied   = [];
        static::$fail      = false;
        static::$lockTitle = false;
        static::$hideNote  = false;
        static::$layout    = null;
        static::$legacy    = null;
        static::$teamSeen  = null;
    }

    public function schema(MemberFieldsContext $context): array
    {
        $restricted = [TextInput::make('restricted')->label('Restricted')->required()->default('dflt')];

        $layout = match (static::$layout) {
            'section'  => [Section::make('Admin only')->schema($restricted)->visible(false)],
            'grid'     => [Grid::make(2)->schema($restricted)->visible(false)],
            'fieldset' => [Fieldset::make('Admin only')->schema($restricted)->visible(false)],
            default    => [],
        };

        return [
            ...$layout,
            Toggle::make('portal_access')->label('Portal access'),
            TextInput::make('job_title')->label('Job title')->required()->disabled(static::$lockTitle),
            TextInput::make('internal_note')->label('Internal note')->visible(fn (): bool => ! static::$hideNote),
            CheckboxList::make('branch_ids')
                ->label('Branches')
                ->options(fn (): array => DB::table('branches')->where('team_id', $context->teamId)->pluck('name', 'id')->all()),
        ];
    }

    public function values(Model $user, MemberFieldsContext $context): array
    {
        return [
            ...(static::$legacy !== null ? ['legacy' => static::$legacy] : []),
            'portal_access' => (bool) $user->getAttribute('portal_access'),
            'job_title'     => (string) $user->getAttribute('job_title'),
            'internal_note' => $user->getAttribute('internal_note'),
            'branch_ids'    => DB::table('branch_user')->where('user_id', $user->getKey())->pluck('branch_id')->map(fn ($id): string => (string) $id)->all(),
        ];
    }

    public function apply(Model $user, MemberProvision $provision, array $values, MemberFieldsContext $context): void
    {
        static::$applied[] = [$values, $context->operation->value];
        static::$teamSeen  = app(PermissionRegistrar::class)->getPermissionsTeamId();

        $user->forceFill([
            'portal_access' => (bool) ($values['portal_access'] ?? false),
            'job_title'     => $values['job_title']     ?? null,
            'internal_note' => $values['internal_note'] ?? null,
        ])->save();

        $ids = DB::table('branches')->where('team_id', $provision->team_id)->whereIn('id', (array) ($values['branch_ids'] ?? []))->pluck('id')->all();

        DB::table('branch_user')->where('user_id', $user->getKey())->delete();

        foreach ($ids as $id) {
            DB::table('branch_user')->insert(['user_id' => $user->getKey(), 'branch_id' => $id]);
        }

        if (static::$fail) {
            throw new RuntimeException('apply failed');
        }
    }
}
