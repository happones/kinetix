<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\GeneratorInput;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class HiddenAttrUser extends Model
{
    protected $table = 'hidden_attr_users';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['password'];
}

/**
 * Form::fill() read attributes with data_get(), which looks past `$hidden`: an
 * edit form shipped the user's password hash to the browser (and a revealable
 * generator showed it as text).
 */
class FormHiddenAttributesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('hidden_attr_users', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('password');
        });
    }

    private function form(HiddenAttrUser $user): Form
    {
        return Form::make($user)->schema([
            TextInput::make('name')->required(),
            GeneratorInput::make('password')->password()->required(),
        ]);
    }

    public function test_a_hidden_attribute_never_reaches_the_browser(): void
    {
        $hash = Hash::make('secret-1');
        $user = HiddenAttrUser::create(['name' => 'Ada', 'password' => $hash]);

        $payload = json_encode($this->form($user)->fill($user)->toArray());

        $this->assertStringNotContainsString($hash, (string) $payload);
        $this->assertStringContainsString('Ada', (string) $payload);
    }

    public function test_left_blank_on_edit_it_keeps_the_stored_value(): void
    {
        $user = HiddenAttrUser::create(['name' => 'Ada', 'password' => Hash::make('secret-1')]);
        $form = $this->form($user);
        $data = ['name' => 'Ada L.', 'password' => ''];

        // Not required, not written: blank means "unchanged".
        $this->assertArrayNotHasKey('password', $form->getValidationRulesForInput($data));
        $this->assertSame(['name' => 'Ada L.'], $form->getState($data));
    }

    public function test_a_new_value_is_still_validated_and_saved(): void
    {
        $user = HiddenAttrUser::create(['name' => 'Ada', 'password' => Hash::make('secret-1')]);
        $form = $this->form($user);
        $data = ['name' => 'Ada', 'password' => 'new-secret'];

        $this->assertArrayHasKey('password', $form->getValidationRulesForInput($data));
        $this->assertSame('new-secret', $form->getState($data)['password']);
    }

    public function test_on_create_a_blank_value_is_validated_as_usual(): void
    {
        $form = $this->form(new HiddenAttrUser);

        $this->assertContains('required', $form->getValidationRulesForInput(['name' => 'Ada', 'password' => ''])['password']);
    }
}
