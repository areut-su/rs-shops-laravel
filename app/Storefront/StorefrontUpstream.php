<?php

declare(strict_types=1);

namespace App\Storefront;

interface StorefrontUpstream
{
    /** @return array<string, mixed> */
    public function catalogMetadata(): array;

    /** @return array<string, mixed> */
    public function catalogPage(int $page, int $perPage): array;

    /** @return array<string, mixed> */
    public function translations(string $locale): array;
}
