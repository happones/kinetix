<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Http;

use Happones\Kinetix\Forms\Form;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A FormRequest whose validation is driven by a Kinetix {@see Form}.
 *
 * Extend it and implement `form()`; the rules, messages, and `:attribute` names
 * come from the form schema. Everything else is a normal FormRequest — override
 * `authorize()`, `prepareForValidation()`, `passedValidation()`, add
 * `additionalRules()`, etc. as usual.
 *
 * ```php
 * class StorePostRequest extends KinetixFormRequest
 * {
 *     protected function form(): Form
 *     {
 *         return PostForm::make()->model(Post::class);
 *     }
 * }
 *
 * // Controller:
 * public function store(StorePostRequest $request)
 * {
 *     Post::create($request->dehydratedState());
 * }
 * ```
 */
abstract class KinetixFormRequest extends FormRequest
{
    use ResolvesKinetixForm;
}
