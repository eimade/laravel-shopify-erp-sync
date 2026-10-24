<?php

namespace App\Providers;

use App\Domain\Enums\VariantSyncStatus;
use App\Integrations\Erp\DatabaseErpClient;
use App\Integrations\Erp\ErpClient;
use App\Integrations\Shopify\CostThrottle;
use App\Integrations\Shopify\FakeShopifyClient;
use App\Integrations\Shopify\GraphqlShopifyClient;
use App\Integrations\Shopify\ShopifyClient;
use GraphQL\Type\Definition\PhpEnumType;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Nuwave\Lighthouse\Schema\TypeRegistry;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ErpClient::class, DatabaseErpClient::class);

        $this->app->singleton(FakeShopifyClient::class, fn ($app) => new FakeShopifyClient($app->make(Cache::class)));

        // Le throttle est partagé par toutes les requêtes d'un même processus (worker).
        $this->app->singleton(CostThrottle::class, fn () => new CostThrottle((int) config('synchub.shopify.throttle_buffer')));

        $this->app->singleton(ShopifyClient::class, function ($app) {
            $config = config('synchub.shopify');

            return match ($config['driver']) {
                'fake' => $app->make(FakeShopifyClient::class),
                'graphql' => new GraphqlShopifyClient(
                    $app->make(HttpFactory::class),
                    $app->make(CostThrottle::class),
                    (string) $config['shop_domain'],
                    (string) $config['access_token'],
                    (string) $config['api_version'],
                    (string) $config['location_id'],
                ),
                default => throw new InvalidArgumentException("SHOPIFY_DRIVER inconnu : {$config['driver']}"),
            };
        });
    }

    public function boot(TypeRegistry $types): void
    {
        // Enum PHP exposée telle quelle dans le schéma GraphQL (une seule source de vérité).
        $types->register(new PhpEnumType(VariantSyncStatus::class));
    }
}
