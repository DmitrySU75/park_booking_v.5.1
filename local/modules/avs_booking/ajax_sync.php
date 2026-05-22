<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use AVS\Booking\SyncManager;

header('Content-Type: application/json');

$logFile = $_SERVER['DOCUMENT_ROOT'] . '/upload/avs_booking_debug.log';
file_put_contents($logFile, date('Y-m-d H:i:s') . " [ajax_sync] Request: " . print_r($_REQUEST, true) . "\n", FILE_APPEND);

// Включить CSRF проверку перед релизом
// if (!check_bitrix_sessid()) {
//     file_put_contents($logFile, date('Y-m-d H:i:s') . " [ajax_sync] CSRF mismatch\n", FILE_APPEND);
//     echo json_encode(['success' => false, 'error' => 'CSRF token mismatch']);
//     exit;
// }

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
            if (!$reference) throw new Exception('Reference required');
            $result = $sync->syncReservation($reference);
            break;
        default:
            throw new Exception('Unknown action');
    }
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [ajax_sync] Result: " . json_encode($result) . "\n", FILE_APPEND);
    echo json_encode($result);
} catch (Exception $e) {
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [ajax_sync] Exception: " . $e->getMessage() . "\n", FILE_APPEND);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
