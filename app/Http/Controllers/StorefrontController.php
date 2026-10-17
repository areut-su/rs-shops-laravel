<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Storefront\StorefrontCatalog;
use App\Storefront\StorefrontUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class StorefrontController extends Controller
{
    public function catalog(Request $request, StorefrontCatalog $storefront): Response
    {
        if ($request->query->all() !== []) {
            return response()->json(['message' => 'Invalid query parameters.'], 422);
        }

        try {
            $catalog = $storefront->openProductsStream();
            $stream = $catalog['stream'];

            return response()->stream(static function () use ($stream): void {
                try {
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                }
            }, 200, [
                'Content-Type' => 'application/json; charset=UTF-8',
                'ETag' => '"'.$catalog['revision'].'"',
            ]);
        } catch (StorefrontUnavailable) {
            return $this->unavailable();
        }
    }

    public function catalogMetadata(Request $request, StorefrontCatalog $storefront): JsonResponse
    {
        if ($request->query->all() !== []) {
            return response()->json(['message' => 'Invalid query parameters.'], 422);
        }

        try {
            return response()->json($storefront->catalogMetadata());
        } catch (StorefrontUnavailable) {
            return $this->unavailable();
        }
    }

    public function translations(Request $request, StorefrontCatalog $storefront): JsonResponse
    {
        $query = $request->query->all();
        if (array_diff(array_keys($query), ['locale']) !== []
            || count($query) > 1
            || (isset($query['locale']) && ! is_string($query['locale']))) {
            return response()->json(['message' => 'Invalid query parameters.'], 422);
        }

        $locale = $query['locale'] ?? 'ru';
        if (! preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $locale)) {
            return response()->json(['message' => 'Invalid locale.'], 422);
        }

        try {
            return response()->json($storefront->translations($locale));
        } catch (StorefrontUnavailable) {
            return $this->unavailable();
        }
    }

    private function unavailable(): JsonResponse
    {
        return response()->json(['message' => 'Storefront data is temporarily unavailable.'], 503);
    }
}
