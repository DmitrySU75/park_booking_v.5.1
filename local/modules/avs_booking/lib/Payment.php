<?php

/**
 * Файл: /local/modules/avs_booking/lib/Payment.php
 */

namespace AVS\Booking;

class Payment
{
    public static function createPayment($orderId, $returnUrl)
    {
        $order = Order::get($orderId);

        if (!$order) {
            return ['success' => false, 'error' => 'Order not found'];
        }

        $legalEntity = $order['LEGAL_ENTITY'];
        $legalSettings = self::getLegalEntitySettings($legalEntity);

        if (!$legalSettings['shop_id'] || !$legalSettings['secret_key']) {
            return ['success' => false, 'error' => 'Payment settings not configured'];
        }

        $paymentAmount = $order['DEPOSIT_AMOUNT'];
        if ($order['PAID_AMOUNT'] > 0) {
            $paymentAmount = $order['PRICE'] - $order['PAID_AMOUNT'];
        }

        if ($paymentAmount <= 0) {
            return ['success' => false, 'error' => 'No payment required'];
        }

        $paymentData = [
            'amount' => [
                'value' => $paymentAmount,
                'currency' => 'RUB'
            ],
            'payment_method_data' => [
                'type' => 'bank_card'
            ],
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => $returnUrl
            ],
            'description' => 'Бронирование беседки ' . $order['PAVILION_NAME'] . ' (' . $order['ORDER_NUMBER'] . ')',
            'metadata' => [
                'order_id' => $orderId,
                'order_number' => $order['ORDER_NUMBER'],
                'legal_entity' => $legalEntity
            ]
        ];

        $yookassa = new \AVSBookingYookassaHandler($legalSettings['shop_id'], $legalSettings['secret_key']);
        $result = $yookassa->createPayment($paymentData);

        if ($result && isset($result['id'])) {
            Order::updatePaymentInfo($orderId, $result['id'], 'pending', $order['PAID_AMOUNT']);
            return [
                'success' => true,
                'payment_id' => $result['id'],
                'confirmation_url' => $result['confirmation']['confirmation_url']
            ];
        }

        return ['success' => false, 'error' => 'Failed to create payment'];
    }

    public static function handleWebhook()
    {
        $source = file_get_contents('php://input');
        $data = json_decode($source, true);

        if (!isset($data['object']['id'])) {
            return;
        }

        $paymentId = $data['object']['id'];

        $orders = Order::getList(['PAYMENT_ID' => $paymentId], 1, 0);

        if (empty($orders)) {
            return;
        }

        $order = $orders[0];
        $legalEntity = $order['LEGAL_ENTITY'];
        $legalSettings = self::getLegalEntitySettings($legalEntity);

        $yookassa = new \AVSBookingYookassaHandler($legalSettings['shop_id'], $legalSettings['secret_key']);
        $paymentInfo = $yookassa->getPaymentInfo($paymentId);

        if ($paymentInfo && $paymentInfo['status'] == 'succeeded') {
            $paidAmount = $paymentInfo['amount']['value'];
            $newPaidAmount = $order['PAID_AMOUNT'] + $paidAmount;

            Order::updatePaymentInfo($order['ID'], $paymentId, 'succeeded', $newPaidAmount);

            if ($newPaidAmount >= $order['PRICE']) {
                Order::updateStatus($order['ID'], 'paid');
            }

            $notification = new \AVSNotificationService();
            $notification->sendPaymentSuccessNotification($order);
        } elseif ($paymentInfo && $paymentInfo['status'] == 'canceled') {
            Order::updatePaymentInfo($order['ID'], $paymentId, 'canceled', $order['PAID_AMOUNT']);
        }
    }

    private static function getLegalEntitySettings($legalEntity)
    {
        $settings = [
            AVS_LEGAL_BETON_SYSTEMS => [
                'shop_id' => \Bitrix\Main\Config\Option::get('avs_booking', 'beton_systems_shop_id', ''),
                'secret_key' => \Bitrix\Main\Config\Option::get('avs_booking', 'beton_systems_secret_key', ''),
                'name' => 'ООО "Бетонные Системы"'
            ],
            AVS_LEGAL_PARK_VICTORY => [
                'shop_id' => \Bitrix\Main\Config\Option::get('avs_booking', 'park_victory_shop_id', ''),
                'secret_key' => \Bitrix\Main\Config\Option::get('avs_booking', 'park_victory_secret_key', ''),
                'name' => 'СК "Парк победы" ООО'
            ]
        ];

        return $settings[$legalEntity] ?? $settings[AVS_LEGAL_BETON_SYSTEMS];
    }
}
