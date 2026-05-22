<?php

/**
 * Файл: /local/modules/avs_booking/lib/OneCIntegration.php
 */

namespace AVS\Booking;

class OneCIntegration
{
    public function exportOrders($orders)
    {
        $export = new \AVSExport1C();
        return ['success' => $export->exportOrders($orders)];
    }

    public function syncPrices($prices)
    {
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return ['success' => false, 'error' => 'IBlock module not loaded'];
        }

        $updated = 0;
        foreach ($prices as $priceData) {
            if (isset($priceData['pavilion_id']) && isset($priceData['hourly_price'])) {
                $pavilionId = (int)$priceData['pavilion_id'];

                $props = [];
                if (isset($priceData['hourly_price'])) $props['PRICE_HOUR'] = $priceData['hourly_price'];
                if (isset($priceData['day_price'])) $props['PRICE'] = $priceData['day_price'];
                if (isset($priceData['night_price'])) $props['PRICE_NIGHT'] = $priceData['night_price'];

                \CIBlockElement::SetPropertyValuesEx($pavilionId, 12, $props);
                $updated++;
            }
        }

        return ['success' => true, 'synced' => $updated];
    }
}

class AVSExport1C
{
    private $exportUrl;
    private $apiKey;

    public function __construct()
    {
        $this->exportUrl = \Bitrix\Main\Config\Option::get('avs_booking', 'api_url', '');
        $this->apiKey = \Bitrix\Main\Config\Option::get('avs_booking', 'api_key', '');
    }

    public function exportOrder($orderId)
    {
        $order = \AVS\Booking\Order::get($orderId);
        if (!$order) return false;

        $exportData = [
            'action' => 'export_order',
            'order_number' => $order['ORDER_NUMBER'],
            'client_name' => $order['CLIENT_NAME'],
            'client_phone' => $order['CLIENT_PHONE'],
            'client_email' => $order['CLIENT_EMAIL'],
            'pavilion_id' => $order['PAVILION_ID'],
            'pavilion_name' => $order['PAVILION_NAME'],
            'start_time' => $order['START_TIME']->toString(),
            'end_time' => $order['END_TIME']->toString(),
            'price' => $order['PRICE'],
            'status' => $order['STATUS'],
            'legal_entity' => $order['LEGAL_ENTITY']
        ];

        return $this->sendTo1C($exportData);
    }

    public function exportOrders($orders)
    {
        $exportData = [
            'action' => 'export_orders',
            'orders' => []
        ];

        foreach ($orders as $order) {
            $exportData['orders'][] = [
                'order_number' => $order['ORDER_NUMBER'],
                'client_name' => $order['CLIENT_NAME'],
                'client_phone' => $order['CLIENT_PHONE'],
                'client_email' => $order['CLIENT_EMAIL'],
                'pavilion_id' => $order['PAVILION_ID'],
                'pavilion_name' => $order['PAVILION_NAME'],
                'start_time' => $order['START_TIME']->toString(),
                'end_time' => $order['END_TIME']->toString(),
                'price' => $order['PRICE'],
                'status' => $order['STATUS'],
                'legal_entity' => $order['LEGAL_ENTITY'],
                'created_at' => $order['CREATED_AT']->toString()
            ];
        }

        return $this->sendTo1C($exportData);
    }

    private function sendTo1C($data)
    {
        if (!$this->exportUrl) return false;

        $ch = curl_init($this->exportUrl . '/1c_exchange.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-Key: ' . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode == 200;
    }
}
