<?php
/**
 * Cron task: send everything sitting in reminder_queue
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

return static function (): string {
    $res = Messenger::processQueue(25);
    return 'processed ' . $res['processed'] . ', sent ' . $res['sent'] . ', failed ' . $res['failed'];
};
