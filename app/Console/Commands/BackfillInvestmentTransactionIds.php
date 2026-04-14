<?php

namespace App\Console\Commands;

use App\Enums\InvestmentType;
use App\Enums\TransactionType;
use App\Models\Investment;
use App\Models\Transaction;
use Illuminate\Console\Command;

/**
 * Mavjud investments yozuvlari uchun transaction_id ni topib to'ldirish.
 *
 * Qoidalar:
 *  - type=3 (ClientsPayment), comment "Sotuv #X to'lovi"
 *      → Transaction type=sale, details.sale_id=X, investor_id mos, amount bir xil
 *  - type=3, comment "Konsignatsiya sotuvi #X"
 *      → Transaction type=consignment_receipt, details.consignment_id=X, investor_id mos
 *  - type=3 (refund), comment "Qaytarish #X"
 *      → Transaction type=refund, details.return_id=X, investor_id mos
 *  - type=4 (BuyingProduct) — eski yozuvlar uchun Transaction mavjud emas, skip
 *
 * Ishga tushirish:
 *   php artisan investments:backfill-transaction-ids --dry   (preview)
 *   php artisan investments:backfill-transaction-ids         (apply)
 */
class BackfillInvestmentTransactionIds extends Command
{
    protected $signature = 'investments:backfill-transaction-ids {--dry : Faqat ko\'rsatish, yozmaslik}';
    protected $description = 'Mavjud investments uchun mos transaction_id ni topib to\'ldirish';

    public function handle(): int
    {
        $dry = $this->option('dry');
        $stats = ['matched' => 0, 'not_found' => 0, 'skipped' => 0, 'already' => 0];

        $query = Investment::whereNull('transaction_id')
            ->whereIn('type', [InvestmentType::ClientsPayment]);

        $total = $query->count();
        $this->info("Kandidatlar: {$total} ta yozuv");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(200, function ($investments) use (&$stats, $dry, $bar) {
            foreach ($investments as $inv) {
                $bar->advance();
                $txId = $this->findMatchingTransactionId($inv);

                if ($txId === null) {
                    $stats['not_found']++;
                    continue;
                }

                if (!$dry) {
                    $inv->update(['transaction_id' => $txId]);
                }
                $stats['matched']++;
            }
        });

        $bar->finish();
        $this->newLine(2);

        // BuyingProduct — alohida hisob
        $buyingCount = Investment::whereNull('transaction_id')
            ->where('type', InvestmentType::BuyingProduct)
            ->count();

        $this->table(['Natija', 'Miqdor'], [
            ['Topildi va yangilandi', $stats['matched']],
            ['Mos kelmadi', $stats['not_found']],
            ['BuyingProduct (Transaction yo\'q, skip)', $buyingCount],
        ]);

        if ($dry) {
            $this->warn("DRY RUN: hech narsa yozilmadi. --dry olib tashlang applicato.");
        }

        return self::SUCCESS;
    }

    private function findMatchingTransactionId(Investment $inv): ?int
    {
        $comment = $inv->comment ?? '';
        $amount = (float) $inv->amount;

        // 1. Sotuv to'lovi: "Sotuv #123 to'lovi"
        if (preg_match("/Sotuv #(\d+)/u", $comment, $m)) {
            $saleId = (int) $m[1];
            return $this->matchSaleTransaction($inv->investor_id, $saleId, $amount);
        }

        // 2. Konsignatsiya: "Konsignatsiya sotuvi #45"
        if (preg_match('/Konsignatsiya sotuvi #(\d+)/u', $comment, $m)) {
            $consId = (int) $m[1];
            return $this->matchConsignmentTransaction($inv->investor_id, $consId, $amount);
        }

        // 3. Qaytarish: "Qaytarish #7"
        if (preg_match('/Qaytarish #(\d+)/u', $comment, $m)) {
            $returnId = (int) $m[1];
            return $this->matchReturnTransaction($inv->investor_id, $returnId, $amount);
        }

        return null;
    }

    private function matchSaleTransaction(int $investorId, int $saleId, float $amount): ?int
    {
        // PostgreSQL jsonb: details->>'sale_id' = '123'
        return Transaction::where('type', TransactionType::Sale)
            ->where('investor_id', $investorId)
            ->whereRaw("(details->>'sale_id')::int = ?", [$saleId])
            ->whereRaw('ABS(amount - ?) < 0.01', [$amount])
            ->orderBy('id')
            ->value('id');
    }

    private function matchConsignmentTransaction(int $investorId, int $consId, float $amount): ?int
    {
        return Transaction::where('type', TransactionType::ConsignmentReceipt)
            ->where('investor_id', $investorId)
            ->whereRaw("(details->>'consignment_id')::int = ?", [$consId])
            ->whereRaw('ABS(amount - ?) < 0.01', [$amount])
            ->orderBy('id')
            ->value('id');
    }

    private function matchReturnTransaction(int $investorId, int $returnId, float $amount): ?int
    {
        return Transaction::where('type', TransactionType::Refund)
            ->where('investor_id', $investorId)
            ->whereRaw("(details->>'return_id')::int = ?", [$returnId])
            ->whereRaw('ABS(amount - ?) < 0.01', [$amount])
            ->orderBy('id')
            ->value('id');
    }
}
