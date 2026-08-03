<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms;

use Happones\Kinetix\Query\KinetixQuery;
use Happones\Kinetix\Support\ConfigCallback;
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

        $builder = $model::query();

        // A searchable `relationship()` can carry a query modifier, but only as
        // an invokable class-string: the descriptor round-trips through the
        // browser, so it must be serializable, and it is resolved back to an
        // object here rather than trusted as arbitrary input.
        $modifier = ConfigCallback::resolve($descriptor['modifier'] ?? null);

        if ($modifier !== null) {
            $modifier($builder);
        }

        $rows = $builder
            ->when($query !== '', fn ($b) => KinetixQuery::search($b, $query, $searchColumns))
            ->limit(20)
            ->get();

        $options = [];

        foreach ($rows as $row) {
            $options[(string) data_get($row, $valueColumn)] = (string) data_get($row, $labelColumn);
        }

        return response()->json(['options' => $options]);
    }
}
