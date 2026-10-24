<?php

namespace App\Integrations\Shopify\Data;

/**
 * Page de résultats paginée par curseur (pageInfo GraphQL).
 *
 * @template T
 */
final readonly class Page
{
    /**
     * @param  list<T>  $items
     */
    public function __construct(
        public array $items,
        public bool $hasNextPage,
        public ?string $endCursor,
    ) {}
}
