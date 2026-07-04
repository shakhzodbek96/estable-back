<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Investor / sotuv / kassa hisob-kitob invariantlarini tekshiradi.
 *
 * Har tenant schema'sida bir qator "buzilmasligi kerak bo'lgan" qoidalarni SQL bilan
 * tekshiradi; har qoida uchun BUZILGAN qatorlar sonini qaytaradi (0 = PASS). Bironta
 * ham buzilish bo'lsa exit kodi 1 (cron/CI uchun).
 *
 * Ishga tushirish:
 *   php artisan accounting:audit                     # barcha tenant'lar
 *   php artisan accounting:audit --tenant=demo-store # bitta tenant
 *   php artisan accounting:audit --details           # buzilgan qatorlarni ham chiqaradi
 *
 * Pul solishtiruvlarida kichik yaxlitlash farqiga yo'l qo'yiladi (tolerance).
 */
class AuditAccounting extends Command
{
    protected $signature = 'accounting:audit
        {--tenant= : Faqat shu tenant (slug) uchun}
        {--details : Buzilgan qatorlarning namunasini ham chiqarish}';

    protected $description = 'Investor/sotuv/kassa hisob-kitob invariantlarini tekshirish';

    /** Pul solishtiruvi uchun ruxsat etilgan yaxlitlash farqi ($) */
    private const EPS = 0.02;

    public function handle(): int
    {
        $tenants = $this->option('tenant')
            ? Tenant::where('id', $this->option('tenant'))->get()
            : Tenant::all();

        if ($tenants->isEmpty()) {
            $this->error('Tenant topilmadi.');
            return self::FAILURE;
        }

        $anyFailure = false;

        foreach ($tenants as $tenant) {
            $this->newLine();
            $this->line("<fg=cyan;options=bold>━━ Tenant: {$tenant->id} ━━</>");

            // Tekshiruvlar tenant kontekstida (tenant connection + search_path) bajariladi.
            // Closure'dan faqat primitiv/array qaytaramiz (Eloquent Collection EMAS).
            $results = $tenant->run(fn () => $this->runChecks());

            $rows = [];
            foreach ($results as $r) {
                $ok = $r['violations'] === 0;
                $anyFailure = $anyFailure || ! $ok;
                $rows[] = [
                    $ok ? '<fg=green>PASS</>' : '<fg=red>FAIL</>',
                    $r['name'],
                    $ok ? '—' : (string) $r['violations'],
                ];

                if (! $ok && $this->option('details')) {
                    $this->newLine();
                    $this->warn("  ✗ {$r['name']} — {$r['violations']} ta buzilish:");
                    $this->table($r['columns'], array_map('get_object_vars', $r['sample']));
                }
            }

            $this->table(['Holat', 'Invariant', 'Buzilish'], $rows);
        }

        $this->newLine();
        if ($anyFailure) {
            $this->error('✗ Hisob-kitobда chetlanish topildi. Yuqoridagi FAIL qatorlarini tekshiring (--details bilan tafsilot).');
            return self::FAILURE;
        }

        $this->info('✓ Barcha invariantlar toza — hisob-kitoblar izchil.');
        return self::SUCCESS;
    }

    /**
     * Barcha invariantlarni tenant connection'da bajaradi.
     *
     * @return array<int, array{name:string, violations:int, columns:array<int,string>, sample:array}>
     */
    private function runChecks(): array
    {
        $eps = self::EPS;

        $checks = [
            // 1) Investor balansi = Σ investments (kredit − debet)
            'Investor.balance = Σ investments' => "
                SELECT i.id, i.name, round(i.balance,2) AS balance,
                       round(COALESCE(v.net,0),2) AS investments_net,
                       round(i.balance - COALESCE(v.net,0),2) AS diff
                FROM investors i
                LEFT JOIN (
                    SELECT investor_id, SUM(CASE WHEN is_credit THEN amount ELSE -amount END) net
                    FROM investments GROUP BY investor_id
                ) v ON v.investor_id = i.id
                WHERE abs(i.balance - COALESCE(v.net,0)) > {$eps}",

            // 2) sale.total_price = Σ sale_items.subtotal
            'sale.total = Σ sale_items' => "
                SELECT s.id, round(s.total_price,2) total_price,
                       round(COALESCE(SUM(si.subtotal),0),2) items_sum,
                       round(s.total_price - COALESCE(SUM(si.subtotal),0),2) diff
                FROM sales s LEFT JOIN sale_items si ON si.sale_id = s.id
                GROUP BY s.id, s.total_price
                HAVING abs(s.total_price - COALESCE(SUM(si.subtotal),0)) > {$eps}",

            // 3) Tasdiqlangan sotuv summasi = tasdiqlangan to'lovlar (USD)
            'accepted sale.total = paid USD' => "
                SELECT s.id, round(s.total_price,2) total_price,
                       round(SUM(CASE WHEN sp.currency='usd' THEN sp.amount ELSE sp.amount/NULLIF(sp.rate,0) END),2) paid_usd
                FROM sales s JOIN sale_payments sp ON sp.sale_id = s.id AND sp.status='accepted'
                GROUP BY s.id, s.total_price
                HAVING abs(s.total_price - SUM(CASE WHEN sp.currency='usd' THEN sp.amount ELSE sp.amount/NULLIF(sp.rate,0) END)) > {$eps}",

            // 4) ⭐ Har tasdiqlangan sotuv: kutilgan investor krediti = kreditlangan
            //    (ko'p-investor split mantig'ining yaxlit tekshiruvi)
            'per-sale investor credit reconcile' => "
                WITH owner AS (
                    SELECT si.sale_id, si.subtotal,
                           CASE WHEN si.item_type='serial' THEN iv.investor_id ELSE ac.investor_id END AS investor_id
                    FROM sale_items si
                    LEFT JOIN inventories iv ON si.item_type='serial' AND iv.id = si.inventory_id
                    LEFT JOIN accessories ac ON si.item_type='bulk'   AND ac.id = si.accessory_id
                ),
                expected AS (
                    SELECT sale_id, SUM(subtotal) FILTER (WHERE investor_id IS NOT NULL) exp_credit
                    FROM owner GROUP BY sale_id
                ),
                actual AS (
                    SELECT (t.details->>'sale_id')::int sale_id, SUM(inv.amount) act_credit
                    FROM investments inv JOIN transactions t ON t.id = inv.transaction_id
                    WHERE inv.type=3 AND inv.is_credit AND t.type='sale'
                    GROUP BY (t.details->>'sale_id')::int
                )
                SELECT s.id AS sale_id,
                       round(COALESCE(e.exp_credit,0),2) expected,
                       round(COALESCE(a.act_credit,0),2) actual,
                       round(COALESCE(a.act_credit,0) - COALESCE(e.exp_credit,0),2) diff
                FROM sales s
                JOIN (SELECT DISTINCT sale_id FROM sale_payments WHERE status='accepted') acc ON acc.sale_id = s.id
                LEFT JOIN expected e ON e.sale_id = s.id
                LEFT JOIN actual   a ON a.sale_id = s.id
                WHERE abs(COALESCE(a.act_credit,0) - COALESCE(e.exp_credit,0)) > {$eps}",

            // 5) Aksessuar miqdori sog'lom (manfiy emas, sold+consigned ≤ quantity)
            'accessory qty sanity' => "
                SELECT id, barcode, quantity, sold_quantity, consigned_quantity
                FROM accessories
                WHERE sold_quantity < 0 OR consigned_quantity < 0
                   OR sold_quantity + consigned_quantity > quantity",

            // 6) Yetim investment (transaction_id bor, lekin transaction yo'q)
            'no orphan investments' => "
                SELECT inv.id, inv.transaction_id
                FROM investments inv
                LEFT JOIN transactions t ON t.id = inv.transaction_id
                WHERE inv.transaction_id IS NOT NULL AND t.id IS NULL",

            // 7) Kassa: har tasdiqlangan to'lovga aniq bitta Sale tranzaksiya (transaction_id)
            'accepted payment has Sale txn' => "
                SELECT sp.id, sp.sale_id, sp.status
                FROM sale_payments sp
                LEFT JOIN transactions t ON t.id = sp.transaction_id AND t.type='sale'
                WHERE sp.status='accepted' AND t.id IS NULL",

            // 8) To'lov modeli: bitta sotuvда accepted+new aralashmasin (atomar tasdiqlash)
            'payment state (no accepted+new mix)' => "
                SELECT sale_id
                FROM sale_payments GROUP BY sale_id
                HAVING bool_or(status='accepted') AND bool_or(status='new')",

            // 9) Manfiy investor balansi (biznes qoidasi buzilishi belgisi)
            'no negative investor balance' => "
                SELECT id, name, round(balance,2) balance FROM investors WHERE balance < -{$eps}",
        ];

        $out = [];
        foreach ($checks as $name => $sql) {
            $rows = DB::select($sql);
            $out[] = [
                'name' => $name,
                'violations' => count($rows),
                'columns' => $rows ? array_keys(get_object_vars($rows[0])) : [],
                'sample' => array_slice($rows, 0, 10),
            ];
        }

        return $out;
    }
}
