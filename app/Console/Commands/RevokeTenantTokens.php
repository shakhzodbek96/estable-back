<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Tanlangan tenant(lar)ning barcha Sanctum tokenlarini bekor qiladi (expire).
 *
 * Tokenlar har tenant schema'sidagi `personal_access_tokens` jadvalida turadi,
 * shuning uchun o'chirish tenant kontekstida (tenant connection + search_path)
 * bajariladi. Config'da `sanctum.expiration = null` bo'lgani uchun tokenlar
 * o'z-o'zidan eskirmaydi — bu command yagona "hammani logout qilish" yo'li.
 *
 * Ishga tushirish:
 *   php artisan tokens:revoke --tenant=demo-store            # bitta tenant, barcha userlar
 *   php artisan tokens:revoke --tenant=demo-store --user=5   # faqat user id=5
 *   php artisan tokens:revoke --tenant=demo-store --user=admin  # username bo'yicha
 *   php artisan tokens:revoke --all                          # barcha tenantlar (ehtiyot bo'ling!)
 *   php artisan tokens:revoke --tenant=demo-store --force    # tasdiqlashsiz
 */
class RevokeTenantTokens extends Command
{
    protected $signature = 'tokens:revoke
        {--tenant= : Tenant slug (id). Bitta tenant uchun majburiy, agar --all berilmasa}
        {--all : Barcha tenantlar uchun tokenlarni bekor qilish}
        {--user= : Faqat shu user (id yoki username) tokenlarini bekor qilish}
        {--force : Tasdiqlash so\'ramasdan bajarish}';

    protected $description = 'Tanlangan tenant(lar)ning barcha Sanctum tokenlarini bekor qilish (barcha userlarni logout qilish)';

    public function handle(): int
    {
        $all = (bool) $this->option('all');
        $slug = $this->option('tenant');
        $userRef = $this->option('user');

        if (! $all && ! $slug) {
            $this->error('--tenant=<slug> yoki --all berilishi shart.');
            return self::FAILURE;
        }

        if ($all && $userRef) {
            $this->error('--user ni --all bilan birga ishlatib bo\'lmaydi (user har tenantda boshqacha).');
            return self::FAILURE;
        }

        $tenants = $all
            ? Tenant::all()
            : Tenant::where('id', $slug)->get();

        if ($tenants->isEmpty()) {
            $this->error($all ? 'Tenant topilmadi.' : "Tenant topilmadi: {$slug}");
            return self::FAILURE;
        }

        // Tasdiqlash (--force bo'lmasa)
        if (! $this->option('force')) {
            $scope = $userRef
                ? "user \"{$userRef}\""
                : 'BARCHA userlar';
            $where = $all
                ? "BARCHA {$tenants->count()} ta tenant"
                : "tenant \"{$slug}\"";

            if (! $this->confirm("{$where} uchun {$scope} tokenlari bekor qilinsin (logout)?")) {
                $this->line('Bekor qilindi.');
                return self::SUCCESS;
            }
        }

        $grandTotal = 0;

        foreach ($tenants as $tenant) {
            // Tenant kontekstida o'chiramiz; closure'dan faqat int qaytaramiz.
            $deleted = $tenant->run(function () use ($userRef, $tenant) {
                $query = PersonalAccessToken::query();

                if ($userRef !== null) {
                    $user = $this->resolveUser($userRef);
                    if (! $user) {
                        $this->warn("  [{$tenant->id}] user topilmadi: {$userRef} — o'tkazib yuborildi.");
                        return 0;
                    }
                    // Faqat User modeliga tegishli tokenlar
                    $query->where('tokenable_type', $user->getMorphClass())
                        ->where('tokenable_id', $user->getKey());
                }

                return $query->delete();
            });

            $grandTotal += $deleted;
            $this->line("<fg=cyan>{$tenant->id}</>: <fg=yellow>{$deleted}</> ta token bekor qilindi.");
        }

        $this->newLine();
        $this->info("Jami {$grandTotal} ta token bekor qilindi.");

        return self::SUCCESS;
    }

    /**
     * --user qiymatini User modeliga aylantiradi (raqam → id, aks holda username).
     * Tenant kontekstida chaqiriladi.
     */
    private function resolveUser(string $ref): ?User
    {
        if (ctype_digit($ref)) {
            return User::find((int) $ref);
        }

        return User::where('username', $ref)->first();
    }
}
