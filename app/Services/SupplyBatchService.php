<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\SupplyBatch;

/**
 * Kirim (intake) uchun Партия yaratish + nasiya (credit) qarz mantig'i.
 *
 * Serial (InventoryService) va aksessuar (AccessoryService) kirimlaridan chaqiriladi.
 * Partiya IXTIYORIY — postavshik/manba berilmasa null qaytadi (eski xatti-harakat).
 *
 * To'lov rejimi:
 *   - paid   — darrov to'langan: chaqiruvchi service odatdagi Purchase Transaction /
 *              investor mantig'ini bajaradi.
 *   - credit — nasiya: postavshik balansiga (bizning qarzimiz) total_cost qo'shiladi,
 *              kassadan chiqim YO'Q, investor ishtirok etmaydi. (Chaqiruvchi service
 *              isCredit() bo'yicha Purchase Transaction'ni o'tkazib yuboradi.)
 */
class SupplyBatchService
{
    /**
     * Kirim uchun partiya yaratadi. Manba (supplier_id yoki supplier_name) bo'lmasa null.
     * total_cost dastlab 0 — item'lar yaratilgach finalize() bilan yangilanadi.
     */
    public function createForIntake(array $data, int $shopId): ?SupplyBatch
    {
        $supplierId = $data['supplier_id'] ?? null;
        $supplierName = trim((string) ($data['supplier_name'] ?? '')) ?: null;

        // Manba yo'q — partiya yaratilmaydi (eski oqim: oddiy kirim).
        if (! $supplierId && ! $supplierName) {
            return null;
        }

        // Nasiya faqat SAQLANGAN postavshik uchun (qarzni yozadigan joy kerak).
        // Walk-in (supplier_name) doim 'paid'.
        $mode = ($data['payment_mode'] ?? 'paid') === 'credit' && $supplierId ? 'credit' : 'paid';

        return SupplyBatch::create([
            'supplier_id' => $supplierId,
            'supplier_name' => $supplierId ? null : $supplierName,
            'invoice_number' => $data['invoice_number'] ?? null,
            'batch_date' => $data['batch_date'] ?? now()->toDateString(),
            'payment_mode' => $mode,
            'total_cost' => 0,
            'notes' => $data['batch_notes'] ?? null,
            'shop_id' => $shopId,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Item'lar yaratilgach: partiya total_cost'ini yozadi va nasiya bo'lsa
     * postavshik balansiga (qarz) qo'shadi.
     */
    public function finalize(SupplyBatch $batch, float $totalCost): void
    {
        $batch->total_cost = $totalCost;
        $batch->save();

        if ($batch->payment_mode === 'credit' && $batch->supplier_id && $totalCost > 0) {
            Supplier::whereKey($batch->supplier_id)->lockForUpdate()->increment('balance', $totalCost);
        }
    }

    public function isCredit(?SupplyBatch $batch): bool
    {
        return $batch !== null && $batch->payment_mode === 'credit';
    }

    /**
     * Item (Inventory/Accessory) o'chirilganda chaqiriladi — agar item nasiya
     * partiyasidan bo'lsa, postavshik qarzini (balance) va partiya total_cost'ini
     * o'chirilgan item narxiga kamaytiradi. Aks holda qarz noto'g'ri ("shishgan")
     * qolib ketardi. Investor bilan moliyalashgan item'larda ishlatilmaydi —
     * bunday item credit partiyaga tegishli bo'la olmaydi (createForIntake'da
     * investor_id credit uchun null qilinadi).
     */
    public function reverseCreditForItem(?int $supplyBatchId, float $contribution): void
    {
        if (! $supplyBatchId || $contribution <= 0) {
            return;
        }

        $batch = SupplyBatch::find($supplyBatchId);
        if (! $batch || $batch->payment_mode !== 'credit' || ! $batch->supplier_id) {
            return;
        }

        $contribution = round($contribution, 2);

        Supplier::whereKey($batch->supplier_id)->lockForUpdate()->decrement('balance', $contribution);

        $batch->total_cost = max(0, (float) $batch->total_cost - $contribution);
        $batch->save();
    }
}
