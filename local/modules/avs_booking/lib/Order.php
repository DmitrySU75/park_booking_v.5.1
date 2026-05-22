<?php

namespace AVS\Booking;

use Bitrix\Main\Type\DateTime;

class Order
{
    public static function create($data)
    {
        $legalEntity = \AVSBookingModule::getLegalEntityByPavilionId($data['pavilion_id']);
        $orderNumber = self::generateOrderNumber();

        $priceData = TariffManager::calculatePrice(
            $data['pavilion_id'],
            $data['rental_type'],
            substr($data['start_time'], 0, 10),
            $data['duration_hours'] ?? null,
            $data['discount_code'] ?? null
        );

        if (isset($priceData['error'])) {
            return false;
        }

        // Конвертируем дату в формат, понятный Битрикс
        $startTime = self::convertToBitrixDate($data['start_time']);
        $endTime = self::convertToBitrixDate($data['end_time']);

        $result = OrderTable::add([
            'ORDER_NUMBER' => $orderNumber,
            'PAVILION_ID' => $data['pavilion_id'],
            'PAVILION_NAME' => $data['pavilion_name'],
            'LEGAL_ENTITY' => $legalEntity,
            'CLIENT_NAME' => $data['client_name'],
            'CLIENT_PHONE' => $data['client_phone'],
            'CLIENT_EMAIL' => $data['client_email'] ?? '',
            'CLIENT_TG_ID' => $data['client_tg_id'] ?? '',
            'START_TIME' => $startTime,
            'END_TIME' => $endTime,
            'PRICE' => $priceData['total_price'],
            'DEPOSIT_AMOUNT' => $priceData['deposit_amount'],
            'DISCOUNT_AMOUNT' => $priceData['discount_amount'],
            'DISCOUNT_CODE' => $data['discount_code'] ?? null,
            'STATUS' => $data['status'] ?? 'pending',
            'RENTAL_TYPE' => $data['rental_type'],
            'DURATION_HOURS' => $priceData['duration_hours'],
            'COMMENT' => $data['comment'] ?? '',
            'LIBREBOOKING_RESERVATION_ID' => $data['librebooking_id'] ?? null,
            'CREATED_AT' => new DateTime(),
            'UPDATED_AT' => new DateTime()
        ]);

        if ($result->isSuccess()) {
            return $result->getId();
        }

        return false;
    }

    private static function convertToBitrixDate($dateString)
    {
        $cleanDate = preg_replace('/\+\d{2}:\d{2}$/', '', $dateString);
        $cleanDate = str_replace('T', ' ', $cleanDate);
        $cleanDate = trim($cleanDate);
        return new DateTime($cleanDate);
    }

    public static function get($orderId)
    {
        $result = OrderTable::getById($orderId);
        return $result->fetch();
    }

    public static function getByOrderNumber($orderNumber)
    {
        $result = OrderTable::getList([
            'filter' => ['ORDER_NUMBER' => $orderNumber],
            'limit' => 1
        ]);
        return $result->fetch();
    }

    public static function getByLibrebookingId($librebookingId)
    {
        $result = OrderTable::getList([
            'filter' => ['LIBREBOOKING_RESERVATION_ID' => $librebookingId],
            'limit' => 1
        ]);
        return $result->fetch();
    }

    public static function getList($filter = [], $limit = 100, $offset = 0)
    {
        $params = [
            'filter' => $filter,
            'limit' => $limit,
            'offset' => $offset,
            'order' => ['ID' => 'DESC']
        ];
        $result = OrderTable::getList($params);
        $orders = [];
        while ($order = $result->fetch()) {
            $orders[] = $order;
        }
        return $orders;
    }

    public static function getListByPeriod($startDate, $endDate, $filter = [])
    {
        $startDateTime = new \DateTime($startDate);
        $endDateTime = new \DateTime($endDate);

        $dateFilter = [
            '>=CREATED_AT' => \Bitrix\Main\Type\DateTime::createFromPhp($startDateTime->setTime(0, 0, 0)),
            '<=CREATED_AT' => \Bitrix\Main\Type\DateTime::createFromPhp($endDateTime->setTime(23, 59, 59)),
            'DELETED_AT' => null
        ];
        $allFilter = array_merge($dateFilter, $filter);
        return self::getList($allFilter, 1000, 0);
    }

    public static function softDelete($orderId, $userId = null)
    {
        $result = OrderTable::update($orderId, [
            'DELETED_AT' => new DateTime(),
            'DELETED_BY' => $userId ?: 0,
            'STATUS' => 'deleted'
        ]);
        return $result->isSuccess();
    }

    public static function updateStatus($orderId, $status)
    {
        $result = OrderTable::update($orderId, ['STATUS' => $status, 'UPDATED_AT' => new DateTime()]);
        return $result->isSuccess();
    }

    public static function update($orderId, $data)
    {
        $updateData = ['UPDATED_AT' => new DateTime()];

        if (isset($data['status'])) {
            $updateData['STATUS'] = $data['status'];
        }
        if (isset($data['start_time'])) {
            $updateData['START_TIME'] = self::convertToBitrixDate($data['start_time']);
        }
        if (isset($data['end_time'])) {
            $updateData['END_TIME'] = self::convertToBitrixDate($data['end_time']);
        }

        if (count($updateData) > 1) {
            $result = OrderTable::update($orderId, $updateData);
            return $result->isSuccess();
        }
        return true;
    }

    public static function updatePaymentInfo($orderId, $paymentId, $paymentStatus, $paidAmount)
    {
        $result = OrderTable::update($orderId, [
            'PAYMENT_ID' => $paymentId,
            'PAYMENT_STATUS' => $paymentStatus,
            'PAID_AMOUNT' => $paidAmount,
            'UPDATED_AT' => new DateTime()
        ]);

        if ($result->isSuccess() && $paymentStatus == 'succeeded') {
            self::updateStatus($orderId, 'paid');
        }

        return $result->isSuccess();
    }

    private static function generateOrderNumber()
    {
        return 'ORD-' . date('YmdHis') . '-' . rand(1000, 9999);
    }
}
