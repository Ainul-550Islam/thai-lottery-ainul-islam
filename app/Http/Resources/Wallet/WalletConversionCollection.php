<?php

declare(strict_types=1);

namespace App\Http\Resources\Wallet;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A page of conversions, with the pagination envelope attached.
 *
 * WHY THIS IS A NAMED CLASS AND NOT AN ANONYMOUS COLLECTION
 * The pagination meta is part of the API contract: a client renders "page 2 of
 * 7" from it, and a reporting integration stops paging when it reaches the last
 * page. Building that envelope inline in a controller means it exists once, in
 * the place least likely to be covered by a test; a named collection can be
 * asserted on directly, and both the web and API surfaces can share it.
 *
 * WHAT THE META GUARANTEES
 * `total` and `last_page` are taken from the paginator, never recomputed from
 * the number of rows in the payload — recomputing is how an API ends up
 * claiming there are 20 results when there are 2,000. When the caller passes a
 * page beyond the end, the paginator reports an empty page with an accurate
 * total, which is the honest answer.
 */
class WalletConversionCollection extends ResourceCollection
{
    /**
     * @var class-string
     */
    public $collects = WalletConversionResource::class;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $paginator = $this->resource instanceof LengthAwarePaginator ? $this->resource : null;

        return [
            'conversions' => $this->collection->map(
                static fn ($conversion): array => (new WalletConversionResource($conversion))->toArray($request),
            )->all(),
            'meta' => [
                'current_page' => $paginator?->currentPage() ?? 1,
                'per_page' => $paginator?->perPage() ?? $this->collection->count(),
                'total' => $paginator?->total() ?? $this->collection->count(),
                'last_page' => $paginator?->lastPage() ?? 1,
                'has_more' => $paginator !== null && $paginator->hasMorePages(),
            ],
        ];
    }
}
