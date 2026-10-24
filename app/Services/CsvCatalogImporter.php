<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Import CSV du mini-ERP (séparateur ";" ou ",").
 *
 * Colonnes : sku;product_handle;product_title;variant_title;price;compare_at_price;location;on_hand;safety_stock
 * Une ligne par couple (SKU, entrepôt). Les lignes invalides sont rejetées individuellement.
 */
class CsvCatalogImporter
{
    public const COLUMNS = ['sku', 'product_handle', 'product_title', 'variant_title', 'price', 'compare_at_price', 'location', 'on_hand', 'safety_stock'];

    private const REQUIRED = ['sku', 'product_handle', 'product_title', 'price', 'location', 'on_hand'];

    /**
     * @return array{rows: int, created: int, updated: int, errors: list<array{line: int, message: string}>, changed_skus: list<string>}
     */
    public function import(string $path): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Fichier CSV illisible : {$path}");
        }

        try {
            $firstLine = (string) fgets($handle);
            $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
            $header = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\u{FEFF}")), str_getcsv($firstLine, $delimiter, '"', ''));

            $missing = array_diff(self::REQUIRED, $header);
            if ($missing !== []) {
                throw new RuntimeException('Colonnes manquantes : '.implode(', ', $missing));
            }

            $report = ['rows' => 0, 'created' => 0, 'updated' => 0, 'errors' => [], 'changed_skus' => []];
            $line = 1;

            while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $line++;
                if ($cells === [null] || $cells === []) {
                    continue;
                }
                $report['rows']++;
                $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), null));

                $error = $this->importRow($row, $report);
                if ($error !== null) {
                    $report['errors'][] = ['line' => $line, 'message' => $error];
                }
            }
        } finally {
            fclose($handle);
        }

        $report['changed_skus'] = array_values(array_unique($report['changed_skus']));

        return $report;
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  array{rows: int, created: int, updated: int, errors: list<array{line: int, message: string}>, changed_skus: list<string>}  $report
     */
    private function importRow(array $row, array &$report): ?string
    {
        $row = array_map(fn ($v) => $v === null ? null : trim($v), $row);
        $row['price'] = isset($row['price']) ? str_replace(',', '.', $row['price']) : null;
        if (isset($row['compare_at_price'])) {
            $row['compare_at_price'] = $row['compare_at_price'] === '' ? null : str_replace(',', '.', $row['compare_at_price']);
        }

        $validator = Validator::make($row, [
            'sku' => ['required', 'string', 'max:64'],
            'product_handle' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/'],
            'product_title' => ['required', 'string', 'max:255'],
            'variant_title' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'location' => ['required', 'string', 'max:64'],
            'on_hand' => ['required', 'integer', 'min:0'],
            'safety_stock' => ['nullable', 'integer', 'min:0'],
        ]);
        if ($validator->fails()) {
            return implode(' ', $validator->errors()->all());
        }

        $sku = (string) SkuMapper::normalize($row['sku']);

        DB::transaction(function () use ($row, $sku, &$report) {
            $product = Product::firstOrCreate(
                ['handle' => $row['product_handle']],
                ['title' => $row['product_title']],
            );

            $variant = Variant::firstOrNew(['sku' => $sku]);
            $isNew = ! $variant->exists;
            $variant->fill([
                'product_id' => $product->id,
                'title' => $row['variant_title'] ?: 'Default',
                'price' => $row['price'],
                'compare_at_price' => $row['compare_at_price'] ?? null,
            ]);
            $dirty = $variant->isDirty(['price', 'compare_at_price']);
            $variant->save();

            $level = StockLevel::firstOrNew(['variant_id' => $variant->id, 'location' => $row['location']]);
            $level->on_hand = (int) $row['on_hand'];
            $level->safety_stock = (int) ($row['safety_stock'] ?? 0);
            $dirty = $dirty || $level->isDirty();
            $level->save();

            $isNew ? $report['created']++ : $report['updated']++;
            if ($isNew || $dirty) {
                $report['changed_skus'][] = $sku;
            }
        });

        return null;
    }
}
