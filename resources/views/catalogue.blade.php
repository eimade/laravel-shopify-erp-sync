<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Uimad Sync — Catalogue unifié</title>
    <link rel="stylesheet" href="{{ asset('css/catalogue.css') }}">
</head>
<body>
<header>
    <h1>Uimad Sync <small>catalogue unifié ERP + PIM + Shopify</small></h1>
    <nav><a href="/admin">Back-office Filament</a></nav>
</header>

<section id="health" class="health" aria-live="polite">Chargement de l'état des connecteurs…</section>

<form id="search-form" role="search">
    <input id="search" type="search" placeholder="Rechercher un produit ou un SKU" aria-label="Recherche">
    <button type="submit">Rechercher</button>
</form>

<main id="catalogue" class="grid" aria-live="polite"></main>
<p id="error" class="error" hidden></p>

<script src="{{ asset('js/catalogue.js') }}" defer></script>
</body>
</html>
