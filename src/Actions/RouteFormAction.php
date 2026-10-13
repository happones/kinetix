<?php

declare(strict_types=1);

namespace Happones\Kinetix\Actions;

use Closure;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Forms\FormRequestFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;
use LogicException;

/**
 * A modal form whose submission goes to YOUR route — your controller, its
 * FormRequest, your policies and events — instead of a Kinetix handler.
 *
 *     RouteFormAction::make('edit')
 *         ->modalForm(PostForm::class)
 *         ->submitTo(fn (Post $post) => route('posts.update', $post), 'put');
 *
 *     // Toolbar: a create modal posting to posts.store, its fields read off
 *     // the FormRequest posts.store validates with.
 *     RouteFormAction::make('create')
 *         ->modalForm(StorePostRequest::class)
 *         ->submitTo(route('posts.store'))
 *         ->precognitive();
 *
 * The modal shows the form class's fields, filled from the row's record. On a
 * row, the form is fetched when the modal opens through the same signed
 * checks as any {@see FormAction}: the record resolved through the table's
 * scope, a row the action rendered on, the action's ability (else the
 * table's write ability, or `update`). Submitting sends the values, top-level
 * as your FormRequest expects them, as an Inertia visit to the URL. A failed
 * validation's errors show in the modal; a redirect closes it.
 *
 * Kinetix doesn't run or re-check the submission: your route authorizes and
 * validates it, as it does for any other client.
 */
class RouteFormAction extends FormAction
{
    /** @var class-string<Form|FormRequest>|null */
    protected ?string $formClass = null;

    /**
     * Only the browser needs where the form goes, so it stays on the table.
     *
     * @var string|(Closure(Model): string)|null
     */
    #[Unsealed]
    protected string|Closure|null $submitUrl = null;

    #[Unsealed]
    protected string $submitMethod = 'post';

    protected bool $precognitive = false;

    /**
     * The form the modal shows, filled from the row's record on a row action:
     * a {@see Form} subclass, or the FormRequest your route validates with —
     * its rules then decide the fields ({@see FormRequestFields}).
     *
     * @param class-string<Form|FormRequest> $form
     */
    public function modalForm(string $form): static
    {
        if (! is_subclass_of($form, Form::class) && ! is_subclass_of($form, FormRequest::class)) {
            throw new InvalidArgumentException("[{$form}] is neither a Kinetix form (a Form subclass) nor a FormRequest.");
        }

        $this->formClass = $form;

        return $this;
    }

    /**
     * Where the form is submitted: a URL, or a closure building it for the
     * row's record (`fn (Post $post) => route('posts.update', $post)`).
     *
     * @param string|(Closure(Model): string) $url
     * @param string                          $method post, put, patch or delete
     */
    public function submitTo(string|Closure $url, string $method = 'post'): static
    {
        $method = strtolower($method);

        if (! in_array($method, ['post', 'put', 'patch', 'delete'], true)) {
            throw new InvalidArgumentException("A form can't be submitted with [{$method}]: use post, put, patch or delete.");
        }

        $this->submitUrl    = $url;
        $this->submitMethod = $method;

        return $this;
    }

    /**
     * Validate the fields live, as the user fills them, against the route the
     * form is submitted to: Laravel Precognition runs your FormRequest's rules
     * for the fields being edited, without reaching the controller. The
     * route needs Laravel's `precognitive` middleware
     * (`HandlePrecognitiveRequests`).
     */
    public function precognitive(bool $condition = true): static
    {
        $this->precognitive = $condition;

        return $this;
    }

    protected function form(Form $form, ?Model $record = null): Form
    {
        if ($this->formClass !== null && is_subclass_of($this->formClass, FormRequest::class)) {
            $form = Form::fromRequest($this->formClass, $record);
        } elseif ($this->formClass !== null && is_subclass_of($this->formClass, Form::class)) {
            $form = $this->formClass::make($record);

            if ($record !== null) {
                $form->fill($record);
            }
        }

        // The browser points it at the route the form is submitted to.
        return $this->precognitive ? $form->precognitive() : $form;
    }

    /**
     * @return array{url: string, method: string}|null
     */
    public function getSubmitTarget(?Model $record = null): ?array
    {
        $url = $this->submitUrl;

        if ($url instanceof Closure) {
            // A row's URL waits for its row.
            if ($record === null && (new \ReflectionFunction($url))->getNumberOfRequiredParameters() > 0) {
                return null;
            }

            $url = $url($record);
        }

        return is_string($url) ? ['url' => $url, 'method' => $this->submitMethod] : null;
    }

    public function submitsToOwnRoute(): bool
    {
        return true;
    }

    /**
     * Never called: the submission goes to the route given to
     * {@see submitTo()}, and Kinetix's form endpoint refuses this action.
     *
     * @param array<string, mixed> $data
     */
    public function handle(array $data, ?Model $record): void
    {
        throw new LogicException('A RouteFormAction is submitted to its own route, not run by Kinetix.');
    }
}
