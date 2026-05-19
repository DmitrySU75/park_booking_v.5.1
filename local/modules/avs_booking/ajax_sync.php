<?php

/**
 * Файл: /local/modules/avs_booking/ajax_sync.php
 * Эндпоинт для синхронизации из дашборда
 */

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use AVS\Booking\SyncManager;

header('Content-Type: application/json');

// Восстанавливаем CSRF защиту
if (!check_bitrix_sessid()) {
    \Bitrix\Main\Diag\Debug::writeToFile(
        ['sessid' => $_REQUEST['sessid'] ?? 'not set', 'remote_addr' => $_SERVER['REMOTE_ADDR']],
        'CSRF validation failed in ajax_sync.php',
        'avs_booking.log'
    );
    echo json_encode(['success' => false, 'error' => 'CSRF token mismatch']);
    exit;
}

$action = $_REQUEST['action'] ?? 'quick';

try {
    if (!CModule::IncludeModule('avs_booking')) {
        throw new Exception('Module avs_booking not loaded');
    }

    $sync = new SyncManager();

    switch ($action) {
        case 'quick':
            $result = $sync->quickSync();
            break;
        case 'full':
            $result = $sync->fullSync(30);
            break;
        case 'reservation':
            $reference = $_REQUEST['reference'] ?? '';
            if (!$reference) {
                throw new Exception('Reference required');
            }
            $result = $sync->syncReservation($reference);
            break;
        default:
            throw new Exception('Unknown action');
    }

    // Добавляем CSRF токен в ответ для клиента
    $result['csrf_token'] = bitrix_sessid();
    
    echo json_encode($result);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
