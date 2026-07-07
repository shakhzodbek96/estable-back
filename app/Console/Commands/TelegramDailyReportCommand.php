<?php

namespace App\Console\Commands;

use App\Jobs\SendTelegramDailyReport;
use App\Services\TelegramReportService;
use Illuminate\Console\Command;

/**
 * Joriy tenant admin kanaliga kunlik Telegram hisobotini navbatga qo'yadi.
 *
 * Scheduler har SOAT `tenants:run telegram:daily-report` chaqiradi (har tenant uchun).
 * Bu komanda tenant sozlamasidagi `send_hour`ni joriy soat bilan solishtiradi —
 * mos kelsa job dispatch qiladi. Shu tarzda har tenant o'z vaqtini tanlaydi,
 * bitta global scheduler yozuvi yetarli bo'ladi.
 *
 *   php artisan tenants:run telegram:daily-report   # scheduler shunday chaqiradi
 *   php artisan telegram:daily-report --force        # vaqtni tekshirmasdan darhol
 */
class TelegramDailyReportCommand extends Command
{
    protected $signature = 'telegram:daily-report {--force : Vaqtni tekshirmasdan darhol yuborish}';

    protected $description = 'Kunlik Telegram hisobotini joriy tenant kanaliga yuboradi';

    /** Owner kutgan soatga mos bo'lishi uchun O'zbekiston vaqti. */
    private const TIMEZONE = 'Asia/Tashkent';

    public function handle(TelegramReportService $service): int
    {
        $cfg = $service->config();

        if (! $cfg['daily_report_enabled']) {
            return self::SUCCESS; // o'chirilgan — jim o'tkazib yuboriladi
        }

        $currentHour = (int) now()->setTimezone(self::TIMEZONE)->hour;

        if (! $this->option('force') && $currentHour !== $cfg['send_hour']) {
            return self::SUCCESS; // hozir bu tenant'ning yuborish soati emas
        }

        SendTelegramDailyReport::dispatch();
        $this->info("[{$cfg['send_hour']}:00] Telegram hisobot navbatga qo'yildi.");

        return self::SUCCESS;
    }
}
