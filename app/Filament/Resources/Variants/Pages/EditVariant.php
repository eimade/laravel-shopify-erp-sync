<?php

namespace App\Filament\Resources\Variants\Pages;

use App\Filament\Resources\Variants\VariantResource;
use App\Jobs\PushVariantToShopify;
use App\Models\Variant;
use App\Services\SyncDispatcher;
use Filament\Resources\Pages\EditRecord;

class EditVariant extends EditRecord
{
    protected static string $resource = VariantResource::class;

    /** Toute modification ERP est aussitôt propagée vers Shopify. */
    protected function afterSave(): void
    {
        /** @var Variant $variant */
        $variant = $this->getRecord();
        app(SyncDispatcher::class)->dispatch(new PushVariantToShopify($variant->sku));
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Article enregistré, synchronisation Shopify en file';
    }
}
