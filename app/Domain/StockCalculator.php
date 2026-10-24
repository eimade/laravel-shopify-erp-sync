<?php

namespace App\Domain;

/**
 * Calcule la quantité vendable publiée sur Shopify à partir des stocks ERP.
 *
 * Règle métier : pour chaque entrepôt, disponible = physique - réservé - stock de sécurité,
 * jamais négatif ; la quantité publiée est la somme des entrepôts.
 */
final class StockCalculator
{
    /**
     * @param  iterable<array{on_hand:int, reserved?:int, safety_stock?:int}>  $levels
     */
    public function available(iterable $levels): int
    {
        $total = 0;
        foreach ($levels as $level) {
            $total += max(0, (int) $level['on_hand'] - (int) ($level['reserved'] ?? 0) - (int) ($level['safety_stock'] ?? 0));
        }

        return $total;
    }

    /**
     * Répartit une vente sur les entrepôts, en commençant par celui qui a le plus de stock.
     *
     * @param  array<string, int>  $onHandByLocation  emplacement => stock physique
     * @return array<string, int> emplacement => quantité à décrémenter
     */
    public function allocate(array $onHandByLocation, int $quantity): array
    {
        arsort($onHandByLocation);
        $allocation = [];
        $remaining = $quantity;

        foreach ($onHandByLocation as $location => $onHand) {
            if ($remaining <= 0) {
                break;
            }
            $take = min(max(0, $onHand), $remaining);
            if ($take > 0) {
                $allocation[$location] = $take;
                $remaining -= $take;
            }
        }

        // Survente : le reliquat est imputé au premier entrepôt (stock négatif visible dans l'ERP).
        if ($remaining > 0 && $onHandByLocation !== []) {
            $first = array_key_first($onHandByLocation);
            $allocation[$first] = ($allocation[$first] ?? 0) + $remaining;
        }

        return $allocation;
    }
}
