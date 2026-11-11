<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms;

use Happones\Kinetix\Query\KinetixQuery;
use Happones\Kinetix\Query\QueryBudget;
use Happones\Kinetix\Query\QueryBudgetExceeded;
use Happones\Kinetix\Query\SearchTerm;
use Happones\Kinetix\Support\DescriptorAudience;
use Happones\Kinetix\Support\DescriptorRejection;
use Happones\Kinetix\Support\OptionList;
use Happones\Kinetix\Support\SignedDescriptor;
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
            return response()->json(['message' => __('kinetix.search_invalid_token')], 400);
        }

        $model = is_array($descriptor) ? ($descriptor['model'] ?? null) : null;

        if (! is_string($model) || ! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            return response()->json(['message' => __('kinetix.search_invalid_model')], 400);
        }

        // The descriptor is bound to the user and team it was minted for, and
        // expires ({@see SignedDescriptor}), so it can't be lifted from
        // someone else's payload and replayed.
        $rejection = SignedDescriptor::rejection($descriptor, $request, DescriptorAudience::Search);

        if ($rejection !== null) {
            $expired = $rejection === DescriptorRejection::Expired;

            return response()->json([
                'message' => $expired ? __('kinetix.search_expired') : __('kinetix.search_forbidden'),
                'code'    => $expired ? 'descriptor_expired' : 'descriptor_forbidden',
            ], 403);
        }

        $labelColumn   = (string) ($descriptor['label'] ?? 'name');
        $valueColumn   = (string) ($descriptor['value'] ?? 'id');
        $searchColumns = is_array($descriptor['columns'] ?? null) ? $descriptor['columns'] : [$labelColumn];

        $query = SearchTerm::from($request->input('q', ''));

        if (SearchTerm::isBelowMinimum($query)) {
            return response()->json([
                'options' => [],
                'meta'    => ['below_min' => true, 'min_chars' => SearchTerm::minChars()],
            ]);
        }

        $builder = SearchScope::apply(
            $model::query(),
            is_array($descriptor['scope'] ?? null) ? $descriptor['scope'] : [],
            $descriptor['modifier'] ?? null,
            ($descriptor['team_scope'] ?? true) !== false,
            ($descriptor['team_scope_global'] ?? false) === true,
        );

        try {
            $rows = QueryBudget::run(
                $builder->getModel()->getConnection(),
                QueryBudget::configured(),
                fn () => $builder
                    ->when($query !== '', fn ($b) => KinetixQuery::search($b, $query, $searchColumns))
                    ->limit(20)
                    ->get(),
            );
        } catch (QueryBudgetExceeded) {
            return response()->json(['options' => [], 'meta' => ['timed_out' => true]]);
        }

        $options = [];

        foreach ($rows as $row) {
            $options[(string) data_get($row, $valueColumn)] = (string) data_get($row, $labelColumn);
        }

        return response()->json(['options' => OptionList::forWire($options)]);
    }
}
