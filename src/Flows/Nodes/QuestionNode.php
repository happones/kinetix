<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows\Nodes;

use Happones\Kinetix\Flows\FlowStep;
use Happones\Kinetix\Flows\NodeOutcome;

/**
 * Asks something and keeps the answer in `variable`. `validate` (text,
 * number, email, phone) checks it: a wrong answer is asked again with
 * `retry`, and after three the flow follows `invalid`.
 */
class QuestionNode extends BaseNode
{
    public const MAX_ATTEMPTS = 3;

    public function outputs(array $data): array
    {
        return ['next', 'invalid'];
    }

    public function execute(FlowStep $step): NodeOutcome
    {
        $step->outbox->say($step->render((string) ($step->data['text'] ?? '')));

        return NodeOutcome::waitReply();
    }

    public function receive(FlowStep $step, string $input): NodeOutcome
    {
        $value = $this->valid(trim($input), (string) ($step->data['validate'] ?? 'text'));

        if ($value !== null) {
            $step->setVariable((string) ($step->data['variable'] ?? 'answer'), $value);
            $step->state->attempts = 0;

            return NodeOutcome::next();
        }

        $step->state->attempts++;

        if ($step->state->attempts >= self::MAX_ATTEMPTS) {
            $step->state->attempts = 0;

            return NodeOutcome::next('invalid');
        }

        $retry = trim((string) ($step->data['retry'] ?? ''));
        $step->outbox->say($retry !== '' ? $step->render($retry) : __('kinetix::kinetix.flow_retry_default'));

        return NodeOutcome::waitReply();
    }

    public function problem(array $data): ?string
    {
        return match (true) {
            trim((string) ($data['text'] ?? ''))     === '' => $this->missing('text'),
            trim((string) ($data['variable'] ?? '')) === '' => $this->missing('variable'),
            default                                         => null,
        };
    }

    /** The answer as kept, or null when it doesn't pass. */
    protected function valid(string $input, string $kind): int|float|string|null
    {
        if ($input === '') {
            return null;
        }

        return match ($kind) {
            'number' => is_numeric(str_replace(',', '.', $input)) ? (float) str_replace(',', '.', $input) + 0 : null,
            'email'  => filter_var($input, FILTER_VALIDATE_EMAIL) !== false ? strtolower($input) : null,
            'phone'  => preg_match('/^\+?[\d\s().-]{7,20}$/', $input) === 1 ? (string) preg_replace('/[^\d+]/', '', $input) : null,
            default  => $input,
        };
    }
}
