<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Actions\DeleteAction;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;

class DeleteActionWidget extends Model
{
    protected $guarded = [];
}

class DeleteActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn (): bool => true);
        $this->actingAs(new User);
    }

    public function test_the_confirmation_names_the_delete_on_its_heading_and_button(): void
    {
        $data = DeleteAction::make()->toData(new DeleteActionWidget);

        $this->assertTrue($data->requiresConfirmation);
        $this->assertSame(__('kinetix.confirm_delete'), $data->modalHeading);
        $this->assertSame(__('kinetix.delete'), $data->modalSubmitActionLabel);
        // Whether it can be undone depends on the model, so no default text.
        $this->assertNull($data->modalDescription);
    }

    public function test_the_app_can_still_word_its_own_confirmation(): void
    {
        $data = DeleteAction::make()
            ->modalHeading('Delete this request?')
            ->modalDescription('This cannot be undone.')
            ->toData(new DeleteActionWidget);

        $this->assertSame('Delete this request?', $data->modalHeading);
        $this->assertSame('This cannot be undone.', $data->modalDescription);
    }
}
