<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Http;

use Happones\Kinetix\Forms\Form;

/**
 * Bridges a Laravel FormRequest to a Kinetix {@see Form}: validation rules,
 * messages, and `:attribute` names are pulled straight from the form schema, so
 * they live in exactly one place. Because the rules come from the FormRequest,
 * Laravel Precognition validates against them automatically — add the
 * `HandlePrecognitiveRequests` middleware to the route and live validation works
 * with no extra wiring.
 *
 * Add this trait to any existing FormRequest and implement `form()`; use
 * `additionalRules()` / `additionalMessages()` / `additionalAttributes()` to
 * layer request-specific extras on top of the schema-derived ones. Prefer
 * extending {@see KinetixFormRequest} when the request has no other base class.
 */
trait ResolvesKinetixForm
{
    protected ?Form $kinetixFormInstance = null;

    /**
     * Build the Kinetix form whose schema drives this request's validation.
     */
    abstract protected function form(): Form;

    /**
     * The memoised form instance — resolved once per request.
     */
    protected function kinetixForm(): Form
    {
        return $this->kinetixFormInstance ??= $this->form();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->kinetixForm()->getValidationRules(), $this->additionalRules());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge($this->kinetixForm()->getValidationMessages(), $this->additionalMessages());
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_merge($this->kinetixForm()->getValidationAttributes(), $this->additionalAttributes());
    }

    /**
     * The submitted input run through the form's dehydration pipeline
     * (`dehydrateStateUsing()` hooks, `saved(false)` fields removed) — the clean
     * array to persist. `getState()` restricts the output to the form's own
     * saved/visible/authorized fields, so the full request input is passed in
     * (fields with no validation rules are absent from `validated()` and would
     * otherwise be silently dropped).
     *
     * @return array<string, mixed>
     */
    public function dehydratedState(): array
    {
        return $this->kinetixForm()->getState($this->all());
    }

    /**
     * Extra rules merged on top of the schema-derived ones.
     *
     * @return array<string, mixed>
     */
    protected function additionalRules(): array
    {
        return [];
    }

    /**
     * Extra messages merged on top of (and overriding) the schema-derived ones.
     *
     * @return array<string, string>
     */
    protected function additionalMessages(): array
    {
        return [];
    }

    /**
     * Extra `:attribute` names merged on top of the schema-derived ones.
     *
     * @return array<string, string>
     */
    protected function additionalAttributes(): array
    {
        return [];
    }
}
