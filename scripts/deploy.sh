#!/usr/bin/env bash
#
# Estable — production deploy script
# ====================================
# Ketma-ketlik:
#   1. Git'dan yangi kod olish
#   2. Composer dependencies (faqat composer.lock o'zgargan bo'lsa)
#   3. Central migratsiyalar + tenant migratsiyalar
#   4. Laravel cache (config, route, event) qayta qurish
#   5. Queue worker va scheduler services restart
#   6. Status tekshiruvi
#
# Ishlatish (VPS'da, root yoki estable user):
#   bash /home/estable/web/api.estable.uz/public_html/scripts/deploy.sh
#
# Yoki symlink orqali (tavsiya):
#   ln -sf /home/estable/web/api.estable.uz/public_html/scripts/deploy.sh ~/deploy.sh
#   ~/deploy.sh
#

set -euo pipefail

# ==========================================================================
# Sozlamalar
# ==========================================================================
readonly PHP="${PHP:-/usr/bin/php8.3}"

# Script qayerda joylashganini aniqlab, Laravel root'ga chiqish
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
readonly APP_DIR="$( cd "$SCRIPT_DIR/.." && pwd )"

# ==========================================================================
# Rangli log helpers
# ==========================================================================
readonly GREEN='\033[0;32m'
readonly BLUE='\033[0;34m'
readonly YELLOW='\033[1;33m'
readonly RED='\033[0;31m'
readonly BOLD='\033[1m'
readonly NC='\033[0m'

log()  { echo -e "${BLUE}→${NC} $*"; }
ok()   { echo -e "${GREEN}✓${NC} $*"; }
warn() { echo -e "${YELLOW}⚠${NC} $*" >&2; }
die()  { echo -e "${RED}✗${NC} $*" >&2; exit 1; }

# ==========================================================================
# Oldindan tekshiruvlar
# ==========================================================================
[ -f "$APP_DIR/artisan" ] || die "Laravel artisan topilmadi: $APP_DIR"
[ -d "$APP_DIR/.git" ]    || die "Git repo topilmadi: $APP_DIR"
command -v "$PHP" >/dev/null 2>&1 || die "$PHP topilmadi"

cd "$APP_DIR"

START_TIME=$(date +%s)
echo -e "${BOLD}Estable deploy — $(date '+%Y-%m-%d %H:%M:%S')${NC}"
echo -e "App dir: ${APP_DIR}"
echo

# ==========================================================================
# 1. GIT PULL
# ==========================================================================
log "[1/5] Git'dan yangilanishlar olinyapti..."

BEFORE_HEAD=$(git rev-parse HEAD)
git pull --ff-only origin main
AFTER_HEAD=$(git rev-parse HEAD)

if [ "$BEFORE_HEAD" = "$AFTER_HEAD" ]; then
    ok "Kod allaqachon eng yangi (HEAD: ${AFTER_HEAD:0:7})"
else
    ok "Yangilandi ${BEFORE_HEAD:0:7} → ${AFTER_HEAD:0:7}"
    echo -e "${BLUE}O'zgargan fayllar:${NC}"
    git diff --name-only "$BEFORE_HEAD" "$AFTER_HEAD" | sed 's/^/    /'
fi
echo

# ==========================================================================
# 2. COMPOSER (faqat composer.lock o'zgargan bo'lsa)
# ==========================================================================
if git diff --name-only "$BEFORE_HEAD" "$AFTER_HEAD" 2>/dev/null | grep -q "^composer.lock$"; then
    log "[2/5] composer.lock o'zgargan — dependencies qayta o'rnatilyapti..."
    composer install --no-dev --optimize-autoloader --no-interaction
    ok "Composer dependencies yangilandi"
else
    log "[2/5] Composer — o'zgarish yo'q, o'tkazildi"
fi
echo

# ==========================================================================
# 3. MIGRATSIYALAR
# ==========================================================================
log "[3/5] Database migratsiyalari..."

# Central schema (public)
$PHP artisan migrate --force

# Tenant schemas — har tenant uchun (agar mavjud bo'lsa)
if $PHP artisan list 2>/dev/null | grep -q "tenants:migrate"; then
    log "Tenant migratsiyalar ishga tushirilyapti..."
    $PHP artisan tenants:migrate --force || warn "Tenant migratsiyalarida xato — qo'lda tekshiring"
fi

ok "Migratsiyalar tugadi"
echo

# ==========================================================================
# 4. CACHE REBUILD
# ==========================================================================
log "[4/5] Laravel cache qayta qurilyapti..."

$PHP artisan config:clear
$PHP artisan route:clear
$PHP artisan cache:clear

$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan event:cache

ok "Cache tayyor"
echo

# ==========================================================================
# 5. SERVICES RESTART
# ==========================================================================
log "[5/5] Queue va scheduler services restart..."

# Root yoki sudo bilan ishlatish
restart_service() {
    local svc="$1"
    if [ "$(id -u)" = "0" ]; then
        systemctl restart "$svc"
    else
        sudo systemctl restart "$svc"
    fi
}

restart_service estable-queue.service
restart_service estable-scheduler.service

# Holat tekshirish
sleep 1
for svc in estable-queue estable-scheduler; do
    if systemctl is-active --quiet "${svc}.service" 2>/dev/null; then
        ok "${svc}.service — active (running)"
    else
        warn "${svc}.service — active emas! Tekshiring: sudo systemctl status ${svc}"
    fi
done
echo

# ==========================================================================
# Yakun
# ==========================================================================
ELAPSED=$(( $(date +%s) - START_TIME ))
echo -e "${GREEN}${BOLD}✓ Deploy tugadi (${ELAPSED}s) 🚀${NC}"
