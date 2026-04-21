<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Central Admin Panel — tenantlarni boshqarish.
 */
class TenantController extends Controller
{
    /**
     * Reserved slugs — tenant ID sifatida ishlatib bo'lmaydigan subdomainlar.
     *
     * Bular infrastruktura, xizmat yoki kelajakda ishlatilishi mumkin bo'lgan
     * subdomainlar. Tenant slug bu ro'yxatdan bo'lmasligi kerak.
     */
    private const RESERVED_SLUGS = [
        // Infrastruktura
        'admin', 'api', 'www', 'root',
        'tenant', 'tenants',

        // Umumiy texnik subdomainlar
        'mail', 'smtp', 'imap', 'pop', 'ftp', 'ssh',
        'cdn', 'static', 'assets', 'media', 'files',
        'ns', 'ns1', 'ns2', 'dns',

        // Xizmat/yordamchi
        'app', 'dashboard', 'panel', 'portal',
        'support', 'help', 'docs', 'blog', 'news',
        'billing', 'payments', 'checkout',
        'status', 'health', 'monitoring',

        // Auth/account
        'auth', 'login', 'signup', 'register', 'account', 'profile',

        // Development/staging
        'test', 'tests', 'staging', 'stage', 'dev', 'development',
        'demo-internal', 'preview', 'beta', 'alpha',

        // Reserved
        'public', 'private', 'internal', 'secure', 'ssl',
    ];

    public function __construct(protected TenantService $tenantService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Tenant::query()->with([
            'domains',
            'createdByAdmin:id,name,username',
        ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('owner_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%")
                    ->orWhere('id', 'ilike', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($plan = $request->query('plan')) {
            $query->where('plan', $plan);
        }

        return response()->json(
            $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 15))
        );
    }

    public function show(string $id): JsonResponse
    {
        $tenant = Tenant::with([
            'domains',
            'createdByAdmin:id,name,username',
        ])->findOrFail($id);

        return response()->json($tenant);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],

            // Slug = tenant ID = subdomain = schema name
            // Qoidalar: lowercase, a-z/0-9/-, harf bilan boshlansin, harf/raqam bilan tugasin
            'slug' => [
                'required',
                'string',
                'min:3',
                'max:55',
                'regex:/^[a-z][a-z0-9-]*[a-z0-9]$/',
                Rule::notIn(self::RESERVED_SLUGS),
                Rule::unique('tenants', 'id'),
            ],

            'owner_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'plan' => ['nullable', Rule::in([
                Tenant::PLAN_TRIAL,
                Tenant::PLAN_BASIC,
                Tenant::PLAN_PRO,
                Tenant::PLAN_ENTERPRISE,
            ])],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'custom_domain' => ['nullable', 'string', 'max:255'],
            'admin_password' => ['nullable', 'string', 'min:8', 'max:100'],
        ], [
            'slug.regex' => 'Subdomain faqat kichik harflar, raqamlar va defis (-) belgisidan iborat bo\'lishi mumkin. Harf bilan boshlanishi va harf/raqam bilan tugashi kerak.',
            'slug.not_in' => 'Bu subdomain tizim tomonidan band qilingan (masalan: admin, api, www, tenants). Boshqasini tanlang.',
            'slug.unique' => 'Bu subdomain allaqachon band. Boshqasini tanlang.',
        ]);

        $result = $this->tenantService->create($data);

        return response()->json([
            'message' => 'Tenant muvaffaqiyatli yaratildi.',
            'tenant' => $result['tenant']->load('domains'),
            'admin_credentials' => [
                'username' => 'admin',
                'password' => $result['admin_password'],
                'note' => 'Bu parolni saqlab qoling — qayta ko\'rsatilmaydi. Birinchi login\'dan so\'ng tenant admin parolni almashtirishi kerak.',
            ],
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'plan' => ['sometimes', Rule::in([
                Tenant::PLAN_TRIAL,
                Tenant::PLAN_BASIC,
                Tenant::PLAN_PRO,
                Tenant::PLAN_ENTERPRISE,
            ])],
            'trial_ends_at' => ['nullable', 'date'],
            'subscription_ends_at' => ['nullable', 'date'],
        ]);

        $tenant = $this->tenantService->update($tenant, $data);

        return response()->json($tenant->load('domains'));
    }

    public function suspend(string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $tenant = $this->tenantService->suspend($tenant);

        return response()->json([
            'message' => 'Tenant to\'xtatildi.',
            'tenant' => $tenant,
        ]);
    }

    public function activate(string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $tenant = $this->tenantService->activate($tenant);

        return response()->json([
            'message' => 'Tenant qayta yoqildi.',
            'tenant' => $tenant,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $this->tenantService->delete($tenant);

        return response()->json([
            'message' => 'Tenant o\'chirildi (schema ham CASCADE o\'chirildi).',
        ]);
    }

    /**
     * Tenant admin parolini tiklash — yangi tasodifiy parol generatsiya qiladi,
     * `must_change_password = true` o'rnatadi, natijani response'da qaytaradi.
     */
    public function resetAdminPassword(string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $newPassword = $this->tenantService->resetAdminPassword($tenant);

        return response()->json([
            'message' => 'Parol muvaffaqiyatli tiklandi.',
            'admin_credentials' => [
                'username' => 'admin',
                'password' => $newPassword,
                'note' => 'Bu parolni saqlab qoling — qayta ko\'rsatilmaydi. Birinchi login\'dan so\'ng tenant admin parolni almashtirishi kerak.',
            ],
        ]);
    }

    /**
     * Tenant schema ichidagi foydalanuvchilar ro'yxati.
     * Central admin tenant kontekstga o'tib users jadvalidan o'qiydi.
     *
     * `toArray()` → tenant context tugashidan oldin barcha relationlar eager
     * yuklansin. Aks holda `tenancy()->end()` dan so'ng JSON serialize qilish
     * paytida `tenant` connection chaqirilib xatolik beradi.
     */
    public function users(string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $users = $tenant->run(function () {
            return \App\Models\User::query()
                ->with('shop:id,name')
                ->orderBy('role')
                ->orderBy('name')
                ->get([
                    'id', 'name', 'username', 'phone', 'role', 'is_blocked',
                    'must_change_password', 'shop_id', 'created_at', 'updated_at',
                ])
                ->toArray();
        });

        return response()->json([
            'tenant_id' => $tenant->id,
            'data' => $users,
            'total' => count($users),
        ]);
    }
}
