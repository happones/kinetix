<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Support\DescriptorAudience;
use Happones\Kinetix\Support\DescriptorRejection;
use Happones\Kinetix\Support\SignedDescriptor;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class DecUser extends Authenticatable
{
    protected $table = 'dec_users';

    public $timestamps = false;

    protected $guarded = [];
}

class DecItem extends Model
{
    protected $table = 'dec_items';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * Calendar moves, kanban moves and form recomputes tell the client WHY a
 * descriptor was refused with a machine `code`, so it can offer a reload
 * for an expired page; statuses and messages are unchanged.
 */
class DescriptorExpiredCodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('dec_users', function (Blueprint $table) {
            $table->increments('id');
        });
        Schema::create('dec_items', function (Blueprint $table) {
            $table->increments('id');
            $table->string('status')->nullable();
        });

        $this->actingAs(DecUser::create([]));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: DescriptorAudience, 3: string, 4: string, 5: int}>
     */
    public static function endpoints(): array
    {
        return [
            'calendar move'  => ['kinetix.tables.calendar-move', 'model', DescriptorAudience::CalendarMove, 'table_descriptor_expired', 'table_invalid_signature', 400],
            'kanban move'    => ['kinetix.tables.kanban-move', 'model', DescriptorAudience::KanbanMove, 'table_descriptor_expired', 'table_invalid_signature', 400],
            'form recompute' => ['kinetix.forms.recompute', 'descriptor', DescriptorAudience::FormRecompute, 'form_session_expired', 'form_session_expired', 400],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_an_expired_descriptor_answers_descriptor_expired(string $route, string $field, DescriptorAudience $audience, string $expired, string $unreadable, int $status): void
    {
        $payload            = SignedDescriptor::bind($this->payload(), $audience);
        $payload['expires'] = now()->subMinute()->getTimestamp();

        $this->postJson(route($route), [$field => Crypt::encrypt($payload), 'status' => 'done'])
            ->assertForbidden()
            ->assertJsonPath('message', __('kinetix.'.$expired))
            ->assertJsonPath('code', 'descriptor_expired');
    }

    #[DataProvider('endpoints')]
    public function test_a_foreign_user_or_audience_answers_descriptor_forbidden(string $route, string $field, DescriptorAudience $audience, string $expired, string $unreadable, int $status): void
    {
        $payload         = SignedDescriptor::bind($this->payload(), $audience);
        $payload['user'] = 999;

        $this->postJson(route($route), [$field => Crypt::encrypt($payload), 'status' => 'done'])
            ->assertForbidden()
            ->assertJsonPath('message', __('kinetix.table_write_forbidden'))
            ->assertJsonPath('code', 'descriptor_forbidden');

        $foreign = SignedDescriptor::seal($this->payload(), DescriptorAudience::Search);

        $this->postJson(route($route), [$field => $foreign, 'status' => 'done'])
            ->assertForbidden()
            ->assertJsonPath('code', 'descriptor_forbidden');
    }

    #[DataProvider('endpoints')]
    public function test_an_unreadable_descriptor_answers_descriptor_expired(string $route, string $field, DescriptorAudience $audience, string $expired, string $unreadable, int $status): void
    {
        $this->postJson(route($route), [$field => 'not-a-descriptor', 'status' => 'done'])
            ->assertStatus($status)
            ->assertJsonPath('message', __('kinetix.'.$unreadable))
            ->assertJsonPath('code', 'descriptor_expired');
    }

    public function test_every_rejection_has_a_code(): void
    {
        $this->assertSame('descriptor_expired', DescriptorRejection::Expired->code());

        foreach ([DescriptorRejection::ForeignUser, DescriptorRejection::ForeignTeam, DescriptorRejection::ForeignAudience] as $rejection) {
            $this->assertSame('descriptor_forbidden', $rejection->code());
        }
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'model'        => DecItem::class,
            'statusColumn' => 'status',
            'statuses'     => ['todo', 'done'],
            'dateColumn'   => 'created_at',
            'moveable'     => true,
            'formClass'    => null,
            'operation'    => 'create',
        ];
    }
}
