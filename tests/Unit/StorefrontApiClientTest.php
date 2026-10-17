<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Storefront\StorefrontApiClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class StorefrontApiClientTest extends TestCase
{
    public function test_sdk_metadata_operation_uses_server_auth_and_returns_revision(): void
    {
        /** @var list<array{request: \Psr\Http\Message\RequestInterface, response: Response|null, error: \Throwable|null, options: array<string, mixed>}> $history */
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['revision' => str_repeat('a', 64)], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($history));
        $client = new Client(['handler' => $stack]);
        $upstream = new StorefrontApiClient('https://core.example.test', 'server-token', $client);

        self::assertSame(['revision' => str_repeat('a', 64)], $upstream->catalogMetadata());
        self::assertIsArray($history);
        self::assertCount(1, $history);
        self::assertSame('/api/v1/satellite/storefront/catalog/meta', $history[0]['request']->getUri()->getPath());
        self::assertSame('', $history[0]['request']->getUri()->getQuery());
        self::assertSame('Bearer server-token', $history[0]['request']->getHeaderLine('Authorization'));
    }

    public function test_sdk_preserves_the_full_catalogue_contract_including_explicit_nulls(): void
    {
        $json = file_get_contents(dirname(__DIR__).'/Fixtures/storefront-catalog.json');
        self::assertIsString($json);
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], $json)]))]);
        $upstream = new StorefrontApiClient('https://core.example.test', 'server-fixture-token', $client);
        self::assertSame(json_decode($json, true, flags: JSON_THROW_ON_ERROR), $upstream->catalogPage(1, 100));
    }

    public function test_sdk_adapter_sends_server_token_and_serializes_the_sdk_page_model(): void
    {
        /** @var list<array{request: \Psr\Http\Message\RequestInterface, response: Response|null, error: \Throwable|null, options: array<string, mixed>}> $history */
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'data' => [],
                'links' => [],
                'meta' => ['current_page' => 2, 'last_page' => 2, 'per_page' => 100, 'total' => 0],
            ], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($history));
        $client = new Client(['handler' => $stack]);
        $upstream = new StorefrontApiClient('https://core.example.test/', 'server-token', $client);

        $page = $upstream->catalogPage(2, 100);

        self::assertSame(2, $page['meta']['current_page'] ?? null);
        self::assertIsArray($history);
        self::assertCount(1, $history);
        self::assertSame('/api/v1/satellite/storefront/catalog', $history[0]['request']->getUri()->getPath());
        self::assertSame('page=2&per_page=100', $history[0]['request']->getUri()->getQuery());
        self::assertSame('Bearer server-token', $history[0]['request']->getHeaderLine('Authorization'));
    }
}
