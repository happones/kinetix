<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Closure;
use Happones\Kinetix\Actions\CreateAction;
use Happones\Kinetix\Actions\DeleteAction;
use Happones\Kinetix\Flash\KinetixFlash;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\NotificationPreferences\KinetixNotificationPreferences;
use Happones\Kinetix\NotificationPreferences\NotificationPreferenceManager;
use Happones\Kinetix\Notifications\KinetixLaravelNotification;
use Happones\Kinetix\Notifications\Notification;
use Happones\Kinetix\Notifications\NotificationFeed;
use Happones\Kinetix\Resources\CrudEvent;
use Happones\Kinetix\Resources\CrudNotificationDelivery;
use Happones\Kinetix\Resources\RelationManager;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class CrudNotifUser extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    public function getCurrentTeamAttribute(): Model
    {
        $team = new class extends Model {};
        $team->setAttribute('id', 7);

        return $team;
    }
}

class CrudNotifWidget extends Model
{
    protected $table = 'crud_notif_widgets';

    public $timestamps = false;

    protected $guarded = [];

    public function items()
    {
        return $this->hasMany(CrudNotifItem::class, 'widget_id');
    }

    public function tags()
    {
        return $this->belongsToMany(CrudNotifTag::class, 'crud_notif_tag_widget', 'widget_id', 'tag_id');
    }
}

class CrudNotifItem extends Model
{
    protected $table = 'crud_notif_items';

    public $timestamps = false;

    protected $guarded = [];
}

class CrudNotifTag extends Model
{
    protected $table = 'crud_notif_tags';

    public $timestamps = false;

    protected $guarded = [];
}

class CrudNotifWidgetResource extends Resource
{
    protected static ?string $model = CrudNotifWidget::class;

    /** @var (Closure(CrudEvent, Model): ?Notification)|null */
    public static ?Closure $notification = null;

    /** @var (Closure(CrudEvent, Model): CrudNotificationDelivery)|null */
    public static ?Closure $delivery = null;

    public static bool $failBeforeDelete = false;

    /** @var list<array{CrudEvent, Model}> */
    public static array $seen = [];

    public static function reset(): void
    {
        static::$notification     = null;
        static::$delivery         = null;
        static::$failBeforeDelete = false;
        static::$seen             = [];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([TextInput::make('name')->required()]);
    }

    public static function beforeDelete(Model $record): void
    {
        if (static::$failBeforeDelete) {
            throw new RuntimeException('nope');
        }
    }

    public static function getCrudNotification(CrudEvent $event, Model $record): ?Notification
    {
        static::$seen[] = [$event, $record];

        return static::$notification !== null
            ? (static::$notification)($event, $record)
            : parent::getCrudNotification($event, $record);
    }

    public static function getCrudNotificationDelivery(CrudEvent $event, Model $record): CrudNotificationDelivery
    {
        return static::$delivery !== null
            ? (static::$delivery)($event, $record)
            : parent::getCrudNotificationDelivery($event, $record);
    }
}

class CrudNotifItemResource extends Resource
{
    protected static ?string $model = CrudNotifItem::class;

    public static function getCrudNotification(CrudEvent $event, Model $record): ?Notification
    {
        return Notification::make()->success()->title('Item '.$event->value);
    }

    public static function getCrudNotificationDelivery(CrudEvent $event, Model $record): CrudNotificationDelivery
    {
        return CrudNotificationDelivery::ToastAndDatabase;
    }
}

class CrudNotifPlainManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function form(Form $form): Form
    {
        return $form->schema([TextInput::make('title')->required()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('title')])
            ->toolbarActions([CreateAction::make()->modal('create')])
            ->recordActions([DeleteAction::make()->modal('delete')]);
    }
}

class CrudNotifInheritingManager extends CrudNotifPlainManager
{
    protected static ?string $relatedResource = CrudNotifItemResource::class;
}

class CrudNotifOverridingManager extends CrudNotifInheritingManager
{
    public function getCrudNotification(CrudEvent $event, Model $record): ?Notification
    {
        return $event === CrudEvent::Deleted ? null : Notification::make()->warning()->title('Manager says '.$event->value);
    }
}

class CrudNotifDatabaseManager extends CrudNotifPlainManager
{
    public function getCrudNotificationDelivery(CrudEvent $event, Model $record): CrudNotificationDelivery
    {
        return CrudNotificationDelivery::Database;
    }
}

class CrudNotifTagsManager extends RelationManager
{
    /** @var list<Model> */
    public static array $records = [];

    protected static string $relationship = 'tags';

    public function form(Form $form): Form
    {
        return $form->schema([TextInput::make('name')->required()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('name')])
            ->toolbarActions([CreateAction::make()->modal('create')]);
    }

    public function getCrudNotification(CrudEvent $event, Model $record): ?Notification
    {
        static::$records[] = $record;

        return parent::getCrudNotification($event, $record);
    }
}

class CrudNotificationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('queue.default', 'sync');

        CrudNotifWidgetResource::reset();
        CrudNotifTagsManager::$records = [];

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
        Schema::create('crud_notif_widgets', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('crud_notif_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('widget_id')->nullable();
            $table->string('title');
        });
        Schema::create('crud_notif_tags', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('crud_notif_tag_widget', function (Blueprint $table) {
            $table->unsignedInteger('widget_id');
            $table->unsignedInteger('tag_id');
        });

        $this->actingAs(CrudNotifUser::create(['name' => 'Ada']));
    }

    private function token(): string
    {
        return (string) Table::make(CrudNotifWidget::query())
            ->recordModals(CrudNotifWidgetResource::class)
            ->toData()
            ->recordModals
            ->token;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function firstToast(): ?array
    {
        return KinetixFlash::flashed()['toasts'][0] ?? null;
    }

    private function store(string $name = 'Gadget'): void
    {
        $this->from('/widgets')->post(route('kinetix.tables.record.store'), [
            'token' => $this->token(),
            'data'  => ['name' => $name],
        ])->assertRedirect('/widgets');
    }

    private function user(): CrudNotifUser
    {
        return CrudNotifUser::query()->firstOrFail();
    }

    public function test_the_default_is_the_generic_toast_and_no_database_row(): void
    {
        $widget = CrudNotifWidget::create(['name' => 'Old']);

        $this->store();
        $this->assertSame((string) __('kinetix.record_created'), $this->firstToast()['message'] ?? null);
        $this->assertSame('success', $this->firstToast()['type'] ?? null);

        $this->put(route('kinetix.tables.record.update'), [
            'token' => $this->token(), 'id' => $widget->id, 'data' => ['name' => 'New'],
        ])->assertRedirect();
        $this->assertSame((string) __('kinetix.record_updated'), session('message'));

        $this->delete(route('kinetix.tables.record.destroy'), ['token' => $this->token(), 'id' => $widget->id])
            ->assertRedirect();
        $this->assertSame((string) __('kinetix.record_deleted'), session('message'));

        $this->assertSame(0, $this->user()->notifications()->count());
    }

    public function test_title_and_body_can_be_customized(): void
    {
        CrudNotifWidgetResource::$notification = static fn (CrudEvent $event, Model $record): ?Notification => Notification::make()
            ->success()
            ->title('Order #'.$record->getKey().' placed')
            ->body('Thanks');

        $this->store();

        $toast = $this->firstToast();
        $this->assertSame('Order #1 placed', $toast['message'] ?? null);
        $this->assertSame('Thanks', $toast['description'] ?? null);
    }

    public function test_the_status_maps_to_the_toast_type(): void
    {
        CrudNotifWidgetResource::$notification = static fn (): Notification => Notification::make()->danger()->title('Careful');
        $this->store();
        $this->assertSame('error', $this->firstToast()['type'] ?? null);

        CrudNotifWidgetResource::$notification = static fn (): Notification => Notification::make()->warning()->title('Hmm');
        $this->store('Other');
        $this->assertSame('warning', KinetixFlash::flashed()['toasts'][1]['type'] ?? null);
    }

    public function test_null_silences_the_message_but_the_write_still_happens(): void
    {
        CrudNotifWidgetResource::$notification = static fn (): ?Notification => null;

        $this->store();

        $this->assertNull($this->firstToast());
        $this->assertNull(session('message'));
        $this->assertSame(1, CrudNotifWidget::count());
        $this->assertSame(0, $this->user()->notifications()->count());
    }

    public function test_a_hook_can_silence_a_single_event(): void
    {
        $widget = CrudNotifWidget::create(['name' => 'Old']);

        CrudNotifWidgetResource::$notification = static fn (CrudEvent $event): ?Notification => match ($event) {
            CrudEvent::Deleted => null,
            default            => Notification::make()->success()->title('ok '.$event->value),
        };

        $this->delete(route('kinetix.tables.record.destroy'), ['token' => $this->token(), 'id' => $widget->id])
            ->assertRedirect();
        $this->assertNull($this->firstToast());

        $this->store();
        $this->assertSame('ok created', $this->firstToast()['message'] ?? null);
    }

    public function test_an_empty_title_sends_nothing(): void
    {
        CrudNotifWidgetResource::$notification = static fn (): Notification => Notification::make();

        $this->store();

        $this->assertNull($this->firstToast());
    }

    public function test_database_delivery_persists_unread_without_a_toast(): void
    {
        CrudNotifWidgetResource::$notification = static fn (): Notification => Notification::make()->success()->title('Saved');
        CrudNotifWidgetResource::$delivery     = static fn (): CrudNotificationDelivery => CrudNotificationDelivery::Database;

        $this->store();

        $this->assertNull($this->firstToast());

        $row = $this->user()->notifications()->sole();
        $this->assertSame('Saved', $row->data['title']);
        $this->assertNull($row->read_at);
    }

    public function test_toast_and_database_stores_a_read_row_and_never_broadcasts(): void
    {
        config()->set('kinetix.notifications.broadcast', true);
        CrudNotifWidgetResource::$delivery = static fn (): CrudNotificationDelivery => CrudNotificationDelivery::ToastAndDatabase;

        $this->store();

        $this->assertSame((string) __('kinetix.record_created'), $this->firstToast()['message'] ?? null);

        $row = $this->user()->notifications()->sole();
        $this->assertNotNull($row->read_at);
        $this->assertSame(0, $this->user()->unreadNotifications()->count());

        NotificationFacade::fake();
        CrudNotifWidgetResource::sendCrudNotification(CrudEvent::Created, new CrudNotifWidget);
        NotificationFacade::assertSentTo(
            $this->user(),
            KinetixLaravelNotification::class,
            static fn (KinetixLaravelNotification $n): bool => $n->markAsRead && $n->channels === ['database'],
        );
    }

    public function test_database_delivery_broadcasts_when_enabled(): void
    {
        config()->set('kinetix.notifications.broadcast', true);
        CrudNotifWidgetResource::$delivery = static fn (): CrudNotificationDelivery => CrudNotificationDelivery::Database;

        NotificationFacade::fake();
        CrudNotifWidgetResource::sendCrudNotification(CrudEvent::Created, new CrudNotifWidget);

        NotificationFacade::assertSentTo(
            $this->user(),
            KinetixLaravelNotification::class,
            static fn (KinetixLaravelNotification $n): bool => $n->channels === ['database', 'broadcast']
                && $n->type                                                 === KinetixNotificationPreferences::RECORDS,
        );
    }

    public function test_the_row_is_stamped_with_the_team_unless_one_is_given(): void
    {
        config()->set('kinetix.teams', true);
        CrudNotifWidgetResource::$delivery = static fn (): CrudNotificationDelivery => CrudNotificationDelivery::Database;

        $expected = KinetixTeams::keyFor('notifications');
        $this->assertNotNull($expected);

        $this->store();
        $this->assertSame($expected, $this->user()->notifications()->first()->data['team'] ?? null);

        CrudNotifWidgetResource::$notification = static fn (): Notification => Notification::make()->title('Pinned')->team(99);
        $this->store('Two');
        $this->assertSame(99, $this->user()->notifications()->get()->firstWhere('data.title', 'Pinned')->data['team'] ?? null);
    }

    public function test_the_row_has_no_team_when_teams_are_off(): void
    {
        CrudNotifWidgetResource::$delivery = static fn (): CrudNotificationDelivery => CrudNotificationDelivery::Database;

        $this->store();

        $this->assertNull($this->user()->notifications()->first()->data['team'] ?? null);
    }

    public function test_a_user_who_switched_the_database_channel_off_gets_no_row_but_still_sees_the_toast(): void
    {
        config()->set('kinetix.notification_preferences.enabled', true);
        config()->set('kinetix.notification_preferences.types', [KinetixNotificationPreferences::RECORDS => 'Records']);
        Schema::create('kinetix_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->json('preferences')->nullable();
            $table->timestamps();
        });
        app(NotificationPreferenceManager::class)->update($this->user(), KinetixNotificationPreferences::RECORDS, 'database', false);

        CrudNotifWidgetResource::$delivery = static fn (): CrudNotificationDelivery => CrudNotificationDelivery::ToastAndDatabase;
        $this->store();

        $this->assertNotNull($this->firstToast());
        $this->assertSame(0, $this->user()->notifications()->count());
    }

    public function test_the_recipient_can_differ_from_the_actor(): void
    {
        $other = CrudNotifUser::create(['name' => 'Bob']);

        CrudNotifWidgetResource::$notification = static fn (): Notification => Notification::make()->title('For Bob')->to($other);
        CrudNotifWidgetResource::$delivery     = static fn (): CrudNotificationDelivery => CrudNotificationDelivery::ToastAndDatabase;

        $this->store();

        $this->assertSame('For Bob', $this->firstToast()['message'] ?? null);
        $this->assertSame(1, $other->notifications()->count());
        $this->assertSame(0, $this->user()->notifications()->count());
    }

    public function test_nothing_is_sent_when_the_write_fails(): void
    {
        $widget = CrudNotifWidget::create(['name' => 'Old']);

        $this->from('/widgets')->post(route('kinetix.tables.record.store'), [
            'token' => $this->token(),
            'data'  => ['name' => ''],
        ])->assertRedirect('/widgets');

        CrudNotifWidgetResource::$failBeforeDelete = true;

        $this->delete(route('kinetix.tables.record.destroy'), [
            'token' => $this->token(), 'id' => $widget->id,
        ])->assertStatus(500);

        $this->assertSame([], CrudNotifWidgetResource::$seen);
        $this->assertSame(0, $this->user()->notifications()->count());
        $this->assertSame(1, CrudNotifWidget::count());
    }

    public function test_the_hook_receives_the_persisted_and_the_deleted_record(): void
    {
        $this->store('Fresh');
        [$event, $record] = CrudNotifWidgetResource::$seen[0];
        $this->assertSame(CrudEvent::Created, $event);
        $this->assertSame(1, $record->getKey());
        $this->assertSame('Fresh', $record->name);

        $this->delete(route('kinetix.tables.record.destroy'), ['token' => $this->token(), 'id' => 1])->assertRedirect();
        [$event, $record] = CrudNotifWidgetResource::$seen[1];
        $this->assertSame(CrudEvent::Deleted, $event);
        $this->assertSame('Fresh', $record->name);
        $this->assertFalse($record->exists);
    }

    private function managerToken(string $manager): string
    {
        $widget = CrudNotifWidget::first() ?? CrudNotifWidget::create(['name' => 'Parent']);

        return (string) $manager::make($widget)->toData()->table->recordModals?->token;
    }

    public function test_a_manager_without_a_related_resource_sends_the_generic_message(): void
    {
        $this->from('/x')->post(route('kinetix.relations.record.store'), [
            'token' => $this->managerToken(CrudNotifPlainManager::class),
            'data'  => ['title' => 'T'],
        ])->assertRedirect('/x');

        $this->assertSame((string) __('kinetix.record_created'), $this->firstToast()['message'] ?? null);
        $this->assertSame((string) __('kinetix.record_created'), session('message'));
    }

    public function test_a_manager_inherits_its_related_resources_message_and_delivery(): void
    {
        $this->from('/x')->post(route('kinetix.relations.record.store'), [
            'token' => $this->managerToken(CrudNotifInheritingManager::class),
            'data'  => ['title' => 'T'],
        ])->assertRedirect('/x');

        $this->assertSame('Item created', $this->firstToast()['message'] ?? null);
        $this->assertSame('Item created', $this->user()->notifications()->sole()->data['title']);
    }

    public function test_a_manager_override_wins_over_its_related_resource(): void
    {
        $token = $this->managerToken(CrudNotifOverridingManager::class);

        $this->from('/x')->post(route('kinetix.relations.record.store'), [
            'token' => $token,
            'data'  => ['title' => 'T'],
        ])->assertRedirect('/x');
        $this->assertSame('Manager says created', $this->firstToast()['message'] ?? null);
        $this->assertSame('warning', $this->firstToast()['type'] ?? null);

        $this->delete(route('kinetix.relations.record.destroy'), ['token' => $token, 'id' => CrudNotifItem::first()->id])
            ->assertRedirect();
        $this->assertCount(1, KinetixFlash::flashed()['toasts']);
    }

    public function test_a_belongs_to_many_create_passes_the_related_record_not_the_pivot(): void
    {
        $this->from('/x')->post(route('kinetix.relations.record.store'), [
            'token' => $this->managerToken(CrudNotifTagsManager::class),
            'data'  => ['name' => 'php'],
        ])->assertRedirect('/x');

        $this->assertCount(1, CrudNotifTagsManager::$records);
        $this->assertInstanceOf(CrudNotifTag::class, CrudNotifTagsManager::$records[0]);
        $this->assertSame('php', CrudNotifTagsManager::$records[0]->name);
    }

    public function test_a_failing_store_is_reported_and_the_write_still_redirects(): void
    {
        Exceptions::fake();
        Schema::drop('notifications');
        CrudNotifWidgetResource::$delivery = static fn (): CrudNotificationDelivery => CrudNotificationDelivery::Database;

        $this->store();

        $this->assertSame(1, CrudNotifWidget::count());
        Exceptions::assertReported(QueryException::class);
    }

    public function test_the_legacy_message_flash_follows_the_toast(): void
    {
        CrudNotifWidgetResource::$delivery = static fn (): CrudNotificationDelivery => CrudNotificationDelivery::Database;
        $this->store();
        $this->assertNull(session('message'));

        CrudNotifWidgetResource::$delivery = static fn (): CrudNotificationDelivery => CrudNotificationDelivery::ToastAndDatabase;
        $this->store('Two');
        $this->assertSame((string) __('kinetix.record_created'), session('message'));
    }

    public function test_a_guest_sends_a_toast_but_stores_nothing(): void
    {
        Auth::forgetGuards();
        Auth::guard()->logout();

        $sent = CrudNotifWidgetResource::sendCrudNotification(CrudEvent::Created, new CrudNotifWidget);
        $this->assertNotNull($sent);

        CrudNotifWidgetResource::$delivery = static fn (): CrudNotificationDelivery => CrudNotificationDelivery::ToastAndDatabase;
        CrudNotifWidgetResource::sendCrudNotification(CrudEvent::Created, new CrudNotifWidget);

        $this->assertSame(0, DatabaseNotification::count());
    }

    public function test_the_queued_job_stores_the_row_as_read_through_the_read_channel(): void
    {
        Queue::fake();
        CrudNotifWidgetResource::$delivery = static fn (): CrudNotificationDelivery => CrudNotificationDelivery::ToastAndDatabase;

        CrudNotifWidgetResource::sendCrudNotification(CrudEvent::Created, new CrudNotifWidget);

        $job = null;
        Queue::assertPushed(SendQueuedNotifications::class, static function (SendQueuedNotifications $pushed) use (&$job): bool {
            $job = $pushed;

            return true;
        });
        $this->assertSame(0, $this->user()->notifications()->count());

        $copy = unserialize(serialize($job));
        $copy->handle(app(ChannelManager::class));

        $this->assertNotNull($this->user()->notifications()->sole()->read_at);
    }

    public function test_the_team_scoped_bell_lists_the_row_only_in_its_team(): void
    {
        config()->set('kinetix.teams', true);
        config()->set('kinetix.notifications.database', true);
        CrudNotifWidgetResource::$notification = static fn (): Notification => Notification::make()->title('Mine');
        CrudNotifWidgetResource::$delivery     = static fn (): CrudNotificationDelivery => CrudNotificationDelivery::Database;

        $this->store();
        $this->user()->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => 'kinetix', 'data' => ['title' => 'Other team', 'team' => 'elsewhere'],
        ]);

        $titles = array_column(NotificationFeed::for($this->user()), 'title');

        $this->assertSame(['Mine'], $titles);
    }

    public function test_database_delivery_from_a_relation_manager_stores_a_row_without_toast_or_message(): void
    {
        $this->from('/x')->post(route('kinetix.relations.record.store'), [
            'token' => $this->managerToken(CrudNotifDatabaseManager::class),
            'data'  => ['title' => 'T'],
        ])->assertRedirect('/x');

        $this->assertNull($this->firstToast());
        $this->assertNull(session('message'));
        $row = $this->user()->notifications()->sole();
        $this->assertSame((string) __('kinetix.record_created'), $row->data['title']);
        $this->assertNull($row->read_at);
    }
}
