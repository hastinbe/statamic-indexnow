<?php

namespace ArtOfWifi\StatamicIndexnow\Http\Controllers;

use ArtOfWifi\StatamicIndexnow\IndexNowClient;
use ArtOfWifi\StatamicIndexnow\SubmissionStore;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry;
use Statamic\Http\Controllers\CP\CpController;

class IndexNowUtilityController extends CpController
{
    public const CACHE_KEY = 'statamic-indexnow.entries';
    private const CACHE_TTL = 300;

    // Must match the column definitions in IndexNowUtility.vue so that
    // ui-listing's setColumns() receives the same array and short-circuits.
    private const COLUMNS = [
        ['field' => 'title',          'label' => 'Title',      'sortable' => true],
        ['field' => 'collection',     'label' => 'Collection', 'sortable' => true],
        ['field' => 'status',         'label' => 'Status',     'sortable' => true],
        ['field' => 'updated_at',     'label' => 'Modified',   'sortable' => true],
        ['field' => 'last_submitted', 'label' => 'Submitted',  'sortable' => true],
    ];

    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function index(Request $request): View
    {
        $productionUrl = config('statamic-indexnow.production_url');
        $key = config('statamic-indexnow.key');
        $excludeCollections = config('statamic-indexnow.exclude_collections', []);

        $collections = CollectionFacade::all()
            ->reject(fn ($c) => in_array($c->handle(), $excludeCollections))
            ->map(fn ($c) => $c->handle())
            ->sort()
            ->values()
            ->all();

        return view('statamic-indexnow::utilities.index', [
            'config' => [
                'configured'     => !empty($key),
                'production_url' => $productionUrl,
                'auto_submit'    => config('statamic-indexnow.auto_submit', false),
                'entries_url'    => cp_route('utilities.index-now.entries'),
                'submit_url'     => cp_route('utilities.index-now.submit'),
                'select_url'     => cp_route('utilities.index-now.select'),
                'csrf_token'     => csrf_token(),
                'collections'    => $collections,
            ],
        ]);
    }

    /**
     * Returns a mapped entries Collection. When a collection filter is given:
     * - warm full cache → PHP slice (O(n), instant)
     * - cold cache      → query-level WHERE collection = ? (loads only matching entries)
     * When no filter, the full result is cached for CACHE_TTL seconds.
     */
    private function buildEntriesCollection(?string $collectionFilter = null): Collection
    {
        if ($collectionFilter !== null) {
            $cached = Cache::get(self::CACHE_KEY);

            if ($cached !== null) {
                return collect($cached)->where('collection', $collectionFilter)->values();
            }

            return $this->queryEntries($collectionFilter);
        }

        // Store as a plain PHP array so the cache driver serializes no class instances.
        $data = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->queryEntries()->all());

        return collect($data);
    }

    private function queryEntries(?string $collectionFilter = null): Collection
    {
        $lastSubmissions = app(SubmissionStore::class)->lastSubmittedPerEntry();
        $excludeCollections = config('statamic-indexnow.exclude_collections', []);
        $client = app(IndexNowClient::class);

        $query = Entry::query()->where('published', true);

        if ($collectionFilter !== null) {
            $query->where('collection', $collectionFilter);
        }

        return $query->get()
            ->reject(fn ($entry) => in_array($entry->collectionHandle(), $excludeCollections))
            ->map(function ($entry) use ($client, $lastSubmissions) {
                $lastSubmitted = $lastSubmissions->get($entry->id());
                $lastModified = $entry->lastModified();
                $uri = $entry->uri();

                if ($lastSubmitted === null) {
                    $status = 'never';
                } elseif ($lastModified && $lastModified->isAfter(Carbon::parse($lastSubmitted))) {
                    $status = 'modified';
                } else {
                    $status = 'submitted';
                }

                return [
                    'id'             => $entry->id(),
                    'title'          => $entry->get('title'),
                    'collection'     => $entry->collectionHandle(),
                    'url'            => $uri ? $client->buildProductionUrl($uri) : config('statamic-indexnow.production_url'),
                    'edit_url'       => $entry->editUrl(),
                    'updated_at'     => $lastModified?->format('Y-m-d H:i'),
                    'last_submitted' => $lastSubmitted ? Carbon::parse($lastSubmitted)->format('Y-m-d H:i') : null,
                    'status'         => $status,
                ];
            });
    }

    public function entries(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $rawPerPage = (int) $request->query('perPage', 25);
        $perPage = in_array($rawPerPage, [25, 50, 100]) ? $rawPerPage : 25;

        $validSortColumns = ['title', 'collection', 'status', 'updated_at', 'last_submitted'];
        $sortBy = in_array((string) $request->query('sort'), $validSortColumns)
            ? (string) $request->query('sort')
            : 'updated_at';
        $rawSortDir = strtolower((string) $request->query('order', 'desc'));
        $sortDir = in_array($rawSortDir, ['asc', 'desc']) ? $rawSortDir : 'desc';

        $collectionFilter = $request->query('collection') ?: null;
        $searchFilter = $request->query('search');

        // Collection filtering is handled inside buildEntriesCollection().
        $filtered = $this->buildEntriesCollection($collectionFilter);

        if ($searchFilter) {
            $filtered = $filtered->filter(
                fn ($entry) => str_contains(strtolower((string) $entry['title']), strtolower($searchFilter))
            );
        }

        if ($sortDir === 'desc') {
            $filtered = $filtered->sortByDesc(fn ($entry) => $entry[$sortBy] ?? '');
        } else {
            $filtered = $filtered->sortBy(fn ($entry) => $entry[$sortBy] ?? '');
        }

        $entries = $filtered->values();

        $total = $entries->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $paginatedEntries = $entries->slice(($page - 1) * $perPage, $perPage);

        return response()->json([
            'data' => $paginatedEntries->values(),
            'meta' => [
                'current_page' => $page,
                'last_page'    => $lastPage,
                'per_page'     => $perPage,
                'total'        => $total,
                'from'         => $total > 0 ? ($page - 1) * $perPage + 1 : 0,
                'to'           => $total > 0 ? min($page * $perPage, $total) : 0,
                'columns'      => self::COLUMNS,
            ],
        ]);
    }

    public function select(Request $request): JsonResponse
    {
        $filter = in_array($request->query('filter'), ['never', 'modified'])
            ? $request->query('filter')
            : 'never';

        $matching = $this->buildEntriesCollection()
            ->filter(fn ($entry) => $entry['status'] === $filter);

        return response()->json([
            'entries' => $matching->map(fn ($e) => ['id' => $e['id'], 'url' => $e['url']])->values(),
        ]);
    }

    public function submit(Request $request): JsonResponse
    {
        $request->validate([
            'urls'             => 'required|array|min:1',
            'urls.*.url'       => 'required|url',
            'urls.*.entry_id'  => 'required|string',
        ]);

        $key = config('statamic-indexnow.key');

        if (empty($key)) {
            return response()->json([
                'success' => false,
                'message' => 'IndexNow API key is not configured. Set INDEXNOW_KEY in your .env file.',
            ], 422);
        }

        $urlEntries = $request->input('urls');
        $client = app(IndexNowClient::class);
        $result = $client->submit($urlEntries);

        if ($result['failed'] > 0) {
            return response()->json([
                'success' => false,
                'message' => implode(' | ', $result['errors']),
            ], 422);
        }

        static::clearCache();

        return response()->json([
            'success'   => true,
            'message'   => "{$result['submitted']} URL(s) submitted to IndexNow successfully.",
            'submitted' => $result['submitted'],
        ]);
    }
}
