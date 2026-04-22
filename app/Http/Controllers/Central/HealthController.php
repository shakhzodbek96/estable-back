<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Jobs\TestQueueJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Health check endpoints — queue va scheduler monitoring uchun.
 * Faqat admin (auth:sanctum + admin middleware) orqali kirish mumkin.
 */
class HealthController extends Controller
{
    public const SCHEDULER_CACHE_KEY = 'health:scheduler:last_run';

    /**
     * POST /health/queue-test
     *
     * TestQueueJob'ni queue'ga dispatch qiladi. Queue worker uni bir necha
     * sekundda bajaradi va Cache'ga timestamp yozadi. So'ngra
     * GET /health/queue-status orqali bajarilganini ko'rish mumkin.
     */
    public function dispatchQueueTest(Request $request): JsonResponse
    {
        $adminId = $request->user()?->id;
        $jobsBefore = DB::table('jobs')->count();

        TestQueueJob::dispatch("admin:{$adminId}");

        return response()->json([
            'dispatched' => true,
            'queue_driver' => config('queue.default'),
            'jobs_pending_before' => $jobsBefore,
            'jobs_pending_after' => DB::table('jobs')->count(),
            'hint' => 'Bir necha sekund kuting va GET /health/queue-status bilan tekshiring.',
        ]);
    }

    /**
     * GET /health/queue-status
     *
     * Oxirgi TestQueueJob bajarilgan vaqtini Cache'dan o'qib qaytaradi.
     * Shuningdek, jobs va failed_jobs jadvallaridagi qator sonini ko'rsatadi.
     */
    public function queueStatus(): JsonResponse
    {
        $lastRun = Cache::get(TestQueueJob::CACHE_KEY);
        $now = now()->unix();

        return response()->json([
            'last_run' => $lastRun,
            'seconds_ago' => $lastRun ? ($now - $lastRun['unixtime']) : null,
            'healthy' => $lastRun !== null,
            'pending_jobs' => DB::table('jobs')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'queue_driver' => config('queue.default'),
            'hint' => $lastRun
                ? 'Queue worker ishlayapti. Timestamp har dispatch orqali yangilanadi.'
                : 'Hech qachon TestQueueJob bajarilmagan. POST /health/queue-test bilan dispatch qiling.',
        ]);
    }

    /**
     * GET /health/scheduler-status
     *
     * Scheduler heartbeat — routes/console.php da har daqiqa chaqiriluvchi
     * task Cache'ga timestamp yozadi. Bu endpoint o'shani qaytaradi.
     *
     * Agar `seconds_ago > 120` bo'lsa — scheduler ishlamayapti.
     */
    public function schedulerStatus(): JsonResponse
    {
        $lastRun = Cache::get(self::SCHEDULER_CACHE_KEY);
        $now = now()->unix();
        $secondsAgo = $lastRun ? ($now - $lastRun['unixtime']) : null;
        $healthy = $secondsAgo !== null && $secondsAgo < 120;

        return response()->json([
            'last_run' => $lastRun,
            'seconds_ago' => $secondsAgo,
            'healthy' => $healthy,
            'hint' => match (true) {
                $lastRun === null => 'Scheduler hali ishga tushmagan yoki service ishlamayapti. 60 sekund kutib qaytaring.',
                !$healthy => "Scheduler {$secondsAgo} sekund oldin oxirgi marta ishlagan — bu 120 sekunddan ortiq. Service xato yoki to'xtagan.",
                default => 'Scheduler sog\'lom. Har daqiqada timestamp yangilanyapti.',
            },
        ]);
    }
}
