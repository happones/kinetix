<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * Resolves remote options for a searchable Select. The request carries the
 * field's encrypted descriptor (model + columns), so only the model/columns the
 * field declared can ever be queried — the query string is never trusted to name
 * a table or column. Mirrors the table cell-update token guard.
 */
class SearchController
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $descriptor = Crypt::decrypt((string) $request->input('token'));
        } catch (\Throwable) {
            return response()->json(['message' => 'Invalid search token.'], 400);
        }

        $model = is_array($descriptor) ? ($descriptor['model'] ?? null) : null;

        if (! is_string($model) || ! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            return response()->json(['message' => 'Invalid search model.'], 400);
        }

        $labelColumn   = (string) ($descriptor['label'] ?? 'name');
        $valueColumn   = (string) ($descriptor['value'] ?? 'id');
        $searchColumns = is_array($descriptor['columns'] ?? null) ? $descriptor['columns'] : [$labelColumn];

        $query = (string) $request->input('q', '');

        $rows = $model::query()
            ->when($query !== '', function ($builder) use ($searchColumns, $query): void {
                $builder->where(function ($where) use ($searchColumns, $query): void {
                    foreach ($searchColumns as $column) {
                        $where->orWhere($column, 'like', "%{$query}%");
                    }
                });
            })
            ->limit(20)
            ->get();

        $options = [];

        foreach ($rows as $row) {
            $options[(string) data_get($row, $valueColumn)] = (string) data_get($row, $labelColumn);
        }

        return response()->json(['options' => $options]);
    }
}
