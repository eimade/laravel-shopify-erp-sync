<?php

namespace Database\Seeders;

use App\Integrations\Shopify\FakeShopifyClient;
use App\Jobs\PullShopifyCatalog;
use App\Jobs\PushVariantToShopify;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\CsvCatalogImporter;
use App\Services\SyncDispatcher;
use Illuminate\Database\Seeder;

/**
 * Données de démonstration : un détaillant mode/beauté.
 *
 * - ERP : catalogue importé depuis database/seeders/data/erp-catalogue.csv (2 entrepôts).
 * - PIM : descriptions et marques des fiches produits.
 * - Boutique Shopify simulée : mêmes produits, sauf FOUL-SOIE-01 (jamais créé dans Shopify),
 *   et un prix modifié à la main dans Shopify sur SERUM-VITC-30 (conflit à arbitrer).
 */
class DatabaseSeeder extends Seeder
{
    public function run(CsvCatalogImporter $importer, SyncDispatcher $dispatcher): void
    {
        User::updateOrCreate(
            ['email' => 'admin@synchub.test'],
            ['name' => 'Admin SyncHub', 'password' => 'password'],
        );

        $importer->import(database_path('seeders/data/erp-catalogue.csv'));
        $this->enrichFromPim();

        if (config('synchub.shopify.driver') !== 'fake') {
            return; // Avec une vraie boutique, on se contente de l'ERP.
        }

        $this->seedFakeShopify(app(FakeShopifyClient::class));

        // Premier cycle : mapping SKU puis publication des prix/stocks ERP.
        $dispatcher->dispatch(new PullShopifyCatalog);
        Variant::orderBy('id')->pluck('sku')->each(fn (string $sku) => $dispatcher->dispatch(new PushVariantToShopify($sku)));
    }

    private function enrichFromPim(): void
    {
        $pim = [
            'robe-lin-ete' => ['Maison Lumen', 'Robes', 'Robe fluide en lin lavé, coupe évasée, idéale pour l\'été.'],
            'chemise-oxford' => ['Maison Lumen', 'Chemises', 'Chemise Oxford en coton peigné, col boutonné.'],
            'jean-droit-brut' => ['Atelier Indigo', 'Jeans', 'Jean coupe droite en denim brut 13 oz.'],
            'serum-vitamine-c' => ['Bio Éclat', 'Soins visage', 'Sérum concentré à 15 % de vitamine C pour un teint lumineux.'],
            'creme-hydratante' => ['Bio Éclat', 'Soins visage', 'Crème hydratante au beurre de karité, peaux sèches.'],
            'huile-argan-bio' => ['Bio Éclat', 'Soins corps', 'Huile d\'argan pressée à froid, certifiée bio.'],
            'eau-de-parfum-rose' => ['Bio Éclat', 'Parfums', 'Eau de parfum florale aux notes de rose de Damas.'],
            'foulard-soie' => ['Maison Lumen', 'Accessoires', 'Foulard 100 % soie, imprimé exclusif.'],
        ];

        foreach ($pim as $handle => [$vendor, $type, $description]) {
            Product::where('handle', $handle)->update(['vendor' => $vendor, 'product_type' => $type, 'description' => $description]);
        }
    }

    private function seedFakeShopify(FakeShopifyClient $fake): void
    {
        $fake->reset();

        Product::with('variants')->orderBy('id')->get()->each(function (Product $product) use ($fake) {
            $variants = $product->variants
                ->reject(fn (Variant $v) => $v->sku === 'FOUL-SOIE-01')
                ->map(fn (Variant $v) => [
                    'sku' => $v->sku,
                    // Promotion saisie à la main dans Shopify, absente de l'ERP.
                    'price' => $v->sku === 'SERUM-VITC-30' ? '29.90' : (string) $v->price,
                    'quantity' => 0,
                ])->values()->all();

            if ($variants !== []) {
                $fake->addProduct($product->handle, $product->title, $variants);
            }
        });
    }
}
