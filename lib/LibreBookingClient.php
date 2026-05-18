<?php

/**
 * Файл: /local/modules/avs_booking/lib/LibreBookingClient.php
 * Обертка для совместимости с LibreBookingAPI из /local/php_interface/
 */

if (!class_exists('LibreBookingAPI')) {
    $libreBookingApiPath = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/LibreBookingAPI.php';
    if (file_exists($libreBookingApiPath)) {
        require_once $libreBookingApiPath;
    } else {
        throw new Exception('LibreBookingAPI class not found. Please ensure /local/php_interface/LibreBookingAPI.php exists.');
    }
}

class AVSBookingLibreBookingClient extends LibreBookingAPI
{
    public function __construct($apiUrl = null, $username = null, $password = null)
    {
        parent::__construct($apiUrl, $username, $password);
    }

    public function checkAvailabilityWithLog($resourceId, $startTime, $endTime, $excludeReservationId = null)
    {
        $result = $this->checkAvailability($resourceId, $startTime, $endTime, $excludeReservationId);
        return $result;
    }

    public function createReservationWithLog($resourceId, $startTime, $endTime, $userData)
    {
        return $this->createReservation($resourceId, $startTime, $endTime, $userData);
    }

    public function checkFullDayAvailability($resourceId, $date, $pavilionId)
    {
        $workEndHour = \AVSBookingModule::getWorkEndHour($pavilionId, $date);
        $timezone = '+05:00';
        $startTime = $date . 'T10:00:00' . $timezone;
        $endTime = $date . 'T' . $workEndHour . ':00:00' . $timezone;

        return $this->checkAvailability($resourceId, $startTime, $endTime);
    }

    public function checkNightAvailability($resourceId, $date)
    {
        $timezone = '+05:00';
        $startTime = $date . 'T01:00:00' . $timezone;
        $endTime = $date . 'T09:00:00' . $timezone;

        return $this->checkAvailability($resourceId, $startTime, $endTime);
    }

    public function checkHourlyAvailability($resourceId, $date, $startHour, $hours = null)
    {
        $minHours = (int)\Bitrix\Main\Config\Option::get('avs_booking', 'min_hours', 4);
        if ($hours === null) {
            $hours = $minHours;
        }

        if ($hours < $minHours) {
            return false;
        }

        $endHour = $startHour + $hours;
        $timezone = '+05:00';
        $startTime = $date . 'T' . sprintf('%02d', $startHour) . ':00:00' . $timezone;
        $endTime = $date . 'T' . sprintf('%02d', $endHour) . ':00:00' . $timezone;

        return $this->checkAvailability($resourceId, $startTime, $endTime);
    }
}
