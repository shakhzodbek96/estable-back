<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Tenantlar ro'yxatini jadval ko'rinishida chiqaradi.
 *
 * `stancl/tenancy` beradigan `tenants:list` juda minimal (faqat id + domain),
 * shu sabab bu command status/plan/egasi/obuna kabi central maydonlarni ham
 * ko'rsatadi. Barcha ma'lumot central (public) schema'dagi `tenants` jadvalidan.
 *
 * Ishga tushirish:
 *   php artisan tenants:overview                       # barchasi
 *   php artisan tenants:overview --status=active       # status bo'yicha filter
 *   php artisan tenants:overview --plan=pro            # plan bo'yicha filter
 *   php artisan tenants:overview --search=demo         # id/name/egasi bo'yicha qidiruv
 *   php artisan tenants:overview --stats               # har tenant uchun user sonini ham
 */
class ListTenants extends Command
{
    protected $signature = 'tenants:overview
        {--status= : Status bo\'yicha filter (active, suspended, trial_expired, archived)}
        {--plan= : Plan bo\'yicha filter (trial, basic, pro, enterprise)}
        {--search= : id / name / owner_name bo\'yicha qidiruv}
        {--stats : Har tenant uchun user sonini ham hisoblab chiqarish (sekinroq)}';

    protected $description = 'Tenantlar ro\'yxatini status/plan/egasi bilan jadval ko\'rinishida chiqarish';

    public function handle(): int
    {
        $query = Tenant::query()->orderBy('created_at');

        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }

        if ($plan = $this->option('plan')) {
            $query->where('plan', $plan);
        }

        if ($search = $this->option('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('id', 'ilike', "%{$search}%")
                    ->orWhere('name', 'ilike', "%{$search}%")
                    ->orWhere('owner_name', 'ilike', "%{$search}%");
            });
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->warn('Tenant topilmadi.');
            return self::SUCCESS;
        }

        $withStats = (bool) $this->option('stats');

        $headers = ['#', 'ID (slug)', 'Nomi', 'Egasi', 'Plan', 'Status', 'Obuna tugashi', 'Oxirgi faollik'];
        if ($withStats) {
            $headers[] = 'Userlar';
        }

        $rows = [];
        foreach ($tenants as $i => $t) {
            $row = [
                $i + 1,
                $t->id,
                $t->name,
                $t->owner_name ?: '—',
                $t->plan,
                $this->colorStatus($t->status),
                $t->subscription_ends_at ? Carbon::parse($t->subscription_ends_at)->format('Y-m-d') : '—',
                $t->last_seen_at ? Carbon::parse($t->last_seen_at)->diffForHumans() : '—',
            ];

            if ($withStats) {
                // User soni tenant schema'sida — tenant kontekstida sanaymiz.
                $row[] = $t->run(fn () => User::count());
            }

            $rows[] = $row;
        }

        $this->table($headers, $rows);
        $this->info("Jami: {$tenants->count()} ta tenant.");

        return self::SUCCESS;
    }

    /** Statusni rangli qilib chiqaradi. */
    private function colorStatus(string $status): string
    {
        return match ($status) {
            Tenant::STATUS_ACTIVE => "<fg=green>{$status}</>",
            Tenant::STATUS_SUSPENDED => "<fg=red>{$status}</>",
            Tenant::STATUS_TRIAL_EXPIRED => "<fg=yellow>{$status}</>",
            Tenant::STATUS_ARCHIVED => "<fg=gray>{$status}</>",
            default => $status,
        };
    }
}
