<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Commandes Shopify remontées dans l'ERP (lecture seule).
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $modelLabel = 'commande';

    protected static ?string $pluralModelLabel = 'commandes Shopify';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 5;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('placed_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Commande')->searchable(),
                TextColumn::make('email')->label('Client')->searchable()->placeholder('—'),
                TextColumn::make('total_price')->label('Total')->money(fn (Order $record) => $record->currency),
                TextColumn::make('financial_status')->label('Paiement')->badge()->placeholder('—'),
                TextColumn::make('accounting_status')->label('Comptabilité')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'exported' ? 'Comptabilisée' : 'En attente')
                    ->color(fn (string $state) => $state === 'exported' ? 'success' : 'warning'),
                TextColumn::make('placed_at')->label('Passée le')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('accounting_status')->label('Comptabilité')->options(['pending' => 'En attente', 'exported' => 'Comptabilisée']),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Commande')->columns(3)->schema([
                TextEntry::make('name')->label('Numéro'),
                TextEntry::make('shopify_order_id')->label('ID Shopify'),
                TextEntry::make('email')->label('Client')->placeholder('—'),
                TextEntry::make('total_price')->label('Total')->money(fn (Order $record) => $record->currency),
                TextEntry::make('financial_status')->label('Paiement')->placeholder('—'),
                TextEntry::make('accounting_exported_at')->label('Comptabilisée le')->dateTime('d/m/Y H:i')->placeholder('—'),
            ]),
            Section::make('Lignes')->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('sku')->label('SKU')->placeholder('sans SKU'),
                    TextEntry::make('title')->label('Article'),
                    TextEntry::make('quantity')->label('Qté'),
                    TextEntry::make('unit_price')->label('Prix unitaire')->money('EUR'),
                ]),
            ]),
        ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
