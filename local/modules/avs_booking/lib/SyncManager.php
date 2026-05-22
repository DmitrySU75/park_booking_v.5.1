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
        $this->logFile = $_SERVER['DOCUMENT_ROOT'] . '/upload/avs_booking_debug.log';
        $this->log("SyncManager initialized");
    }

    private function log($message, $level = 'info')
    {
        $logEntry = date('Y-m-d H:i:s') . " [SyncManager][$level] " . $message . PHP_EOL;
        file_put_contents($this->logFile, $logEntry, FILE_APPEND);
    }

    public function fullSync($daysBack = 30)
    {
        $this->log("Starting fullSync for $daysBack days back");
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
            $this->log("Period: $startDate - $endDate");

            $reservations = $this->api->getReservations($startDate, $endDate);
            $this->log("Got " . count($reservations) . " reservations from LibreBooking");

            if (empty($reservations)) {
                $this->log("No reservations to sync");
                return $result;
            }

            $existingOrders = [];
            $dbOrders = Order::getList([], 10000, 0);
            foreach ($dbOrders as $order) {
                if ($order['LIBREBOOKING_RESERVATION_ID']) {
                    $existingOrders[$order['LIBREBOOKING_RESERVATION_ID']] = $order;
                }
            }
            $this->log("Existing orders in Bitrix: " . count($existingOrders));

            $resourceMap = $this->getResourceMap();
            $this->log("Resource map: " . print_r($resourceMap, true));

            foreach ($reservations as $res) {
                try {
                    $referenceNumber = $res['referenceNumber'];
                    $resourceId = $res['resourceId'] ?? 0;
                    $pavilionId = $resourceMap[$resourceId] ?? 0;

                    if (!$pavilionId) {
                        $this->log("Skipping: no pavilion for resource_id=$resourceId", 'warning');
                        continue;
                    }

                    $gazebo = \AVSBookingModule::getGazeboData($pavilionId);
                    if (!$gazebo) {
                        $this->log("Skipping: gazebo data not found for pavilion ID $pavilionId", 'warning');
                        continue;
                    }

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
                            $this->log("Updated reservation: $referenceNumber");
                        }
                    } else {
                        $bookingData = [
                            'pavilion_id' => $pavilionId,
                            'pavilion_name' => $gazebo['name'] ?? "Pavilion #{$pavilionId}",
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
                            $this->log("Added new order: $referenceNumber, order ID: $orderId");
                        } else {
                            $this->log("Failed to create order for reservation: $referenceNumber", 'error');
                        }
                    }
                } catch (\Exception $e) {
                    $result['errors'][] = $e->getMessage();
                    $this->log("Error processing reservation: " . $e->getMessage(), 'error');
                }
            }

            $this->syncDeletedReservations($existingOrders, $reservations);
        } catch (\Exception $e) {
            $result['success'] = false;
            $result['errors'][] = $e->getMessage();
            $this->log("Sync error: " . $e->getMessage(), 'error');
        }

        $result['execution_time'] = round(microtime(true) - $startTime, 2);
        $this->log("Sync completed: added {$result['added']}, updated {$result['updated']}, errors " . count($result['errors']) . ", time {$result['execution_time']} sec");
        return $result;
    }

    public function quickSync()
    {
        $this->log("Quick sync (last 1 day)");
        return $this->fullSync(1);
    }

    public function syncReservation($referenceNumber)
    {
        $this->log("Sync single reservation: $referenceNumber");
        try {
            $res = $this->api->getReservation($referenceNumber);
            if (!$res) {
                $this->log("Reservation not found: $referenceNumber", 'error');
                return ['success' => false, 'error' => 'Reservation not found'];
            }

            $resourceMap = $this->getResourceMap();
            $resourceId = $res['resourceId'] ?? 0;
            $pavilionId = $resourceMap[$resourceId] ?? 0;

            if (!$pavilionId) {
                $this->log("No pavilion for resource ID $resourceId", 'error');
                return ['success' => false, 'error' => 'Pavilion not found'];
            }

            $existingOrder = Order::getByLibrebookingId($referenceNumber);

            if ($existingOrder) {
                Order::update($existingOrder['ID'], [
                    'start_time' => $res['startDate'],
                    'end_time' => $res['endDate']
                ]);
                $this->log("Updated existing order for $referenceNumber");
                return ['success' => true, 'action' => 'updated'];
            } else {
                $gazebo = \AVSBookingModule::getGazeboData($pavilionId);
                $bookingData = [
                    'pavilion_id' => $pavilionId,
                    'pavilion_name' => $gazebo['name'] ?? "Pavilion #{$pavilionId}",
                    'client_name' => trim(($res['firstName'] ?? '') . ' ' . ($res['lastName'] ?? '')),
                    'start_time' => $res['startDate'],
                    'end_time' => $res['endDate'],
                    'librebooking_id' => $referenceNumber,
                    'price' => 0,
                    'rental_type' => $this->detectRentalType($res['startDate'], $res['endDate'])
                ];
                $orderId = Order::create($bookingData);
                $this->log("Created new order for $referenceNumber, order ID: $orderId");
                return ['success' => true, 'action' => 'created', 'order_id' => $orderId];
            }
        } catch (\Exception $e) {
            $this->log("Error in syncReservation: " . $e->getMessage(), 'error');
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
                $this->log("Marked as cancelled: $ref");
            }
        }
    }
}