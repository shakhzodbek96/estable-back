<?php

namespace App\Http\Controllers\Api;

use App\Enums\InventoryStatus;
use App\Enums\InvestmentType;
use App\Enums\SalePaymentStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Models\Accessory;
use App\Models\Inventory;
use App\Models\Investment;
use App\Models\Investor;
use App\Models\Rate;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InvestmentController extends Controller
{
    /**
     * Investor bo'yicha investitsiya/divident yozuvlari ro'yxati.
     * Filter: investor_id (majburiy), type, date_from, date_to
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'investor_id' => 'required|integer|exists:investors,id',
            'type' => ['nullable', Rule::in([1, 2])],
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $q = Investment::query()
            ->where('investor_id', $request->integer('investor_id'))
            ->with(['creator:id,name'])
            ->latest('id');

        if ($type = $request->integer('type')) {
            $q->where('type', $type);
        }
        if ($df = $request->input('date_from')) {
            $q->whereDate('created_at', '>=', $df);
        }
        if ($dt = $request->input('date_to')) {
            $q->whereDate('created_at', '<=', $dt);
        }

        return response()->json($q->paginate($request->integer('per_page', 15)));
    }

    /**
     * Investor bo'yicha barcha vaqtdagi jami summalar (type bo'yicha).
     * GET /api/investments/totals?investor_id=X
     */
    public function totals(Request $request): JsonResponse
    {
        $request->validate([
            'investor_id' => 'required|integer|exists:investors,id',
        ]);

        $investorId = $request->integer('investor_id');

        $rows = Investment::where('investor_id', $investorId)
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $investment = (float) ($rows[1] ?? 0);            // tikkan sarmoya (depozitlar)
        $inGoods = $this->inGoodsValue($investorId);       // hozir tovardagi kapital (sotilmagan)
        $pending = $this->pendingReceivableValue($investorId); // sotilgan, kassada tasdiqlanmagan tushum
        $balance = (float) Investor::whereKey($investorId)->value('balance');

        // Yig'ilgan (yechilmagan) foyda = joriy balans + tovardagi qiymat − tikkan sarmoya.
        // ESLATMA: pending (kutilayotgan tushum) foydaga QO'SHILMAYDI — u faqat kassada
        // tasdiqlangach balansga tushadi. Alohida ko'rsatkich sifatida chiqariladi.
        $accumulatedProfit = round($balance + $inGoods - $investment, 2);

        return response()->json([
            'investment' => $investment,
            'dividend' => (float) ($rows[2] ?? 0),
            'clients_payment' => (float) ($rows[3] ?? 0),
            'buying_product' => (float) ($rows[4] ?? 0),
            'in_goods' => $inGoods,
            'pending_receivable' => $pending,
            'balance' => round($balance, 2),
            'accumulated_profit' => $accumulatedProfit,
        ]);
    }

    /**
     * Investor mablag'iga olingan, hali SOTILMAGAN tovarlardagi kapital (tannarx bo'yicha).
     * Serial: purchase_price + extra_cost (sold/written_off bundan tashqari).
     * Aksessuar: purchase_price * (quantity − sold_quantity − consigned_quantity).
     */
    private function inGoodsValue(int $investorId): float
    {
        $serial = (float) Inventory::where('investor_id', $investorId)
            ->whereNotIn('status', [InventoryStatus::Sold->value, InventoryStatus::WrittenOff->value])
            ->selectRaw('COALESCE(SUM(purchase_price + extra_cost), 0) as t')
            ->value('t');

        $acc = (float) Accessory::where('investor_id', $investorId)
            ->selectRaw('COALESCE(SUM(purchase_price * (quantity - sold_quantity - consigned_quantity)), 0) as t')
            ->value('t');

        return round($serial + $acc, 2);
    }

    /**
     * Kutilayotgan tushum: investor tovarlari SOTILGAN, lekin kassada to'lovi hali
     * TASDIQLANMAGAN (status=new) sotuvlar bo'yicha kutilayotgan tushum (subtotal, USD).
     *
     * Bu tovarlar sklad hisobidan (in_goods) chiqib ketgan, ammo pul balansga hali
     * kelmagan — shu "oraliq"da investor qiymati vaqtincha tushib ko'rinmasligi uchun
     * alohida ko'rsatiladi. To'lov tasdiqlangач bu summa balansga o'tadi.
     */
    private function pendingReceivableValue(int $investorId): float
    {
        $new = SalePaymentStatus::New->value;

        $hasPendingPayment = fn ($q) => $q->from('sale_payments')
            ->whereColumn('sale_payments.sale_id', 'sales.id')
            ->where('sale_payments.status', $new);

        $serial = (float) DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->join('inventories', 'sale_items.inventory_id', '=', 'inventories.id')
            ->where('sale_items.item_type', 'serial')
            ->where('inventories.investor_id', $investorId)
            ->whereExists($hasPendingPayment)
            ->sum('sale_items.subtotal');

        $acc = (float) DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->join('accessories', 'sale_items.accessory_id', '=', 'accessories.id')
            ->where('sale_items.item_type', 'bulk')
            ->where('accessories.investor_id', $investorId)
            ->whereExists($hasPendingPayment)
            ->sum('sale_items.subtotal');

        return round($serial + $acc, 2);
    }

    /**
     * Yangi yozuv qo'shish (1=Investitsiya +, 2=Divident -).
     * Investor balansini avtomatik yangilaydi.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'investor_id' => 'required|integer|exists:investors,id',
            'type' => ['required', Rule::in([1, 2])],
            'amount' => 'required|numeric|min:0.01',
            // Dividend (type=2) uchun: do'kon ulushi (foyda taqsimoti) — qo'lda kiritiladi
            'shop_share' => 'nullable|numeric|min:0',
            'comment' => 'nullable|string|max:1000',
        ]);

        try {
            $investment = DB::transaction(function () use ($data, $request) {
                $investor = Investor::lockForUpdate()->findOrFail($data['investor_id']);
                $type = InvestmentType::from((int) $data['type']);
                $isCredit = $type === InvestmentType::Investment; // +investment, -dividend
                $amount = (float) $data['amount'];
                $rate = Rate::current();

                $shopShare = 0.0;
                if ($type === InvestmentType::Dividend) {
                    // Dividend = foyda yechish: yig'ilgan foydadan oshmasligi shart
                    $invested = (float) Investment::where('investor_id', $investor->id)->where('type', 1)->sum('amount');
                    $accProfit = round((float) $investor->balance + $this->inGoodsValue($investor->id) - $invested, 2);

                    if ($amount > $accProfit + 0.01) {
                        throw new \RuntimeException(
                            'Дивиденд не может превышать накопленную прибыль. Доступно: $' . number_format(max(0, $accProfit), 2, '.', '')
                        );
                    }

                    $shopShare = round((float) ($data['shop_share'] ?? 0), 2);
                    if ($shopShare > $amount + 0.01) {
                        throw new \RuntimeException('Доля магазина не может превышать сумму дивиденда');
                    }
                }

                // Dividend bo'lsa — izohga taqsimotni yozamiz
                $comment = $data['comment'] ?? null;
                if ($type === InvestmentType::Dividend && $shopShare > 0) {
                    $investorGets = round($amount - $shopShare, 2);
                    $split = 'Доля магазина: $' . number_format($shopShare, 2, '.', '')
                        . ', инвестору: $' . number_format($investorGets, 2, '.', '');
                    $comment = $comment ? ($comment . ' | ' . $split) : $split;
                }

                $investment = Investment::create([
                    'investor_id' => $investor->id,
                    'type' => $type,
                    'is_credit' => $isCredit,
                    'amount' => $amount,
                    'rate' => $rate?->rate ?? 0,
                    'comment' => $comment,
                    'created_by' => $request->user()->id,
                ]);

                $delta = $amount * ($isCredit ? 1 : -1);
                $investor->balance = (float) $investor->balance + $delta;
                $investor->save();

                // Do'kon ulushi — shop foydasi sifatida Transaction (investordan olingan foyda)
                if ($type === InvestmentType::Dividend && $shopShare > 0) {
                    Transaction::create([
                        'amount' => $shopShare,
                        'currency' => 'usd',
                        'rate' => $rate?->rate ?? 0,
                        'is_credit' => true, // do'kon uchun daromad
                        'type' => TransactionType::InvestorProfitShare,
                        'transaction_date' => now()->toDateString(),
                        'shop_id' => $request->user()->shop_id,
                        'investor_id' => $investor->id,
                        'created_by' => $request->user()->id,
                        'accepted_by' => $request->user()->id,
                        'details' => [
                            'investor_id' => $investor->id,
                            'investment_id' => $investment->id,
                            'dividend_amount' => $amount,
                            'note' => 'Доля магазина с дивиденда инвестора',
                        ],
                    ]);
                }

                return $investment->load('creator:id,name');
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($investment, 201);
    }

    /**
     * Mavjud yozuvni tahrirlash (summa/izoh/tur).
     */
    public function update(Request $request, Investment $investment): JsonResponse
    {
        $data = $request->validate([
            'type' => ['sometimes', Rule::in([1, 2])],
            'amount' => 'sometimes|numeric|min:0.01',
            'comment' => 'nullable|string|max:1000',
        ]);

        $updated = DB::transaction(function () use ($investment, $data) {
            $investor = Investor::lockForUpdate()->findOrFail($investment->investor_id);

            // Eski ta'sirni qaytarish
            $oldDelta = (float) $investment->amount * ($investment->is_credit ? 1 : -1);
            $investor->balance = (float) $investor->balance - $oldDelta;

            // Yangi qiymatlar
            $newType = isset($data['type'])
                ? InvestmentType::from((int) $data['type'])
                : $investment->type;
            $newIsCredit = $newType === InvestmentType::Investment;
            $newAmount = (float) ($data['amount'] ?? $investment->amount);

            $investment->fill([
                'type' => $newType,
                'is_credit' => $newIsCredit,
                'amount' => $newAmount,
                'comment' => array_key_exists('comment', $data) ? $data['comment'] : $investment->comment,
            ])->save();

            $newDelta = $newAmount * ($newIsCredit ? 1 : -1);
            $investor->balance = (float) $investor->balance + $newDelta;
            $investor->save();

            return $investment->load('creator:id,name');
        });

        return response()->json($updated);
    }

    /**
     * Yozuvni o'chirish — investor balansidan ta'sirni qaytaradi.
     */
    public function destroy(Investment $investment): JsonResponse
    {
        DB::transaction(function () use ($investment) {
            $investor = Investor::lockForUpdate()->findOrFail($investment->investor_id);
            $delta = (float) $investment->amount * ($investment->is_credit ? 1 : -1);
            $investor->balance = (float) $investor->balance - $delta;
            $investor->save();

            // Dividend bo'lsa — bog'liq do'kon-ulush (InvestorProfitShare) tranzaksiyasini ham o'chiramiz
            if ($investment->type === InvestmentType::Dividend) {
                Transaction::where('type', TransactionType::InvestorProfitShare)
                    ->where('details->investment_id', $investment->id)
                    ->delete();
            }

            $investment->delete();
        });

        return response()->json(['message' => 'Deleted']);
    }
}
