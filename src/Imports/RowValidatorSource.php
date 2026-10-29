<?php

declare(strict_types=1);

namespace Happones\Kinetix\Imports;

use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Resources\Resource;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;
use InvalidArgumentException;
use LogicException;

/**
 * Builds the validator an importer row is checked with from the same class
 * the screen validates with ({@see Importer::$validateUsing}), so importing
 * and capturing can't disagree:
 *
 * - a FormRequest runs as it would on its route: prepareForValidation(),
 *   rules(), messages(), attributes(), after() and withValidator(). There is
 *   no HTTP request on the worker, so it gets one carrying the row, the user
 *   who started the import as `$this->user()`, and the record the row updates
 *   as the route parameter named after the model (`$this->route('employee')`)
 *   and as `$this->route('record')`;
 * - a Form class or a Resource (its form()) contributes every field's rules,
 *   messages and labels, filled from the record the row updates, so a field
 *   the file leaves out keeps the value it already has.
 *
 * @internal
 */
final class RowValidatorSource
{
    /**
     * @param  class-string                                 $source
     * @param  array<string, mixed>                         $row
     * @param  class-string<Model>                          $model
     * @return array{0: Validator, 1: array<string, mixed>} the validator and the row it validates
     */
    public static function make(string $source, array $row, ?Model $record, string $model, ?Authenticatable $user): array
    {
        if (is_subclass_of($source, FormRequest::class)) {
            return self::fromRequest($source, $row, $record, $model, $user);
        }

        if (is_subclass_of($source, Resource::class)) {
            return [self::fill($source::form(Form::make($record)), $record, $model)->makeValidator($row), $row];
        }

        if (is_a($source, Form::class, true)) {
            return [self::fill($source::make($record), $record, $model)->makeValidator($row), $row];
        }

        throw new InvalidArgumentException("[{$source}] is not a FormRequest, a Form or a Resource.");
    }

    /**
     * @param  class-string<FormRequest>                    $source
     * @param  array<string, mixed>                         $row
     * @param  class-string<Model>                          $model
     * @return array{0: Validator, 1: array<string, mixed>}
     */
    private static function fromRequest(string $source, array $row, ?Model $record, string $model, ?Authenticatable $user): array
    {
        $base  = Request::create('/', 'POST', $row);
        $route = (new Route('POST', '/', []))->bind($base);

        if ($record !== null) {
            $route->setParameter(Str::snake(class_basename($model)), $record);
            $route->setParameter('record', $record);
        }

        $base->setRouteResolver(static fn (): Route => $route);
        $base->setUserResolver(static fn (): ?Authenticatable => $user);

        $request = $source::createFrom($base);
        $request->setContainer(app());

        // Both are protected: the request is never "resolved" here (no
        // authorize(), no redirect on failure), only validated.
        $validator = (function (): ValidatorContract {
            $this->prepareForValidation();

            return $this->getValidatorInstance();
        })->call($request);

        // Rows add the importer's own rules, messages and labels on top.
        if (! $validator instanceof Validator) {
            throw new LogicException("[{$source}] must build an ".Validator::class.' to validate import rows.');
        }

        return [$validator, $request->all()];
    }

    /**
     * @param class-string<Model> $model
     */
    private static function fill(Form $form, ?Model $record, string $model): Form
    {
        return $record !== null ? $form->fill($record) : $form->model($model);
    }
}
