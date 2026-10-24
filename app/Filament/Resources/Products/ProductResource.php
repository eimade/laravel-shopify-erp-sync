<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Fiches produits (données PIM) : titre, description, marque, type.
 */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $modelLabel = 'fiche produit';

    protected static ?string $pluralModelLabel = 'fiches produits (PIM)';

    protected static ?string $navigationLabel = 'Fiches produits';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('Titre')->required()->maxLength(255),
            TextInput::make('handle')->label('Handle')->disabled(),
            TextInput::make('vendor')->label('Marque')->maxLength(255),
            TextInput::make('product_type')->label('Type de produit')->maxLength(255),
            Textarea::make('description')->label('Description')->rows(5)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('title')
            ->columns([
                TextColumn::make('title')->label('Titre')->searchable()->sortable(),
                TextColumn::make('handle')->label('Handle')->searchable(),
                TextColumn::make('vendor')->label('Marque')->sortable(),
                TextColumn::make('product_type')->label('Type'),
                TextColumn::make('variants_count')->label('Variantes')->counts('variants'),
                IconColumn::make('shopify_product_id')->label('Sur Shopify')
                    ->state(fn (Product $record) => $record->shopify_product_id !== null)->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
