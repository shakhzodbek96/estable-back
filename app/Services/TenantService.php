<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Models\User as TenantUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Throwable;

/**
 * Estable tenant provisioning xizmati.
 *
 * Barcha Central Admin Panel operatsiyalari shu yerdan o'tadi:
 *   - yangi tenant yaratish (DB schema + migrate + seed + domain + admin user)
 *   - tenantni to'xtatish / qayta yoqish
 *   - tenantni o'chirish (schema drop cascade)
 *
 * Transaction ichida ishlaydi — stancl tenant yaratish events
 * (CreateDatabase, MigrateDatabase) job pipeline'i sinkron bajariladi
 * (TenancyServiceProvider'da shouldBeQueued(false) set qilingan).
 */
class TenantService
{
    /**
     * Yangi tenant provision qilish.
     *
     * Qadamlar (stancl avto-boshqaradi 1-bosqichda):
     *  1) Tenant jadval yozuvi yaratish  →  CREATE SCHEMA + tenant migrations
     *  2) Domain qo'shish
     *  3) Tenant kontekstida DatabaseSeeder (admin user + default shop + rate + products)
     *  4) Admin parolini qayta yaratish (unique, birinchi login'da almashtirish talab qilinadi)
     *
     * DB::transaction ishlatilmaydi — stancl schema/migrate operatsiyalari bir nechta
     * connection kontekstida ishlaydi va transaction wrapper ularning search_path'ini
     * chigallashtiradi. O'rniga xatolik bo'lsa manual cleanup qilinadi.
     *
     * @param array{name: string, slug?: ?string, owner_name?: ?string, email?: ?string, phone?: ?string, plan?: string, trial_days?: ?int, custom_domain?: ?string, admin_password?: ?string} $data
     * @return array{tenant: Tenant, admin_password: string}
     */
    public function create(array $data): array
    {
        $tenant = null;

        try {
            $attributes = [
                'name' => $data['name'],
                'owner_name' => $data['owner_name'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'plan' => $data['plan'] ?? Tenant::PLAN_TRIAL,
                'status' => Tenant::STATUS_ACTIVE,
                'trial_ends_at' => isset($data['trial_days'])
                    ? now()->addDays((int) $data['trial_days'])
                    : (($data['plan'] ?? Tenant::PLAN_TRIAL) === Tenant::PLAN_TRIAL ? now()->addDays(14) : null),
                // Auth kontekstdan: qaysi super admin yaratyapti (central `auth:sanctum`).
                'created_by_admin_id' => auth()->id(),
            ];

            // Slug (admin qo'lda kiritadi) → tenant ID (primary key) sifatida.
            // Agar slug berilmagan bo'lsa, TenantIdGenerator fallback chaqiriladi.
            if (!empty($data['slug'])) {
                $attributes['id'] = $data['slug'];
            }

            $tenant = Tenant::create($attributes);

            // Default subdomain
            $defaultDomain = $tenant->id . '.estable.uz';
            $tenant->domains()->create(['domain' => $defaultDomain]);

            // Custom domain (ixtiyoriy)
            if (!empty($data['custom_domain'])) {
                $tenant->domains()->create(['domain' => $data['custom_domain']]);
            }

            // Tenant schema ichida default data seed qilish
            $tenant->run(function () {
                Artisan::call('db:seed', [
                    '--class' => 'DatabaseSeeder',
                    '--force' => true,
                ]);
            });

            // Tenant admin parolini almashtirish (unique, har tenantda alohida)
            $plainPassword = $data['admin_password'] ?? $this->generatePassword();
            $this->setTenantAdminPassword($tenant, $plainPassword);

            return [
                'tenant' => $tenant->fresh(),
                'admin_password' => $plainPassword, // response'da bir marta ko'rinadi
            ];
        } catch (Throwable $e) {
            // Agar tenant yaratilgan, lekin keyingi qadamlar muvaffaqiyatsiz bo'lsa —
            // orphan tenant'ni va DROP SCHEMA'ni tozalaymiz.
            if ($tenant !== null && $tenant->exists) {
                try {
                    $tenant->delete(); // stancl avto DROP SCHEMA CASCADE
                } catch (Throwable) {
                    // cleanup'da xatolik bo'lsa yut (asl exception muhimroq)
                }
            }
            throw $e;
        }
    }

    public function update(Tenant $tenant, array $data): Tenant
    {
        $tenant->fill(array_intersect_key($data, array_flip([
            'name', 'owner_name', 'email', 'phone', 'plan',
            'trial_ends_at', 'subscription_ends_at',
        ])));
        $tenant->save();

        return $tenant->fresh();
    }

    public function suspend(Tenant $tenant): Tenant
    {
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        return $tenant->fresh();
    }

    public function activate(Tenant $tenant): Tenant
    {
        $tenant->update(['status' => Tenant::STATUS_ACTIVE]);

        return $tenant->fresh();
    }

    /**
     * Tenantni o'chirish — schema CASCADE drop (stancl DeleteDatabase job).
     *
     * WARNING: qaytarilmaydi! Barcha tenant ma'lumotlari yo'qoladi.
     */
    public function delete(Tenant $tenant): void
    {
        $tenant->delete(); // stancl avto: DROP SCHEMA CASCADE
    }

    /**
     * Tenant admin parolini tiklash — yangi tasodifiy parol yaratadi,
     * `must_change_password = true` belgilaydi. Yangi parolni plain text'da
     * qaytaradi (central admin UI bir marta ko'rsatishi uchun).
     */
    public function resetAdminPassword(Tenant $tenant): string
    {
        $plainPassword = $this->generatePassword();
        $this->setTenantAdminPassword($tenant, $plainPassword);

        return $plainPassword;
    }

    /**
     * 12 belgilik tasodifiy parol — harflar, raqamlar (simvollarsiz, o'qish qulayligi uchun).
     */
    protected function generatePassword(): string
    {
        return Str::password(12, true, true, false);
    }

    /**
     * Tenant kontekstda admin user parolini yangilash + must_change_password = true.
     *
     * NOTE: query builder `update()` model events'ni bypass qilganligi uchun `hashed`
     * cast ishlamaydi. Shuning uchun model instance orqali `save()` qilamiz — bu
     * avtomatik bcrypt hash qiladi.
     */
    protected function setTenantAdminPassword(Tenant $tenant, string $plainPassword): void
    {
        $tenant->run(function () use ($plainPassword) {
            $admin = TenantUser::where('username', 'admin')->first();
            if ($admin) {
                $admin->password = $plainPassword;
                $admin->must_change_password = true;
                $admin->save();
            }
        });
    }
}
