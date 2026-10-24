<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncRun;
use App\Services\SyncDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Journal de synchronisation exposé en REST (supervision externe, scripts d'exploitation).
 */
class SyncRunController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:queued,running,succeeded,failed,dead'],
            'type' => ['nullable', 'in:push_variant,pull_catalog,product_update,import_order,pull_orders'],
            'subject' => ['nullable', 'string', 'max:100'],
        ]);

        $runs = SyncRun::query()
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($validated['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($validated['subject'] ?? null, fn ($q, $s) => $q->where('subject', $s))
            ->latest('id')
            ->paginate(50);

        return response()->json($runs);
    }

    public function show(SyncRun $syncRun): JsonResponse
    {
        return response()->json($syncRun);
    }

    public function replay(SyncRun $syncRun, SyncDispatcher $dispatcher): JsonResponse
    {
        try {
            $run = $dispatcher->replay($syncRun);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($run, 202);
    }
}
