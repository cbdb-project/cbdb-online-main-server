<?php

namespace App\Http\Controllers;

use App\Services\EntryQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class SearchByEntryController extends Controller {
    public function __construct(
        private readonly EntryQueryService $entryQueryService
    ) {
    }

    public function index(Request $request): InertiaResponse {
        $filters = $this->validatedFilters($request, false);
        $normalizedFilters = $this->entryQueryService->normalizeFilters($filters);
        $preloadedCodes = $this->entryQueryService->getEntryCodes($normalizedFilters['type_id'])
            ->merge($this->entryQueryService->getEntryCodesByIds($normalizedFilters['entry_codes']))
            ->unique('c_entry_code')
            ->values();

        return Inertia::render('SearchByEntry/Index', [
            'entryTypes' => $this->entryQueryService->getEntryTypes(),
            'dynasties' => $this->entryQueryService->getDynasties(),
            'preloadedCodes' => $preloadedCodes,
            'preloadedPlaces' => $this->entryQueryService->getPlacesByIds($normalizedFilters['place_ids']),
            'initialFilters' => $normalizedFilters,
            'pageUrl' => route('app.search-by.entry.index', [], false),
            'typesEndpoint' => route('app.search-by.entry.types', [], false),
            'codesEndpoint' => route('app.search-by.entry.codes', [], false),
            'placesEndpoint' => route('app.search-by.entry.places', [], false),
            'queryEndpoint' => route('app.search-by.entry.query', [], false),
        ]);
    }

    public function getEntryTypes(): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => $this->entryQueryService->getEntryTypes(),
        ]);
    }

    public function getEntryCodes(Request $request): JsonResponse {
        $validated = $request->validate([
            'type_id' => 'nullable|string|max:255',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->entryQueryService->getEntryCodes($validated['type_id'] ?? null),
        ]);
    }

    public function getPlaces(Request $request): JsonResponse {
        $validated = $request->validate([
            'q' => 'nullable|string|max:100',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->entryQueryService->searchPlaces($validated['q'] ?? null, (int) ($validated['limit'] ?? 20)),
        ]);
    }

    public function query(Request $request): JsonResponse {
        $validated = $this->validatedFilters($request, true);
        $filters = $this->entryQueryService->normalizeFilters($validated);

        if (!$this->entryQueryService->hasConditions($filters)) {
            return response()->json([
                'message' => '請至少設定一項搜尋條件。',
                'errors' => [
                    'filters' => ['請至少設定一項搜尋條件。'],
                ],
            ], 422);
        }

        $result = $this->entryQueryService->search($filters);

        return response()->json([
            'success' => true,
            'data' => [
                'filters' => $result['filters'],
                'records' => $result['records']->toArray(),
                'people' => $result['people']->toArray(),
                'summary' => $result['summary'],
            ],
        ]);
    }

    private function validatedFilters(Request $request, bool $includePagination): array {
        $rules = [
            'person_keyword' => 'nullable|string|max:100',
            'type_id' => 'nullable|string|max:255',
            'entry_codes' => 'nullable|array',
            'entry_codes.*' => 'integer',
            'place_ids' => 'nullable|array',
            'place_ids.*' => 'integer',
            'include_sub_units' => 'nullable|boolean',
            'use_index_year_range' => 'nullable|boolean',
            'index_year_from' => 'nullable|integer',
            'index_year_to' => 'nullable|integer',
            'use_entry_year_range' => 'nullable|boolean',
            'entry_year_from' => 'nullable|integer',
            'entry_year_to' => 'nullable|integer',
            'dynasty_codes' => 'nullable|array',
            'dynasty_codes.*' => 'string|max:255',
        ];

        if ($includePagination) {
            $rules['records_page'] = 'nullable|integer|min:1';
            $rules['people_page'] = 'nullable|integer|min:1';
        }

        return $request->validate($rules);
    }
}
