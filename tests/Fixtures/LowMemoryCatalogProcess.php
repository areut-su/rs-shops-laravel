<?php

declare(strict_types=1);

use App\Storefront\StorefrontCatalog;
use App\Storefront\StorefrontUpstream;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;

$applicationRoot = dirname(__DIR__, 2);
$cacheRoot = getenv('STOREFRONT_TEST_CACHE_DIR');
if (! is_string($cacheRoot) || $cacheRoot === '') {
    fwrite(STDERR, "Missing test cache directory.\n");
    exit(2);
}

require $applicationRoot.'/vendor/autoload.php';
$application = require $applicationRoot.'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

$productTotal = 7446;
$lastPage = 75;
$path = 'https://core.example.test/api/v1/satellite/storefront/catalog';
$upstream = new class($productTotal, $lastPage, $path) implements StorefrontUpstream {
    public int $catalogCalls = 0;

    public function __construct(private readonly int $productTotal, private readonly int $lastPage, private readonly string $path)
    {
    }

    /** @return array{revision: string} */
    public function catalogMetadata(): array
    {
        return ['revision' => str_repeat('a', 64)];
    }

    /** @return array<string, mixed> */
    public function catalogPage(int $page, int $perPage): array
    {
        $this->catalogCalls++;
        $start = (($page - 1) * $perPage) + 1;
        $end = min($page * $perPage, $this->productTotal);
        $products = [];
        for ($productId = $start; $productId <= $end; $productId++) {
            $variants = [];
            $variantCount = $productId <= 462 ? 6 : 5;
            for ($variantNumber = 1; $variantNumber <= $variantCount; $variantNumber++) {
                $variants[] = [
                    'public_id' => sprintf('00000000-0000-4000-8000-%012d', ($productId * 10) + $variantNumber),
                    'code' => 'variant-'.$variantNumber,
                    'period_days' => 30,
                    'traffic_mb' => null,
                    'quantity_unit' => 1,
                    'is_default' => $variantNumber === 1,
                    'prices' => [['currency' => 'USD', 'price' => '0007.30', 'min_quantity' => null]],
                ];
            }
            $products[] = [
                'public_id' => sprintf('00000000-0000-4000-8000-%012d', $productId),
                'slug' => 'product-'.$productId,
                'category' => ['slug' => 'proxy', 'name' => 'Proxy'],
                'translations' => [['locale' => 'en', 'title' => 'Product '.$productId, 'description' => null]],
                'proxy_profile' => [
                    'ip_type' => 1,
                    'proxy_type' => 1,
                    'proxy_access_type' => 'private',
                    'country_code' => null,
                    'min_quantity' => 1,
                    'multiple_quantity' => 1,
                ],
                'variants' => $variants,
            ];
        }

        $url = fn (int $number): string => $this->path.'?page='.$number.'&per_page=100';
        $from = $start;
        $to = $end;

        return [
            'data' => $products,
            'links' => [
                ['url' => $page > 1 ? $url($page - 1) : null, 'label' => '&laquo; Previous', 'page' => null, 'active' => false],
                ['url' => $url($page), 'label' => (string) $page, 'page' => $page, 'active' => true],
                ['url' => $page < $this->lastPage ? $url($page + 1) : null, 'label' => 'Next &raquo;', 'page' => null, 'active' => false],
            ],
            'meta' => [
                'current_page' => $page,
                'from' => $from,
                'last_page' => $this->lastPage,
                'path' => $this->path,
                'per_page' => 100,
                'to' => $to,
                'total' => $this->productTotal,
                'first_page_url' => $url(1),
                'last_page_url' => $url($this->lastPage),
                'next_page_url' => $page < $this->lastPage ? $url($page + 1) : null,
                'prev_page_url' => $page > 1 ? $url($page - 1) : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function translations(string $locale): array
    {
        return [];
    }
};

config([
    'storefront.api_url' => 'https://core.example.test',
    'storefront.token' => 'isolated-process-test-token',
    'storefront.catalog_cache_path' => $cacheRoot.'/catalog',
    'cache.default' => 'file',
    'cache.stores.file.path' => $cacheRoot.'/cache-data',
    'cache.stores.file.lock_path' => $cacheRoot.'/cache-locks',
]);
$application->instance(StorefrontUpstream::class, $upstream);

try {
    $catalog = $application->make(StorefrontCatalog::class);
    $catalogStream = $catalog->openProductsStream();
    $stream = $catalogStream['stream'];
    $sink = fopen('/dev/null', 'wb');
    if (! is_resource($sink)) {
        throw new RuntimeException('Could not open streaming sink.');
    }
    $bytes = stream_copy_to_stream($stream, $sink);
    fclose($stream);
    fclose($sink);

    if (! is_int($bytes) || $upstream->catalogCalls !== $lastPage) {
        throw new RuntimeException('The catalog stream was incomplete.');
    }
    echo json_encode([
        'bytes' => $bytes,
        'catalog_calls' => $upstream->catalogCalls,
        'peak_memory' => memory_get_peak_usage(true),
        'products' => $productTotal,
        'variants' => 37692,
    ], JSON_THROW_ON_ERROR);
} finally {
    File::deleteDirectory($cacheRoot);
}
