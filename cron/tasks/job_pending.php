<?php
/**
 * Cron task: pending job digest to me
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 */

declare(strict_types=1);

return static function (): string {
    $jobs = DB::all(
        "SELECT j.id, j.job_no, j.title, j.due_date, j.status, j.priority,
                c.name AS customer_name,
                DATEDIFF(j.due_date, CURDATE()) AS days_to_due
         FROM jobs j JOIN customers c ON c.id = j.customer_id
         WHERE j.deleted_at IS NULL AND j.remind_enabled = 1
           AND j.status IN ('pending','in_progress','on_hold')
           AND (j.due_date IS NULL OR j.due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY))
         ORDER BY j.due_date IS NULL, j.due_date ASC
         LIMIT 40"
    );

    $supplierWorks = DB::all(
        "SELECT a.id, a.work_detail, a.due_date, s.name AS supplier_name, j.job_no,
                DATEDIFF(a.due_date, CURDATE()) AS days_to_due
         FROM job_supplier_assign a
         JOIN suppliers s ON s.id = a.supplier_id
         JOIN jobs j ON j.id = a.job_id
         WHERE a.status IN ('pending','in_progress')
           AND a.due_date IS NOT NULL AND a.due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
         ORDER BY a.due_date ASC LIMIT 30"
    );

    if (!$jobs && !$supplierWorks) {
        return 'nothing pending';
    }

    $lines = [];
    if ($jobs) {
        $lines[] = '🧾 বাকি কাজ:';
        foreach ($jobs as $j) {
            $when = $j['due_date'] === null
                ? 'তারিখ নেই'
                : ((int) $j['days_to_due'] < 0
                    ? abs((int) $j['days_to_due']) . ' দিন পার'
                    : (int) $j['days_to_due'] . ' দিন বাকি');
            $lines[] = '• ' . $j['customer_name'] . ' - ' . $j['title'] . ' (' . $when . ')';
        }
    }
    if ($supplierWorks) {
        $lines[] = '';
        $lines[] = '🤝 সাপ্লায়ারের কাজ:';
        foreach ($supplierWorks as $w) {
            $lines[] = '• ' . $w['supplier_name'] . ' - ' . ($w['work_detail'] ?? $w['job_no'])
                . ' (' . (int) $w['days_to_due'] . ' দিন)';
        }
    }

    $digest = implode("\n", $lines);
    $selfWa = (string) DB::setting('self_whatsapp', '');
    $selfTg = (string) DB::setting('self_telegram_chat_id', '');

    if ($selfWa !== '') {
        Messenger::queue(['ref_type' => 'job', 'party_type' => 'self',
            'channel' => 'whatsapp', 'recipient' => $selfWa, 'body' => $digest]);
    } elseif ($selfTg !== '') {
        Messenger::queue(['ref_type' => 'job', 'party_type' => 'self',
            'channel' => 'telegram', 'recipient' => $selfTg, 'body' => $digest]);
    } else {
        return 'digest built but your own number is not set in settings';
    }

    return 'queued pending digest (' . count($jobs) . ' jobs, ' . count($supplierWorks) . ' supplier works)';
};
