<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows\Nodes;

use Happones\Kinetix\Flows\FlowStep;
use Happones\Kinetix\Flows\NodeOutcome;
use Illuminate\Support\Str;

/**
 * Offers `options` ({id, label}) and branches on the pick — buttons where the
 * provider has them, a numbered menu elsewhere; "2" and the option's label
 * both count. Anything else follows `other` when it's wired, or asks again.
 * The pick is kept in `variable` when one is set.
 */
class OptionsNode extends BaseNode
{
    public function outputs(array $data): array
    {
        return [...array_map(static fn (array $option): string => (string) $option['id'], $this->options($data)), 'other'];
    }

    public function execute(FlowStep $step): NodeOutcome
    {
        $step->outbox->say(
            $step->render((string) ($step->data['text'] ?? '')),
            array_map(static fn (array $option): string => $option['label'], $this->options($step->data)),
        );

        return NodeOutcome::waitReply();
    }

    public function receive(FlowStep $step, string $input): NodeOutcome
    {
        $options = $this->options($step->data);
        $answer  = trim($input);
        $picked  = null;

        if (ctype_digit($answer) && isset($options[(int) $answer - 1])) {
            $picked = $options[(int) $answer - 1];
        } else {
            foreach ($options as $option) {
                if ($this->same($option['label'], $answer)) {
                    $picked = $option;
                    break;
                }
            }
        }

        if ($picked !== null) {
            $step->state->attempts = 0;

            if (($variable = trim((string) ($step->data['variable'] ?? ''))) !== '') {
                $step->setVariable($variable, $picked['label']);
            }

            return NodeOutcome::next((string) $picked['id']);
        }

        // Free text the flow handles itself, when `other` is wired (the
        // engine follows it); otherwise the menu again, a few times.
        $step->state->attempts++;

        if ($step->isWired('other') || $step->state->attempts >= QuestionNode::MAX_ATTEMPTS) {
            $step->state->attempts = 0;
            $step->setVariable((string) ($step->data['variable'] ?? '') ?: 'answer', $answer);

            return NodeOutcome::next('other');
        }

        $step->outbox->say(__('kinetix::kinetix.flow_options_retry'), array_map(static fn (array $option): string => $option['label'], $options));

        return NodeOutcome::waitReply();
    }

    public function problem(array $data): ?string
    {
        return match (true) {
            trim((string) ($data['text'] ?? '')) === '' => $this->missing('text'),
            count($this->options($data)) < 2            => __('kinetix::kinetix.flow_problem_options'),
            default                                     => null,
        };
    }

    /**
     * @param  array<string, mixed>                   $data
     * @return list<array{id: string, label: string}>
     */
    protected function options(array $data): array
    {
        $options = [];

        foreach ((array) ($data['options'] ?? []) as $option) {
            if (is_array($option) && is_scalar($option['id'] ?? null) && trim((string) ($option['label'] ?? '')) !== '') {
                $options[] = ['id' => (string) $option['id'], 'label' => trim((string) $option['label'])];
            }
        }

        return $options;
    }

    protected function same(string $label, string $answer): bool
    {
        return Str::lower(Str::ascii($label)) === Str::lower(Str::ascii($answer));
    }
}
