<?php

/**
 * Обёртка для совместимости с LibreBookingAPI
 * Наследует класс из пространства имён AVS\Booking\External
 */

use AVS\Booking\External\LibreBookingAPI;

class AVSBookingLibreBookingClient extends LibreBookingAPI
{
    /**
     * @param string|null $apiUrl
     * @param string|null $username
     * @param string|null $password
     */
    public function __construct($apiUrl = null, $username = null, $password = null)
    {
        parent::__construct($apiUrl, $username, $password);
    }

    /**
     * Проверка доступности с логированием (опционально)
     */
    public function checkAvailabilityWithLog($resourceId, $startTime, $endTime, $excludeReservationId = null)
    {
        return $this->checkAvailability($resourceId, $startTime, $endTime, $excludeReservationId);
    }

    /**
     * Создание бронирования с логированием
     */
    public function createReservationWithLog($resourceId, $startTime, $endTime, $userData)
    {
        return $this->createReservation($resourceId, $startTime, $endTime, $userData);
    }

    /**
     * Проверка доступности на полный день
     */
    public function checkFullDayAvailability($resourceId, $date, $pavilionId)
    {
        $workEndHour = \AVSBookingModule::getWorkEndHour($pavilionId, $date);
        $timezone = '+05:00';
        $startTime = $date . 'T10:00:00' . $timezone;
        $endTime = $date . 'T' . $workEndHour . ':00:00' . $timezone;
        return $this->checkAvailability($resourceId, $startTime, $endTime);
    }

    /**
     * Проверка доступности на ночь
     */
    public function checkNightAvailability($resourceId, $date)
    {
        $timezone = '+05:00';
        $startTime = $date . 'T01:00:00' . $timezone;
        $endTime = $date . 'T09:00:00' . $timezone;
        return $this->checkAvailability($resourceId, $startTime, $endTime);
    }

    /**
     * Проверка доступности почасовой аренды
     */
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
