# AffMoment TDS — установка на VPS

> Актуально на 2026-08-18. Источник для docx (`docs/AffMoment-TDS-установка-VPS.docx`).
> При изменении деплой-механики обновлять оба файла.
> Основано на реальном пайплайне репозитория (`docs/DEPLOYMENT.md`, `docs/AI-INSTALL.md`, `.env.example`).

## Что понадобится

- **VPS** с Linux (Debian/Ubuntu проще всего), root или sudo. Минимум ~2 ГБ RAM, ~10 ГБ диска.
- **Домен** (для режима `direct` обязателен; для Cloudflare — тоже нужен, но за прокси CF).
- **Docker** ≥ 24 с плагином Compose v2 (ставится на шаге 2).
- Опционально: аккаунт **MaxMind** (бесплатный GeoLite2) для гео-таргетинга; **Telegram-бот** для уведомлений.

Важно: на хосте НЕ нужны PHP, Composer, Node/Bun — всё собирается внутри Docker-образа. Ничего лишнего не ставить.

## Выбор режима деплоя (параметр DEPLOY_MODE)

| Режим | Когда использовать | TLS | Порты |
|---|---|---|---|
| **direct** | Обычный VPS без Cloudflare. **Выбор по умолчанию.** | Caddy сам берёт сертификат Let's Encrypt | 80 и 443 наружу |
| **cf_flex** | Домен проксируется через Cloudflare, между CF и сервером — HTTP | TLS терминирует Cloudflare | 80 |
| **cf_full** | Через Cloudflare + на сервере HTTPS с Origin-сертификатом CF | Origin Cert CF на Caddy | 443 |
| dev | Только локальная машина, портов наружу НЕ публикует. На сервере не использовать. | — | — |

Дальше инструкция идёт по режиму **direct** (для plain VPS); отличия для Cloudflare отмечены отдельно.

## Шаг 1. DNS и порты

1. A-запись домена → публичный IP вашего VPS (для `cf_*` — включить оранжевое облако проксирования).
2. Проверить, что порты 80/443 свободны:
   ```
   ss -lntp | grep -E ':(80|443)\s' || echo "ПОРТЫ СВОБОДНЫ"
   ```
   Если что-то уже слушает 80/443 — это главная причина «наполовину рабочей» установки. Разобраться до продолжения.

## Шаг 2. Установить Docker

```
apt-get update
apt-get install -y ca-certificates curl gnupg
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/debian $(. /etc/os-release && echo $VERSION_CODENAME) stable" > /etc/apt/sources.list.d/docker.list
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
```
(Для Ubuntu заменить `debian` на `ubuntu` в двух местах.) Проверка: `docker --version` и `docker compose version`.

## Шаг 3. Файрвол (не заблокируйте себе SSH)

```
ufw allow 22/tcp        # СНАЧАЛА SSH — иначе закроете себе доступ навсегда
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
```
Для `cf_*` режимов вместо открытого 80/443 всему миру рекомендуется разрешить эти порты только с IP-диапазонов Cloudflare (чтобы никто не обошёл CF, обратившись напрямую к origin-IP) — см. пример с циклом по `cloudflare.com/ips-v4` в `docs/DEPLOYMENT.md`.

## Шаг 4. Получить код на сервер

Трекер лежит в git. Способы доставки:
- Свой приватный git-remote (если настроен): `git clone <ваш-remote> slimtds`
- Либо перенести папку `slimtds-main` на сервер через `scp`/`rsync` (без `docker-app/`, `docker-data/`, `node_modules/`, `vendor/`).

Затем:
```
cd slimtds        # (или slimtds-main)
make env          # копирует .env.example → .env, генерирует APP_SECRET и ADMIN_PASSWORD
```
`make env` НЕ перезапишет существующий `.env`. Если он уже есть — не трогать, править вручную.

## Шаг 5. Настроить .env (параметры)

Открыть `.env` и выставить под свой режим. **Обязательные к правке параметры:**

| Параметр | Что это | Значение для direct |
|---|---|---|
| `APP_ENV` | Окружение | `prod` |
| `APP_DEBUG` | Отладка (в проде выключить) | `false` |
| `DEPLOY_MODE` | Режим деплоя | `direct` |
| `DOMAIN` | Ваш домен | `tds.example.com` |
| `APP_URL` | Полный URL админки | `https://tds.example.com` |
| `APP_SECRET` | Подпись сессий/CSRF (64 hex) | сгенерировано `make env`; либо `openssl rand -hex 32` |
| `ADMIN_LOGIN` | Логин первого админа | `admin` |
| `ADMIN_PASSWORD` | Пароль первого админа | сгенерировано `make env` — сохранить! |
| `DB_PASSWORD` | Пароль базы | **сменить ДО первого старта** (см. предупреждение ниже) |
| `APP_TZ` | Таймзона для отчётов | напр. `Europe/Moscow` |

**Предупреждение по `DB_PASSWORD`:** PostgreSQL применяет пароль только при первой инициализации пустого тома данных. Смена пароля ПОСЛЕ первого старта → база держит старый, приложение шлёт новый → ошибка авторизации. Менять строго до первого запуска.

**Опциональные параметры:**

| Параметр | Назначение | По умолчанию |
|---|---|---|
| `SESSION_LIFETIME` | Скользящее окно сессии, сек | `1209600` (14 дней) |
| `SESSION_ABSOLUTE_LIFETIME` | Жёсткий потолок сессии от логина, сек | `2592000` (30 дней) |
| `FRANKENPHP_WORKER_MODE` | 1 = резидентный воркер (быстро); 0 = классика (для отладки) | `1` |
| `RATE_LIMIT_IP` / `RATE_LIMIT_LOGIN` / `RATE_LIMIT_COOKIE` | Лимиты запросов/мин на админку | `10` / `5` / `20` |
| `RATE_LIMIT_PUBLIC` | Лимит/мин/IP на `/postback`, `/p/event` (поднять, если легитимный лендинг упирается) | `120` |
| `MAXMIND_ACCOUNT_ID` / `MAXMIND_LICENSE_KEY` | Гео (шаг 8) | пусто |
| `TELEGRAM_BOT_TOKEN` / `TELEGRAM_CHAT_ID` | Уведомления (шаг 9) | пусто |
| `TRUSTED_PROXIES` | Доп. доверенный прокси (CIDR/IP через запятую) | пусто |

**Про реальный IP посетителя:**
- `direct` без своего прокси: `TRUSTED_PROXIES` оставить пустым — реальный IP берётся из соединения.
- `direct` со своим reverse-proxy/балансировщиком перед Caddy: вписать его IP/CIDR в `TRUSTED_PROXIES`, иначе все посетители будут выглядеть как IP прокси (сломается гео, антибот-клоака, per-visitor rate-limit).
- `cf_flex`/`cf_full`: `TRUSTED_PROXIES` пустой — диапазоны Cloudflare уже доверяются встроенно, IP читается из `CF-Connecting-IP`.

Никогда не редактировать `Caddyfile.*` руками — правильный выбирается автоматически по `DEPLOY_MODE`.

## Шаг 6. Запустить стек

```
make prod-up-direct     # для DEPLOY_MODE=direct
# или: make prod-up-cf  # для cf_flex / cf_full
```
Первый старт собирает образ (Bun-ассеты → Composer → FrankenPHP), это несколько минут — нормально.

**Никогда не запускать голый `docker compose up` в проде** — он подмешает `docker-compose.override.yml` и примонтирует исходники поверх образа, спрятав собранные ассеты (`public/assets/`, `public/p.js`). Итог: админка без стилей и `/p.js` = 404 при «здоровых» контейнерах. Только `make prod-up-*` либо явная пара `-f docker-compose.yml -f docker-compose.prod.<direct|cf>.yml`.

Для `cf_full` дополнительно примонтировать Origin-сертификат Cloudflare, чтобы Caddy отдавал `:443`.

## Шаг 7. Инициализация

Задать пару compose один раз (для direct):
```
DC="docker compose -f docker-compose.yml -f docker-compose.prod.direct.yml"
# для cf: DC="docker compose -f docker-compose.yml -f docker-compose.prod.cf.yml"
```
Затем по порядку:
```
# 1. Схема БД
$DC run --rm --entrypoint="" app vendor/bin/phinx migrate -c phinx.php
# 2. Партиции (иначе первый же клик/пиксель отдаст 500 — таблицы stats.* партиционированы по месяцам)
$DC exec app php bin/console partitions:rotate
# 3. Первый админ (читает ADMIN_LOGIN/ADMIN_PASSWORD из .env, только первый раз)
$DC exec app php bin/console admin:init
```

## Шаг 8. GeoIP (опционально, для гео-таргетинга)

1. Зарегистрироваться на MaxMind, создать License Key, вписать `MAXMIND_ACCOUNT_ID` / `MAXMIND_LICENSE_KEY` в `.env`.
2. Скачать базы (работает в любом режиме):
```
docker run --rm \
  -e GEOIPUPDATE_ACCOUNT_ID="<id>" \
  -e GEOIPUPDATE_LICENSE_KEY="<key>" \
  -e GEOIPUPDATE_EDITION_IDS="GeoLite2-Country GeoLite2-City GeoLite2-ASN" \
  -v "$PWD/geoip-data:/usr/share/GeoIP" \
  maxmindinc/geoipupdate:latest
ls geoip-data/        # ожидаем три .mmdb файла
$DC restart app       # приложение открывает базы при старте
```
Без баз гео-фильтры просто не срабатывают (это штатно, не ошибка).

## Шаг 9. Telegram (опционально)

Уведомления не обязательны — без токена трекер работает как есть, ничего не шлётся, на скорость не влияет (уходят через очередь, посетителей/постбеки не тормозят).

1. Создать бота у @BotFather, скопировать токен.
2. Узнать chat_id (переслать сообщение боту @userinfobot).
3. Вписать `TELEGRAM_BOT_TOKEN` и `TELEGRAM_CHAT_ID` в `.env`, `$DC up -d --force-recreate app cron`.
4. Проверка: `$DC exec app php bin/console telegram:alerts`.

## Шаг 10. Проверка (definition of done)

Должны пройти все:
```
DOMAIN=<ваш домен>
$DC ps                                                          # app/db/cron Up, app healthy
curl -sf "https://$DOMAIN/__health" -o /dev/null -w '%{http_code}\n'   # 200
curl -sI "https://$DOMAIN/admin/login" | head -1                # HTTP/2 200
curl -s "https://$DOMAIN/admin/login" | grep -oE '/assets/app\.[a-z0-9]+\.css' | head -1   # есть путь = ассеты собраны
curl -sI "https://$DOMAIN/p.js" | head -1                       # 200, НЕ 404
```
Если `/p.js` = 404 или админка без стилей — был запущен голый `docker compose up` (см. шаг 6), сделать `make prod-down` и `make prod-up-direct`.

## После установки

- Сменить пароль админа в `/admin/settings` (или `$DC exec app php bin/console admin:set-password admin <новый>`).
- Создать первую кампанию/оффер/поток — порядок в инструкции оператора (`AffMoment-TDS-инструкция.docx`).
- Направить трафик-домен на сервер; движок отвечает на `/<slug-кампании>`.
- Бэкапы: `./var/backups/` на хосте, ежедневный cron, хранятся последние 7. Восстановление: `$DC exec app php bin/console db:restore <файл>.dump yes`.

## Типовые проблемы

| Симптом | Причина | Что делать |
|---|---|---|
| Админка без стилей, `/p.js` = 404 | Запущен голый `docker compose up`, override спрятал ассеты | `make prod-down`, затем `make prod-up-direct`/`make prod-up-cf` |
| Контейнер `app` в цикле рестартов | Ошибка bootstrap воркера (плохое значение в `.env`, не прошла миграция) | `make logs`; временно `FRANKENPHP_WORKER_MODE=0` — увидеть реальную ошибку, починить, вернуть 1 |
| Let's Encrypt не выдаёт сертификат | DNS не на этот сервер, либо порт 80 занят | Перепроверить шаг 1; для ACME-челленджа Caddy нужен доступный :80 |
| Ошибка авторизации БД | `DB_PASSWORD` сменили после инициализации тома | Вернуть старый пароль, либо `$DC down -v` (**УНИЧТОЖИТ данные**) и заново |
| IP посетителя = IP прокси | Не тот `DEPLOY_MODE`, или свой прокси не в `TRUSTED_PROXIES` (direct) | Поставить `cf_flex`/`cf_full`, либо добавить IP прокси в `TRUSTED_PROXIES`, перезапустить |
| Гео-фильтры не срабатывают | Нет `.mmdb` баз | Выполнить `docker run … geoipupdate` (шаг 8), перезапустить `app` |
