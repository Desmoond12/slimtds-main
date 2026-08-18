#!/usr/bin/env bash
#
# AffMoment TDS — one-command server installer.
#
# Run from the repo root on a fresh Ubuntu/Debian server:
#     bash deploy/setup.sh
#
# It installs Docker + make + git if missing, writes .env from your answers,
# starts the stack, runs migrations, creates the first admin, and prints the
# login details. Safe to re-run: it won't overwrite an existing .env and skips
# tools already installed.
#
set -euo pipefail

say()  { printf '\n\033[1;34m▶ %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m! %s\033[0m\n' "$*"; }
die()  { printf '\n\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" = "0" ] || die "Запусти от root:  sudo bash deploy/setup.sh"

# Move to repo root (this script lives in deploy/).
cd "$(dirname "$0")/.."
[ -f docker-compose.yml ] || die "Не вижу файлов трекера. Запускай из папки, куда скачан трекер."

# ── 1. System packages ────────────────────────────────────────────────────
say "Шаг 1/6 — базовые пакеты (make, git, curl)"
export DEBIAN_FRONTEND=noninteractive
# Some VPS resolve to IPv6 but have no working IPv6 route, which makes apt/curl
# "could not resolve host" intermittently. Force IPv4 for package downloads —
# non-destructive, doesn't touch the rest of the network stack.
echo 'Acquire::ForceIPv4 "true";' > /etc/apt/apt.conf.d/99force-ipv4
apt-get update -qq
apt-get install -y -qq make git curl ca-certificates gnupg >/dev/null
ok "make / git / curl готовы"

# ── 2. Docker ─────────────────────────────────────────────────────────────
say "Шаг 2/6 — Docker"
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    ok "Docker уже установлен"
else
    # Prefer the distro's own docker.io + docker-compose-v2: they come from the
    # Ubuntu/Debian mirrors the box already reaches, so this sidesteps
    # download.docker.com entirely (that host is IPv6-flaky on some VPS and
    # stalls the official-repo path). Fall back to Docker's official repo only
    # if the distro packages aren't available.
    if apt-get install -y -qq docker.io docker-compose-v2 >/dev/null 2>&1; then
        systemctl enable --now docker >/dev/null 2>&1 || true
        ok "Docker установлен (из репозитория дистрибутива)"
    else
        warn "docker.io недоступен — ставлю из официального репозитория Docker"
        install -m 0755 -d /etc/apt/keyrings
        rm -f /etc/apt/keyrings/docker.gpg
        . /etc/os-release
        distro="${ID:-ubuntu}"; [ "$distro" = "debian" ] || distro="ubuntu"
        curl -fsSL --ipv4 "https://download.docker.com/linux/$distro/gpg" | gpg --dearmor --yes -o /etc/apt/keyrings/docker.gpg
        chmod a+r /etc/apt/keyrings/docker.gpg
        echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/$distro ${VERSION_CODENAME} stable" \
            > /etc/apt/sources.list.d/docker.list
        apt-get update -qq
        apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-compose-plugin >/dev/null
        ok "Docker установлен (из официального репозитория)"
    fi
fi

# ── 3. .env ───────────────────────────────────────────────────────────────
say "Шаг 3/6 — настройки (.env)"
if [ -f .env ]; then
    warn ".env уже существует — оставляю как есть (значения не трогаю)"
else
    cp .env.example .env
    # APP_SECRET + ADMIN_PASSWORD via the container's PHP (no host PHP needed).
    APP_SECRET=$(docker run --rm composer:2 php -r 'echo bin2hex(random_bytes(32));' 2>/dev/null || openssl rand -hex 32)
    ADMIN_PASSWORD=$(openssl rand -hex 8)
    DB_PASSWORD=$(openssl rand -hex 12)

    printf 'Домен трекера (например track.mysite.com), Enter чтобы пропустить: '
    read -r DOMAIN_IN || true
    DOMAIN_IN="${DOMAIN_IN:-tds.local}"

    printf 'Режим: 1) за Cloudflare [по умолчанию]  2) напрямую (Let'\''s Encrypt). Выбор [1]: '
    read -r MODE_IN || true
    case "${MODE_IN:-1}" in
        2) DEPLOY_MODE="direct" ;;
        *) DEPLOY_MODE="cf_flex" ;;
    esac

    set_env() { # key value
        if grep -qE "^$1=" .env; then
            sed -i "s|^$1=.*|$1=$2|" .env
        else
            printf '%s=%s\n' "$1" "$2" >> .env
        fi
    }
    set_env APP_ENV        prod
    set_env APP_DEBUG      false
    set_env DEPLOY_MODE    "$DEPLOY_MODE"
    set_env DOMAIN         "$DOMAIN_IN"
    set_env APP_URL        "https://$DOMAIN_IN"
    set_env APP_SECRET     "$APP_SECRET"
    set_env ADMIN_PASSWORD "$ADMIN_PASSWORD"
    set_env DB_PASSWORD    "$DB_PASSWORD"
    ok ".env создан (режим $DEPLOY_MODE, домен $DOMAIN_IN)"
fi

DEPLOY_MODE=$(grep -E '^DEPLOY_MODE=' .env | head -1 | cut -d= -f2)
case "$DEPLOY_MODE" in
    cf_flex|cf_full) COMPOSE="-f docker-compose.yml -f docker-compose.prod.cf.yml" ;;
    direct)          COMPOSE="-f docker-compose.yml -f docker-compose.prod.direct.yml" ;;
    *)               COMPOSE="" ; warn "DEPLOY_MODE=$DEPLOY_MODE — использую dev-режим" ;;
esac

# ── 4. Start the stack ────────────────────────────────────────────────────
say "Шаг 4/6 — сборка и запуск (первый раз это несколько минут)"
docker compose $COMPOSE up -d --build
ok "Контейнеры запущены"

# Wait for the app container to be up before running console commands.
say "Шаг 5/6 — база данных и партиции"
for i in $(seq 1 30); do
    if docker compose $COMPOSE exec -T app php -v >/dev/null 2>&1; then break; fi
    sleep 2
done
docker compose $COMPOSE run --rm --entrypoint="" app vendor/bin/phinx migrate -c phinx.php
docker compose $COMPOSE exec -T app php bin/console partitions:rotate
ok "Схема и партиции готовы"

# ── 6. First admin ────────────────────────────────────────────────────────
say "Шаг 6/6 — администратор"
docker compose $COMPOSE exec -T app php bin/console admin:init || warn "admin:init: возможно, админ уже создан"

DOMAIN=$(grep -E '^DOMAIN=' .env | head -1 | cut -d= -f2)
LOGIN=$(grep -E '^ADMIN_LOGIN=' .env | head -1 | cut -d= -f2)
PASS=$(grep -E '^ADMIN_PASSWORD=' .env | head -1 | cut -d= -f2)

printf '\n\033[1;32m═══════════════════════════════════════════════\033[0m\n'
ok "Готово! Трекер установлен."
printf '   Адрес входа : https://%s/admin/login\n' "$DOMAIN"
printf '   Логин       : %s\n' "${LOGIN:-admin}"
printf '   Пароль      : %s\n' "$PASS"
printf '\033[1;32m═══════════════════════════════════════════════\033[0m\n'
printf 'Дальше: смени пароль в /admin/settings. Инструкция оператора — в docs/.\n'
if [ "$DEPLOY_MODE" = "cf_flex" ] || [ "$DEPLOY_MODE" = "cf_full" ]; then
    printf 'Режим Cloudflare: домен %s должен быть проксирован через Cloudflare (оранжевое облачко), SSL = Flexible.\n' "$DOMAIN"
fi
