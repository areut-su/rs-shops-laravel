<?php

declare(strict_types=1);

namespace App\Storefront;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Throwable;

final class StorefrontCatalog
{
    private const PER_PAGE = 100;
    private const METADATA_CACHE_SECONDS = 3600;
    private const CACHE_LOCK_SECONDS = 120;

    public function __construct(private readonly StorefrontUpstream $upstream)
    {
    }

    /** @return array{stream: resource, revision: string} */
    public function openProductsStream(): array
    {
        $this->assertUpstreamConfigured();
        $metadata = $this->catalogMetadata();
        $revision = $metadata['revision'];
        $cacheKey = $this->cacheKey('catalog', '');
        $scope = hash('sha256', (string) config('storefront.api_url').'|'.(string) config('storefront.token'));
        $directory = rtrim((string) config('storefront.catalog_cache_path'), DIRECTORY_SEPARATOR);
        $cachePath = $directory.DIRECTORY_SEPARATOR.'catalog-'.$scope.'-'.$revision.'.json';
        $stream = null;

        $this->withCacheLock($cacheKey, function () use ($scope, $revision, $directory, $cachePath, &$stream): void {
            $this->ensureCatalogDirectory($directory);
            $this->cleanupTemporaryCatalogs($directory, $scope);
            $this->cleanupObsoleteCatalogs($directory, $scope, $revision);
            $stream = $this->openCachedCatalog($cachePath);
            if (is_resource($stream)) {
                return;
            }

            $this->buildCatalogCache($directory, $cachePath, $scope, $revision);
            $stream = $this->openCachedCatalog($cachePath);
        });

        if (! is_resource($stream)) {
            throw new StorefrontUnavailable('Storefront data is temporarily unavailable.');
        }

        return ['stream' => $stream, 'revision' => $revision];
    }

    /** @return array{revision: string} */
    public function catalogMetadata(): array
    {
        $this->assertUpstreamConfigured();
        $cacheKey = $this->cacheKey('metadata', '');
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && $this->isValidRevision($cached['revision'] ?? null)) {
            return ['revision' => $cached['revision']];
        }

        $metadata = [];
        $this->withCacheLock($cacheKey, function () use ($cacheKey, &$metadata): void {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && $this->isValidRevision($cached['revision'] ?? null)) {
                $metadata = ['revision' => $cached['revision']];
                return;
            }

            $metadata = $this->fetchCatalogMetadata();
            Cache::put($cacheKey, $metadata, now()->addSeconds(self::METADATA_CACHE_SECONDS));
        });

        if (! $this->isValidRevision($metadata['revision'] ?? null)) {
            throw new StorefrontUnavailable('Storefront data is temporarily unavailable.');
        }

        return ['revision' => $metadata['revision']];
    }

    /** @return array<string, mixed> */
    public function translations(string $locale): array
    {
        $this->assertUpstreamConfigured();
        $cacheKey = $this->cacheKey('translations', $locale);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && ! array_is_list($cached)) {
            return $cached;
        }

        $payload = [];
        $this->withCacheLock($cacheKey, function () use ($cacheKey, $locale, &$payload): void {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && ! array_is_list($cached)) {
                $payload = $cached;
                return;
            }

            try {
                $payload = $this->upstream->translations($locale);
                $this->validateTranslations($payload, $locale);
            } catch (Throwable $exception) {
                throw new StorefrontUnavailable('Storefront data is temporarily unavailable.', previous: $exception);
            }

            Cache::put($cacheKey, $payload, now()->addSeconds(60));
        });

        return $payload;
    }

    /** @return array{revision: string} */
    private function fetchCatalogMetadata(): array
    {
        try {
            $metadata = $this->upstream->catalogMetadata();
        } catch (Throwable $exception) {
            throw new StorefrontUnavailable('Storefront data is temporarily unavailable.', previous: $exception);
        }

        if (! $this->hasExactKeys($metadata, ['revision']) || ! $this->isValidRevision($metadata['revision'] ?? null)) {
            throw new StorefrontUnavailable('The upstream returned invalid catalog metadata.');
        }

        return ['revision' => $metadata['revision']];
    }

    private function isValidRevision(mixed $revision): bool
    {
        return is_string($revision) && preg_match('/^[a-f0-9]{64}$/', $revision) === 1;
    }

    private function buildCatalogCache(string $directory, string $cachePath, string $scope, string $revision): void
    {
        $temporaryDirectory = $directory.DIRECTORY_SEPARATOR.'catalog-'.$scope.'.tmp-'.bin2hex(random_bytes(8));
        $payloadPath = $temporaryDirectory.DIRECTORY_SEPARATOR.'payload';
        $payloadStream = null;

        try {
            if (! mkdir($temporaryDirectory, 0700)) {
                throw new StorefrontUnavailable('Storefront cache is unavailable.');
            }
            $payloadStream = fopen($payloadPath, 'xb');
            if (! is_resource($payloadStream)) {
                throw new StorefrontUnavailable('Storefront cache is unavailable.');
            }
            if (! chmod($payloadPath, 0600)) {
                throw new StorefrontUnavailable('Storefront cache is unavailable.');
            }
            $this->writeAll($payloadStream, '{"data":[');

            $firstPage = $this->upstream->catalogPage(1, self::PER_PAGE);
            $meta = $firstPage['meta'] ?? null;
            if (! is_array($meta)) {
                throw new StorefrontUnavailable('The upstream omitted pagination metadata.');
            }

            $lastPage = $meta['last_page'] ?? null;
            $total = $meta['total'] ?? null;
            if (! is_int($lastPage) || ! is_int($total) || $total < 0) {
                throw new StorefrontUnavailable('The upstream returned invalid pagination metadata.');
            }
            $expectedLastPage = max(1, intdiv($total, self::PER_PAGE) + ($total % self::PER_PAGE === 0 ? 0 : 1));
            if ($lastPage !== $expectedLastPage || $expectedLastPage > 1000) {
                throw new StorefrontUnavailable('The upstream returned invalid pagination metadata.');
            }

            $referenceMetadata = null;
            $productCount = 0;
            /** @var array<string, true> $seenProductIds */
            $seenProductIds = [];
            for ($page = 1; $page <= $lastPage; $page++) {
                $response = $page === 1 ? $firstPage : $this->upstream->catalogPage($page, self::PER_PAGE);
                $pageMeta = $response['meta'] ?? null;
                $data = $response['data'] ?? null;
                $links = $response['links'] ?? null;
                if (! is_array($pageMeta) || ! is_array($data) || ! array_is_list($data)
                    || ! is_array($links) || ! array_is_list($links)
                    || ! $this->hasExactKeys($response, ['data', 'links', 'meta'])) {
                    throw new StorefrontUnavailable('The upstream returned an incomplete catalog page.');
                }

                $this->validatePaginationMeta($pageMeta, $page, $lastPage, $total);
                $this->validatePaginationLinks($links, $page, $lastPage);
                if ($referenceMetadata === null) {
                    $referenceMetadata = $pageMeta;
                } elseif ($pageMeta['path'] !== $referenceMetadata['path']
                    || $pageMeta['first_page_url'] !== $referenceMetadata['first_page_url']
                    || $pageMeta['last_page_url'] !== $referenceMetadata['last_page_url']) {
                    throw new StorefrontUnavailable('The upstream returned inconsistent pagination metadata.');
                }

                $expectedRows = $total === 0 ? 0 : min(self::PER_PAGE, $total - (($page - 1) * self::PER_PAGE));
                if (count($data) !== $expectedRows) {
                    throw new StorefrontUnavailable('The upstream returned an incomplete catalog page.');
                }

                foreach ($data as $product) {
                    if (! is_array($product) || array_is_list($product)) {
                        throw new StorefrontUnavailable('The upstream returned an invalid catalog item.');
                    }
                    $this->validateProduct($product);
                    $publicId = $product['public_id'];
                    if (isset($seenProductIds[$publicId])) {
                        throw new StorefrontUnavailable('The upstream returned a duplicate catalog item.');
                    }
                    $seenProductIds[$publicId] = true;

                    if ($productCount > 0) {
                        $this->writeAll($payloadStream, ',');
                    }
                    $encodedProduct = json_encode($product, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $this->writeAll($payloadStream, $encodedProduct);
                    $productCount++;
                }
                unset($product, $data, $links, $pageMeta, $response);
                if ($page === 1) {
                    unset($firstPage);
                }
            }

            if ($productCount !== $total) {
                throw new StorefrontUnavailable('The upstream catalog was incomplete.');
            }

            $this->writeAll($payloadStream, ']}');
            if (! fflush($payloadStream)) {
                throw new StorefrontUnavailable('Storefront cache is unavailable.');
            }
            fclose($payloadStream);
            $payloadStream = null;

            try {
                $latestMetadata = $this->fetchCatalogMetadata();
            } catch (Throwable $exception) {
                Cache::forget($this->cacheKey('metadata', ''));
                throw $exception;
            }
            if ($latestMetadata['revision'] !== $revision) {
                Cache::forget($this->cacheKey('metadata', ''));
                throw new StorefrontUnavailable('The storefront catalog changed while loading.');
            }
            Cache::put($this->cacheKey('metadata', ''), $latestMetadata, now()->addSeconds(self::METADATA_CACHE_SECONDS));

            if (! rename($payloadPath, $cachePath)) {
                throw new StorefrontUnavailable('Storefront cache is unavailable.');
            }
        } catch (Throwable $exception) {
            throw new StorefrontUnavailable('Storefront data is temporarily unavailable.', previous: $exception);
        } finally {
            if (is_resource($payloadStream)) {
                fclose($payloadStream);
            }
            File::deleteDirectory($temporaryDirectory);
        }
    }

    private function ensureCatalogDirectory(string $directory): void
    {
        if ($directory === '' || (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory))) {
            throw new StorefrontUnavailable('Storefront cache is unavailable.');
        }
        if (! chmod($directory, 0700)) {
            throw new StorefrontUnavailable('Storefront cache is unavailable.');
        }
    }

    private function cleanupTemporaryCatalogs(string $directory, string $scope): void
    {
        $pattern = $directory.DIRECTORY_SEPARATOR.'catalog-'.$scope.'.tmp-*';
        $temporaryDirectories = glob($pattern, GLOB_NOSORT);
        if ($temporaryDirectories === false) {
            return;
        }
        foreach ($temporaryDirectories as $temporaryDirectory) {
            File::deleteDirectory($temporaryDirectory);
        }
    }

    private function cleanupObsoleteCatalogs(string $directory, string $scope, string $revision): void
    {
        $entries = opendir($directory);
        if (! is_resource($entries)) {
            return;
        }

        try {
            while (($filename = readdir($entries)) !== false) {
                if (preg_match('/^catalog-'.preg_quote($scope, '/').'-([a-f0-9]{64})\.json$/', $filename, $matches) !== 1
                    || $matches[1] === $revision) {
                    continue;
                }

                $path = $directory.DIRECTORY_SEPARATOR.$filename;
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        } finally {
            closedir($entries);
        }
    }

    /** @return resource|null */
    private function openCachedCatalog(string $cachePath): mixed
    {
        if (! is_file($cachePath)) {
            return null;
        }
        $stream = @fopen($cachePath, 'rb');
        if (! is_resource($stream)) {
            @unlink($cachePath);

            return null;
        }

        $stat = fstat($stream);
        if (! is_array($stat) || $stat['size'] < 11
            || fseek($stream, 0) !== 0
            || fread($stream, 9) !== '{"data":['
            || fseek($stream, -2, SEEK_END) !== 0
            || fread($stream, 2) !== ']}') {
            fclose($stream);
            @unlink($cachePath);

            return null;
        }
        if (fseek($stream, 0) !== 0) {
            fclose($stream);
            @unlink($cachePath);

            return null;
        }

        return $stream;
    }

    /** @param resource $stream */
    private function writeAll(mixed $stream, string $contents): void
    {
        $length = strlen($contents);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if (! is_int($written) || $written < 1) {
                throw new StorefrontUnavailable('Storefront cache is unavailable.');
            }
            $offset += $written;
        }
    }

    /** @param array<string, mixed> $product */
    private function validateProduct(array $product): void
    {
        if (! is_string($product['public_id'] ?? null)
            || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $product['public_id'])
            || ! is_string($product['slug'] ?? null)
            || $product['slug'] === ''
            || ! $this->hasExactKeys($product, ['public_id', 'slug', 'category', 'translations', 'proxy_profile', 'variants', 'groups'])
            || ! is_array($product['category'] ?? null)
            || ! is_array($product['translations'] ?? null)
            || ! array_is_list($product['translations'])
            || ! is_array($product['proxy_profile'] ?? null)
            || ! is_array($product['variants'] ?? null)
            || ! array_is_list($product['variants'])
            || ! is_array($product['groups'] ?? null)
            || ! array_is_list($product['groups'])) {
            throw new StorefrontUnavailable('The upstream returned an invalid catalog item.');
        }

        $category = $product['category'];
        $profile = $product['proxy_profile'];
        if (! $this->hasExactKeys($category, ['slug', 'name', 'parent_slug', 'parent_name'])
            || ! is_string($category['slug'] ?? null) || $category['slug'] === ''
            || ! is_string($category['name'] ?? null) || $category['name'] === ''
            || ! $this->nullableNonEmptyString($category['parent_slug'] ?? null)
            || ! $this->nullableNonEmptyString($category['parent_name'] ?? null)
            || ! $this->hasExactKeys($profile, ['ip_type', 'proxy_type', 'proxy_access_type', 'country_code', 'traffic_mb', 'countries', 'min_quantity', 'multiple_quantity'])
            || ! in_array($profile['ip_type'] ?? null, [1, 2], true)
            || ! in_array($profile['proxy_type'] ?? null, [1, 2, 3, 4], true)
            || ! array_key_exists('proxy_access_type', $profile)
            || ! in_array($profile['proxy_access_type'], ['private', 'shared', null], true)
            || ! array_key_exists('country_code', $profile)
            || ! ($profile['country_code'] === null || is_string($profile['country_code']) && preg_match('/^[A-Z]{2}$/', $profile['country_code']) === 1)
            || ! array_key_exists('traffic_mb', $profile)
            || ! ($profile['traffic_mb'] === null || $this->positiveInt($profile['traffic_mb']))
            || ! $this->validCountryCodes($profile['countries'] ?? null)
            || ! $this->positiveInt($profile['min_quantity'] ?? null)
            || ! $this->positiveInt($profile['multiple_quantity'] ?? null)
            || $product['variants'] === []
            || ! $this->validGroupCodes($product['groups'])) {
            throw new StorefrontUnavailable('The upstream returned an invalid catalog item.');
        }

        foreach ($product['translations'] as $translation) {
            if (! is_array($translation) || ! $this->hasExactKeys($translation, ['locale', 'title', 'description'])
                || ! is_string($translation['locale'] ?? null) || strlen($translation['locale']) < 2
                || ! is_string($translation['title'] ?? null)
                || $translation['title'] === ''
                || ! array_key_exists('description', $translation)
                || ! (is_string($translation['description']) || $translation['description'] === null)) {
                throw new StorefrontUnavailable('The upstream returned invalid product translations.');
            }
        }

        foreach ($product['variants'] as $variant) {
            if (! is_array($variant)
                || ! $this->hasExactKeys($variant, ['public_id', 'code', 'period_days', 'traffic_mb', 'quantity_unit', 'is_default', 'prices'])
                || ! array_key_exists('public_id', $variant)
                || ! ($variant['public_id'] === null || is_string($variant['public_id']) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $variant['public_id']) === 1)
                || ! array_key_exists('code', $variant)
                || ! (is_string($variant['code']) || $variant['code'] === null)
                || ! $this->nonNegativeInt($variant['period_days'] ?? null)
                || ! $this->positiveInt($variant['quantity_unit'] ?? null)
                || ! array_key_exists('traffic_mb', $variant)
                || ! (is_int($variant['traffic_mb']) && $variant['traffic_mb'] > 0 || $variant['traffic_mb'] === null)
                || ! is_bool($variant['is_default'] ?? null)
                || ! is_array($variant['prices'] ?? null) || ! array_is_list($variant['prices'])) {
                throw new StorefrontUnavailable('The upstream returned an invalid variant.');
            }
            foreach ($variant['prices'] as $price) {
                if (! is_array($price) || ! is_string($price['currency'] ?? null)
                    || ! $this->hasExactKeys($price, ['currency', 'price', 'min_quantity'])
                    || ! preg_match('/^[A-Z]{3}$/', $price['currency'])
                    || ! is_string($price['price'] ?? null)
                    || ! preg_match('/^\d{1,10}\.\d{2}$/', $price['price'])
                    || ! array_key_exists('min_quantity', $price)
                    || ! (is_int($price['min_quantity']) && $price['min_quantity'] > 0 || $price['min_quantity'] === null)) {
                    throw new StorefrontUnavailable('The upstream returned invalid price data.');
                }
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function validateTranslations(array &$payload, string $locale): void
    {
        if (($payload['locale'] ?? null) !== $locale
            || ($payload['default_locale'] ?? null) !== 'ru'
            || ($payload['fallback_locale'] ?? null) !== 'en'
            || ! is_array($payload['available_locales'] ?? null)
            || ! array_is_list($payload['available_locales'])
            || ! is_array($payload['messages'] ?? null)
            || ($payload['messages'] !== [] && array_is_list($payload['messages']))) {
            throw new StorefrontUnavailable('The upstream returned invalid translations.');
        }
        foreach ($payload['available_locales'] as $availableLocale) {
            if (! is_array($availableLocale) || ! is_string($availableLocale['code'] ?? null)
                || ! is_string($availableLocale['name'] ?? null)) {
                throw new StorefrontUnavailable('The upstream returned invalid available locales.');
            }
        }
        foreach ($payload['messages'] as $key => $message) {
            if (! is_string($key) || ! is_string($message)) {
                throw new StorefrontUnavailable('The upstream returned invalid translation messages.');
            }
        }

        if ($payload['messages'] === []) {
            $payload['messages'] = (object) [];
        }
    }

    /** @param array<string, mixed> $meta */
    private function validatePaginationMeta(array $meta, int $page, int $lastPage, int $total): void
    {
        $expectedFrom = $total === 0 ? null : (($page - 1) * self::PER_PAGE + 1);
        $expectedTo = $total === 0 ? null : min($page * self::PER_PAGE, $total);
        if (! $this->hasExactKeys($meta, [
            'current_page', 'from', 'last_page', 'path', 'per_page', 'to', 'total',
            'first_page_url', 'last_page_url', 'next_page_url', 'prev_page_url',
        ])
            || ($meta['current_page'] ?? null) !== $page
            || ($meta['from'] ?? null) !== $expectedFrom
            || ($meta['last_page'] ?? null) !== $lastPage
            || ! is_string($meta['path'] ?? null) || $meta['path'] === ''
            || ($meta['per_page'] ?? null) !== self::PER_PAGE
            || ($meta['to'] ?? null) !== $expectedTo
            || ($meta['total'] ?? null) !== $total
            || ! is_string($meta['first_page_url'] ?? null) || $meta['first_page_url'] === ''
            || ! is_string($meta['last_page_url'] ?? null) || $meta['last_page_url'] === ''
            || ! $this->nullableNonEmptyString($meta['next_page_url'] ?? null)
            || ! $this->nullableNonEmptyString($meta['prev_page_url'] ?? null)
            || (($page < $lastPage) !== is_string($meta['next_page_url']))
            || (($page > 1) !== is_string($meta['prev_page_url']))) {
            throw new StorefrontUnavailable('The upstream returned inconsistent pagination metadata.');
        }
    }

    /** @param list<mixed> $links */
    private function validatePaginationLinks(array $links, int $page, int $lastPage): void
    {
        if (count($links) < 3) {
            throw new StorefrontUnavailable('The upstream returned invalid pagination links.');
        }

        foreach ($links as $link) {
            if (! is_array($link) || ! $this->hasExactKeys($link, ['url', 'label', 'page', 'active'])
                || ! $this->nullableNonEmptyString($link['url'] ?? null)
                || ! is_string($link['label'] ?? null) || $link['label'] === ''
                || ! array_key_exists('page', $link)
                || ! (is_int($link['page']) || $link['page'] === null)
                || ! is_bool($link['active'] ?? null)) {
                throw new StorefrontUnavailable('The upstream returned invalid pagination links.');
            }
        }

        $previous = $links[0];
        $next = $links[array_key_last($links)];
        $activePages = [];
        foreach ($links as $link) {
            if ($link['active'] === true && is_int($link['page'])) {
                $activePages[] = $link['page'];
            }
        }
        if ($activePages !== [$page]
            || $previous['page'] !== null || $previous['active'] !== false
            || (($page === 1) !== ($previous['url'] === null))
            || $next['page'] !== null || $next['active'] !== false
            || (($page === $lastPage) !== ($next['url'] === null))) {
            throw new StorefrontUnavailable('The upstream returned inconsistent pagination links.');
        }
    }

    private function nullableNonEmptyString(mixed $value): bool
    {
        return $value === null || is_string($value) && $value !== '';
    }

    private function validCountryCodes(mixed $countries): bool
    {
        if (! is_array($countries) || ! array_is_list($countries)) {
            return false;
        }

        $seen = [];
        foreach ($countries as $country) {
            if (! is_string($country) || preg_match('/^[A-Z]{2}$/', $country) !== 1 || isset($seen[$country])) {
                return false;
            }
            $seen[$country] = true;
        }

        return true;
    }

    /** @param list<mixed> $groups */
    private function validGroupCodes(array $groups): bool
    {
        foreach ($groups as $group) {
            if (! is_string($group) || preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $group) !== 1) {
                return false;
            }
        }

        return count($groups) === count(array_unique($groups));
    }

    private function positiveInt(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private function nonNegativeInt(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $keys
     */
    private function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    private function cacheKey(string $resource, string $locale): string
    {
        $scope = hash('sha256', (string) config('storefront.api_url').'|'.(string) config('storefront.token'));

        return 'storefront:'.$scope.':'.$resource.':'.$locale;
    }

    private function assertUpstreamConfigured(): void
    {
        if ((string) config('storefront.api_url') === '' || (string) config('storefront.token') === '') {
            throw new StorefrontUnavailable('Storefront data is temporarily unavailable.');
        }
    }

    /** @param \Closure(): void $callback */
    private function withCacheLock(string $cacheKey, \Closure $callback): void
    {
        $repository = Cache::store();
        $store = $repository->getStore();
        if (! $store instanceof LockProvider) {
            $callback();
            return;
        }

        try {
            $store->lock($cacheKey.':lock', self::CACHE_LOCK_SECONDS)->block(10, $callback);
        } catch (Throwable $exception) {
            throw new StorefrontUnavailable('Storefront data is temporarily unavailable.', previous: $exception);
        }
    }
}
