<?php

namespace App\Filament\Resources\Variants\Pages;

use App\Filament\Resources\Variants\VariantResource;
use App\Jobs\PushVariantToShopify;
use App\Services\CsvCatalogImporter;
use App\Services\SyncDispatcher;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ListVariants extends ListRecords
{
    protected static string $resource = VariantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importCsv')
                ->label('Importer un CSV ERP')
                ->icon(Heroicon::OutlinedDocumentArrowUp)
                ->schema([
                    FileUpload::make('file')
                        ->label('Fichier CSV')
                        ->helperText('Colonnes : '.implode(';', CsvCatalogImporter::COLUMNS))
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->disk('local')
                        ->directory('imports')
                        ->required(),
                ])
                ->action(function (array $data) {
                    $path = Storage::disk('local')->path($data['file']);
                    try {
                        $report = app(CsvCatalogImporter::class)->import($path);
                    } catch (RuntimeException $e) {
                        Notification::make()->title('Import refusé')->body($e->getMessage())->danger()->send();

                        return;
                    } finally {
                        Storage::disk('local')->delete($data['file']);
                    }

                    foreach ($report['changed_skus'] as $sku) {
                        app(SyncDispatcher::class)->dispatch(new PushVariantToShopify($sku));
                    }

                    $errors = collect($report['errors'])->map(fn ($e) => "Ligne {$e['line']} : {$e['message']}")->implode("\n");
                    Notification::make()
                        ->title("{$report['rows']} lignes importées, ".count($report['changed_skus']).' SKU à synchroniser')
                        ->body($errors !== '' ? $errors : null)
                        ->color($errors !== '' ? 'warning' : 'success')
                        ->send();
                }),
        ];
    }
}
