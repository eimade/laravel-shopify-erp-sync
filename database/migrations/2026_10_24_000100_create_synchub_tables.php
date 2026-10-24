<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catalogue (fiches PIM) : un produit regroupe des variantes.
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('vendor')->nullable();
            $table->string('product_type')->nullable();
            $table->string('shopify_product_id')->nullable()->unique();
            $table->timestamps();
        });

        // Variantes : le SKU est la clé de rapprochement ERP <-> Shopify.
        Schema::create('variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->unique();
            $table->string('title');
            $table->decimal('price', 10, 2);
            $table->decimal('compare_at_price', 10, 2)->nullable();
            $table->string('shopify_variant_id')->nullable()->unique();
            $table->string('shopify_inventory_item_id')->nullable();
            $table->string('sync_status')->default('pending')->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        // Stock ERP par entrepôt (source de vérité).
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->string('location');
            $table->integer('on_hand')->default(0);
            $table->integer('reserved')->default(0);
            $table->integer('safety_stock')->default(0);
            $table->timestamps();
            $table->unique(['variant_id', 'location']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('shopify_order_id')->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('currency', 3);
            $table->decimal('total_price', 12, 2);
            $table->string('financial_status')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->string('accounting_status')->default('pending')->index();
            $table->timestamp('accounting_exported_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku')->nullable();
            $table->string('title');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->timestamps();
        });

        // Journal de synchronisation : une ligne par exécution de job.
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index();
            $table->string('direction');
            $table->string('subject')->nullable()->index();
            $table->string('status')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('job_class');
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->foreignId('replayed_from_id')->nullable()->constrained('sync_runs')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // Webhooks reçus : l'unicité de webhook_id garantit l'idempotence.
        Schema::create('webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('webhook_id')->unique();
            $table->string('topic')->index();
            $table->string('shop_domain')->nullable();
            $table->json('payload');
            $table->foreignId('sync_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // Conflits détectés entre la valeur ERP et la valeur Shopify.
        Schema::create('conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->string('field');
            $table->string('erp_value')->nullable();
            $table->string('shopify_value')->nullable();
            $table->string('source');
            $table->string('status')->default('open')->index();
            $table->string('resolution')->nullable();
            $table->string('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['conflicts', 'webhook_receipts', 'sync_runs', 'order_lines', 'orders', 'stock_levels', 'variants', 'products'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
