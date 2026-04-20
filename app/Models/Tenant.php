<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * Estable tenant modeli.
 *
 * Har bir tenant = bitta biznes egasi (partner). Tenant ichida bir nechta
 * `shops` bo'lishi mumkin — bu tenant DB ichidagi `shops` jadvalida saqlanadi.
 *
 * Central DB da esa shu Tenant modeli quyidagi qatorda turadi:
 *  - id (UUID)
 *  - name, owner_name, email, phone
 *  - plan, status, trial_ends_at, subscription_ends_at
 *  - db_name, last_seen_at
 *  - data (JSON — qolgan custom fieldlar)
 *
 * HasDatabase  — har tenantga alohida DB yaratishni ta'minlaydi.
 * HasDomains   — tenant'ga bir nechta domain (subdomain) biriktirishga imkon beradi.
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;

    /**
     * VirtualColumn tizimi uchun: bu ustunlar alohida DB columnlar sifatida
     * saqlanadi, qolganlari `data` JSON ustunga tushadi.
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'owner_name',
            'email',
            'phone',
            'created_by_admin_id',
            'plan',
            'status',
            'trial_ends_at',
            'subscription_ends_at',
            'db_name',
            'last_seen_at',
        ];
    }

    /**
     * Tenantni yaratgan super admin (agar ma'lum bo'lsa).
     */
    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by_admin_id');
    }

    /**
     * Tenant statuslari (oddiy enum o'rniga string constantlar).
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_TRIAL_EXPIRED = 'trial_expired';
    public const STATUS_ARCHIVED = 'archived';

    public const PLAN_TRIAL = 'trial';
    public const PLAN_BASIC = 'basic';
    public const PLAN_PRO = 'pro';
    public const PLAN_ENTERPRISE = 'enterprise';

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }
}
