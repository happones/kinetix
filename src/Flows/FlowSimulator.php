<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows;

/**
 * The editor's test chat: runs a graph (the unsaved draft, as edited) without
 * a conversation — nothing is sent, waits pass at once — and returns what
 * the flow would say, where it stands, and the state to send back with the
 * next reply.
 */
class FlowSimulator
{
    public function __construct(protected FlowInterpreter $interpreter) {}

    /**
     * @param  array<string, mixed>                                                                                                                                               $graph
     * @param  array<string, mixed>|null                                                                                                                                          $state null to start over
     * @return array{entries: list<array{kind: string, text: string, options: list<string>}>, state: array<string, mixed>, node: string|null, status: string, path: list<string>}
     */
    public function run(array $graph, ?array $state, ?string $input, string $contactName = ''): array
    {
        $flowState = $state === null
            ? new FlowState(variables: [
                'contact' => ['name' => $contactName !== '' ? $contactName : __('kinetix::kinetix.flow_sim_contact'), 'key' => '5215555555555'],
                'line'    => ['name' => __('kinetix::kinetix.flow_sim_line'), 'provider' => 'whatsapp'],
                'message' => $input,
            ])
            : FlowState::fromArray($state);

        if ($state !== null && $input !== null) {
            $flowState->variables['message'] = $input;
        }

        $outbox = new RecordingOutbox;
        $path   = [];

        $this->interpreter->advance(new FlowGraph($graph), $flowState, $outbox, $state === null ? null : $input, simulation: true, entered: static function (string $node) use (&$path): void {
            $path[] = $node;
        });

        if ($flowState->status === FlowRunStatus::Failed && $flowState->error !== null) {
            $outbox->system($flowState->error);
        } elseif (! $flowState->status->isActive()) {
            $outbox->system(__('kinetix::kinetix.flow_sim_finished_'.$flowState->status->value));
        }

        return [
            'entries' => $outbox->entries,
            'state'   => $flowState->toArray(),
            'node'    => $flowState->node,
            'status'  => $flowState->status->value,
            'path'    => $path,
        ];
    }
}
