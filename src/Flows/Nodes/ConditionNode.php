<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows\Nodes;

use Happones\Kinetix\Flows\FlowStep;
use Happones\Kinetix\Flows\NodeOutcome;
use Illuminate\Support\Str;

/**
 * Branches on the run's variables: `rules` ({variable, operator, value}),
 * all or any of them (`match`), to `yes` or `no`.
 */
class ConditionNode extends BaseNode
{
    public const OPERATORS = ['equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'greater', 'less', 'empty', 'not_empty'];

    public function outputs(array $data): array
    {
        return ['yes', 'no'];
    }

    public function execute(FlowStep $step): NodeOutcome
    {
        $rules   = array_values(array_filter((array) ($step->data['rules'] ?? []), 'is_array'));
        $results = array_map(fn (array $rule): bool => $this->passes($rule, $step), $rules);
        $any     = ($step->data['match'] ?? 'all') === 'any';

        $passed = $results !== [] && ($any ? in_array(true, $results, true) : ! in_array(false, $results, true));

        return NodeOutcome::next($passed ? 'yes' : 'no');
    }

    public function problem(array $data): ?string
    {
        foreach ((array) ($data['rules'] ?? []) as $rule) {
            if (is_array($rule) && trim((string) ($rule['variable'] ?? '')) !== '') {
                return null;
            }
        }

        return __('kinetix::kinetix.flow_problem_rules');
    }

    /**
     * @param array<string, mixed> $rule
     */
    protected function passes(array $rule, FlowStep $step): bool
    {
        $actual   = data_get($step->state->variables, trim((string) ($rule['variable'] ?? '')));
        $expected = $step->render((string) ($rule['value'] ?? ''));
        $text     = Str::lower(is_scalar($actual) ? (string) $actual : (string) json_encode($actual));
        $wanted   = Str::lower($expected);

        return match ($rule['operator'] ?? 'equals') {
            'not_equals'   => $text   !== $wanted,
            'contains'     => $wanted !== '' && str_contains($text, $wanted),
            'not_contains' => $wanted === '' || ! str_contains($text, $wanted),
            'starts_with'  => $wanted !== ''      && str_starts_with($text, $wanted),
            'greater'      => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'less'         => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'empty'        => $actual === null || $text === '' || $actual === [],
            'not_empty'    => ! ($actual === null || $text === '' || $actual === []),
            default        => $text === $wanted,
        };
    }
}
