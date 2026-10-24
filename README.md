# SyncHub — connecteur Laravel Shopify ↔ ERP/PIM

[![CI](https://github.com/eimade/laravel-shopify-erp-sync/actions/workflows/ci.yml/badge.svg)](https://github.com/eimade/laravel-shopify-erp-sync/actions/workflows/ci.yml)

SyncHub synchronise automatiquement **prix, stocks, fiches produits et commandes** entre l'ERP / le PIM
d'un détaillant et sa boutique **Shopify**, trace chaque échange et alerte l'équipe en cas d'échec.

> Démo 100 % hors-ligne : une boutique Shopify **simulée** (faux client respectant le même contrat que le
> client GraphQL réel) permet de tout lancer et tester sans compte ni clé Shopify.

---

## 1. Problématique (recueil des besoins)

Un détaillant mode/beauté de taille moyenne :

| Système | Rôle | Problème constaté |
|---|---|---|
| ERP | prix de vente, stocks par entrepôt, comptabilité | les prix/stocks sont ressaisis à la main dans Shopify |
| PIM | titres, descriptions, marques | fiches divergentes entre outils |
| Shopify | vente en ligne | ruptures affichées à tort, prix incohérents, commandes non remontées en compta |

Besoins exprimés par l'équipe e-commerce et la comptabilité :

1. **L'ERP est la source de vérité** pour le prix et le stock : toute modification ERP doit être visible sur Shopify en quelques secondes.
2. Le stock publié doit tenir compte du **stock de sécurité** et des **réservations**, sur **plusieurs entrepôts**.
3. Chaque **commande Shopify** doit décrémenter le stock ERP et être **comptabilisée une seule fois**, même si Shopify renvoie le webhook plusieurs fois.
4. Une modification de prix faite **directement dans Shopify** ne doit pas être écrasée silencieusement : c'est un **conflit** que l'équipe arbitre.
5. Chaque échange doit être **tracé** (payload, statut, erreur, durée) et **rejouable** ; les échecs définitifs doivent **alerter** l'équipe.
6. Le catalogue unifié doit être **consultable par un front JS** (API GraphQL).

## 2. Fonctionnalités

- **Connecteur Shopify Admin API GraphQL** (`GraphqlShopifyClient`) : produits, variantes, niveaux de stock, commandes ;
  **rate limit par coût** (`CostThrottle` lit `extensions.cost.throttleStatus`, attend juste ce qu'il faut, relance sur `THROTTLED`/429) ;
  **pagination par curseur** (`pageInfo { hasNextPage endCursor }`).
- **Webhooks** `orders/create` et `products/update` : vérification **HMAC-SHA256** (`X-Shopify-Hmac-Sha256`, comparaison à temps constant),
  **idempotence** par `X-Shopify-Webhook-Id` (contrainte d'unicité) + idempotence métier par ID de commande ; réponse immédiate `202`, traitement en file.
- **Mini-ERP simulé** : API REST (jeton `X-Erp-Token`) + **import CSV** (lignes invalides rejetées individuellement, rapport ligne par ligne) ;
  **mapping SKU ↔ variant ID** (normalisation des SKU, détection des SKU orphelins des deux côtés).
- **Jobs en file Redis** : retries **exponentiels** (10 s, 30 s, 90 s, 270 s — 5 tentatives), erreurs non rejouables envoyées directement en
  **dead-letter** (`failed_jobs` + statut `dead`), alerte e-mail à l'équipe, verrou par SKU (`WithoutOverlapping`).
- **Journal de synchronisation** (`sync_runs`) : flux, objet, statut, tentatives, payload, résultat, erreur, durée.
- **Back-office Filament** : tableau de bord de santé des connecteurs, journal filtrable avec bouton **Rejouer**,
  **résolution des conflits** (garder l'ERP / accepter Shopify), édition prix/stocks ERP, import CSV, fiches PIM, commandes.
- **API GraphQL interne (Lighthouse)** en lecture : catalogue unifié paginé, recherche, variante par SKU, santé des connecteurs.
- **Front JavaScript** (vanilla, sans build) consommant l'API GraphQL : `http://localhost:18080/`.
- **Mode démo hors-ligne** : `SHOPIFY_DRIVER=fake` (par défaut) ; commande `synchub:simulate-webhook` pour envoyer de vrais webhooks signés.
- **Réconciliation planifiée** (scheduler) : rattrapage horaire des commandes, relecture du catalogue et republication quotidiennes.

## 3. Schéma des flux

```
                    API REST /api/erp (X-Erp-Token)          Back-office Filament (/admin)
                    Import CSV  ─────────┐                   ┌──── édition prix/stock, rejouer, conflits
                                         ▼                   ▼
   ┌──────────────┐  prix/stock  ┌────────────────────────────────────┐
   │ Mini-ERP     │◄────────────►│              SyncHub               │
   │ (variants,   │  ventes      │                                    │
   │ stock_levels,│◄─────────────│  SyncDispatcher ─► sync_runs       │
   │ orders)      │              │        │ (journal)                 │
   └──────────────┘              │        ▼                           │
   ┌──────────────┐  fiches      │   File Redis ─► worker             │
   │ PIM          │─────────────►│   PushVariantToShopify     ────────┼──► productVariantsBulkUpdate
   │ (products)   │              │   PullShopifyCatalog       ◄───────┼─── products (pagination curseur)
   └──────────────┘              │   ImportShopifyOrder               │       inventorySetQuantities
                                 │   PullShopifyOrders        ◄───────┼─── orders (rattrapage)
                                 │   ProcessProductUpdate             │
                                 │        │ échec définitif           │        ┌──────────────────┐
                                 │        ▼                           │        │  Shopify Admin   │
                                 │   dead-letter + alerte e-mail      │        │  API GraphQL     │
                                 │                                    │        │  (ou faux client)│
     Webhooks orders/create ────►│ /api/webhooks/shopify              │◄───────┤                  │
     products/update (HMAC)      │  middleware HMAC + idempotence     │        └──────────────────┘
                                 │                                    │
     Front JS  ◄── /graphql ─────│ Lighthouse (lecture seule)         │
                                 └────────────────────────────────────┘
```

**Règles de synchronisation**

| Flux | Déclencheur | Règle |
|---|---|---|
| ERP → Shopify (prix, stock) | modification ERP (API, CSV, back-office), planif. quotidienne | stock publié = Σ entrepôts max(0, physique − réservé − sécurité) ; prix non poussé si un conflit de prix est ouvert |
| Shopify → mapping | lecture du catalogue, webhook `products/update` | rattachement par SKU normalisé ; écart de prix ⇒ conflit |
| Shopify → ERP (commandes) | webhook `orders/create`, rattrapage horaire | création unique par ID Shopify ; décrément du stock en partant de l'entrepôt le mieux pourvu ; passage en « comptabilisée » |

## 4. Architecture

Monolithe modulaire Laravel, en couches :

```
app/
├── Domain/                    règles métier pures (sans framework)
│   ├── StockCalculator.php    stock vendable, répartition d'une vente par entrepôt
│   ├── ShopifyGid.php         conversion ID REST <-> GID GraphQL
│   └── Enums/                 SyncStatus, SyncType, VariantSyncStatus, Conflict*
├── Models/                    Product, Variant, StockLevel, Order, OrderLine, SyncRun, WebhookReceipt, Conflict
├── Integrations/
│   ├── Shopify/               contrat ShopifyClient + GraphqlShopifyClient (réel) + FakeShopifyClient (démo/tests)
│   │                          CostThrottle (rate limit), DTO (Page, ShopifyProduct, ShopifyVariant, OrderData)
│   └── Erp/                   contrat ErpClient + DatabaseErpClient (mini-ERP)
├── Services/                  SkuMapper, OrderImporter, ConflictResolver, SyncDispatcher, CsvCatalogImporter, ConnectorHealth
├── Jobs/                      TrackedSyncJob (journal, retries, dead-letter) et 5 jobs concrets
├── Http/                      webhook (middleware HMAC), API REST ERP (FormRequest, Resource), middleware jeton
├── GraphQL/                   résolveur syncHealth (schéma : graphql/schema.graphql)
├── Filament/                  back-office (ressources, widget de santé)
└── Notifications/             alerte dead-letter
```

Le reste de l'application ne dépend que des **contrats** `ShopifyClient` et `ErpClient` : le choix de l'implémentation
se fait dans `AppServiceProvider` selon `SHOPIFY_DRIVER` (`fake` ou `graphql`).

**Docker Compose** : `app` (php-fpm 8.3), `nginx` (port **18080**), `mariadb` 11.4, `redis` 7, `worker` (`queue:work`), `scheduler` (`schedule:work`).

## 5. Stack

PHP 8.3 · Laravel 12 · Filament 5 · Lighthouse 6 (GraphQL) · MariaDB 11 · Redis 7 (Predis) · Shopify Admin API GraphQL · Webhooks ·
API REST · JavaScript (vanilla) · Docker Compose · GitHub Actions · PHPUnit 11 · Pint · PHPStan/Larastan niveau 6.

## 6. Lancement (1 commande)

```bash
docker compose up -d --build
```

Au premier démarrage le conteneur `app` génère la clé applicative (jamais versionnée), migre la base, charge les données
de démo et exécute un premier cycle de synchronisation.

| URL | Contenu |
|---|---|
| http://localhost:18080/ | front JS du catalogue unifié (API GraphQL) |
| http://localhost:18080/admin | back-office Filament — `admin@synchub.test` / `password` (compte de démo) |
| http://localhost:18080/graphql | API GraphQL (POST) |
| http://localhost:18080/api/erp/items | API REST ERP (en-tête `X-Erp-Token: demo-erp-token`) |

Données de démo (`database/seeders/data/erp-catalogue.csv`) : 8 produits, 13 SKU, 2 entrepôts. Volontairement :
- `FOUL-SOIE-01` n'existe pas dans Shopify ⇒ job en **dead-letter** + alerte ;
- `SERUM-VITC-30` a été soldé à la main dans Shopify (29,90 € au lieu de 34,50 €) ⇒ **conflit** à arbitrer ;
- `ROBE-LIN-L` est en rupture ERP ⇒ stock 0 publié.

### Sans Docker (développement)

```bash
composer install
cp .env.example .env && php artisan key:generate
# .env : QUEUE_CONNECTION=sync, SHOPIFY_WEBHOOK_SECRET=demo-webhook-secret, ERP_API_TOKEN=demo-erp-token
php artisan migrate --seed
php artisan serve --port=18080
```

## 7. Exemples d'appels

### API REST ERP

```bash
# Liste filtrée
curl -s -H "X-Erp-Token: demo-erp-token" "http://localhost:18080/api/erp/items?search=ROBE"

# Mise à jour prix + stock : 202 Accepted, synchronisation Shopify mise en file
curl -s -X PATCH -H "X-Erp-Token: demo-erp-token" -H "Content-Type: application/json" \
  -d '{"price": 69.90, "stocks": [{"location": "entrepot-lyon", "on_hand": 5}]}' \
  http://localhost:18080/api/erp/items/ROBE-LIN-S

# Import CSV (207 Multi-Status si des lignes sont rejetées)
curl -s -H "X-Erp-Token: demo-erp-token" -F "file=@database/seeders/data/erp-catalogue.csv" \
  http://localhost:18080/api/erp/import

# Journal : jobs en dead-letter, puis relance
curl -s -H "X-Erp-Token: demo-erp-token" "http://localhost:18080/api/erp/sync-runs?status=dead"
curl -s -X POST -H "X-Erp-Token: demo-erp-token" http://localhost:18080/api/erp/sync-runs/42/replay
```

### Webhook Shopify signé

```bash
# Simulation intégrée (passe par la vraie pile HTTP : HMAC, idempotence, file)
docker compose exec app php artisan synchub:simulate-webhook orders/create --sku=ROBE-LIN-M --qty=2
docker compose exec app php artisan synchub:simulate-webhook products/update --sku=CHEM-OXF-BL-M --price=49.00

# Ou à la main avec curl
BODY='{"id":1001,"name":"#1001","currency":"EUR","total_price":"79.90","line_items":[{"sku":"ROBE-LIN-S","title":"Robe","quantity":1,"price":"79.90"}]}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "demo-webhook-secret" -binary | base64)
curl -s -X POST http://localhost:18080/api/webhooks/shopify \
  -H "Content-Type: application/json" -H "X-Shopify-Topic: orders/create" \
  -H "X-Shopify-Webhook-Id: demo-1" -H "X-Shopify-Hmac-Sha256: $SIG" -d "$BODY"
# => {"status":"accepted","sync_run_id":…}   (rejoué : {"status":"duplicate",…})
```

### API GraphQL

```bash
curl -s http://localhost:18080/graphql -H "Content-Type: application/json" -d '{"query":"{ products(search: \"robe\", first: 5) { data { title priceFrom totalAvailable variants { sku price available syncStatus } } } syncHealth { shopifyOk deadLetters openConflicts } }"}'
```

### Commandes d'exploitation

```bash
php artisan synchub:pull-catalog          # mapping SKU + détection des conflits
php artisan synchub:push-all              # republication de tout l'ERP
php artisan synchub:pull-orders --since=2026-10-01T00:00:00Z
php artisan synchub:import-csv chemin/fichier.csv
```

## 8. Tests et qualité

```bash
php artisan test                 # tests unitaires + fonctionnels (SQLite mémoire, faux client Shopify)
vendor/bin/pint --test           # style
vendor/bin/phpstan analyse       # analyse statique niveau 6
```

Couverture fonctionnelle :
- **unitaires** : calcul de stock et répartition par entrepôt, normalisation SKU / comparaison de prix, GID, signature HMAC, rate limit par coût ;
- **webhooks** : signature valide / invalide / corps altéré / secret absent, idempotence (même webhook, même commande), topic inconnu, conflit de prix ;
- **jobs** : push ERP → Shopify, dead-letter + alerte, retry sur erreur transitoire, relance, pagination du catalogue, rattrapage des commandes, conflits ;
- **client GraphQL réel** contre `Http::fake` : pagination par curseur, `THROTTLED`, attente préventive, `userErrors`, 401/5xx ;
- **API REST** (auth, validation, import CSV, journal, relance) et **API GraphQL** ;
- **Filament** : rendu des pages, action « Rejouer », résolution de conflit, édition ERP déclenchant la synchro.

La CI GitHub Actions exécute Pint, PHPStan, les tests avec couverture (pcov) puis construit l'image Docker.

## 9. Choix techniques

- **Contrat `ShopifyClient` + faux client** : le métier est testable sans réseau ; le faux client stocke sa « boutique » dans le cache
  (Redis en Docker) pour que le serveur web et le worker partagent le même état.
- **ERP source de vérité, conflits explicites** : un prix modifié dans Shopify n'est ni écrasé ni accepté automatiquement ;
  tant que le conflit est ouvert, seul le stock est poussé.
- **Webhooks rapides** : on vérifie, on enregistre l'accusé de réception (`webhook_receipts`, unique) et on répond `202` ; le travail
  se fait dans la file (Shopify exige une réponse en moins de 5 s). Si la mise en file échoue, l'accusé est supprimé pour que la relivraison de Shopify soit traitée.
- **Double idempotence** : par ID de webhook (relivraisons) et par ID de commande (même commande reçue par webhook et par rattrapage).
- **Erreurs classées** : réseau / 5xx / `THROTTLED` ⇒ retry exponentiel ; `userErrors`, 401, SKU absent ⇒ dead-letter immédiat (inutile d'insister).
- **Throttling par coût** plutôt qu'un simple compteur de requêtes : c'est le modèle de l'API GraphQL Shopify.
- **Montants en `decimal`**, comparaison des prix en centimes.
- **Clé applicative générée au démarrage** : aucun secret dans le dépôt ; les valeurs du `docker-compose.yml` sont des valeurs de démo.

## 10. Procédure de débogage en production

1. **Tableau de bord** `/admin` : le widget *Santé des connecteurs* indique si Shopify répond (latence), le taux de succès 24 h, les dead-letters et conflits.
2. **Journal de synchro** : filtrer `Statut = Dead-letter` ; la fiche montre le **payload**, l'**erreur** et le nombre de tentatives.
3. Diagnostiquer selon l'erreur :
   - `SKU … introuvable dans Shopify` → créer la variante dans Shopify ou corriger le SKU dans l'ERP ;
   - `Accès refusé par Shopify (HTTP 401/403)` → jeton expiré ou scope manquant (`read/write_products`, `read/write_inventory`, `read_orders`) ;
   - `Erreur GraphQL … THROTTLED` répétée → réduire la taille des pages ou espacer la planification ;
   - `… refusé par Shopify : …` (userErrors) → donnée invalide côté ERP (prix négatif, emplacement inconnu).
4. Corriger la cause puis cliquer **Rejouer** (ou `POST /api/erp/sync-runs/{id}/replay`) : une nouvelle exécution liée à l'originale est créée.
5. Commande manquante ? `php artisan synchub:pull-orders --since=…` (idempotent). Webhooks reçus : table `webhook_receipts`.
6. Logs : `docker compose logs -f worker app` (les dead-letters sont journalisés en `critical`).
7. File bloquée : `docker compose exec app php artisan queue:failed` / `queue:retry all` ; vérifier `docker compose ps` (worker, redis).

## 11. Simulations

Aucun compte externe n'est nécessaire pour lancer ou tester le projet. Chaque système tiers est derrière une interface
et remplacé par une implémentation locale :

| Système réel | Contrat | Implémentation de démo | Ce qui est simulé |
|---|---|---|---|
| Boutique Shopify (Admin API GraphQL) | `App\Integrations\Shopify\ShopifyClient` | `FakeShopifyClient` (`SHOPIFY_DRIVER=fake`, défaut) | produits, variantes, stocks et commandes conservés dans le cache (Redis en Docker, mémoire en test), pagination par curseur reproduite. Le client réel `GraphqlShopifyClient` existe et est testé contre des réponses `Http::fake` (pagination, `THROTTLED`, 401/5xx, `userErrors`) |
| Webhooks envoyés par Shopify | middleware `VerifyShopifyWebhook` | commande `synchub:simulate-webhook` | génère un payload `orders/create` / `products/update`, le signe en HMAC avec le secret de démo et l'envoie à la vraie route HTTP |
| ERP du détaillant | `App\Integrations\Erp\ErpClient` | `DatabaseErpClient` (« mini-ERP ») | tables `variants` / `stock_levels` / `orders` dans la base SyncHub, exposées par une API REST et un import CSV |
| PIM | table `products` | fiches éditables dans Filament | titres, descriptions, marques |
| Alerte e-mail de l'équipe | notification `SyncJobDeadLettered` | `MAIL_MAILER=log` | l'e-mail est écrit dans les logs (`docker compose logs app worker`) |

Pour passer sur une vraie boutique, il suffit de changer `SHOPIFY_DRIVER=graphql` et de fournir les identifiants (section suivante).

## 12. Brancher une vraie boutique Shopify

1. Créer une application personnalisée sur une boutique de développement, scopes : `read_products`, `write_products`,
   `read_inventory`, `write_inventory`, `read_orders`, `read_locations`.
2. Variables (dans un `.env` non versionné ou l'environnement) : `SHOPIFY_DRIVER=graphql`, `SHOPIFY_SHOP_DOMAIN`, `SHOPIFY_ACCESS_TOKEN`,
   `SHOPIFY_LOCATION_ID` (GID de l'entrepôt Shopify), `SHOPIFY_WEBHOOK_SECRET`.
3. Déclarer les webhooks `orders/create` et `products/update` vers `https://<domaine>/api/webhooks/shopify`.
4. `php artisan synchub:pull-catalog` puis `php artisan synchub:push-all`.

## 13. Limites connues

- Le client GraphQL réel est testé contre des réponses simulées (`Http::fake`), pas contre une boutique réelle.
- Un seul emplacement Shopify est alimenté (somme des entrepôts ERP) ; pas de multi-location Shopify.
- Le budget de coût GraphQL est suivi par processus worker (pas de seau partagé entre plusieurs workers).
- Les prix barrés et le stock sont synchronisés, mais pas la création de produits Shopify depuis le PIM (les fiches PIM sont consultables/éditables et exposées en GraphQL).
- Pas de gestion des remboursements / annulations de commande.
- Authentification de l'API ERP par jeton partagé unique (pas de gestion fine des clients API).

---

Projet réalisé avec l'assistance de Claude Code (développement assisté par IA).
