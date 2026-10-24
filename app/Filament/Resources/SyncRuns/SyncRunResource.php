<?php

namespace App\Filament\Resources\SyncRuns;

use App\Domain\Enums\SyncStatus;
use App\Domain\Enums\SyncType;
use App\Filament\Resources\SyncRuns\Pages\ListSyncRuns;
use App\Filament\Resources\SyncRuns\Pages\ViewSyncRun;
use App\Models\SyncRun;
use App\Services\SyncDispatcher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use InvalidArgumentException;

class SyncRunResource extends Resource
{
    protected static ?string $model = SyncRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $modelLabel = 'exécution';

    protected static ?string $pluralModelLabel = 'journal de synchronisation';

    protected static ?string $navigationLabel = 'Journal de synchro';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $dead = SyncRun::where('status', SyncStatus::Dead)->count();

        return $dead > 0 ? (string) $dead : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('10s')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('type')->label('Flux')->formatStateUsing(fn (SyncType $state) => $state->label()),
                TextColumn::make('subject')->label('Objet')->searchable(),
                TextColumn::make('status')->label('Statut')->badge()
                    ->formatStateUsing(fn (SyncStatus $state) => $state->label())
                    ->color(fn (SyncStatus $state) => $state->color()),
                TextColumn::make('attempts')->label('Tentatives'),
                TextColumn::make('duration_ms')->label('Durée')->suffix(' ms')->placeholder('—'),
                TextColumn::make('error')->label('Erreur')->limit(60)->placeholder('—')->wrap(),
                TextColumn::make('created_at')->label('Créé')->dateTime('d/m/Y H:i:s')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Statut')->options(collect(SyncStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                SelectFilter::make('type')->label('Flux')->options(collect(SyncType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])),
            ])
            ->recordActions([
                ViewAction::make(),
                self::replayAction(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Exécution')->columns(3)->schema([
                TextEntry::make('type')->label('Flux')->formatStateUsing(fn (SyncType $state) => $state->label()),
                TextEntry::make('direction')->label('Sens'),
                TextEntry::make('subject')->label('Objet')->placeholder('—'),
                TextEntry::make('status')->label('Statut')->badge()
                    ->formatStateUsing(fn (SyncStatus $state) => $state->label())
                    ->color(fn (SyncStatus $state) => $state->color()),
                TextEntry::make('attempts')->label('Tentatives'),
                TextEntry::make('duration_ms')->label('Durée (ms)')->placeholder('—'),
                TextEntry::make('started_at')->label('Début')->dateTime('d/m/Y H:i:s')->placeholder('—'),
                TextEntry::make('finished_at')->label('Fin')->dateTime('d/m/Y H:i:s')->placeholder('—'),
                TextEntry::make('replayed_from_id')->label('Relance de')->prefix('#')->placeholder('—'),
                TextEntry::make('job_class')->label('Job')->columnSpanFull(),
            ]),
            Section::make('Erreur')->schema([
                TextEntry::make('error')->hiddenLabel()->placeholder('Aucune')->color('danger'),
            ]),
            Section::make('Payload')->schema([
                TextEntry::make('payload')->hiddenLabel()
                    ->state(fn (SyncRun $record) => self::prettyJson($record->payload))
                    ->fontFamily('mono')->extraAttributes(['style' => 'white-space: pre-wrap']),
            ]),
            Section::make('Résultat')->schema([
                TextEntry::make('result')->hiddenLabel()
                    ->state(fn (SyncRun $record) => self::prettyJson($record->result))
                    ->fontFamily('mono')->extraAttributes(['style' => 'white-space: pre-wrap']),
            ]),
        ]);
    }

    public static function replayAction(): Action
    {
        return Action::make('replay')
            ->label('Rejouer')
            ->icon(Heroicon::OutlinedPlay)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('Le job sera remis en file avec le même payload. Vérifiez que la cause de l\'échec est corrigée.')
            ->visible(fn (SyncRun $record) => $record->status->isReplayable())
            ->action(function (SyncRun $record) {
                try {
                    $run = app(SyncDispatcher::class)->replay($record);
                    Notification::make()->title("Job rejoué (journal #{$run->id})")->success()->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private static function prettyJson(?array $data): string
    {
        return $data === null ? '—' : (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSyncRuns::route('/'),
            'view' => ViewSyncRun::route('/{record}'),
        ];
    }
}
