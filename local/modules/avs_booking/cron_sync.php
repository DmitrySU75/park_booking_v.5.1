<?php

/**
 * Файл: /local/modules/avs_booking/cron_sync.php
 * CRON-задача для автоматической синхронизации
 */

// Определяем корень сайта динамически
if (!defined('DOCUMENT_ROOT')) {
    if (isset($_SERVER['DOCUMENT_ROOT']) && !empty($_SERVER['DOCUMENT_ROOT'])) {
        define('DOCUMENT_ROOT', $_SERVER['DOCUMENT_ROOT']);
    } elseif (isset($_SERVER['PWD'])) {
        // Для запуска из CLI
        $dir = dirname($_SERVER['PWD']);
        while ($dir != '/') {
            if (file_exists($dir . '/bitrix/modules/main/include/prolog_before.php')) {
                define('DOCUMENT_ROOT', $dir);
                break;
            }
            $dir = dirname($dir);
        }
    }
}

if (!defined('DOCUMENT_ROOT')) {
    die('Could not determine DOCUMENT_ROOT');
}

require_once DOCUMENT_ROOT . '/bitrix/modules/main/include/prolog_before.php';

use AVS\Booking\SyncManager;

if (!\Bitrix\Main\Loader::includeModule('avs_booking')) {
    die('Module avs_booking not installed');
}

$lockFile = DOCUMENT_ROOT . '/upload/avs_booking_sync.lock';
$fp = fopen($lockFile, 'c');
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    echo date('Y-m-d H:i:s') . " - Синхронизация уже запущена\n";
    exit;
}

try {
    echo date('Y-m-d H:i:s') . " - Начало синхронизации\n";

    $sync = new SyncManager();
    $result = $sync->fullSync(7);

    echo "Синхронизация завершена:\n";
    echo "- Добавлено: {$result['added']}\n";
    echo "- Обновлено: {$result['updated']}\n";
    echo "- Ошибок: " . count($result['errors']) . "\n";
    echo "- Время: {$result['execution_time']} сек\n";

    if (!empty($result['errors'])) {
        echo "Ошибки:\n";
        foreach ($result['errors'] as $error) {
            echo "  - {$error}\n";
        }
    }
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}

flock($fp, LOCK_UN);
fclose($fp);
