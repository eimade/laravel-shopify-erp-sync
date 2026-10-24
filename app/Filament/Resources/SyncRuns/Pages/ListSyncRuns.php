<?php

namespace App\Filament\Resources\SyncRuns\Pages;

use App\Filament\Resources\SyncRuns\SyncRunResource;
use App\Jobs\PullShopifyCatalog;
use App\Jobs\PullShopifyOrders;
use App\Services\SyncDispatcher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;

class ListSyncRuns extends ListRecords
{
    protected static string $resource = SyncRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pullCatalog')
                ->label('Lire le catalogue Shopify')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(function () {
                    $run = app(SyncDispatcher::class)->dispatch(new PullShopifyCatalog);
                    Notification::make()->title("Lecture du catalogue en file (#{$run->id})")->success()->send();
                }),
            Action::make('pushAll')
                ->label('Pousser tout l\'ERP')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->requiresConfirmation()
                ->action(function () {
                    Artisan::call('synchub:push-all');
                    Notification::make()->title(trim(Artisan::output()))->success()->send();
                }),
            Action::make('pullOrders')
                ->label('Rattraper les commandes 24 h')
                ->icon(Heroicon::OutlinedShoppingCart)
                ->action(function () {
                    $run = app(SyncDispatcher::class)->dispatch(new PullShopifyOrders(now()->subDay()->toIso8601String()));
                    Notification::make()->title("Rattrapage en file (#{$run->id})")->success()->send();
                }),
        ];
    }
}
