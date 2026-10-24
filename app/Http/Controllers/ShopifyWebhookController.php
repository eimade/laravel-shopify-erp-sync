<?php

namespace App\Http\Controllers;

use App\Integrations\Shopify\Data\OrderData;
use App\Jobs\ImportShopifyOrder;
use App\Jobs\ProcessProductUpdate;
use App\Jobs\TrackedSyncJob;
use App\Models\WebhookReceipt;
use App\Services\SyncDispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * Réception des webhooks Shopify (signature déjà vérifiée par le middleware).
 * Répond vite (< 5 s exigées par Shopify) : le traitement est délégué à la file d'attente.
 */
class ShopifyWebhookController extends Controller
{
    private const TOPICS = ['orders/create', 'products/update'];

    public function __invoke(Request $request, SyncDispatcher $dispatcher): JsonResponse
    {
        $topic = (string) $request->header('X-Shopify-Topic', '');
        $webhookId = (string) $request->header('X-Shopify-Webhook-Id', '');

        if (! in_array($topic, self::TOPICS, true)) {
            return response()->json(['message' => "Topic non géré : {$topic}"], 422);
        }
        if ($webhookId === '') {
            return response()->json(['message' => 'En-tête X-Shopify-Webhook-Id manquant.'], 422);
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'Corps JSON invalide.'], 400);
        }

        // Idempotence : Shopify peut livrer plusieurs fois le même webhook.
        if (WebhookReceipt::where('webhook_id', $webhookId)->exists()) {
            return response()->json(['status' => 'duplicate', 'webhook_id' => $webhookId]);
        }

        try {
            $job = $this->jobFor($topic, $payload);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        try {
            $receipt = WebhookReceipt::create([
                'webhook_id' => $webhookId,
                'topic' => $topic,
                'shop_domain' => $request->header('X-Shopify-Shop-Domain'),
                'payload' => $payload,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Livraison concurrente du même webhook : déjà prise en charge.
            return response()->json(['status' => 'duplicate', 'webhook_id' => $webhookId]);
        }

        try {
            $run = $dispatcher->dispatch($job);
        } catch (Throwable $e) {
            // Mise en file impossible (Redis indisponible…) : on oublie l'accusé de réception
            // pour que la relivraison automatique de Shopify soit traitée.
            $receipt->delete();

            throw $e;
        }
        $receipt->update(['sync_run_id' => $run->id]);

        return response()->json(['status' => 'accepted', 'sync_run_id' => $run->id], 202);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function jobFor(string $topic, array $payload): TrackedSyncJob
    {
        return match ($topic) {
            'orders/create' => new ImportShopifyOrder(OrderData::fromWebhook($payload)->toArray()),
            'products/update' => isset($payload['id'], $payload['variants'])
                ? new ProcessProductUpdate($payload)
                : throw new InvalidArgumentException('Payload produit incomplet (id, variants requis).'),
        };
    }
}
