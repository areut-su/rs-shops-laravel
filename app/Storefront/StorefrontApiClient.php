<?php

declare(strict_types=1);

namespace App\Storefront;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Ll\ApiClientSatellite\Api\StorefrontApi;
use Ll\ApiClientSatellite\Configuration;
use Ll\ApiClientSatellite\ObjectSerializer;
use JsonException;

final class StorefrontApiClient implements StorefrontUpstream
{
    private readonly StorefrontApi $api;

    public function __construct(string $baseUrl, string $token, ?ClientInterface $httpClient = null)
    {
        $configuration = new Configuration();
        $configuration->setHost(rtrim($baseUrl, '/'));
        $configuration->setAccessToken($token);
        $this->api = new StorefrontApi(
            client: $httpClient ?? new Client([
                'timeout' => 5,
                'connect_timeout' => 2,
                'allow_redirects' => false,
            ]),
            config: $configuration,
        );
    }

    /** @return array<string, mixed> */
    public function catalogPage(int $page, int $perPage): array
    {
        return $this->modelToArray($this->api->listStorefrontCatalog($page, $perPage));
    }

    /** @return array<string, mixed> */
    public function catalogMetadata(): array
    {
        return $this->modelToArray($this->api->getStorefrontCatalogMetadata());
    }

    /** @return array<string, mixed> */
    public function translations(string $locale): array
    {
        return $this->modelToArray($this->api->getStorefrontTranslations($locale));
    }

    /** @return array<string, mixed> */
    private function modelToArray(object $model): array
    {
        try {
            $normalized = ObjectSerializer::sanitizeForSerialization($model);
            $data = json_decode(json_encode($normalized, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new StorefrontUnavailable('The upstream returned an unreadable response.', previous: $exception);
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new StorefrontUnavailable('The upstream returned an invalid response.');
        }

        return $data;
    }
}
