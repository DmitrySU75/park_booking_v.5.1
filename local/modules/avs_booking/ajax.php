<?php

/**
 * Файл: /local/modules/avs_booking/ajax.php
 * AJAX-обработчик для формы бронирования
 */

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use AVS\Booking\Order;
use AVS\Booking\Payment;
use AVS\Booking\TariffManager;

CModule::IncludeModule('avs_booking');

$action = $_REQUEST['action'] ?? '';

header('Content-Type: application/json');

// CSRF-защита для всех изменяющих действий
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
{
    $orderId = intval($_REQUEST['order_id'] ?? 0);
    $newEndTime = $_REQUEST['new_end_time'] ?? '';

    if (!$orderId || !$newEndTime) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        return;
    }

    $result = Order::extendTime($orderId, $newEndTime);
    echo json_encode($result);
}

function createPayment()
{
    $orderId = intval($_REQUEST['order_id'] ?? 0);
    $returnUrl = $_REQUEST['return_url'] ?? '';

    if (!$orderId || !$returnUrl) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        return;
    }

    $result = Payment::createPayment($orderId, $returnUrl);
    echo json_encode($result);
}

function checkAvailability()
{
    $pavilionId = intval($_REQUEST['pavilion_id'] ?? 0);
    $date = $_REQUEST['date'] ?? '';
    $rentalType = $_REQUEST['rental_type'] ?? '';
    $startHour = intval($_REQUEST['start_hour'] ?? 0);
    $hours = intval($_REQUEST['hours'] ?? 0);

    if (!$pavilionId || !$date || !$rentalType) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        return;
    }

    $timeRange = AVSBookingModule::calculateTimeRange($rentalType, $date, $pavilionId, $startHour, $hours);

    if (!$timeRange) {
        echo json_encode(['success' => false, 'available' => false, 'error' => 'Invalid time range']);
        return;
    }

    $gazebo = AVSBookingModule::getGazeboData($pavilionId);
    if (!$gazebo || !$gazebo['resource_id']) {
        echo json_encode(['success' => false, 'available' => false, 'error' => 'Gazebo not found']);
        return;
    }

    try {
        $client = new AVSBookingLibreBookingClient();
        $available = $client->checkAvailability($gazebo['resource_id'], $timeRange['start'], $timeRange['end']);
        echo json_encode(['success' => true, 'available' => $available]);
    } catch (Exception $e) {
        \Bitrix\Main\Diag\Debug::writeToFile($e->getMessage(), 'checkAvailability error', 'avs_booking.log');
        echo json_encode(['success' => false, 'available' => false, 'error' => $e->getMessage()]);
    }
}

function getPrice()
{
    $pavilionId = intval($_REQUEST['pavilion_id'] ?? 0);
    $rentalType = $_REQUEST['rental_type'] ?? '';
    $date = $_REQUEST['date'] ?? '';
    $hours = intval($_REQUEST['hours'] ?? 0);
    $discountCode = $_REQUEST['discount_code'] ?? '';

    if (!$pavilionId || !$rentalType || !$date) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        return;
    }

    $priceData = TariffManager::calculatePrice($pavilionId, $rentalType, $date, $hours, $discountCode);
    echo json_encode($priceData);
}

function applyDiscount()
{
    $code = $_REQUEST['code'] ?? '';
    $amount = floatval($_REQUEST['amount'] ?? 0);

    if (!$code || !$amount) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        return;
    }

    $result = AVSBookingDiscountManager::applyDiscount($code, $amount);
    echo json_encode($result);
}

function getDateRestrictions()
{
    $pavilionId = intval($_REQUEST['pavilion_id'] ?? 0);
    $date = $_REQUEST['date'] ?? '';

    if (!$pavilionId || !$date) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        return;
    }

    $restrictions = AVSBookingModule::getDateRestrictions($pavilionId, $date);
    echo json_encode([
        'success' => true,
        'data' => [
            'is_special' => $restrictions['is_special'],
            'allowed_types' => $restrictions['allowed_types'],
            'price_modifier' => $restrictions['price_modifier'],
            'description' => $restrictions['description']
        ]
    ]);
}

function getWorkHours()
{
    $pavilionId = intval($_REQUEST['pavilion_id'] ?? 0);
    $date = $_REQUEST['date'] ?? '';

    if (!$pavilionId || !$date) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        return;
    }

    $workEndHour = AVSBookingModule::getWorkEndHour($pavilionId, $date);
    echo json_encode([
        'success' => true,
        'work_end_hour' => $workEndHour,
        'min_hours' => (int)\Bitrix\Main\Config\Option::get('avs_booking', 'min_hours', 4)
    ]);
}

function getAvailableSlotsData()
{
    $pavilionId = intval($_REQUEST['pavilion_id'] ?? 0);
    $date = $_REQUEST['date'] ?? '';
    $workEndHour = intval($_REQUEST['work_end_hour'] ?? 0);
    $minHours = intval($_REQUEST['min_hours'] ?? 0);

    if (!$pavilionId || !$date) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']);
        return;
    }

    if (!$workEndHour) {
        $workEndHour = AVSBookingModule::getWorkEndHour($pavilionId, $date);
    }
    if (!$minHours) {
        $minHours = (int)\Bitrix\Main\Config\Option::get('avs_booking', 'min_hours', 4);
    }

    $slots = [];
    for ($hour = 10; $hour <= $workEndHour - $minHours; $hour++) {
        $maxPossibleHours = $workEndHour - $hour;
        $slots[] = [
            'hour' => $hour,
            'label' => $hour . ':00',
            'max_hours' => $maxPossibleHours
        ];
    }

    echo json_encode([
        'success' => true,
        'slots' => $slots,
        'work_end_hour' => $workEndHour,
        'min_hours' => $minHours
    ]);
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php');
