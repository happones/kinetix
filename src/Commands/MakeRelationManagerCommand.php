<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

class MakeRelationManagerCommand extends GeneratorCommand
{
    protected $signature = 'kinetix:make-relation-manager
        {name : The relation manager class name (e.g. PostsRelationManager)}
        {--relationship= : The parent relationship method name}
        {--attach : BelongsToMany — add AttachAction (toolbar) + DetachAction (row) alongside Create}
        {--associate : HasMany/MorphMany — add AssociateAction (toolbar) + DissociateAction (row) alongside Create}
        {--force}';

    protected $description = 'Create a Kinetix Relation Manager class';

    protected function subNamespace(): string
    {
        return 'RelationManagers';
    }

    protected function stub(string $class): string
    {
        $namespace    = $this->namespace();
        $relationship = $this->option('relationship') ?: 'items';

        // Filament convention: the header always ships Create by default; the
        // relationship-transfer actions (Attach / Associate) COMPOSE with it
        // rather than replacing it.
        $attach    = (bool) $this->option('attach');
        $associate = (bool) $this->option('associate');

        $extraImports = '';
        $toolbarExtra = "\n                // BelongsToMany: AttachAction::make() — HasMany: AssociateAction::make()";
        $rowExtra     = "\n                    // BelongsToMany: DetachAction::make() — HasMany: DissociateAction::make()";
        $titleAttr    = "// The attribute the attach/associate pickers label and search by.\n    // protected static ?string \$recordTitleAttribute = 'name';";

        if ($attach) {
            $extraImports = "\nuse Happones\\Kinetix\\Actions\\AttachAction;\nuse Happones\\Kinetix\\Actions\\DetachAction;";
            $toolbarExtra = "\n                AttachAction::make(),";
            $rowExtra     = "\n                    DetachAction::make(),";
            $titleAttr    = "// The attribute the attach picker labels and searches by.\n    protected static ?string \$recordTitleAttribute = 'name';";
        } elseif ($associate) {
            $extraImports = "\nuse Happones\\Kinetix\\Actions\\AssociateAction;\nuse Happones\\Kinetix\\Actions\\DissociateAction;";
            $toolbarExtra = "\n                AssociateAction::make(),";
            $rowExtra     = "\n                    DissociateAction::make(),";
            $titleAttr    = "// The attribute the associate picker labels and searches by.\n    protected static ?string \$recordTitleAttribute = 'name';";
        }

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Happones\\Kinetix\\Actions\\ActionGroup;{$extraImports}
        use Happones\\Kinetix\\Actions\\CreateAction;
        use Happones\\Kinetix\\Actions\\DeleteAction;
        use Happones\\Kinetix\\Actions\\EditAction;
        use Happones\\Kinetix\\Forms\\Components\\TextInput;
        use Happones\\Kinetix\\Forms\\Form;
        use Happones\\Kinetix\\Resources\\RelationManager;
        use Happones\\Kinetix\\Tables\\Columns\\TextColumn;
        use Happones\\Kinetix\\Tables\\Table;

        class {$class} extends RelationManager
        {
            protected static string \$relationship = '{$relationship}';

            // Pages this manager appears on. Restrict with e.g. ['view'] or ['edit'].
            protected static array \$visibleOn = ['edit', 'view'];

            // Lazy: serialize only the tab stub until the tab is opened.
            // protected static bool \$isLazy = true;

            // Combine with other managers into one tab / add a collapse toggle.
            // protected static ?string \$group = 'Attachments';
            // protected static bool \$isCollapsible = true;

            {$titleAttr}

            /**
             * The form the create/edit MODALS render. Created records are bound
             * to the parent server-side (FK / morph / pivot), so the schema
             * never needs a parent select or foreign-key field. Authorization is
             * INHERITED from the related model's own policy — Create checks
             * `create`, Edit/Delete check `update`/`delete` per record.
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
                        CreateAction::make()->modal('create'),{$toolbarExtra}
                    ])
                    ->recordActions([
                        ActionGroup::make([
                            EditAction::make()->modal('edit'),
                            DeleteAction::make()->modal('delete'),{$rowExtra}
                        ]),
                    ]);
            }
        }
        PHP;
    }
}
