<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Erreur fonctionnelle qu'un nouvel essai ne corrigera pas (ex. SKU absent de Shopify) :
 * le job part directement en dead-letter pour traitement humain.
 */
class NonRetryableSyncException extends RuntimeException {}
