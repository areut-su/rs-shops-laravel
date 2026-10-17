<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Storefront\StorefrontUpstream;
use RuntimeException;

final class FakeStorefrontUpstream implements StorefrontUpstream
{
    /** @var array<int, array<string, mixed>> */
    public array $catalogPages = [];

    /** @var array<string, array<string, mixed>> */
    public array $translationPayloads = [];

    /** @var list<array{page: int, perPage: int}> */
    public array $catalogCalls = [];

    /** @var list<string> */
    public array $translationCalls = [];

    /** @var array<string, mixed> */
    public array $metadataPayload = ['revision' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'];

    /** @var list<array<string, mixed>> */
    public array $metadataCalls = [];

    public ?int $changeRevisionAfterCatalogCall = null;

    public ?string $nextCatalogRevision = null;

    public bool $metadataUnavailable = false;

    /** @return array<string, mixed> */
    public function catalogMetadata(): array
    {
        if ($this->metadataUnavailable) {
            throw new RuntimeException('Fake catalog metadata is unavailable.');
        }
        $this->metadataCalls[] = $this->metadataPayload;

        return $this->metadataPayload;
    }

    /** @return array<string, mixed> */
    public function catalogPage(int $page, int $perPage): array
    {
        $this->catalogCalls[] = ['page' => $page, 'perPage' => $perPage];
        $payload = $this->catalogPages[$page] ?? throw new RuntimeException('Missing fake catalog page.');
        if ($this->changeRevisionAfterCatalogCall === count($this->catalogCalls) && $this->nextCatalogRevision !== null) {
            $this->metadataPayload = ['revision' => $this->nextCatalogRevision];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function translations(string $locale): array
    {
        $this->translationCalls[] = $locale;
        return $this->translationPayloads[$locale] ?? throw new RuntimeException('Missing fake translations.');
    }
}
