#!/usr/bin/env bash
#
# AffMoment TDS — Plan B: pull the pre-built image and run it (NO build on the
# server). Use when the server is too slow/hostile to build the image itself.
#
#   bash deploy/pull-run.sh
#
# Pulls ghcr.io/desmoond12/slimtds:latest (built + verified elsewhere), tags it
# as the local image the compose files expect, then brings the stack up,
# migrates, and creates the first admin. No compilation happens here.
#
set -uo pipefail

say()  { printf '\n\033[1;34m▶ %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m! %s\033[0m\n' "$*"; }
die()  { printf '\n\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
retry(){ local n=$1 s=$2; shift 2; local i=1; until "$@"; do [ "$i" -ge "$n" ] && return 1; warn "повтор $i/$n через ${s}s…"; sleep "$s"; i=$((i+1)); done; }

IMAGE="ghcr.io/desmoond12/slimtds:latest"
LOCAL_TAG="slimtds:local"

[ "$(id -u)" = "0" ] || die "Запусти от root: sudo bash deploy/pull-run.sh"
cd "$(dirname "$0")/.." || die "не могу перейти в папку трекера"
[ -f docker-compose.yml ] || die "запускай из папки трекера"

# ── DNS: make sure the daemon can resolve for the pull ─────────────────────
say "Шаг 1/5 — DNS для загрузки образа"
sysctl -w net.ipv6.conf.all.disable_ipv6=1 >/dev/null 2>&1 || true
sysctl -w net.ipv6.conf.default.disable_ipv6=1 >/dev/null 2>&1 || true
# The local systemd-resolved stub resolves reliably on this class of VPS where
# external :53 is blocked. Point the daemon at it and restart so it re-reads.
if [ -e /run/systemd/resolve/stub-resolv.conf ]; then
    chattr -i /etc/resolv.conf 2>/dev/null || true
    ln -sf /run/systemd/resolve/stub-resolv.conf /etc/resolv.conf
    systemctl restart docker 2>/dev/null || true
    sleep 3
fi
ok "DNS готов"

# ── Pull the pre-built image ───────────────────────────────────────────────
say "Шаг 2/5 — скачиваю готовый образ (без сборки)"
retry 10 8 docker pull "$IMAGE" || die "не смог скачать $IMAGE — проверь, что пакет публичный на GitHub"
docker tag "$IMAGE" "$LOCAL_TAG"
ok "образ на месте: $LOCAL_TAG"

# ── Compose pair from DEPLOY_MODE ──────────────────────────────────────────
DEPLOY_MODE=$(grep -E '^DEPLOY_MODE=' .env 2>/dev/null | head -1 | cut -d= -f2)
case "$DEPLOY_MODE" in
    cf_flex|cf_full) COMPOSE="-f docker-compose.yml -f docker-compose.prod.cf.yml" ;;
    direct)          COMPOSE="-f docker-compose.yml -f docker-compose.prod.direct.yml" ;;
    *)               COMPOSE="" ; warn "DEPLOY_MODE=$DEPLOY_MODE — dev-режим" ;;
esac

# ── Up (no build — use the pulled image) ───────────────────────────────────
say "Шаг 3/5 — поднимаю контейнеры (без сборки)"
export COMPOSE_PARALLEL_LIMIT=1
# db + geoipupdate are small external images; pull them serially first.
retry 8 8 docker compose $COMPOSE pull --ignore-buildable || warn "часть образов не дотянулась — up попробует ещё"
retry 6 10 docker compose $COMPOSE up -d --no-build || die "не удалось поднять контейнеры"
ok "контейнеры подняты"

# ── DB + admin ─────────────────────────────────────────────────────────────
say "Шаг 4/5 — база, партиции, администратор"
for _ in $(seq 1 30); do docker compose $COMPOSE exec -T app php -v >/dev/null 2>&1 && break; sleep 2; done
retry 5 5 docker compose $COMPOSE run --rm --entrypoint="" app vendor/bin/phinx migrate -c phinx.php || warn "миграции с ошибкой"
docker compose $COMPOSE exec -T app php bin/console partitions:rotate || warn "partitions:rotate с ошибкой"
docker compose $COMPOSE exec -T app php bin/console admin:init || warn "admin:init (возможно, админ уже есть)"

# ── Summary ────────────────────────────────────────────────────────────────
say "Шаг 5/5 — готово"
DOMAIN=$(grep -E '^DOMAIN=' .env | head -1 | cut -d= -f2)
LOGIN=$(grep -E '^ADMIN_LOGIN=' .env | head -1 | cut -d= -f2)
PASS=$(grep -E '^ADMIN_PASSWORD=' .env | head -1 | cut -d= -f2)
printf '\n\033[1;32m═══════════════════════════════════════════════\033[0m\n'
ok "Трекер запущен из готового образа."
printf '   Адрес входа : https://%s/admin/login\n' "$DOMAIN"
printf '   Логин       : %s\n' "${LOGIN:-admin}"
printf '   Пароль      : %s\n' "$PASS"
printf '\033[1;32m═══════════════════════════════════════════════\033[0m\n'
