<?php
/**
 * AH5 Office - master cron dispatcher
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * Add ONE cPanel cron entry (every 5 minutes):
 *   /usr/local/bin/php /home/USER/public_html/cron/master.php
 *
 * New scheduled work is registered as a row in `cron_tasks`,
 * never as a separate cPanel cron entry.
 *
 * Browser fallback (if CLI cron is unavailable):
 *   https://yourdomain/cron/master.php?key=THE_CRON_KEY
 *   (set cron_key in settings first)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    $key = (string) DB::setting('cron_key', '');
    if ($key === '' || (string) ($_GET['key'] ?? '') !== $key) {
        http_response_code(403);
        exit("Forbidden\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(300);
$startedAll = microtime(true);
$now        = new DateTime('now', new DateTimeZone(APP_TIMEZONE));
$ran        = [];

$tasks = DB::all('SELECT * FROM cron_tasks WHERE is_active = 1 ORDER BY id ASC');

foreach ($tasks as $task) {
    if (!shouldRun($task, $now)) {
        continue;
    }

    $file = BASE_PATH . '/' . ltrim((string) $task['handler_file'], '/');
    if (!is_file($file)) {
        DB::update('cron_tasks', [
            'last_status'  => 'error',
            'last_message' => 'Handler file missing: ' . $task['handler_file'],
            'last_run_at'  => $now->format('Y-m-d H:i:s'),
        ], 'id = ?', [(int) $task['id']]);
        continue;
    }

    $startedAt = date('Y-m-d H:i:s');
    DB::update('cron_tasks', ['last_status' => 'running'], 'id = ?', [(int) $task['id']]);

    try {
        /** @var callable $handler */
        $handler = require $file;
        $message = is_callable($handler) ? (string) $handler() : 'ok';
        $status  = 'ok';
    } catch (Throwable $e) {
        $message = 'ERROR: ' . $e->getMessage();
        $status  = 'error';
        error_log('[cron ' . $task['task_key'] . '] ' . $e->getMessage());
    }

    DB::update('cron_tasks', [
        'last_run_at'  => $startedAt,
        'next_run_at'  => nextRunAt($task, $now),
        'last_status'  => $status,
        'last_message' => mb_substr($message, 0, 255),
    ], 'id = ?', [(int) $task['id']]);

    DB::insert('cron_log', [
        'task_key'    => (string) $task['task_key'],
        'started_at'  => $startedAt,
        'finished_at' => date('Y-m-d H:i:s'),
        'status'      => $status,
        'message'     => mb_substr($message, 0, 255),
    ]);

    $ran[] = $task['task_key'] . ': ' . $message;
}

// keep the log table small
DB::run('DELETE FROM cron_log WHERE started_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');

$elapsed = round(microtime(true) - $startedAll, 2);
echo '[' . $now->format('Y-m-d H:i:s') . '] ran ' . count($ran) . ' task(s) in ' . $elapsed . "s\n";
foreach ($ran as $line) {
    echo '  - ' . $line . "\n";
}

// ---------------- helpers ----------------

function shouldRun(array $task, DateTime $now): bool
{
    $lastRun = !empty($task['last_run_at']) ? new DateTime((string) $task['last_run_at']) : null;

    // fixed time-of-day task: run once per day, on or after that time
    if (!empty($task['run_at_time'])) {
        $todayAt = new DateTime($now->format('Y-m-d') . ' ' . $task['run_at_time']);
        if ($now < $todayAt) {
            return false;
        }
        return $lastRun === null || $lastRun < $todayAt;
    }

    $interval = max(1, (int) $task['interval_min']);
    if ($lastRun === null) {
        return true;
    }
    return ($now->getTimestamp() - $lastRun->getTimestamp()) >= $interval * 60;
}

function nextRunAt(array $task, DateTime $now): string
{
    if (!empty($task['run_at_time'])) {
        return (new DateTime($now->format('Y-m-d') . ' ' . $task['run_at_time']))
            ->modify('+1 day')->format('Y-m-d H:i:s');
    }
    $interval = max(1, (int) $task['interval_min']);
    return (clone $now)->modify('+' . $interval . ' minute')->format('Y-m-d H:i:s');
}
