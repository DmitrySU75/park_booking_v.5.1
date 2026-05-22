<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use AVS\Booking\Order;
use AVS\Booking\Payment;
use AVS\Booking\TariffManager;

CModule::IncludeModule('avs_booking');

$action = $_REQUEST['action'] ?? '';
header('Content-Type: application/json');

$changingActions = ['extend_time', 'create_payment', 'apply_discount'];
if (in_array($action, $changingActions) && !check_bitrix_sessid()) {
    echo json_encode(['success' => false, 'error' => 'CSRF token mismatch']);
    exit;
}

switch ($action) {
    case 'extend_time':
        extendTime();
        break;
    case 'create_payment':
        createPayment();
        break;
    case 'check_availability':
        checkAvailability();
        break;
    case 'get_price':
        getPrice();
        break;
    case 'apply_discount':
        applyDiscount();
        break;
    case 'get_date_restrictions':
        getDateRestrictions();
        break;
    case 'get_work_hours':
        getWorkHours();
        break;
    case 'get_available_slots_data':
        getAvailableSlotsData();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}

function extendTime()
{ /* ... */
}
function createPayment()
{ /* ... */
}
// ... остальные функции (они уже были в истории)