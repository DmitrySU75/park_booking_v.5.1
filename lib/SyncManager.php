<?php

/**
 * Файл: /local/modules/avs_booking/lib/SyncManager.php
 * Управление синхронизацией между LibreBooking и Битрикс
 */

namespace AVS\Booking;

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

class SyncManager
{
    private $api;
    private $logFile;

    public function __construct()
    {
        $this->api = new \AVSBookingLibreBookingClient();
        $this->logFile = $_SERVER['DOCUMENT_ROOT'] . '/upload/avs_booking_sync.log';
    }

    public function fullSync($daysBack = 30)
    {
        $startTime = microtime(true);
        $result = [
            'success' => true,
            'added' => 0,
            'updated' => 0,
            'deleted' => 0,
            'errors' => []
        ];

        try {
            $startDate = date('Y-m-d', strtotime("-$daysBack days"));
            $endDate = date('Y-m-d', strtotime('+' . $daysBack . ' days'));

            $reservations = $this->api->getReservations($startDate, $endDate);

            if (empty($reservations)) {
                $this->log("Нет бронирований для синхронизации за период {$startDate} - {$endDate}");
                return $result;
            }

            $existingOrders = [];
            $dbOrders = Order::getList([], 10000, 0);
            foreach ($dbOrders as $order) {
                if ($order['LIBREBOOKING_RESERVATION_ID']) {
                    $existingOrders[$order['LIBREBOOKING_RESERVATION_ID']] = $order;
                }
            }

            $resourceMap = $this->getResourceMap();

            foreach ($reservations as $res) {
                try {
                    $referenceNumber = $res['referenceNumber'];
                    $resourceId = $res['resourceId'] ?? 0;
                    $pavilionId = $resourceMap[$resourceId] ?? 0;

                    if (!$pavilionId) {
                        $this->log("Пропуск: не найдена беседка для resource_id={$resourceId}", 'warning');
                        continue;
                    }

                    $gazebo = \AVSBookingModule::getGazeboData($pavilionId);

                    if (isset($existingOrders[$referenceNumber])) {
                        $order = $existingOrders[$referenceNumber];
                        $needUpdate = false;

                        $currentStart = $order['START_TIME'] instanceof DateTime ? $order['START_TIME']->toString() : $order['START_TIME'];
                        $currentEnd = $order['END_TIME'] instanceof DateTime ? $order['END_TIME']->toString() : $order['END_TIME'];

                        if ($currentStart != $res['startDate'] || $currentEnd != $res['endDate']) {
                            $needUpdate = true;
                        }

                        if ($needUpdate) {
                            Order::update($order['ID'], [
                                'start_time' => $res['startDate'],
                                'end_time' => $res['endDate']
                            ]);
                            $result['updated']++;
                            $this->log("Обновлено бронирование: {$referenceNumber}");
                        }
                    } else {
                        $bookingData = [
                            'pavilion_id' => $pavilionId,
                            'pavilion_name' => $gazebo['name'] ?? "Беседка #{$pavilionId}",
                            'client_name' => trim(($res['firstName'] ?? '') . ' ' . ($res['lastName'] ?? '')),
                            'client_phone' => $res['phone'] ?? '',
                            'client_email' => $res['email'] ?? '',
                            'start_time' => $res['startDate'],
                            'end_time' => $res['endDate'],
                            'price' => 0,
                            'rental_type' => $this->detectRentalType($res['startDate'], $res['endDate']),
                            'comment' => $res['description'] ?? '',
                            'librebooking_id' => $referenceNumber,
                            'status' => $res['requiresApproval'] ? 'pending' : 'confirmed'
                        ];

                        $date = substr($res['startDate'], 0, 10);
                        $hours = $this->calculateDurationHours($res['startDate'], $res['endDate']);
                        $priceData = TariffManager::calculatePrice($pavilionId, $bookingData['rental_type'], $date, $hours, null);

                        if (!isset($priceData['error'])) {
                            $bookingData['price'] = $priceData['total_price'];
                            $bookingData['duration_hours'] = $priceData['duration_hours'];
                        }

                        $orderId = Order::create($bookingData);
                        if ($orderId) {
                            $result['added']++;
                            $this->log("Добавлено новое бронирование: {$referenceNumber}");
                        }
                    }
                } catch (\Exception $e) {
                    $result['errors'][] = $e->getMessage();
                    $this->log("Ошибка обработки: " . $e->getMessage(), 'error');
                }
            }

            $this->syncDeletedReservations($existingOrders, $reservations);
        } catch (\Exception $e) {
            $result['success'] = false;
            $result['errors'][] = $e->getMessage();
            $this->log("Ошибка синхронизации: " . $e->getMessage(), 'error');
        }

        $result['execution_time'] = round(microtime(true) - $startTime, 2);
        $this->log("Синхронизация завершена: добавлено {$result['added']}, обновлено {$result['updated']}, время: {$result['execution_time']} сек");

        return $result;
    }

    public function quickSync()
    {
        return $this->fullSync(1);
    }

    public function syncReservation($referenceNumber)
    {
        try {
            $res = $this->api->getReservation($referenceNumber);
            if (!$res) {
                return ['success' => false, 'error' => 'Бронирование не найдено'];
            }

            $resourceMap = $this->getResourceMap();
            $resourceId = $res['resourceId'] ?? 0;
            $pavilionId = $resourceMap[$resourceId] ?? 0;

            if (!$pavilionId) {
                return ['success' => false, 'error' => 'Беседка не найдена'];
            }

            $existingOrder = Order::getByLibrebookingId($referenceNumber);

            if ($existingOrder) {
                Order::update($existingOrder['ID'], [
                    'start_time' => $res['startDate'],
                    'end_time' => $res['endDate']
                ]);
                return ['success' => true, 'action' => 'updated'];
            } else {
                $gazebo = \AVSBookingModule::getGazeboData($pavilionId);
                $bookingData = [
                    'pavilion_id' => $pavilionId,
                    'pavilion_name' => $gazebo['name'] ?? "Беседка #{$pavilionId}",
                    'client_name' => trim(($res['firstName'] ?? '') . ' ' . ($res['lastName'] ?? '')),
                    'start_time' => $res['startDate'],
                    'end_time' => $res['endDate'],
                    'librebooking_id' => $referenceNumber,
                    'price' => 0,
                    'rental_type' => $this->detectRentalType($res['startDate'], $res['endDate'])
                ];
                $orderId = Order::create($bookingData);
                return ['success' => true, 'action' => 'created', 'order_id' => $orderId];
            }
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function getResourceMap()
    {
        $map = [];
        if (Loader::includeModule('iblock')) {
            $res = \CIBlockElement::GetList(
                [],
                ['IBLOCK_ID' => 12, 'ACTIVE' => 'Y'],
                false,
                false,
                ['ID', 'PROPERTY_LIBREBOOKING_RESOURCE_ID']
            );
            while ($el = $res->Fetch()) {
                $resourceId = (int)$el['PROPERTY_LIBREBOOKING_RESOURCE_ID_VALUE'];
                if ($resourceId) {
                    $map[$resourceId] = (int)$el['ID'];
                }
            }
        }
        return $map;
    }

    private function detectRentalType($startTime, $endTime)
    {
        $startHour = (int)date('H', strtotime($startTime));
        $endHour = (int)date('H', strtotime($endTime));
        $duration = $endHour - $startHour;

        if ($startHour == 1 && $endHour == 9) {
            return 'night';
        } elseif ($startHour == 10 && $duration >= 8) {
            return 'full_day';
        } else {
            return 'hourly';
        }
    }

    private function calculateDurationHours($startTime, $endTime)
    {
        $start = new \DateTime($startTime);
        $end = new \DateTime($endTime);
        $diff = $start->diff($end);
        return $diff->h + ($diff->i > 30 ? 1 : 0);
    }

    private function syncDeletedReservations($existingOrders, $currentReservations)
    {
        $currentRefs = [];
        foreach ($currentReservations as $res) {
            $currentRefs[] = $res['referenceNumber'];
        }

        foreach ($existingOrders as $ref => $order) {
            if (!in_array($ref, $currentRefs) && $order['STATUS'] != 'cancelled') {
                Order::updateStatus($order['ID'], 'cancelled');
                $this->log("Помечено как отмененное: {$ref}");
            }
        }
    }

    private function log($message, $level = 'info')
    {
        $logEntry = date('Y-m-d H:i:s') . " [{$level}] " . $message . PHP_EOL;
        file_put_contents($this->logFile, $logEntry, FILE_APPEND);
    }
}
