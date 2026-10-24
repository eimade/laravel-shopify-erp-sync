#!/usr/bin/env bash
# Point d'entrée commun aux conteneurs app / worker / scheduler.
# CONTAINER_ROLE=app : génère la clé, migre, seed la démo au premier démarrage, publie les assets.
set -euo pipefail

KEY_FILE=/var/www/keys/app_key
ROLE="${CONTAINER_ROLE:-app}"

# Clé applicative générée au premier lancement (jamais versionnée), partagée via un volume.
if [ -z "${APP_KEY:-}" ]; then
  if [ "$ROLE" = "app" ] && [ ! -s "$KEY_FILE" ]; then
    php -r 'echo "base64:".base64_encode(random_bytes(32));' > "$KEY_FILE"
  fi
  until [ -s "$KEY_FILE" ]; do echo "En attente de la clé applicative…"; sleep 2; done
  export APP_KEY="$(cat "$KEY_FILE")"
fi

if [ "$ROLE" = "app" ]; then
  php artisan migrate --force
  if [ "${SEED_DEMO:-true}" = "true" ] && [ "$(php artisan uimadsync:is-seeded)" = "no" ]; then
    php artisan db:seed --force
  fi
  # Fichiers statiques servis par nginx (volume partagé).
  cp -a public/. /var/www/public-shared/
  touch /var/www/keys/ready
else
  until [ -f /var/www/keys/ready ]; do echo "En attente de l'initialisation de l'application…"; sleep 2; done
fi

php artisan optimize

exec "$@"
