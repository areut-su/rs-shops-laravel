<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Storefront\StorefrontUpstream;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakeStorefrontUpstream;
use Tests\TestCase;

final class StorefrontApiTest extends TestCase
{
    private FakeStorefrontUpstream $upstream;

    private string $catalogCacheDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogCacheDirectory = sys_get_temp_dir().'/rs-storefront-catalog-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->catalogCacheDirectory);
        config(['storefront.catalog_cache_path' => $this->catalogCacheDirectory]);
        $this->upstream = new FakeStorefrontUpstream();
        $this->app->instance(StorefrontUpstream::class, $this->upstream);
        config(['storefront.api_url' => 'https://core.example.test', 'storefront.token' => 'private-token']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->catalogCacheDirectory);
        parent::tearDown();
    }

    public function test_catalog_aggregates_pages_keeps_raw_decimal_strings_and_returns_revision_etag(): void
    {
        $products = [];
        for ($id = 1; $id <= 101; $id++) {
            $products[] = $this->product($id, $id === 1 ? '0007.30' : '0.09');
        }
        $this->upstream->catalogPages = [
            1 => $this->page(1, 101, array_slice($products, 0, 100)),
            2 => $this->page(2, 101, array_slice($products, 100)),
        ];
        $response = $this->get('/api/storefront/v1/catalog')->assertOk();
        $payload = $this->streamedJson($response);
        self::assertSame('0007.30', $payload['data'][0]['variants'][0]['prices'][0]['price']);
        self::assertSame('0.09', $payload['data'][100]['variants'][0]['prices'][0]['price']);
        self::assertSame(['ipv4'], $payload['data'][0]['groups']);
        self::assertNull($payload['data'][0]['category']['parent_slug']);
        self::assertSame('"'.str_repeat('a', 64).'"', $response->headers->get('ETag'));
        self::assertSame([['page' => 1, 'perPage' => 100], ['page' => 2, 'perPage' => 100]], $this->upstream->catalogCalls);
    }

    public function test_catalog_is_loaded_once_even_after_metadata_cache_refresh(): void
    {
        $this->upstream->catalogPages = [1 => $this->page(1, 0, [])];
        $response = $this->get('/api/storefront/v1/catalog')->assertOk();
        self::assertSame(['data' => []], $this->streamedJson($response));
        $this->streamedJson($this->get('/api/storefront/v1/catalog')->assertOk());
        self::assertCount(1, $this->upstream->catalogCalls);
        self::assertCount(2, $this->upstream->metadataCalls);

        $this->travel(61)->seconds();
        $this->streamedJson($this->get('/api/storefront/v1/catalog')->assertOk());
        self::assertCount(1, $this->upstream->catalogCalls);
        self::assertCount(2, $this->upstream->metadataCalls);

        $this->travel(3600)->seconds();
        $this->streamedJson($this->get('/api/storefront/v1/catalog')->assertOk());
        self::assertCount(1, $this->upstream->catalogCalls);
        self::assertCount(3, $this->upstream->metadataCalls);
    }

    public function test_metadata_is_cached_for_one_hour_and_unavailable_metadata_returns_503(): void
    {
        $cacheDirectory = sys_get_temp_dir().'/rs-storefront-cache-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($cacheDirectory);
        config([
            'cache.default' => 'file',
            'cache.stores.file.path' => $cacheDirectory.'/data',
            'cache.stores.file.lock_path' => $cacheDirectory.'/locks',
        ]);
        Cache::purge('file');
        $this->upstream->catalogPages = [1 => $this->page(1, 1, [$this->product(1, '10.00')])];

        try {
            $this->getJson('/api/storefront/v1/catalog/meta')->assertOk()->assertExactJson(['revision' => str_repeat('a', 64)]);
            $this->getJson('/api/storefront/v1/catalog/meta')->assertOk();
            self::assertCount(1, $this->upstream->metadataCalls);

            $this->travel(3601)->seconds();
            $this->getJson('/api/storefront/v1/catalog/meta')->assertOk();
            self::assertCount(2, $this->upstream->metadataCalls);

            $this->travel(3601)->seconds();
            $this->upstream->metadataUnavailable = true;
            $this->getJson('/api/storefront/v1/catalog/meta')->assertStatus(503)
                ->assertExactJson(['message' => 'Storefront data is temporarily unavailable.']);
        } finally {
            Cache::purge('file');
            File::deleteDirectory($cacheDirectory);
        }
    }

    public function test_partial_catalog_returns_generic_503_without_partial_data(): void
    {
        $this->upstream->catalogPages = [1 => $this->page(1, 101, [$this->product(1, '12.30')])];
        $this->getJson('/api/storefront/v1/catalog')->assertStatus(503)
            ->assertExactJson(['message' => 'Storefront data is temporarily unavailable.']);
    }

    public function test_later_page_failure_does_not_publish_partial_catalog(): void
    {
        $firstPageProducts = [];
        for ($id = 1; $id <= 100; $id++) {
            $firstPageProducts[] = $this->product($id, '10.00');
        }
        $this->upstream->catalogPages = [1 => $this->page(1, 101, $firstPageProducts)];

        $this->getJson('/api/storefront/v1/catalog')->assertStatus(503)
            ->assertExactJson(['message' => 'Storefront data is temporarily unavailable.']);
        self::assertSame([], glob($this->catalogCacheDirectory.'/catalog-*.json') ?: []);
        self::assertSame([], glob($this->catalogCacheDirectory.'/catalog-*.tmp-*') ?: []);

        $this->upstream->catalogPages[2] = $this->page(2, 101, [$this->product(101, '10.00')]);
        $response = $this->get('/api/storefront/v1/catalog')->assertOk();
        self::assertCount(101, $this->streamedJson($response)['data']);
        self::assertSame([1, 2, 1, 2], array_column($this->upstream->catalogCalls, 'page'));
    }

    public function test_revision_change_rebuilds_and_never_serves_old_catalog_after_refresh_failure(): void
    {
        $this->upstream->catalogPages = [1 => $this->page(1, 1, [$this->product(1, '10.00')])];
        $firstResponse = $this->get('/api/storefront/v1/catalog')->assertOk();
        $this->streamedJson($firstResponse);
        self::assertSame('"'.str_repeat('a', 64).'"', $firstResponse->headers->get('ETag'));

        $this->travel(3601)->seconds();
        $this->upstream->metadataPayload = ['revision' => str_repeat('b', 64)];
        $this->upstream->catalogPages = [];
        $this->getJson('/api/storefront/v1/catalog')->assertStatus(503)->assertDontSee('10.00');
        self::assertSame([], glob($this->catalogCacheDirectory.'/catalog-*.json') ?: []);

        $this->upstream->catalogPages = [1 => $this->page(1, 1, [$this->product(1, '12.34')])];
        $response = $this->get('/api/storefront/v1/catalog')->assertOk();
        self::assertSame('12.34', $this->streamedJson($response)['data'][0]['variants'][0]['prices'][0]['price']);
        self::assertSame('"'.str_repeat('b', 64).'"', $response->headers->get('ETag'));
        self::assertCount(3, $this->upstream->catalogCalls);
    }

    public function test_revision_change_during_download_discards_spool_and_invalidates_metadata_cache(): void
    {
        $products = [];
        for ($id = 1; $id <= 101; $id++) {
            $products[] = $this->product($id, '10.00');
        }
        $this->upstream->catalogPages = [
            1 => $this->page(1, 101, array_slice($products, 0, 100)),
            2 => $this->page(2, 101, array_slice($products, 100)),
        ];
        $this->upstream->changeRevisionAfterCatalogCall = 1;
        $this->upstream->nextCatalogRevision = str_repeat('b', 64);

        $this->getJson('/api/storefront/v1/catalog')->assertStatus(503);
        self::assertSame([], glob($this->catalogCacheDirectory.'/catalog-*.json') ?: []);
        self::assertSame([], glob($this->catalogCacheDirectory.'/catalog-*.tmp-*') ?: []);

        $response = $this->get('/api/storefront/v1/catalog')->assertOk();
        self::assertCount(101, $this->streamedJson($response)['data']);
        self::assertSame('"'.str_repeat('b', 64).'"', $response->headers->get('ETag'));
        self::assertSame([1, 2, 1, 2], array_column($this->upstream->catalogCalls, 'page'));
        self::assertCount(4, $this->upstream->metadataCalls);
    }

    public function test_catalog_request_cleans_an_abandoned_private_spool(): void
    {
        $scope = hash('sha256', 'https://core.example.test|private-token');
        $abandonedDirectory = $this->catalogCacheDirectory.'/catalog-'.$scope.'.tmp-abandoned';
        File::ensureDirectoryExists($abandonedDirectory);
        file_put_contents($abandonedDirectory.'/payload', 'incomplete');
        $obsoleteCache = $this->catalogCacheDirectory.'/catalog-'.$scope.'-'.str_repeat('b', 64).'.json';
        file_put_contents($obsoleteCache, '{"data":[]}');
        $this->upstream->catalogPages = [1 => $this->page(1, 0, [])];

        $response = $this->get('/api/storefront/v1/catalog')->assertOk();

        self::assertSame(['data' => []], $this->streamedJson($response));
        self::assertFalse(File::exists($abandonedDirectory));
        self::assertFalse(File::exists($obsoleteCache));
    }

    public function test_catalog_stream_stays_within_128_megabytes_for_realistic_catalog_size(): void
    {
        $cacheDirectory = sys_get_temp_dir().'/rs-storefront-low-memory-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($cacheDirectory);
        $process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=128M', __DIR__.'/../Fixtures/LowMemoryCatalogProcess.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            ['STOREFRONT_TEST_CACHE_DIR' => $cacheDirectory],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        try {
            self::assertSame(0, $exitCode, $stderr);
            self::assertIsString($stdout);
            $metrics = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($metrics);
            self::assertSame(75, $metrics['catalog_calls'] ?? null);
            self::assertSame(7446, $metrics['products'] ?? null);
            self::assertSame(37692, $metrics['variants'] ?? null);
            self::assertGreaterThan(1_000_000, $metrics['bytes'] ?? 0);
            self::assertLessThan(128 * 1024 * 1024, $metrics['peak_memory'] ?? PHP_INT_MAX);
        } finally {
            File::deleteDirectory($cacheDirectory);
        }
    }

    public function test_malformed_last_page_returns_503(): void
    {
        $rows = [];
        for ($id = 1; $id <= 100; $id++) {
            $rows[] = $this->product($id, '10.00');
        }
        $this->upstream->catalogPages = [1 => $this->page(1, 101, $rows, reportedLastPage: 1)];

        $this->getJson('/api/storefront/v1/catalog')->assertStatus(503);
    }

    public function test_short_first_page_returns_503(): void
    {
        $this->upstream->catalogPages = [1 => $this->page(1, 101, [$this->product(1, '10.00')])];

        $this->getJson('/api/storefront/v1/catalog')->assertStatus(503);
    }

    public function test_malformed_price_returns_503_and_is_not_cached(): void
    {
        $this->upstream->catalogPages = [1 => $this->page(1, 1, [$this->product(1, '10.0')])];
        $this->getJson('/api/storefront/v1/catalog')->assertStatus(503);
        $this->upstream->catalogPages[1] = $this->page(1, 1, [$this->product(1, '10.00')]);
        $this->streamedJson($this->get('/api/storefront/v1/catalog')->assertOk());
        self::assertCount(2, $this->upstream->catalogCalls);
    }

    public function test_missing_groups_returns_503_and_is_not_cached(): void
    {
        $product = $this->product(1, '10.00');
        unset($product['groups']);
        $this->upstream->catalogPages = [1 => $this->page(1, 1, [$product])];
        $this->getJson('/api/storefront/v1/catalog')->assertStatus(503);
        $this->upstream->catalogPages[1] = $this->page(1, 1, [$this->product(1, '10.00')]);
        $this->streamedJson($this->get('/api/storefront/v1/catalog')->assertOk());
        self::assertCount(2, $this->upstream->catalogCalls);
    }

    public function test_malformed_group_codes_return_503(): void
    {
        foreach ([['IPv4'], ['1pv4'], ['a'], [str_repeat('a', 40)], ['ipv4', 'ipv4'], ['ipv4' => 'ipv4']] as $groups) {
            $product = $this->product(1, '10.00');
            $product['groups'] = $groups;
            $this->upstream->catalogPages = [1 => $this->page(1, 1, [$product])];
            $this->getJson('/api/storefront/v1/catalog')->assertStatus(503);
        }
    }

    public function test_missing_category_parent_slug_returns_503(): void
    {
        $product = $this->product(1, '10.00');
        unset($product['category']['parent_slug']);
        $this->upstream->catalogPages = [1 => $this->page(1, 1, [$product])];
        $this->getJson('/api/storefront/v1/catalog')->assertStatus(503);

        $product['category']['parent_slug'] = '';
        $this->upstream->catalogPages[1] = $this->page(1, 1, [$product]);
        $this->getJson('/api/storefront/v1/catalog')->assertStatus(503);
    }

    public function test_missing_category_parent_name_returns_503_and_null_is_allowed(): void
    {
        $product = $this->product(1, '10.00');
        unset($product['category']['parent_name']);
        $this->upstream->catalogPages = [1 => $this->page(1, 1, [$product])];
        $this->getJson('/api/storefront/v1/catalog')->assertStatus(503);

        $product['category']['parent_name'] = '';
        $this->upstream->catalogPages[1] = $this->page(1, 1, [$product]);
        $this->getJson('/api/storefront/v1/catalog')->assertStatus(503);

        $product['category']['parent_name'] = null;
        $this->upstream->catalogPages[1] = $this->page(1, 1, [$product]);
        $payload = $this->streamedJson($this->get('/api/storefront/v1/catalog')->assertOk());
        self::assertNull($payload['data'][0]['category']['parent_name']);
    }

    public function test_catalog_cache_is_scoped_to_upstream_credentials(): void
    {
        $this->upstream->catalogPages = [1 => $this->page(1, 1, [$this->product(1, '10.00')])];
        $this->streamedJson($this->get('/api/storefront/v1/catalog')->assertOk());
        config(['storefront.token' => 'different-private-token']);
        $this->streamedJson($this->get('/api/storefront/v1/catalog')->assertOk());
        self::assertCount(2, $this->upstream->catalogCalls);
        self::assertCount(4, $this->upstream->metadataCalls);
    }

    public function test_price_string_with_leading_zeroes_is_preserved_exactly(): void
    {
        $this->upstream->catalogPages = [1 => $this->page(1, 1, [$this->product(1, '0007.30')])];
        $response = $this->get('/api/storefront/v1/catalog')->assertOk();
        $payload = $this->streamedJson($response);
        self::assertSame('0007.30', $payload['data'][0]['variants'][0]['prices'][0]['price']);
    }

    public function test_empty_translation_map_is_serialized_as_a_json_object(): void
    {
        $this->upstream->translationPayloads = ['ru' => array_replace($this->translation('ru'), ['messages' => []])];
        $response = $this->getJson('/api/storefront/v1/translations')->assertOk();
        self::assertStringContainsString('"messages":{}', (string) $response->getContent());
    }

    public function test_query_allowlists_reject_browser_upstream_overrides(): void
    {
        $this->getJson('/api/storefront/v1/catalog?api_url=https://evil.test&reseller_id=1')->assertStatus(422);
        $this->getJson('/api/storefront/v1/catalog/meta?reseller_id=1')->assertStatus(422);
        $this->getJson('/api/storefront/v1/translations?locale=ru&token=attacker')->assertStatus(422);
        self::assertSame([], $this->upstream->catalogCalls);
        self::assertSame([], $this->upstream->metadataCalls);
        self::assertSame([], $this->upstream->translationCalls);
    }

    public function test_invalid_catalog_revision_returns_503_and_is_not_cached(): void
    {
        $this->upstream->metadataPayload = ['revision' => str_repeat('A', 64)];
        $this->getJson('/api/storefront/v1/catalog/meta')->assertStatus(503);

        $this->upstream->metadataPayload = ['revision' => str_repeat('c', 64)];
        $this->getJson('/api/storefront/v1/catalog/meta')->assertOk()
            ->assertExactJson(['revision' => str_repeat('c', 64)]);
        self::assertCount(2, $this->upstream->metadataCalls);
    }

    public function test_translation_locale_cache_partition_and_default_locale(): void
    {
        $this->upstream->translationPayloads = ['ru' => $this->translation('ru'), 'en' => $this->translation('en')];
        $this->getJson('/api/storefront/v1/translations')->assertOk()->assertJsonPath('locale', 'ru');
        $this->getJson('/api/storefront/v1/translations?locale=en')->assertOk()->assertJsonPath('locale', 'en');
        $this->getJson('/api/storefront/v1/translations?locale=en')->assertOk();
        self::assertSame(['ru', 'en'], $this->upstream->translationCalls);
    }

    public function test_secret_is_not_in_html(): void
    {
        $this->get('/')->assertOk()->assertDontSee('private-token')->assertSee('/api/storefront/v1', false);
    }

    public function test_storefront_page_renders_widget_variant_theme_and_theme_stylesheet(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('<link rel="stylesheet" href="/assets/storefront-theme.css">', false)
            ->assertSee('<ps-price-widget api-base="/api/storefront/v1" locale="ru" variant="main" theme="light"></ps-price-widget>', false)
            ->assertSee('src="/assets/rs-storefront-widget.js"', false);

        $html = $response->getContent();
        self::assertIsString($html);
        self::assertSame(1, substr_count($html, '<ps-price-widget '));
        self::assertStringNotContainsString('&quot;main&quot;', $html);
        self::assertStringNotContainsString('&amp;', $html);
    }

    public function test_health_route_does_not_need_core_credentials_and_missing_credentials_fail_cleanly(): void
    {
        config(['storefront.token' => '']);
        $this->get('/healthz')->assertOk();
        $this->getJson('/api/storefront/v1/catalog')->assertStatus(503)
            ->assertExactJson(['message' => 'Storefront data is temporarily unavailable.']);
        self::assertSame([], $this->upstream->catalogCalls);
    }

    /** @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     *  @return array<string, mixed>
     */
    private function streamedJson(TestResponse $response): array
    {
        $payload = json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    /**
     * @param list<array<string, mixed>> $data
     * @return array{
     *   data: list<array<string, mixed>>,
     *   links: list<array{url: string|null, label: string, page: int|null, active: bool}>,
     *   meta: array{current_page: int, from: int|null, last_page: int, path: string, per_page: int, to: int|null, total: int, first_page_url: string, last_page_url: string, next_page_url: string|null, prev_page_url: string|null}
     * }
     */
    private function page(int $current, int $total, array $data, ?int $reportedLastPage = null): array
    {
        $lastPage = $reportedLastPage ?? max(1, intdiv($total, 100) + ($total % 100 === 0 ? 0 : 1));
        $path = 'https://core.example.test/api/v1/satellite/storefront/catalog';
        $url = static fn (int $page): string => $path.'?page='.$page;
        $links = [[
            'url' => $current > 1 ? $url($current - 1) : null,
            'label' => '&laquo; Previous',
            'page' => null,
            'active' => false,
        ]];
        for ($page = 1; $page <= $lastPage; $page++) {
            $links[] = ['url' => $url($page), 'label' => (string) $page, 'page' => $page, 'active' => $page === $current];
        }
        $links[] = [
            'url' => $current < $lastPage ? $url($current + 1) : null,
            'label' => 'Next &raquo;',
            'page' => null,
            'active' => false,
        ];
        return [
            'data' => $data,
            'links' => $links,
            'meta' => [
                'current_page' => $current,
                'from' => $total === 0 ? null : (($current - 1) * 100 + 1),
                'last_page' => $lastPage,
                'path' => $path,
                'per_page' => 100,
                'to' => $total === 0 ? null : min($current * 100, $total),
                'total' => $total,
                'first_page_url' => $url(1),
                'last_page_url' => $url($lastPage),
                'next_page_url' => $current < $lastPage ? $url($current + 1) : null,
                'prev_page_url' => $current > 1 ? $url($current - 1) : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function product(int $id, string $price): array
    {
        return [
            'public_id' => sprintf('00000000-0000-4000-8000-%012d', $id),
            'slug' => 'product-'.$id,
            'category' => ['slug' => 'proxy', 'name' => 'Proxy', 'parent_slug' => null, 'parent_name' => null],
            'translations' => [['locale' => 'en', 'title' => 'Product', 'description' => null]],
            'proxy_profile' => ['ip_type' => 1, 'proxy_type' => 1, 'proxy_access_type' => 'private', 'country_code' => null, 'min_quantity' => 1, 'multiple_quantity' => 1],
            'variants' => [[
                'public_id' => null, 'code' => null, 'period_days' => 30, 'traffic_mb' => null,
                'quantity_unit' => 1, 'is_default' => true,
                'prices' => [['currency' => 'USD', 'price' => $price, 'min_quantity' => null]],
            ]],
            'groups' => ['ipv4'],
        ];
    }

    /** @return array<string, mixed> */
    private function translation(string $locale): array
    {
        return ['locale' => $locale, 'default_locale' => 'ru', 'fallback_locale' => 'en',
            'available_locales' => [['code' => 'ru', 'name' => 'Русский'], ['code' => 'en', 'name' => 'English']],
            'messages' => ['catalog.empty' => 'Empty']];
    }
}
