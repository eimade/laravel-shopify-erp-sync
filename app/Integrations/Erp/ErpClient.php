<?php

namespace App\Integrations\Erp;

use App\Models\Order;

/**
 * Contrat d'accès à l'ERP (source de vérité prix/stock et comptabilité).
 *
 * L'implémentation fournie (DatabaseErpClient) pilote le mini-ERP simulé embarqué ;
 * un vrai ERP (Sage, Odoo, Cegid…) se brancherait en implémentant ce contrat.
 */
interface ErpClient
{
    public function item(string $sku): ?ErpItem;

    public function updatePrice(string $sku, string $price): void;

    /**
     * Enregistre la vente : décrément du stock et écriture comptable.
     * Doit être idempotent (une commande déjà comptabilisée est ignorée).
     */
    public function recordSale(Order $order): bool;
}
