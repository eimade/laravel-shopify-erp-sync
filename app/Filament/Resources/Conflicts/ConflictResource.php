<?php

namespace App\Filament\Resources\Conflicts;

use App\Domain\Enums\ConflictResolution;
use App\Domain\Enums\ConflictStatus;
use App\Filament\Resources\Conflicts\Pages\ListConflicts;
use App\Models\Conflict;
use App\Services\ConflictResolver;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class ConflictResource extends Resource
{
    protected static ?string $model = Conflict::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $modelLabel = 'conflit';

    protected static ?string $pluralModelLabel = 'conflits';

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        $open = Conflict::where('status', ConflictStatus::Open)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('variant.product'))
            ->columns([
                TextColumn::make('variant.sku')->label('SKU')->searchable(),
                TextColumn::make('variant.product.title')->label('Produit'),
                TextColumn::make('field')->label('Champ'),
                TextColumn::make('erp_value')->label('Valeur ERP'),
                TextColumn::make('shopify_value')->label('Valeur Shopify'),
                TextColumn::make('source')->label('Détecté par'),
                TextColumn::make('status')->label('Statut')->badge()
                    ->formatStateUsing(fn (ConflictStatus $state) => $state === ConflictStatus::Open ? 'Ouvert' : 'Résolu')
                    ->color(fn (ConflictStatus $state) => $state === ConflictStatus::Open ? 'warning' : 'success'),
                TextColumn::make('resolution')->label('Décision')
                    ->formatStateUsing(fn (?ConflictResolution $state) => $state?->label())->placeholder('—'),
                TextColumn::make('resolved_by')->label('Par')->placeholder('—'),
                TextColumn::make('created_at')->label('Détecté le')->dateTime('d/m/Y H:i'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Statut')->options(['open' => 'Ouvert', 'resolved' => 'Résolu'])->default('open'),
            ])
            ->recordActions([
                self::resolveAction(ConflictResolution::KeepErp, 'success', Heroicon::OutlinedServerStack),
                self::resolveAction(ConflictResolution::AcceptShopify, 'gray', Heroicon::OutlinedShoppingBag),
            ]);
    }

    private static function resolveAction(ConflictResolution $resolution, string $color, Heroicon $icon): Action
    {
        return Action::make($resolution->value)
            ->label($resolution->label())
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->modalDescription(fn (Conflict $record) => $resolution === ConflictResolution::KeepErp
                ? "Le prix ERP ({$record->erp_value}) sera repoussé vers Shopify."
                : "Le prix Shopify ({$record->shopify_value}) deviendra le prix de référence dans l'ERP.")
            ->visible(fn (Conflict $record) => $record->status === ConflictStatus::Open)
            ->action(function (Conflict $record) use ($resolution) {
                try {
                    app(ConflictResolver::class)->resolve($record, $resolution, (string) Auth::user()?->email);
                    Notification::make()->title('Conflit résolu')->success()->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListConflicts::route('/')];
    }
}
