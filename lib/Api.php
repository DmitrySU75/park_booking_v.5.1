<?php

/**
 * Файл: /local/modules/avs_booking/lib/Api.php
 */

namespace AVS\Booking;

class Api
{
    private $apiKey;

    public function __construct()
    {
        $this->apiKey = \Bitrix\Main\Config\Option::get('avs_booking', 'api_key', '');
    }

    public function handleRequest()
    {
        header('Content-Type: application/json');

        if (!$this->checkAuth()) {
            $this->errorResponse('Unauthorized', 401);
            return;
        }

        $action = $_GET['action'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'];

        switch ($action) {
            case 'create_order':
                if ($method === 'POST') $this->createOrder();
                else $this->errorResponse('Method not allowed', 405);
                break;
            case 'update_status':
                if ($method === 'POST' || $method === 'PUT') $this->updateStatus();
                else $this->errorResponse('Method not allowed', 405);
                break;
            case 'update_order':
                if ($method === 'POST' || $method === 'PUT') $this->updateOrder();
                else $this->errorResponse('Method not allowed', 405);
                break;
            case 'get_orders':
                if ($method === 'GET') $this->getOrders();
                else $this->errorResponse('Method not allowed', 405);
                break;
            case 'get_payment_info':
                if ($method === 'GET') $this->getPaymentInfo();
                else $this->errorResponse('Method not allowed', 405);
                break;
            case 'update_prices':
                if ($method === 'POST' || $method === 'PUT') $this->updatePrices();
                else $this->errorResponse('Method not allowed', 405);
                break;
            default:
                $this->errorResponse('Action not found', 404);
        }
    }

    private function createOrder()
    {
        $data = json_decode(file_get_contents('php://input'), true);

        $required = ['pavilion_id', 'client_name', 'client_phone', 'period_start', 'period_end', 'price'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $this->errorResponse("Missing field: {$field}", 400);
                return;
            }
        }

        $gazebo = \AVSBookingModule::getGazeboData($data['pavilion_id']);
        if (!$gazebo) {
            $this->errorResponse('Pavilion not found', 404);
            return;
        }

        $date = substr($data['period_start'], 0, 10);
        $rentalType = $data['rental_type'] ?? 'hourly';

        $restrictions = \AVSBookingModule::getDateRestrictions($data['pavilion_id'], $date);
        if ($restrictions['is_special'] && !in_array($rentalType, $restrictions['allowed_types'])) {
            $this->errorResponse('Данный тип аренды недоступен в выбранную дату', 400);
            return;
        }

        $available = $this->checkAvailability($gazebo['resource_id'], $data['period_start'], $data['period_end']);
        if (!$available) {
            $this->errorResponse('Выбранное время недоступно', 400);
            return;
        }

        $orderData = [
            'pavilion_id' => $data['pavilion_id'],
            'pavilion_name' => $gazebo['name'],
            'client_name' => $data['client_name'],
            'client_phone' => $data['client_phone'],
            'client_email' => $data['client_email'] ?? '',
            'start_time' => $data['period_start'],
            'end_time' => $data['period_end'],
            'price' => $data['price'],
            'rental_type' => $rentalType,
            'status' => $data['status'] ?? 'pending',
            'comment' => $data['comment'] ?? '',
            'discount_code' => $data['discount_code'] ?? null
        ];

        $orderId = Order::create($orderData);

        if ($orderId) {
            $order = Order::get($orderId);
            $this->successResponse([
                'order_id' => $orderId,
                'order_number' => $order['ORDER_NUMBER'],
                'status' => $order['STATUS'],
                'price' => $order['PRICE'],
                'deposit_amount' => $order['DEPOSIT_AMOUNT']
            ]);
        } else {
            $this->errorResponse('Failed to create order', 500);
        }
    }

    private function updateStatus()
    {
        $data = json_decode(file_get_contents('php://input'), true);

        $orderId = $data['order_id'] ?? 0;
        $orderNumber = $data['order_number'] ?? '';
        $newStatus = $data['status'] ?? '';

        if ((!$orderId && !$orderNumber) || !$newStatus) {
            $this->errorResponse('order_id/order_number and status required', 400);
            return;
        }

        $allowedStatuses = ['pending', 'paid', 'confirmed', 'cancelled', 'completed'];
        if (!in_array($newStatus, $allowedStatuses)) {
            $this->errorResponse('Invalid status. Allowed: ' . implode(', ', $allowedStatuses), 400);
            return;
        }

        $order = null;
        if ($orderId) {
            $order = Order::get($orderId);
        } else {
            $order = Order::getByOrderNumber($orderNumber);
        }

        if (!$order) {
            $this->errorResponse('Order not found', 404);
            return;
        }

        if (Order::updateStatus($order['ID'], $newStatus)) {
            $this->successResponse([
                'order_id' => $order['ID'],
                'order_number' => $order['ORDER_NUMBER'],
                'old_status' => $order['STATUS'],
                'new_status' => $newStatus
            ]);
        } else {
            $this->errorResponse('Status update failed', 500);
        }
    }

    private function updateOrder()
    {
        $data = json_decode(file_get_contents('php://input'), true);

        $orderId = $data['order_id'] ?? 0;
        $orderNumber = $data['order_number'] ?? '';

        if ((!$orderId && !$orderNumber)) {
            $this->errorResponse('order_id or order_number required', 400);
            return;
        }

        $order = null;
        if ($orderId) {
            $order = Order::get($orderId);
        } else {
            $order = Order::getByOrderNumber($orderNumber);
        }

        if (!$order) {
            $this->errorResponse('Order not found', 404);
            return;
        }

        $updateData = [];
        $changes = [];

        if (isset($data['new_start_time']) && isset($data['new_end_time'])) {
            $newStart = $data['new_start_time'];
            $newEnd = $data['new_end_time'];

            $gazebo = \AVSBookingModule::getGazeboData($order['PAVILION_ID']);
            if ($gazebo && $gazebo['resource_id']) {
                $available = $this->checkAvailability($gazebo['resource_id'], $newStart, $newEnd, $order['LIBREBOOKING_RESERVATION_ID']);
                if (!$available) {
                    $this->errorResponse('Новое время недоступно', 400);
                    return;
                }
            }

            $updateData['new_start_time'] = $newStart;
            $updateData['new_end_time'] = $newEnd;
            $changes['time'] = [
                'old_start' => $order['START_TIME']->toString(),
                'old_end' => $order['END_TIME']->toString(),
                'new_start' => $newStart,
                'new_end' => $newEnd
            ];
        }

        if (isset($data['new_pavilion_id'])) {
            $newPavilionId = (int)$data['new_pavilion_id'];
            $newGazebo = \AVSBookingModule::getGazeboData($newPavilionId);

            if (!$newGazebo) {
                $this->errorResponse('New pavilion not found', 404);
                return;
            }

            $currentStart = $updateData['new_start_time'] ?? $order['START_TIME']->toString();
            $currentEnd = $updateData['new_end_time'] ?? $order['END_TIME']->toString();

            if ($newGazebo['resource_id']) {
                $available = $this->checkAvailability($newGazebo['resource_id'], $currentStart, $currentEnd, null);
                if (!$available) {
                    $this->errorResponse('Новая беседка недоступна в выбранное время', 400);
                    return;
                }
            }

            $updateData['new_pavilion_id'] = $newPavilionId;
            $updateData['new_pavilion_name'] = $newGazebo['name'];
            $changes['pavilion'] = [
                'old_id' => $order['PAVILION_ID'],
                'old_name' => $order['PAVILION_NAME'],
                'new_id' => $newPavilionId,
                'new_name' => $newGazebo['name']
            ];
        }

        if (empty($updateData)) {
            $this->errorResponse('No update data provided', 400);
            return;
        }

        if (Order::updateRekvizits($order['ID'], $updateData, $changes)) {
            $updatedOrder = Order::get($order['ID']);
            $this->successResponse([
                'order_id' => $updatedOrder['ID'],
                'order_number' => $updatedOrder['ORDER_NUMBER'],
                'changes' => $changes,
                'status' => $updatedOrder['STATUS']
            ]);
        } else {
            $this->errorResponse('Update failed', 500);
        }
    }

    private function getOrders()
    {
        $startDate = $_GET['start_date'] ?? '';
        $endDate = $_GET['end_date'] ?? '';
        $status = $_GET['status'] ?? '';
        $pavilionId = (int)($_GET['pavilion_id'] ?? 0);
        $legalEntity = $_GET['legal_entity'] ?? '';

        if (!$startDate || !$endDate) {
            $this->errorResponse('start_date and end_date required', 400);
            return;
        }

        $filter = [];
        if ($status) $filter['STATUS'] = $status;
        if ($pavilionId) $filter['PAVILION_ID'] = $pavilionId;
        if ($legalEntity) $filter['LEGAL_ENTITY'] = $legalEntity;

        $orders = Order::getListByPeriod($startDate, $endDate, $filter);

        $result = [];
        foreach ($orders as $order) {
            $result[] = [
                'id' => $order['ID'],
                'order_number' => $order['ORDER_NUMBER'],
                'pavilion_id' => $order['PAVILION_ID'],
                'pavilion_name' => $order['PAVILION_NAME'],
                'legal_entity' => $order['LEGAL_ENTITY'],
                'client_name' => $order['CLIENT_NAME'],
                'client_phone' => $order['CLIENT_PHONE'],
                'client_email' => $order['CLIENT_EMAIL'],
                'period_start' => $order['START_TIME']->toString(),
                'period_end' => $order['END_TIME']->toString(),
                'price' => $order['PRICE'],
                'deposit_amount' => $order['DEPOSIT_AMOUNT'],
                'paid_amount' => $order['PAID_AMOUNT'],
                'status' => $order['STATUS'],
                'payment_status' => $order['PAYMENT_STATUS'],
                'rental_type' => $order['RENTAL_TYPE'],
                'duration_hours' => $order['DURATION_HOURS'],
                'created_at' => $order['CREATED_AT']->toString(),
                'updated_at' => $order['UPDATED_AT']->toString()
            ];
        }

        $this->successResponse([
            'orders' => $result,
            'total' => count($result),
            'period' => ['start' => $startDate, 'end' => $endDate]
        ]);
    }

    private function getPaymentInfo()
    {
        $orderId = (int)($_GET['order_id'] ?? 0);
        $orderNumber = $_GET['order_number'] ?? '';

        if (!$orderId && !$orderNumber) {
            $this->errorResponse('order_id or order_number required', 400);
            return;
        }

        $order = null;
        if ($orderId) {
            $order = Order::get($orderId);
        } else {
            $order = Order::getByOrderNumber($orderNumber);
        }

        if (!$order) {
            $this->errorResponse('Order not found', 404);
            return;
        }

        $this->successResponse([
            'order_id' => $order['ID'],
            'order_number' => $order['ORDER_NUMBER'],
            'pavilion_name' => $order['PAVILION_NAME'],
            'price' => $order['PRICE'],
            'deposit_amount' => $order['DEPOSIT_AMOUNT'],
            'paid_amount' => $order['PAID_AMOUNT'],
            'payment_id' => $order['PAYMENT_ID'],
            'payment_status' => $order['PAYMENT_STATUS'],
            'legal_entity' => $order['LEGAL_ENTITY'],
            'status' => $order['STATUS'],
            'requires_payment' => $order['PAID_AMOUNT'] < $order['PRICE']
        ]);
    }

    private function updatePrices()
    {
        $data = json_decode(file_get_contents('php://input'), true);

        $effectiveFrom = $data['effective_from'] ?? '';
        $prices = $data['prices'] ?? [];

        if (!$effectiveFrom || empty($prices)) {
            $this->errorResponse('effective_from and prices array required', 400);
            return;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom)) {
            $this->errorResponse('effective_from must be in YYYY-MM-DD format', 400);
            return;
        }

        $updated = [];
        $errors = [];

        foreach ($prices as $priceItem) {
            if (empty($priceItem['pavilion_id'])) {
                $errors[] = 'Missing pavilion_id in price item';
                continue;
            }

            $pavilionId = (int)$priceItem['pavilion_id'];
            $gazebo = \AVSBookingModule::getGazeboData($pavilionId);

            if (!$gazebo) {
                $errors[] = "Pavilion ID {$pavilionId} not found";
                continue;
            }

            $updateData = [];
            if (isset($priceItem['price_hour'])) $updateData['hourly_price'] = (float)$priceItem['price_hour'];
            if (isset($priceItem['price_day'])) $updateData['full_day_price'] = (float)$priceItem['price_day'];
            if (isset($priceItem['price_night'])) $updateData['night_price'] = (float)$priceItem['price_night'];

            if (empty($updateData)) {
                $errors[] = "No price data for pavilion ID {$pavilionId}";
                continue;
            }

            self::savePriceHistory($pavilionId, $updateData, $effectiveFrom);

            $result = \AVSBookingModule::updateGazeboPrices($pavilionId, $updateData);

            if ($result) {
                $updated[] = [
                    'pavilion_id' => $pavilionId,
                    'pavilion_name' => $gazebo['name'],
                    'effective_from' => $effectiveFrom,
                    'new_prices' => $updateData
                ];
            } else {
                $errors[] = "Failed to update prices for pavilion ID {$pavilionId}";
            }
        }

        $this->successResponse([
            'effective_from' => $effectiveFrom,
            'updated' => $updated,
            'updated_count' => count($updated),
            'errors' => $errors
        ]);
    }

    private function savePriceHistory($pavilionId, $prices, $effectiveFrom)
    {
        global $DB;

        $fields = [];
        if (isset($prices['hourly_price'])) $fields['PRICE_HOUR'] = $prices['hourly_price'];
        if (isset($prices['full_day_price'])) $fields['PRICE_DAY'] = $prices['full_day_price'];
        if (isset($prices['night_price'])) $fields['PRICE_NIGHT'] = $prices['night_price'];

        $sql = "INSERT INTO avs_booking_price_history 
                (PAVILION_ID, PRICE_HOUR, PRICE_DAY, PRICE_NIGHT, EFFECTIVE_FROM, CREATED_AT)
                VALUES (
                    {$pavilionId},
                    " . ($fields['PRICE_HOUR'] ?? 'NULL') . ",
                    " . ($fields['PRICE_DAY'] ?? 'NULL') . ",
                    " . ($fields['PRICE_NIGHT'] ?? 'NULL') . ",
                    '{$effectiveFrom}',
                    NOW()
                )";

        $DB->Query($sql);
    }

    private function checkAvailability($resourceId, $startTime, $endTime, $excludeReservationId = null)
    {
        if (!$resourceId) return true;

        try {
            $client = new \AVSBookingLibreBookingClient();
            return $client->checkAvailability($resourceId, $startTime, $endTime, $excludeReservationId);
        } catch (\Exception $e) {
            return true;
        }
    }

    private function checkAuth()
    {
        $headers = getallheaders();
        $apiKey = $headers['X-API-Key'] ?? '';
        return $apiKey === $this->apiKey;
    }

    private function successResponse($data)
    {
        echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function errorResponse($message, $code = 400)
    {
        http_response_code($code);
        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
