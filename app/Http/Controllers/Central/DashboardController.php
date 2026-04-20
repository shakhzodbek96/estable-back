<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * Central Admin Dashboard — umumiy statistika.
 */
class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        $totals = Tenant::query()
            ->selectRaw("COUNT(*) AS total")
            ->selectRaw("COUNT(*) FILTER (WHERE status = ?) AS active", [Tenant::STATUS_ACTIVE])
            ->selectRaw("COUNT(*) FILTER (WHERE status = ?) AS suspended", [Tenant::STATUS_SUSPENDED])
            ->selectRaw("COUNT(*) FILTER (WHERE status = ?) AS trial_expired", [Tenant::STATUS_TRIAL_EXPIRED])
            ->selectRaw("COUNT(*) FILTER (WHERE plan = ?) AS plan_trial", [Tenant::PLAN_TRIAL])
            ->selectRaw("COUNT(*) FILTER (WHERE plan = ?) AS plan_basic", [Tenant::PLAN_BASIC])
            ->selectRaw("COUNT(*) FILTER (WHERE plan = ?) AS plan_pro", [Tenant::PLAN_PRO])
            ->selectRaw("COUNT(*) FILTER (WHERE plan = ?) AS plan_enterprise", [Tenant::PLAN_ENTERPRISE])
            ->selectRaw("COUNT(*) FILTER (WHERE created_at >= ?) AS new_this_month", [now()->startOfMonth()])
            ->selectRaw("COUNT(*) FILTER (WHERE trial_ends_at IS NOT NULL AND trial_ends_at BETWEEN ? AND ?) AS trial_expires_soon", [
                now(),
                now()->addDays(7),
            ])
            ->first();

        return response()->json([
            'tenants' => [
                'total' => (int) $totals->total,
                'by_status' => [
                    'active' => (int) $totals->active,
                    'suspended' => (int) $totals->suspended,
                    'trial_expired' => (int) $totals->trial_expired,
                ],
                'by_plan' => [
                    'trial' => (int) $totals->plan_trial,
                    'basic' => (int) $totals->plan_basic,
                    'pro' => (int) $totals->plan_pro,
                    'enterprise' => (int) $totals->plan_enterprise,
                ],
                'new_this_month' => (int) $totals->new_this_month,
                'trial_expires_within_7d' => (int) $totals->trial_expires_soon,
            ],
            'as_of' => now()->toIso8601String(),
        ]);
    }
}
