<?php

/**
 * Файл: /local/php_interface/init.php
 * Инициализация сайта - подключаем API и обработчики
 */

// Подключаем класс API LibreBooking
require_once __DIR__ . '/LibreBookingAPI.php';

// Проверяем, установлен ли модуль avs_booking
if (\Bitrix\Main\Loader::includeModule('avs_booking')) {

    AddEventHandler('iblock', 'OnIBlockElementBuildFilter', 'OnIBlockElementBuildFilterHandler');

    function OnIBlockElementBuildFilterHandler(&$arFilter, $arParams)
    {
        if (($arParams['IBLOCK_ID'] ?? 0) != 12 && ($arFilter['IBLOCK_ID'] ?? 0) != 12) {
            return;
        }

        $date = $_REQUEST['date'] ?? $_SESSION['avs_booking_filter_date'] ?? '';
        $rentalType = $_REQUEST['rental_type'] ?? 'hourly';
        $startHour = $_REQUEST['start_hour'] ?? '';
        $hours = (int)($_REQUEST['hours'] ?? 0);

        if (empty($date)) {
            return;
        }

        $_SESSION['avs_booking_filter_date'] = $date;

        $availablePavilions = [];

        if ($rentalType == 'hourly' && $startHour && $hours >= 4) {
            $timezone = '+05:00';
            $startTime = $date . 'T' . sprintf('%02d', $startHour) . ':00:00' . $timezone;
            $endTime = $date . 'T' . sprintf('%02d', $startHour + $hours) . ':00:00' . $timezone;

            $res = \CIBlockElement::GetList(
                [],
                ['IBLOCK_ID' => 12, 'ACTIVE' => 'Y'],
                false,
                false,
                ['ID', 'PROPERTY_LIBREBOOKING_RESOURCE_ID']
            );

            $api = new LibreBookingAPI();

            while ($el = $res->Fetch()) {
                $resourceId = (int)$el['PROPERTY_LIBREBOOKING_RESOURCE_ID_VALUE'];

                if ($resourceId) {
                    try {
                        if ($api->checkAvailability($resourceId, $startTime, $endTime)) {
                            $availablePavilions[] = $el['ID'];
                        }
                    } catch (Exception $e) {
                        $availablePavilions[] = $el['ID'];
                    }
                } else {
                    $availablePavilions[] = $el['ID'];
                }
            }

            if (!empty($availablePavilions)) {
                $arFilter['ID'] = $availablePavilions;
            }
        }
    }
}
