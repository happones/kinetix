<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

class MakeRelationManagerCommand extends GeneratorCommand
{
    protected $signature = 'kinetix:make-relation-manager {name : The relation manager class name (e.g. PostsRelationManager)} {--relationship= : The parent relationship method name} {--force}';

    protected $description = 'Create a Kinetix Relation Manager class';

    protected function subNamespace(): string
    {
        return 'RelationManagers';
    }

    protected function stub(string $class): string
    {
        $namespace    = $this->namespace();
        $relationship = $this->option('relationship') ?: 'items';

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Happones\\Kinetix\\Actions\\ActionGroup;
        use Happones\\Kinetix\\Actions\\CreateAction;
        use Happones\\Kinetix\\Actions\\DeleteAction;
        use Happones\\Kinetix\\Actions\\EditAction;
        use Happones\\Kinetix\\Forms\\Components\\TextInput;
        use Happones\\Kinetix\\Forms\\Form;
        use Happones\\Kinetix\\Resources\\RelationManager;
        use Happones\\Kinetix\\Tables\\Table;
        use Happones\\Kinetix\\Tables\\Columns\\TextColumn;

        class {$class} extends RelationManager
        {
            protected static string \$relationship = '{$relationship}';

            // Pages this manager appears on. Restrict with e.g. ['view'] or ['edit'].
            protected static array \$visibleOn = ['edit', 'view'];

            // The attribute the attach/associate pickers label and search by.
            // protected static ?string \$recordTitleAttribute = 'name';

            /**
             * The form the create/edit MODALS render. Created records are bound
             * to the parent server-side (FK / morph / pivot), so the schema
             * never needs a parent select or foreign-key field.
             */
            public function form(Form \$form): Form
            {
                return \$form->schema([
                    TextInput::make('name')->required(),
                ]);
            }

            public function table(Table \$table): Table
            {
                return \$table
                    ->columns([
                        TextColumn::make('name')->searchable(),
                    ])
                    ->toolbarActions([
                        CreateAction::make()->modal('create'),
                        // BelongsToMany: AttachAction::make() — HasMany: AssociateAction::make()
                    ])
                    ->recordActions([
                        ActionGroup::make([
                            EditAction::make()->modal('edit'),
                            DeleteAction::make()->modal('delete'),
                            // BelongsToMany: DetachAction::make() — HasMany: DissociateAction::make()
                        ]),
                    ]);
            }
        }
        PHP;
    }
}
