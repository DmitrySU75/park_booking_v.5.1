<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Context;
use Bitrix\Main\Loader;

if (!Loader::includeModule('avs_booking')) {
    ShowError('Модуль avs_booking не установлен');
    return;
}

$elementId = intval($arParams['ELEMENT_ID']);
if (!$elementId) {
    ShowError('Не указан ID беседки');
    return;
}

$gazebo = AVSBookingModule::getGazeboData($elementId);
if (!$gazebo) {
    ShowError('Беседка не найдена');
    return;
}

$arResult['GAZEBO'] = $gazebo;
$request = Context::getCurrent()->getRequest();

if ($request->isPost() && check_bitrix_sessid()) {
    $rentalType = $request->getPost('rental_type');
    $date = $request->getPost('date');
    $startHour = $request->getPost('start_hour');
    $hours = $request->getPost('hours');
    $clientName = trim($request->getPost('client_name'));
    $clientPhone = trim($request->getPost('client_phone'));
    $clientEmail = trim($request->getPost('client_email'));
    $comment = trim($request->getPost('comment'));
    $discountCode = trim($request->getPost('discount_code'));

    $errors = [];

    if (empty($clientName)) $errors[] = 'Введите имя';
    if (empty($clientPhone)) $errors[] = 'Введите телефон';
    if (!$date) $errors[] = 'Выберите дату';

    if ($rentalType == 'hourly') {
        if ($startHour === null) $errors[] = 'Выберите время начала';
        if (!$hours) $errors[] = 'Выберите продолжительность';
    }

    if (empty($errors)) {
        $timeRange = AVSBookingModule::calculateTimeRange($rentalType, $date, $elementId, $startHour, $hours);

        if ($timeRange) {
            $priceData = \AVS\Booking\TariffManager::calculatePrice($elementId, $rentalType, $date, $hours, $discountCode);

            if (isset($priceData['error'])) {
                $errors[] = $priceData['error'];
            } else {
                $available = true;
                if ($gazebo['resource_id']) {
                    try {
                        $client = new AVSBookingLibreBookingClient();
                        $available = $client->checkAvailability($gazebo['resource_id'], $timeRange['start'], $timeRange['end']);
                    } catch (Exception $e) {
                        $errors[] = 'Ошибка проверки доступности: ' . $e->getMessage();
                        $available = false;
                    }
                }

                if ($available) {
                    $startTime = str_replace('T', ' ', $timeRange['start']);
                    $startTime = preg_replace('/\+\d{2}:\d{2}$/', '', $startTime);
                    $endTime = str_replace('T', ' ', $timeRange['end']);
                    $endTime = preg_replace('/\+\d{2}:\d{2}$/', '', $endTime);

                    $bookingData = [
                        'pavilion_id' => $elementId,
                        'pavilion_name' => $gazebo['name'],
                        'client_name' => $clientName,
                        'client_phone' => $clientPhone,
                        'client_email' => $clientEmail,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'price' => $priceData['total_price'],
                        'rental_type' => $rentalType,
                        'duration_hours' => $priceData['duration_hours'],
                        'comment' => $comment,
                        'discount_code' => $discountCode
                    ];

                    $reservationId = null;
                    if ($gazebo['resource_id']) {
                        $userData = [
                            'name' => $clientName,
                            'title' => 'Бронирование ' . $gazebo['name'],
                            'phone' => $clientPhone,
                            'email' => $clientEmail,
                            'comment' => $comment
                        ];
                        try {
                            $client = new AVSBookingLibreBookingClient();
                            $reservationId = $client->createReservation($gazebo['resource_id'], $timeRange['start'], $timeRange['end'], $userData);
                        } catch (Exception $e) {
                            $errors[] = 'Ошибка создания бронирования: ' . $e->getMessage();
                        }
                    }

                    if ($reservationId || !$gazebo['resource_id']) {
                        if ($reservationId) {
                            $bookingData['librebooking_id'] = $reservationId;
                        }

                        $orderId = AVSBookingModule::createOrder($bookingData);

                        if ($orderId) {
                            LocalRedirect($arParams['SUCCESS_PAGE'] . '?order_id=' . $orderId);
                        } else {
                            $errors[] = 'Ошибка создания заказа';
                        }
                    } else {
                        $errors[] = 'Ошибка создания бронирования в системе';
                    }
                } else {
                    $errors[] = 'Выбранное время уже занято';
                }
            }
        } else {
            $errors[] = 'Выбранное время выходит за пределы времени работы беседки';
        }
    }

    if (!empty($errors)) {
        $arResult['ERRORS'] = $errors;
        $arResult['POST'] = $request->getPostList()->toArray();
    }
}

$selectedDate = $request->getPost('date') ?: date('Y-m-d');
$arResult['SELECTED_DATE'] = $selectedDate;
$arResult['RENTAL_TYPES'] = AVSBookingModule::getAvailableRentalTypes($elementId, $selectedDate);
$arResult['WORK_END_HOUR'] = AVSBookingModule::getWorkEndHour($elementId, $selectedDate);
$arResult['MIN_HOURS'] = (int)\Bitrix\Main\Config\Option::get('avs_booking', 'min_hours', 4);
$arResult['MAX_HOURS'] = $arResult['WORK_END_HOUR'] - 10;

if (isset($arResult['RENTAL_TYPES']['hourly'])) {
    $slots = [];
    $minHours = $arResult['MIN_HOURS'];
    for ($hour = 10; $hour <= $arResult['WORK_END_HOUR'] - $minHours; $hour++) {
        $slots[] = ['hour' => $hour, 'label' => $hour . ':00'];
    }
    $arResult['AVAILABLE_SLOTS'] = $slots;
}

$this->includeComponentTemplate();
