<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flows;

/**
 * A flow's graph — the shape the editor saves: `nodes` ({id, type, position,
 * data}) and `edges` ({source, sourceHandle, target}). An output is a node
 * plus a handle name (`next`, `yes`, an option's id); following it gives
 * the node it's wired to.
 */
final class FlowGraph
{
    /** @var array<string, array{id: string, type: string, data: array<string, mixed>}> */
    private array $nodes = [];

    /** @var array<string, string> "source|handle" => target */
    private array $edges = [];

    /**
     * @param array{nodes?: mixed, edges?: mixed} $graph
     */
    public function __construct(array $graph)
    {
        foreach ((array) ($graph['nodes'] ?? []) as $node) {
            if (is_array($node) && is_string($node['id'] ?? null) && is_string($node['type'] ?? null)) {
                $this->nodes[$node['id']] = [
                    'id'   => $node['id'],
                    'type' => $node['type'],
                    'data' => is_array($node['data'] ?? null) ? $node['data'] : [],
                ];
            }
        }

        foreach ((array) ($graph['edges'] ?? []) as $edge) {
            if (is_array($edge) && is_string($edge['source'] ?? null) && is_string($edge['target'] ?? null)) {
                $handle                                   = is_string($edge['sourceHandle'] ?? null) && $edge['sourceHandle'] !== '' ? $edge['sourceHandle'] : 'next';
                $this->edges[$edge['source'].'|'.$handle] = $edge['target'];
            }
        }
    }

    /** @return array<string, array{id: string, type: string, data: array<string, mixed>}> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /** @return array{id: string, type: string, data: array<string, mixed>}|null */
    public function node(?string $id): ?array
    {
        return $id === null ? null : ($this->nodes[$id] ?? null);
    }

    /** @return array{id: string, type: string, data: array<string, mixed>}|null */
    public function start(): ?array
    {
        foreach ($this->nodes as $node) {
            if ($node['type'] === 'start') {
                return $node;
            }
        }

        return null;
    }

    public function next(string $nodeId, string $handle = 'next'): ?string
    {
        $target = $this->edges[$nodeId.'|'.$handle] ?? null;

        return $target !== null && isset($this->nodes[$target]) ? $target : null;
    }

    public function hasEdge(string $nodeId, string $handle): bool
    {
        return isset($this->edges[$nodeId.'|'.$handle]);
    }
}
