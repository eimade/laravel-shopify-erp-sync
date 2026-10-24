<?php

use App\Http\Middleware\VerifyShopifyWebhook;
use App\Integrations\Shopify\FakeShopifyClient;
use App\Jobs\PullShopifyCatalog;
use App\Jobs\PullShopifyOrders;
use App\Jobs\PushVariantToShopify;
use App\Models\Variant;
use App\Services\CsvCatalogImporter;
use App\Services\SyncDispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('synchub:pull-catalog', function (SyncDispatcher $dispatcher) {
    $run = $dispatcher->dispatch(new PullShopifyCatalog);
    $this->info("Lecture du catalogue Shopify mise en file (journal #{$run->id}, statut {$run->status->label()}).");
})->purpose('Rapprocher le catalogue Shopify des SKU ERP (mapping + conflits)');

Artisan::command('synchub:push-all', function (SyncDispatcher $dispatcher) {
    $count = 0;
    Variant::query()->orderBy('id')->pluck('sku')->each(function (string $sku) use ($dispatcher, &$count) {
        $dispatcher->dispatch(new PushVariantToShopify($sku));
        $count++;
    });
    $this->info("{$count} synchronisation(s) ERP → Shopify mises en file.");
})->purpose('Pousser prix et stocks ERP de tous les SKU vers Shopify');

Artisan::command('synchub:pull-orders {--since= : date ISO 8601 (défaut : 24 h)}', function (SyncDispatcher $dispatcher) {
    $since = $this->option('since') ?: now()->subDay()->toIso8601String();
    $run = $dispatcher->dispatch(new PullShopifyOrders($since));
    $this->info("Rattrapage des commandes depuis {$since} mis en file (journal #{$run->id}).");
})->purpose('Rattraper les commandes Shopify dont le webhook aurait été perdu');

Artisan::command('synchub:import-csv {path}', function (CsvCatalogImporter $importer, SyncDispatcher $dispatcher) {
    $report = $importer->import((string) $this->argument('path'));
    foreach ($report['changed_skus'] as $sku) {
        $dispatcher->dispatch(new PushVariantToShopify($sku));
    }
    $this->info("Lignes : {$report['rows']} — créées : {$report['created']} — mises à jour : {$report['updated']} — SKU à synchroniser : ".count($report['changed_skus']));
    foreach ($report['errors'] as $error) {
        $this->warn("Ligne {$error['line']} : {$error['message']}");
    }

    return $report['errors'] === [] ? 0 : 1;
})->purpose('Importer un CSV prix/stocks dans le mini-ERP');

/*
 * Démo hors-ligne : simule l'envoi d'un webhook signé par Shopify, traité par la vraie
 * pile HTTP (middleware HMAC, idempotence, mise en file).
 */
Artisan::command('synchub:simulate-webhook {topic : orders/create ou products/update} {--sku=} {--qty=1} {--price=}', function (FakeShopifyClient $fake) {
    $topic = (string) $this->argument('topic');
    $sku = (string) ($this->option('sku') ?: Variant::whereNotNull('shopify_variant_id')->value('sku'));
    $variant = Variant::with('product')->where('sku', $sku)->first();
    if ($variant === null) {
        $this->error("SKU inconnu : {$sku}");

        return 1;
    }

    if ($topic === 'orders/create') {
        $qty = max(1, (int) $this->option('qty'));
        $number = random_int(1000, 9999);
        $payload = [
            'id' => random_int(5_000_000, 9_999_999),
            'name' => "#{$number}",
            'email' => 'cliente@example.com',
            'currency' => 'EUR',
            'total_price' => number_format((float) $variant->price * $qty, 2, '.', ''),
            'financial_status' => 'paid',
            'created_at' => now()->toIso8601String(),
            'line_items' => [['sku' => $sku, 'title' => $variant->product->title, 'quantity' => $qty, 'price' => (string) $variant->price]],
        ];
    } elseif ($topic === 'products/update') {
        $price = (string) ($this->option('price') ?: number_format((float) $variant->price * 0.9, 2, '.', ''));
        if (config('synchub.shopify.driver') === 'fake') {
            $fake->setPriceBySku($sku, $price); // la boutique simulée reflète la modification
        }
        $payload = [
            'id' => (int) Str::afterLast((string) $variant->product->shopify_product_id, '/'),
            'admin_graphql_api_id' => $variant->product->shopify_product_id,
            'handle' => $variant->product->handle,
            'title' => $variant->product->title,
            'variants' => [[
                'id' => (int) Str::afterLast((string) $variant->shopify_variant_id, '/'),
                'admin_graphql_api_id' => $variant->shopify_variant_id,
                'sku' => $sku,
                'price' => $price,
                'inventory_item_id' => (int) Str::afterLast((string) $variant->shopify_inventory_item_id, '/'),
            ]],
        ];
    } else {
        $this->error('Topic non géré. Valeurs possibles : orders/create, products/update');

        return 1;
    }

    $body = (string) json_encode($payload);
    $request = Request::create('/api/webhooks/shopify', 'POST', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SHOPIFY_TOPIC' => $topic,
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => (string) Str::uuid(),
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => config('synchub.shopify.shop_domain'),
        'HTTP_X_SHOPIFY_HMAC_SHA256' => VerifyShopifyWebhook::sign($body, (string) config('synchub.shopify.webhook_secret')),
    ], content: $body);

    $response = app(HttpKernel::class)->handle($request);
    $this->line("HTTP {$response->getStatusCode()} : {$response->getContent()}");

    return $response->getStatusCode() < 300 ? 0 : 1;
})->purpose('Simuler un webhook Shopify signé (démo hors-ligne)');

Artisan::command('synchub:is-seeded', function () {
    $this->line(Variant::query()->exists() ? 'yes' : 'no');
})->purpose('Indique si les données de démo sont présentes (utilisé par le conteneur Docker)');

// Réconciliation périodique : filet de sécurité si un webhook ou un job a été perdu.
Schedule::command('synchub:pull-orders')->hourly()->withoutOverlapping();
Schedule::command('synchub:pull-catalog')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('synchub:push-all')->dailyAt('03:00')->withoutOverlapping();
