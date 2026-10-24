<?php

namespace App\Filament\Widgets;

use App\Services\ConnectorHealth;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Santé des connecteurs : état Shopify, taux de succès, dead-letters, conflits, SKU non publiés.
 */
class ConnectorHealthWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '15s';

    protected ?string $heading = 'Santé des connecteurs';

    /** @return list<Stat> */
    protected function getStats(): array
    {
        $h = app(ConnectorHealth::class)->snapshot();

        $lastSuccess = $h['last_success_at'] ? Carbon::parse($h['last_success_at'])->diffForHumans() : 'jamais';
        $lastWebhook = $h['last_webhook_at'] ? Carbon::parse($h['last_webhook_at'])->diffForHumans() : 'aucun';

        return [
            Stat::make('Shopify', $h['shopify_ok'] ? 'Connecté' : 'Injoignable')
                ->description($h['shopify_ok']
                    ? "{$h['shop_name']} · mode {$h['shopify_driver']} · {$h['latency_ms']} ms"
                    : (string) $h['shopify_error'])
                ->color($h['shopify_ok'] ? 'success' : 'danger'),
            Stat::make('Succès sur 24 h', $h['success_rate_24h'].' %')
                ->description("{$h['runs_24h']} jobs, {$h['failed_24h']} en échec · dernier succès {$lastSuccess}")
                ->color($h['success_rate_24h'] >= 95 ? 'success' : 'warning'),
            Stat::make('Dead-letters', (string) $h['dead_letters'])
                ->description('Jobs à analyser puis rejouer')
                ->color($h['dead_letters'] === 0 ? 'success' : 'danger'),
            Stat::make('Conflits ouverts', (string) $h['open_conflicts'])
                ->description('Écarts de prix ERP / Shopify')
                ->color($h['open_conflicts'] === 0 ? 'success' : 'warning'),
            Stat::make('SKU non publiés', (string) $h['unmapped_variants'])
                ->description('Absents de Shopify (mapping SKU)')
                ->color($h['unmapped_variants'] === 0 ? 'success' : 'warning'),
            Stat::make('Dernier webhook', $lastWebhook)
                ->description('orders/create, products/update'),
        ];
    }
}
