<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Data\TableRowData;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Group;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GroupTeam extends Model
{
    protected $table = 'group_teams';

    public $timestamps = false;

    protected $guarded = [];

    public function tasks(): HasMany
    {
        return $this->hasMany(GroupTask::class, 'team_id');
    }
}

class GroupTask extends Model
{
    protected $table = 'group_tasks';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<GroupTeam, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(GroupTeam::class, 'team_id');
    }
}

enum GroupTaskStatus: string
{
    case Open = 'open';
    case Done = 'done';

    public function getLabel(): string
    {
        return $this === self::Open ? 'In progress' : 'Finished';
    }
}

class GroupEnumTask extends GroupTask
{
    protected function casts(): array
    {
        return ['status' => GroupTaskStatus::class];
    }
}

class GroupFlag extends Model
{
    protected $table = 'group_flags';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['done' => 'boolean'];
    }
}

/**
 * Row grouping (Filament's `->groups([...])` / `->defaultGroup(...)`): the
 * table buckets the current dataset's rows by a column — attribute or an
 * eager-loaded relation path — serializing per-row membership so KinetixTable
 * renders them under collapsible headers.
 */
class TableGroupingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('group_teams', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('group_tasks', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('team_id')->nullable();
            $table->string('title');
            $table->string('status');
            $table->string('created_on')->nullable();
        });

        $alpha = GroupTeam::create(['name' => 'Alpha']);
        $beta  = GroupTeam::create(['name' => 'Beta']);

        // Deliberately INTERLEAVED statuses so an ungrouped order would not be
        // contiguous by status — the grouping must reorder them.
        GroupTask::create(['team_id' => $alpha->id, 'title' => 'A1', 'status' => 'open', 'created_on' => '2024-01-01']);
        GroupTask::create(['team_id' => $beta->id, 'title' => 'B1', 'status' => 'done', 'created_on' => '2024-01-02']);
        GroupTask::create(['team_id' => $alpha->id, 'title' => 'A2', 'status' => 'open', 'created_on' => '2024-01-01']);
        GroupTask::create(['team_id' => $beta->id, 'title' => 'B2', 'status' => 'done', 'created_on' => '2024-01-02']);
        GroupTask::create(['team_id' => $alpha->id, 'title' => 'A3', 'status' => 'done', 'created_on' => '2024-01-03']);
    }

    public function test_groups_serialize_to_table_data(): void
    {
        $data = Table::make(GroupTask::query())
            ->columns([TextColumn::make('title'), TextColumn::make('status')])
            ->groups([
                Group::make('status')->label('State')->collapsible(),
                'created_on',
            ])
            ->toArray();

        $this->assertCount(2, $data['groups']);

        $this->assertSame('status', $data['groups'][0]['column']);
        $this->assertSame('State', $data['groups'][0]['label']);
        $this->assertTrue($data['groups'][0]['collapsible']);

        // A bare string is sugar for Group::make(); label defaults to headline.
        $this->assertSame('created_on', $data['groups'][1]['column']);
        $this->assertSame('Created On', $data['groups'][1]['label']);
        $this->assertFalse($data['groups'][1]['collapsible']);

        // No group is active until defaultGroup()/?group= selects one.
        $this->assertNull($data['defaultGroup']);
    }

    public function test_default_group_activates_a_registered_group(): void
    {
        $data = Table::make(GroupTask::query())
            ->columns([TextColumn::make('title'), TextColumn::make('status')])
            ->groups(['status'])
            ->defaultGroup('status')
            ->toArray();

        $this->assertSame('status', $data['defaultGroup']);
    }

    public function test_default_group_string_auto_registers_when_not_in_groups(): void
    {
        // defaultGroup() is DEVELOPER config, not client input: a string that
        // groups() didn't register is auto-registered and activated (Filament
        // parity — defaultGroup('status') stands alone). Only ?group= is
        // allowlist-checked.
        $data = Table::make(GroupTask::query())
            ->columns([TextColumn::make('title')])
            ->groups(['status'])
            ->defaultGroup('created_on')
            ->toArray();

        $this->assertSame('created_on', $data['defaultGroup']);
        $this->assertCount(2, $data['groups']);
    }

    public function test_default_group_object_registers_and_activates(): void
    {
        // Passing a Group to defaultGroup() stands alone without groups().
        $data = Table::make(GroupTask::query())
            ->columns([TextColumn::make('title')])
            ->defaultGroup(Group::make('status')->collapsible())
            ->toArray();

        $this->assertSame('status', $data['defaultGroup']);
        $this->assertCount(1, $data['groups']);
        $this->assertTrue($data['groups'][0]['collapsible']);
    }

    public function test_each_row_carries_its_group_key_and_label(): void
    {
        $data = Table::make(GroupTask::query())
            ->columns([TextColumn::make('title'), TextColumn::make('status')])
            ->defaultGroup('status')
            ->paginated(false)
            ->toArray();

        foreach ($data['records'] as $record) {
            $this->assertSame($record['values']['status'], $record['groupKey']);
            $this->assertSame($record['values']['status'], $record['groupLabel']);
        }
    }

    public function test_rows_without_an_active_group_have_null_group_metadata(): void
    {
        $data = Table::make(GroupTask::query())
            ->columns([TextColumn::make('title')])
            ->groups(['status']) // registered but not defaulted → inactive
            ->toArray();

        $this->assertNull($data['records'][0]['groupKey']);
        $this->assertNull($data['records'][0]['groupLabel']);
    }

    public function test_active_group_orders_rows_contiguously_by_group(): void
    {
        // Statuses were inserted interleaved; grouping must bring same-status
        // rows together so the frontend renders one header per contiguous run.
        $data = Table::make(GroupTask::query())
            ->columns([TextColumn::make('title'), TextColumn::make('status')])
            ->defaultGroup('status')
            ->paginated(false)
            ->toArray();

        $keys = array_map(static fn (array $r): mixed => $r['groupKey'], $data['records']);

        // Collapse consecutive duplicates: a contiguous grouping yields exactly
        // one run per distinct key.
        $runs = [];
        foreach ($keys as $key) {
            if ($runs === [] || end($runs) !== $key) {
                $runs[] = $key;
            }
        }

        $this->assertSame(count(array_unique($keys)), count($runs), 'Group rows are not contiguous.');
    }

    public function test_user_sort_orders_rows_within_each_group(): void
    {
        request()->merge(['sort' => 'title', 'direction' => 'desc']);

        $data = Table::make(GroupTask::query())
            ->columns([
                TextColumn::make('title')->sortable(),
                TextColumn::make('status'),
            ])
            ->defaultGroup('status')
            ->paginated(false)
            ->toArray();

        // Group is primary; within the 'open' bucket, titles descend (A2, A1).
        $openTitles = [];
        foreach ($data['records'] as $record) {
            if ($record['groupKey'] === 'open') {
                $openTitles[] = $record['values']['title'];
            }
        }

        $this->assertSame(['A2', 'A1'], $openTitles);
    }

    public function test_relation_group_column_does_not_query_per_row(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $data = Table::make(GroupTask::query())
            ->columns([
                TextColumn::make('title'),
                TextColumn::make('team.name'),
            ])
            ->defaultGroup('team.name')
            ->paginated(false)
            ->toArray();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // base rows + one eager load for teams — NOT one per row. The group key
        // must come from the already-loaded relation, never a fresh query.
        $this->assertLessThanOrEqual(2, $queries, 'Grouping a relation column is lazy-loading per row (N+1).');

        // Every row carries its team name as the group key/label.
        $byTitle = [];
        foreach ($data['records'] as $record) {
            $byTitle[$record['values']['title']] = $record['groupKey'];
        }

        $this->assertSame('Alpha', $byTitle['A1']);
        $this->assertSame('Beta', $byTitle['B1']);
    }

    public function test_request_group_param_overrides_default_within_allowlist(): void
    {
        request()->merge(['group' => 'created_on']);

        $data = Table::make(GroupTask::query())
            ->columns([TextColumn::make('title')])
            ->groups(['status', 'created_on'])
            ->defaultGroup('status')
            ->toArray();

        $this->assertSame('created_on', $data['defaultGroup']);
    }

    public function test_request_group_param_outside_allowlist_falls_back_to_default(): void
    {
        // A crafted ?group= naming an unregistered column must never activate.
        request()->merge(['group' => 'secret_column']);

        $data = Table::make(GroupTask::query())
            ->columns([TextColumn::make('title')])
            ->groups(['status'])
            ->defaultGroup('status')
            ->toArray();

        $this->assertSame('status', $data['defaultGroup']);
    }

    public function test_group_date_helper_buckets_by_day(): void
    {
        $data = Table::make(GroupTask::query())
            ->columns([TextColumn::make('title')])
            ->defaultGroup(Group::make('created_on')->date())
            ->paginated(false)
            ->toArray();

        $byTitle = [];
        foreach ($data['records'] as $record) {
            $byTitle[$record['values']['title']] = $record['groupKey'];
        }

        $this->assertSame('2024-01-01', $byTitle['A1']);
        $this->assertSame('2024-01-03', $byTitle['A3']);
    }

    public function test_format_record_returns_table_row_data_with_group_fields(): void
    {
        // Guards the DTO contract the frontend reads.
        $row = new TableRowData(
            id: 1,
            values: ['status' => 'open'],
            icons: [],
            iconColors: [],
            badgeColors: [],
            descriptions: [],
            groupKey: 'open',
            groupLabel: 'Open',
        );

        $this->assertSame('open', $row->groupKey);
        $this->assertSame('Open', $row->groupLabel);
    }

    private static function runs(array $records): array
    {
        $runs = [];

        foreach ($records as $record) {
            if ($runs === [] || end($runs) !== $record['groupKey']) {
                $runs[] = $record['groupKey'];
            }
        }

        return $runs;
    }

    /** Casting an enum to string threw: grouping by an enum column was a 500. */
    public function test_an_enum_column_groups_by_its_value_under_its_label(): void
    {
        $data = Table::make(GroupEnumTask::query())
            ->columns([TextColumn::make('title')])
            ->defaultGroup('status')
            ->paginated(false)
            ->toArray();

        $labels = [];
        foreach ($data['records'] as $record) {
            $labels[$record['groupKey']] = $record['groupLabel'];
        }

        $this->assertSame(['done' => 'Finished', 'open' => 'In progress'], $labels);
    }

    /** `false` became '' and read as "no value". */
    public function test_a_boolean_column_groups_as_yes_and_no(): void
    {
        Schema::create('group_flags', static function (Blueprint $table): void {
            $table->increments('id');
            $table->boolean('done');
        });
        GroupFlag::create(['done' => true]);
        GroupFlag::create(['done' => false]);

        $data = Table::make(GroupFlag::query())
            ->columns([TextColumn::make('id')])
            ->defaultGroup('done')
            ->paginated(false)
            ->toArray();

        $labels = [];
        foreach ($data['records'] as $record) {
            $labels[$record['groupKey']] = $record['groupLabel'];
        }

        $this->assertSame(
            ['0' => __('kinetix.table_group_false'), '1' => __('kinetix.table_group_true')],
            $labels,
        );
    }

    /** param() already namespaces by the prefix; `?p_group=` was ignored. */
    public function test_a_prefixed_table_reads_its_group_param(): void
    {
        request()->merge(['p_group' => 'status']);

        $data = Table::make(GroupTask::query())
            ->queryPrefix('p_')
            ->columns([TextColumn::make('title')])
            ->groups(['status', 'created_on'])
            ->defaultGroup('created_on')
            ->paginated(false)
            ->toArray();

        $this->assertSame('status', $data['defaultGroup']);
    }

    /** Without a `team.name` column the team lazy-loaded once per row. */
    public function test_a_relation_group_without_its_column_is_eager_loaded(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        Table::make(GroupTask::query())
            ->columns([TextColumn::make('title')])
            ->defaultGroup('team.name')
            ->paginated(false)
            ->toArray();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(2, $queries);
    }

    /**
     * A group SQL can't order (here an aggregate alias, ordered unqualified)
     * or can't order at all still renders one header per bucket.
     */
    public function test_a_withcount_alias_group_is_ordered_without_qualifying_it(): void
    {
        $data = Table::make(GroupTeam::query()->withCount('tasks'))
            ->columns([TextColumn::make('name')])
            ->defaultGroup('tasks_count')
            ->paginated(false)
            ->toArray();

        $this->assertSame(['2', '3'], self::runs($data['records']));
    }
}
