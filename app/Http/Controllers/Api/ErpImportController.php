<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\PushVariantToShopify;
use App\Services\CsvCatalogImporter;
use App\Services\SyncDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ErpImportController extends Controller
{
    public function __invoke(Request $request, CsvCatalogImporter $importer, SyncDispatcher $dispatcher): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        try {
            $report = $importer->import($request->file('file')->getRealPath());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Chaque SKU créé ou modifié est resynchronisé vers Shopify.
        $runs = [];
        foreach ($report['changed_skus'] as $sku) {
            $runs[] = $dispatcher->dispatch(new PushVariantToShopify($sku))->id;
        }

        return response()->json([...$report, 'sync_run_ids' => $runs], $report['errors'] === [] ? 200 : 207);
    }
}
