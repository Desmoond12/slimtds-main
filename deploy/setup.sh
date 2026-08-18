#!/usr/bin/env bash
#
# AffMoment TDS — bulletproof one-command server installer.
#
#   bash deploy/setup.sh
#
# Designed to be SLOW BUT RELIABLE on any VPS, including hosts with hostile
# networking (external DNS blocked, IPv6 advertised but dead, resolvers that
# choke on parallel queries). It:
#   • disables broken IPv6,
#   • probes which DNS resolver actually works and locks it in,
#   • runs a local caching resolver (dnsmasq) so the host AND build containers
#     resolve through one cache instead of hammering a flaky upstream,
#   • pulls every image one at a time with retries,
#   • retries every network step.
# Safe to re-run: it never clobbers an existing .env and skips finished work.
#
set -uo pipefail

say()  { printf '\n\033[1;34m▶ %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m! %s\033[0m\n' "$*"; }
die()  { printf '\n\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

# retry <attempts> <sleep_seconds> <command...>
retry() {
    local n=$1 s=$2; shift 2
    local i=1
    until "$@"; do
        if [ "$i" -ge "$n" ]; then return 1; fi
        warn "не вышло (попытка $i/$n) — жду ${s}s и повторяю…"
        sleep "$s"; i=$((i+1))
    done
    return 0
}

[ "$(id -u)" = "0" ] || die "Запусти от root:  sudo bash deploy/setup.sh"
cd "$(dirname "$0")/.." || die "не могу перейти в папку трекера"
[ -f docker-compose.yml ] || die "запускай из папки трекера (нет docker-compose.yml)"

TEST_HOST="registry-1.docker.io"

# ══ 1. Базовые пакеты ═══════════════════════════════════════════════════════
say "Шаг 1/8 — базовые пакеты (make, git, curl, dnsutils, dnsmasq)"
export DEBIAN_FRONTEND=noninteractive
# Force IPv4 for apt — dead-IPv6 hosts otherwise stall package downloads too.
echo 'Acquire::ForceIPv4 "true";' > /etc/apt/apt.conf.d/99force-ipv4
retry 5 5 apt-get update -qq || warn "apt update с ошибками — иду дальше"
retry 5 5 apt-get install -y -qq make git curl ca-certificates gnupg dnsutils dnsmasq \
    || warn "часть пакетов не встала — продолжаю, ретраи ниже подстрахуют"
# dnsmasq's default unit grabs :53 and fights systemd-resolved; we configure it
# properly in step 5, so stop it for now to avoid a noisy failed state.
systemctl stop dnsmasq 2>/dev/null || true
ok "пакеты готовы"

# ══ 2. Отключаем IPv6 ═══════════════════════════════════════════════════════
say "Шаг 2/8 — отключаю IPv6 (частая причина 'could not resolve' на VPS)"
cat > /etc/sysctl.d/99-tds-disable-ipv6.conf <<'EOF'
net.ipv6.conf.all.disable_ipv6 = 1
net.ipv6.conf.default.disable_ipv6 = 1
net.ipv6.conf.lo.disable_ipv6 = 1
EOF
sysctl -p /etc/sysctl.d/99-tds-disable-ipv6.conf >/dev/null 2>&1 || true
ok "IPv6 выключен (и сейчас, и после перезагрузки)"

# ══ 3. Находим рабочий DNS ══════════════════════════════════════════════════
say "Шаг 3/8 — ищу DNS-резолвер, который реально отвечает"
dns_ok() { nslookup -timeout=3 -retry=1 "$TEST_HOST" "$1" >/dev/null 2>&1; }

UPSTREAMS=()
# (a) local systemd-resolved stub — the path that works when the provider
#     blocks direct :53 to public DNS (it uses DoT/its own uplinks).
if systemctl is-active --quiet systemd-resolved 2>/dev/null; then
    systemctl restart systemd-resolved 2>/dev/null || true
    sleep 1
    dns_ok 127.0.0.53 && UPSTREAMS+=("127.0.0.53")
fi
# (b) public resolvers — work on normal hosts, blocked on some; we test.
for r in 1.1.1.1 8.8.8.8 9.9.9.9; do dns_ok "$r" && UPSTREAMS+=("$r"); done
# (c) the default gateway, in case it runs a local resolver.
GW=$(ip route 2>/dev/null | awk '/^default/ {print $3; exit}')
[ -n "${GW:-}" ] && dns_ok "$GW" && UPSTREAMS+=("$GW")

# de-dup, preserving order
if [ "${#UPSTREAMS[@]}" -gt 0 ]; then
    mapfile -t UPSTREAMS < <(printf '%s\n' "${UPSTREAMS[@]}" | awk '!s[$0]++')
fi
# absolute fallback so we never end up with nothing
if [ "${#UPSTREAMS[@]}" -eq 0 ]; then
    warn "ни один DNS не ответил на тесте — беру системный + публичные вслепую"
    UPSTREAMS=("127.0.0.53" "1.1.1.1" "8.8.8.8")
fi
ok "рабочие DNS: ${UPSTREAMS[*]}"

# ══ 4. Docker ═══════════════════════════════════════════════════════════════
say "Шаг 4/8 — Docker"
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    ok "Docker уже установлен"
else
    if retry 3 5 apt-get install -y -qq docker.io docker-compose-v2; then
        ok "Docker (из репозитория дистрибутива)"
    else
        warn "docker.io недоступен — ставлю из официального репозитория Docker"
        install -m 0755 -d /etc/apt/keyrings; rm -f /etc/apt/keyrings/docker.gpg
        . /etc/os-release; distro="${ID:-ubuntu}"; [ "$distro" = debian ] || distro=ubuntu
        retry 3 5 bash -c "curl -fsSL --ipv4 https://download.docker.com/linux/$distro/gpg | gpg --dearmor --yes -o /etc/apt/keyrings/docker.gpg"
        echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/$distro ${VERSION_CODENAME} stable" > /etc/apt/sources.list.d/docker.list
        retry 3 5 apt-get update -qq
        retry 3 5 apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-compose-plugin \
            || die "не смог поставить Docker ни одним способом"
        ok "Docker (официальный репозиторий)"
    fi
fi
systemctl enable --now docker >/dev/null 2>&1 || true
# Give the default bridge (docker0 = 172.17.0.1) a moment to come up — the
# container-side resolver below binds it.
for _ in $(seq 1 15); do ip addr show docker0 >/dev/null 2>&1 && break; sleep 1; done

# ══ 5. Локальный кэширующий DNS для хоста И контейнеров ═════════════════════
say "Шаг 5/8 — поднимаю локальный DNS-кэш (dnsmasq) и фиксирую его"
# dnsmasq listens on loopback (for the host / Docker daemon pulls) and on the
# docker0 bridge IP (for build containers — composer/bun install need DNS too,
# and containers cannot reach the host's 127.0.0.53). It forwards to the
# upstream(s) we proved work, and caches — so parallel bursts hit the cache
# instead of drowning a fragile resolver.
{
    echo "no-resolv"
    echo "bind-dynamic"
    echo "listen-address=127.0.0.1"
    echo "listen-address=172.17.0.1"
    echo "cache-size=10000"
    echo "min-cache-ttl=120"
    for u in "${UPSTREAMS[@]}"; do echo "server=$u"; done
} > /etc/dnsmasq.d/tds.conf
systemctl enable dnsmasq >/dev/null 2>&1 || true
if retry 3 3 systemctl restart dnsmasq; then
    ok "dnsmasq запущен (кэш на 127.0.0.1 и 172.17.0.1)"
    # Host resolver → dnsmasq, then lock it so nothing reverts it.
    chattr -i /etc/resolv.conf 2>/dev/null || true
    rm -f /etc/resolv.conf
    printf 'nameserver 127.0.0.1\noptions timeout:5 attempts:5\n' > /etc/resolv.conf
    chattr +i /etc/resolv.conf 2>/dev/null || true
    # Docker daemon → tell containers to resolve via dnsmasq on the bridge.
    mkdir -p /etc/docker
    printf '{ "dns": ["172.17.0.1", "127.0.0.1"] }\n' > /etc/docker/daemon.json
    DNS_MODE="dnsmasq"
else
    warn "dnsmasq не поднялся — включаю запасной план (прямые DNS + ретраи)"
    chattr -i /etc/resolv.conf 2>/dev/null || true
    rm -f /etc/resolv.conf
    { for u in "${UPSTREAMS[@]}"; do echo "nameserver $u"; done
      echo "options timeout:5 attempts:5 rotate"; } > /etc/resolv.conf
    chattr +i /etc/resolv.conf 2>/dev/null || true
    mkdir -p /etc/docker
    { printf '{ "dns": ['
      first=1; for u in "${UPSTREAMS[@]}"; do
        case "$u" in 127.*) continue;; esac   # containers can't use host loopback
        [ $first -eq 1 ] || printf ', '; printf '"%s"' "$u"; first=0
      done
      [ $first -eq 1 ] && printf '"1.1.1.1"'
      printf '] }\n'; } > /etc/docker/daemon.json
    DNS_MODE="direct"
fi
# Restart Docker so it re-reads resolv.conf + daemon.json.
retry 3 3 systemctl restart docker || warn "docker restart с ошибкой — иду дальше"
sleep 3
# Smoke test: can Docker actually pull anything now?
say "проверяю, что Docker резолвит и качает (тяну крошечный hello-world)…"
if retry 8 8 docker pull hello-world >/dev/null 2>&1; then
    ok "DNS для Docker работает ($DNS_MODE)"
else
    warn "hello-world пока не дотянулся — продолжаю, дальше ещё ретраи"
fi

# ══ 6. Настройки (.env) ════════════════════════════════════════════════════
say "Шаг 6/8 — настройки (.env)"
if [ -f .env ]; then
    warn ".env уже есть — не трогаю (значения оставляю как есть)"
else
    cp .env.example .env
    APP_SECRET=$(openssl rand -hex 32)
    ADMIN_PASSWORD=$(openssl rand -hex 8)
    DB_PASSWORD=$(openssl rand -hex 12)
    printf 'Домен трекера (например track.mysite.com), Enter чтобы пропустить: '
    read -r DOMAIN_IN || true; DOMAIN_IN="${DOMAIN_IN:-tds.local}"
    printf 'Режим: 1) за Cloudflare [по умолчанию]  2) напрямую (Let'\''s Encrypt) [1]: '
    read -r MODE_IN || true
    case "${MODE_IN:-1}" in 2) DEPLOY_MODE="direct";; *) DEPLOY_MODE="cf_flex";; esac
    set_env() { if grep -qE "^$1=" .env; then sed -i "s|^$1=.*|$1=$2|" .env; else printf '%s=%s\n' "$1" "$2" >> .env; fi; }
    set_env APP_ENV prod; set_env APP_DEBUG false
    set_env DEPLOY_MODE "$DEPLOY_MODE"
    set_env DOMAIN "$DOMAIN_IN"; set_env APP_URL "https://$DOMAIN_IN"
    set_env APP_SECRET "$APP_SECRET"; set_env ADMIN_PASSWORD "$ADMIN_PASSWORD"; set_env DB_PASSWORD "$DB_PASSWORD"
    ok ".env создан (режим $DEPLOY_MODE, домен $DOMAIN_IN)"
fi
DEPLOY_MODE=$(grep -E '^DEPLOY_MODE=' .env | head -1 | cut -d= -f2)
case "$DEPLOY_MODE" in
    cf_flex|cf_full) COMPOSE="-f docker-compose.yml -f docker-compose.prod.cf.yml" ;;
    direct)          COMPOSE="-f docker-compose.yml -f docker-compose.prod.direct.yml" ;;
    *)               COMPOSE="" ; warn "DEPLOY_MODE=$DEPLOY_MODE — dev-режим" ;;
esac

# ══ 7. Образы и запуск — медленно, по одному, с ретраями ═══════════════════
say "Шаг 7/8 — сборка и запуск (медленно и по одному — так надёжнее)"
export COMPOSE_PARALLEL_LIMIT=1   # никакой параллельной пачки к DNS

# Пред-качиваем базовые образы из Dockerfile по одному (чтобы сборка не
# устраивала параллельный залп запросов в реестр).
if [ -f Dockerfile ]; then
    grep -iE '^[[:space:]]*FROM[[:space:]]' Dockerfile | awk '{print $2}' | sort -u | while read -r img; do
        [ -z "$img" ] && continue
        case "$img" in scratch|*'$'*) continue;; esac
        retry 6 8 docker pull "$img" >/dev/null 2>&1 || warn "не пред-скачал $img (соберётся при билде)"
    done
    ok "базовые образы подготовлены"
fi

# Тянем образы сервисов по одному.
retry 8 8 docker compose $COMPOSE pull --ignore-buildable || warn "часть образов не дотянулась — билд/ап попробуют ещё"

# Сборка (с повторами).
retry 6 12 docker compose $COMPOSE build || warn "сборка с ошибками — пробую поднять что собралось"

# Поднимаем (с повторами).
retry 8 12 docker compose $COMPOSE up -d \
    || die "не удалось поднять контейнеры даже после повторов — покажи мне вывод выше"
ok "контейнеры подняты"

# ══ 8. База и администратор ════════════════════════════════════════════════
say "Шаг 8/8 — база данных, партиции, администратор"
for _ in $(seq 1 30); do docker compose $COMPOSE exec -T app php -v >/dev/null 2>&1 && break; sleep 2; done
retry 5 5 docker compose $COMPOSE run --rm --entrypoint="" app vendor/bin/phinx migrate -c phinx.php \
    || warn "миграции с ошибкой — проверь вывод"
docker compose $COMPOSE exec -T app php bin/console partitions:rotate || warn "partitions:rotate с ошибкой"
docker compose $COMPOSE exec -T app php bin/console admin:init || warn "admin:init (возможно, админ уже создан)"

DOMAIN=$(grep -E '^DOMAIN=' .env | head -1 | cut -d= -f2)
LOGIN=$(grep -E '^ADMIN_LOGIN=' .env | head -1 | cut -d= -f2)
PASS=$(grep -E '^ADMIN_PASSWORD=' .env | head -1 | cut -d= -f2)
printf '\n\033[1;32m═══════════════════════════════════════════════\033[0m\n'
ok "Готово! Трекер установлен."
printf '   Адрес входа : https://%s/admin/login\n' "$DOMAIN"
printf '   Логин       : %s\n' "${LOGIN:-admin}"
printf '   Пароль      : %s\n' "$PASS"
printf '\033[1;32m═══════════════════════════════════════════════\033[0m\n'
printf 'Смени пароль в /admin/settings. Инструкция оператора — в docs/.\n'
case "$DEPLOY_MODE" in cf_flex|cf_full)
    printf 'Cloudflare: домен %s должен быть проксирован (оранжевое облачко), SSL = Flexible.\n' "$DOMAIN";;
esac
