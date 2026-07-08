<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Setting;
use App\Models\TelegramConfig;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Joriy tenant uchun kunlik KPI hisobotini Telegram kanaliga yuboradi.
 *
 * ReportService (ma'lumot) + TelegramService (transport) ni birlashtiradi.
 * Ham qo'lda ("Отправить сейчас" tugmasi), ham scheduler (kunlik job)
 * shu servisdan foydalanadi.
 */
class TelegramReportService
{
    public function __construct(
        private ReportService $reports,
        private TelegramService $telegram,
        private TelegramTemplateService $templates,
    ) {
    }

    /** Tenant maqsadli-config kesh kaliti (tenant-scoped). */
    public const CACHE_KEY = 'telegram_tenant_cfg';

    /**
     * Kunlik hisobotni tenant sozlagan "report_chat_id"ga yuboradi (markaziy bot orqali).
     *
     * @return array{ok: bool, skipped?: bool, error?: string|null}
     */
    public function sendDailyReport(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $cfg = $this->config();

        if (! $cfg['daily_report_enabled']) {
            return ['ok' => false, 'skipped' => true, 'error' => null];
        }
        if ($cfg['bot_token'] === '') {
            return ['ok' => false, 'error' => 'Центральный бот не настроен администратором.'];
        }
        if ($cfg['report_chat_id'] === '') {
            return ['ok' => false, 'error' => 'Не указан Chat ID для отчётов.'];
        }

        $to = $dateTo ?? now()->toDateString();
        $from = $dateFrom ?? $to; // standart — bugungi kun

        $data = $this->reports->dashboard(['date_from' => $from, 'date_to' => $to]);
        $text = $this->format($data, $from, $to);

        return $this->telegram->sendMessage($cfg['bot_token'], $cfg['report_chat_id'], $text);
    }

    /**
     * Bitta sotuv bildirishnomasini tenant sozlagan "sale_chat_id"ga yuboradi.
     * POS sotuvi commit bo'lgach (SendSaleTelegramNotification job orqali) chaqiriladi.
     *
     * @return array{ok: bool, skipped?: bool, error?: string|null}
     */
    public function sendSaleNotification(int $saleId): array
    {
        $cfg = $this->config();

        if (! $cfg['notify_on_sale']) {
            return ['ok' => false, 'skipped' => true, 'error' => null];
        }
        if ($cfg['bot_token'] === '') {
            return ['ok' => false, 'error' => 'Центральный бот не настроен.'];
        }
        if ($cfg['sale_chat_id'] === '') {
            return ['ok' => false, 'error' => 'Не указан Chat ID для продаж.'];
        }

        $sale = Sale::with(['customer:id,name', 'seller:id,name', 'shop:id,name', 'items:id,sale_id,quantity'])->find($saleId);

        if (! $sale) {
            return ['ok' => false, 'skipped' => true, 'error' => null];
        }

        return $this->telegram->sendMessage($cfg['bot_token'], $cfg['sale_chat_id'], $this->formatSale($sale));
    }

    /**
     * Tenant maqsadli-konfiguratsiyasi + markaziy bot token (keshlangan).
     *
     * @return array{bot_token: string, notify_on_sale: bool, sale_chat_id: string, daily_report_enabled: bool, report_chat_id: string, send_hour: int}
     */
    public function config(): array
    {
        // Tenant sozlamasi keshlanadi (saqlashda forgetCache bilan tozalanadi)
        $p = Cache::remember(self::CACHE_KEY, 600, fn () => Setting::getValue(Setting::TELEGRAM_BOT_CONFIG, []));
        $p = is_array($p) ? $p : [];

        return [
            'bot_token' => TelegramConfig::activeToken(), // markaziy, keshlangan
            'notify_on_sale' => (bool) ($p['notify_on_sale'] ?? false),
            'sale_chat_id' => trim((string) ($p['sale_chat_id'] ?? '')),
            'daily_report_enabled' => (bool) ($p['daily_report_enabled'] ?? false),
            'report_chat_id' => trim((string) ($p['report_chat_id'] ?? '')),
            'send_hour' => max(0, min(23, (int) ($p['send_hour'] ?? 21))),
        ];
    }

    /** Tenant config kesh'ini tozalash (sozlama saqlanganда). */
    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Dashboard ma'lumotidan Telegram HTML matnini yasaydi.
     */
    private function format(array $d, string $from, string $to): string
    {
        $money = static fn ($v): string => '$' . number_format((float) $v, 2);
        $esc = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $title = $from === $to
            ? $this->humanDate($to)
            : $this->humanDate($from) . ' — ' . $this->humanDate($to);

        $s = $d['sales'];
        $inv = $d['inventory'];

        $L = [];
        $L[] = '📊 <b>Отчёт · ' . $esc($title) . '</b>';
        $L[] = '';
        $L[] = '🛒 <b>Продажи</b>';
        $L[] = 'Чеков: <b>' . (int) $s['count'] . '</b>';
        $L[] = 'Выручка: <b>' . $money($s['total_revenue']) . '</b>';
        $L[] = 'Прибыль: <b>' . $money($s['gross_profit']) . '</b>';

        // ---- Kassa (to'lov turlari bo'yicha) ----
        $byMethod = $s['by_payment_method'] ?? collect();
        $methodPairs = is_array($byMethod) ? $byMethod : $byMethod->all();
        if (! empty($methodPairs)) {
            $L[] = '';
            $L[] = '💰 <b>Касса (принято)</b>';
            foreach ($methodPairs as $type => $amount) {
                $L[] = $this->methodLabel((string) $type) . ': ' . $money($amount);
            }
        }

        // ---- Foyda ----
        $L[] = '';
        $L[] = '💸 Расходы: <b>' . $money($d['expenses']['total']) . '</b>';
        $L[] = '✅ Чистая прибыль: <b>' . $money($d['net_profit']) . '</b>';

        if ((float) $d['pending_payments'] > 0) {
            $L[] = '⏳ Неподтверждённые платежи: <b>' . $money($d['pending_payments']) . '</b>';
        }

        // ---- Sklad ----
        $L[] = '';
        $L[] = '📦 <b>Склад</b>';
        $L[] = 'Серийные: ' . (int) $inv['serial_count'] . ' шт · ' . $money($inv['serial_value']);
        $L[] = 'Аксессуары: ' . (int) $inv['accessories_count'] . ' · ' . $money($inv['accessories_value']);
        $L[] = 'Итого: <b>' . $money($inv['total_value']) . '</b>';

        // ---- Kam qolgan tovarlar ----
        $low = $d['low_stock'] ?? collect();
        $lowArr = is_array($low) ? $low : (method_exists($low, 'all') ? $low->all() : []);
        if (! empty($lowArr)) {
            $L[] = '';
            $L[] = '⚠️ <b>Заканчивается</b>';
            foreach (array_slice($lowArr, 0, 5) as $row) {
                $name = is_object($row) ? ($row->product_name ?? '') : ($row['product_name'] ?? '');
                $avail = is_object($row) ? ($row->available ?? 0) : ($row['available'] ?? 0);
                $L[] = '• ' . $esc($name) . ' — ' . (int) $avail . ' шт';
            }
        }

        if ((int) $d['new_customers'] > 0) {
            $L[] = '';
            $L[] = '👥 Новых клиентов: <b>' . (int) $d['new_customers'] . '</b>';
        }

        return implode("\n", $L);
    }

    /**
     * Bitta sotuv uchun qisqa Telegram HTML matnini yasaydi.
     */
    private function formatSale(Sale $sale): string
    {
        $money = static fn ($v): string => '$' . number_format((float) $v, 2);
        $esc = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $when = $sale->created_at
            ? $sale->created_at->copy()->setTimezone('Asia/Tashkent')->format('d.m.Y H:i')
            : $this->humanDate(now()->toDateString());

        // payment_method PaymentMethod enum'ga cast qilinadi, lekin 'multiple'/'transfer'
        // kabi qiymatlar enum'da bo'lmasligi mumkin — xom qiymatni o'qiymiz (cast'siz).
        $method = (string) ($sale->getRawOriginal('payment_method') ?? '');

        // Shablon uchun ma'lumot — dinamik matnlar esc'lanadi (raqamlar xavfsiz).
        $data = [
            'sale_id' => (string) (int) $sale->id,
            'datetime' => $when,
            'seller' => $sale->seller ? $esc($sale->seller->name) : '—',
            'customer' => $sale->customer ? $esc($sale->customer->name) : '—',
            'items_count' => (string) (int) $sale->items->sum('quantity'),
            'payment' => $method !== '' ? $this->methodLabel($method) : '—',
            'total' => $money($sale->total_price),
            'shop' => $sale->shop ? $esc($sale->shop->name) : '—',
        ];

        return $this->templates->render('sale', $data);
    }

    /** To'lov turi kodini ruscha nomiga o'giradi. */
    private function methodLabel(string $type): string
    {
        return match ($type) {
            'cash' => 'Наличные',
            'card' => 'Карта',
            'transfer' => 'Перевод',
            'terminal' => 'Терминал',
            'debt' => 'Долг',
            'multiple' => 'Несколько способов',
            default => mb_convert_case($type, MB_CASE_TITLE, 'UTF-8'),
        };
    }

    private function humanDate(string $d): string
    {
        return Carbon::parse($d)->format('d.m.Y');
    }
}
