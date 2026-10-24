<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vérifie la signature HMAC-SHA256 (base64) envoyée par Shopify dans X-Shopify-Hmac-Sha256,
 * calculée sur le corps brut de la requête avec le secret de l'application.
 */
class VerifyShopifyWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('synchub.shopify.webhook_secret');
        if ($secret === '') {
            Log::error('SyncHub : SHOPIFY_WEBHOOK_SECRET non configuré, webhook refusé.');

            return response()->json(['message' => 'Webhooks non configurés.'], 503);
        }

        $signature = (string) $request->header('X-Shopify-Hmac-Sha256', '');
        if (! self::isValid($request->getContent(), $signature, $secret)) {
            Log::warning('SyncHub : signature de webhook invalide', ['topic' => $request->header('X-Shopify-Topic'), 'ip' => $request->ip()]);

            return response()->json(['message' => 'Signature HMAC invalide.'], 401);
        }

        return $next($request);
    }

    public static function sign(string $body, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $body, $secret, true));
    }

    public static function isValid(string $body, string $signature, string $secret): bool
    {
        return $signature !== '' && hash_equals(self::sign($body, $secret), $signature);
    }
}
