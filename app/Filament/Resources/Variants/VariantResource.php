<?php

namespace App\Filament\Resources\Variants;

use App\Domain\Enums\VariantSyncStatus;
use App\Filament\Resources\Variants\Pages\EditVariant;
use App\Filament\Resources\Variants\Pages\ListVariants;
use App\Jobs\PushVariantToShopify;
use App\Models\Variant;
use App\Services\SyncDispatcher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * Mini-ERP : prix et stocks par SKU (source de vérité).
 */
class VariantResource extends Resource
{
    protected static ?string $model = Variant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $modelLabel = 'article ERP';

    protected static ?string $pluralModelLabel = 'articles ERP (prix & stocks)';

    protected static ?string $navigationLabel = 'Articles ERP';

    protected static ?string $recordTitleAttribute = 'sku';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Prix (ERP)')->columns(2)->schema([
                TextInput::make('price')->label('Prix de vente')->numeric()->minValue(0)->required()->suffix('€'),
                TextInput::make('compare_at_price')->label('Prix barré')->numeric()->minValue(0)->gt('price')->suffix('€'),
            ]),
            Section::make('Stocks par entrepôt')->schema([
                Repeater::make('stockLevels')->relationship()->hiddenLabel()->columns(3)->schema([
                    TextInput::make('location')->label('Entrepôt')->required()->maxLength(64)->distinct(),
                    TextInput::make('on_hand')->label('Stock physique')->integer()->minValue(0)->required(),
                    TextInput::make('safety_stock')->label('Stock de sécurité')->integer()->minValue(0)->default(0),
                ])->addActionLabel('Ajouter un entrepôt'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sku')
            ->modifyQueryUsing(fn ($query) => $query->with(['product', 'stockLevels']))
            ->columns([
                TextColumn::make('sku')->label('SKU')->searchable()->sortable(),
                TextColumn::make('product.title')->label('Produit')->searchable(),
                TextColumn::make('title')->label('Variante'),
                TextColumn::make('price')->label('Prix')->money('EUR')->sortable(),
                TextColumn::make('available')->label('Vendable')
                    ->state(fn (Variant $record) => $record->availableQuantity())
                    ->color(fn (int $state) => $state === 0 ? 'danger' : null),
                TextColumn::make('sync_status')->label('Synchro')->badge()
                    ->color(fn (VariantSyncStatus $state) => $state->color()),
                TextColumn::make('shopify_variant_id')->label('Variant Shopify')->placeholder('non rattaché')->toggleable(),
                TextColumn::make('last_synced_at')->label('Dernière synchro')->since()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('sync_status')->label('Synchro')
                    ->options(collect(VariantSyncStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->value])),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('push')
                    ->label('Synchroniser')
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->action(function (Variant $record) {
                        $run = app(SyncDispatcher::class)->dispatch(new PushVariantToShopify($record->sku));
                        Notification::make()->title("Synchronisation {$record->sku} en file (#{$run->id})")->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('pushSelected')
                    ->label('Synchroniser la sélection')
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->action(function (Collection $records) {
                        $records->each(fn (Variant $v) => app(SyncDispatcher::class)->dispatch(new PushVariantToShopify($v->sku)));
                        Notification::make()->title($records->count().' synchronisation(s) en file')->success()->send();
                    }),
            ]);
    }

    public static function canCreate(): bool
    {
        return false; // création via import CSV ou API ERP
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVariants::route('/'),
            'edit' => EditVariant::route('/{record}/edit'),
        ];
    }
}
