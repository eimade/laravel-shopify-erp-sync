<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateErpItemRequest;
use App\Http\Resources\ErpItemResource;
use App\Jobs\PushVariantToShopify;
use App\Models\StockLevel;
use App\Models\Variant;
use App\Services\SkuMapper;
use App\Services\SyncDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * API REST du mini-ERP : consultation et mise à jour des prix / stocks.
 * Toute modification déclenche la synchronisation vers Shopify.
 */
class ErpItemController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'sync_status' => ['nullable', 'string', 'in:pending,synced,unmapped,conflict,error'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Variant::with(['product', 'stockLevels'])->orderBy('sku');
        if (! empty($validated['search'])) {
            $term = '%'.$validated['search'].'%';
            $query->where(fn ($q) => $q->where('sku', 'like', $term)->orWhereHas('product', fn ($p) => $p->where('title', 'like', $term)));
        }
        if (! empty($validated['sync_status'])) {
            $query->where('sync_status', $validated['sync_status']);
        }

        return ErpItemResource::collection($query->paginate((int) ($validated['per_page'] ?? 25))->withQueryString());
    }

    public function show(string $sku): ErpItemResource
    {
        return new ErpItemResource($this->find($sku));
    }

    public function update(UpdateErpItemRequest $request, string $sku, SyncDispatcher $dispatcher): JsonResponse
    {
        $variant = $this->find($sku);
        $data = $request->validated();

        // Le prix barré doit rester supérieur au prix de vente effectif (nouveau ou actuel).
        $effectivePrice = (float) ($data['price'] ?? $variant->price);
        if (isset($data['compare_at_price']) && (float) $data['compare_at_price'] <= $effectivePrice) {
            return response()->json([
                'message' => 'Le prix barré doit être supérieur au prix de vente.',
                'errors' => ['compare_at_price' => ['Le prix barré doit être supérieur au prix de vente.']],
            ], 422);
        }

        DB::transaction(function () use ($variant, $data) {
            $variant->fill(array_intersect_key($data, array_flip(['price', 'compare_at_price'])))->save();

            foreach ($data['stocks'] ?? [] as $stock) {
                $level = StockLevel::firstOrNew(['variant_id' => $variant->id, 'location' => $stock['location']]);
                $level->on_hand = (int) $stock['on_hand'];
                if (isset($stock['safety_stock'])) {
                    $level->safety_stock = (int) $stock['safety_stock'];
                }
                $level->save();
            }
        });

        $run = $dispatcher->dispatch(new PushVariantToShopify($variant->sku));

        return (new ErpItemResource($variant->fresh(['product', 'stockLevels'])))
            ->additional(['sync_run_id' => $run->id])
            ->response()
            ->setStatusCode(202);
    }

    private function find(string $sku): Variant
    {
        return Variant::with(['product', 'stockLevels'])
            ->where('sku', SkuMapper::normalize($sku))
            ->firstOrFail();
    }
}
