// Front JS minimal consommant l'API GraphQL interne (Lighthouse) de SyncHub.
(() => {
    'use strict';

    const CATALOGUE_QUERY = `
      query Catalogue($search: String) {
        products(search: $search, first: 50) {
          data {
            handle title vendor productType priceFrom totalAvailable
            variants { sku title price compareAtPrice available syncStatus lastSyncedAt }
          }
          paginatorInfo { total }
        }
      }`;

    const HEALTH_QUERY = `
      query { syncHealth { shopifyOk shopName shopifyDriver successRate24h deadLetters openConflicts unmappedVariants } }`;

    const STATUS_LABELS = {
        Synced: 'synchronisé', Pending: 'en attente', Unmapped: 'absent de Shopify',
        Conflict: 'conflit', Error: 'erreur',
    };

    const euros = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' });

    async function graphql(query, variables = {}) {
        const response = await fetch('/graphql', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ query, variables }),
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        const body = await response.json();
        if (body.errors) {
            throw new Error(body.errors.map((e) => e.message).join(' ; '));
        }
        return body.data;
    }

    function el(tag, attrs = {}, ...children) {
        const node = document.createElement(tag);
        Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, v));
        children.flat().forEach((c) => node.append(c instanceof Node ? c : document.createTextNode(String(c))));
        return node;
    }

    function renderHealth(h) {
        const box = document.getElementById('health');
        box.className = `health ${h.shopifyOk ? 'ok' : 'ko'}`;
        box.replaceChildren(
            el('strong', {}, h.shopifyOk ? `Shopify connecté (${h.shopName}, mode ${h.shopifyDriver})` : 'Shopify injoignable'),
            ` · succès 24 h : ${h.successRate24h} % · dead-letters : ${h.deadLetters}`,
            ` · conflits ouverts : ${h.openConflicts} · SKU non publiés : ${h.unmappedVariants}`,
        );
    }

    function renderProducts(products) {
        const root = document.getElementById('catalogue');
        if (products.length === 0) {
            root.replaceChildren(el('p', {}, 'Aucun produit trouvé.'));
            return;
        }
        root.replaceChildren(...products.map((p) => el('article', { class: 'card' },
            el('h2', {}, p.title),
            el('p', { class: 'meta' }, `${p.vendor ?? ''} · ${p.productType ?? ''} · dès ${euros.format(p.priceFrom)} · ${p.totalAvailable} en stock`),
            el('table', {},
                el('thead', {}, el('tr', {}, ['SKU', 'Variante', 'Prix', 'Dispo', 'Sync'].map((h) => el('th', {}, h)))),
                el('tbody', {}, p.variants.map((v) => el('tr', {},
                    el('td', {}, v.sku),
                    el('td', {}, v.title),
                    el('td', {}, euros.format(v.price), v.compareAtPrice ? el('s', {}, ` ${euros.format(v.compareAtPrice)}`) : ''),
                    el('td', { class: v.available === 0 ? 'out' : '' }, v.available === 0 ? 'rupture' : v.available),
                    el('td', {}, el('span', { class: `badge ${v.syncStatus.toLowerCase()}` }, STATUS_LABELS[v.syncStatus] ?? v.syncStatus)),
                ))),
            ),
        )));
    }

    async function load(search = null) {
        const error = document.getElementById('error');
        error.hidden = true;
        try {
            const [catalogue, health] = await Promise.all([
                graphql(CATALOGUE_QUERY, { search }),
                graphql(HEALTH_QUERY),
            ]);
            renderProducts(catalogue.products.data);
            renderHealth(health.syncHealth);
        } catch (e) {
            error.textContent = `Impossible de charger le catalogue : ${e.message}`;
            error.hidden = false;
        }
    }

    document.getElementById('search-form').addEventListener('submit', (event) => {
        event.preventDefault();
        load(document.getElementById('search').value.trim() || null);
    });

    load();
})();
