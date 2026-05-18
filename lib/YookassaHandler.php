<?php

/**
 * Файл: /local/modules/avs_booking/lib/YookassaHandler.php
 */

class AVSBookingYookassaHandler
{
    private $shopId;
    private $secretKey;

    public function __construct($shopId, $secretKey)
    {
        $this->shopId = $shopId;
        $this->secretKey = $secretKey;
    }

    public function createPayment($paymentData)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.yookassa.ru/v3/payments');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($paymentData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Idempotence-Key: ' . uniqid()
        ]);
        curl_setopt($ch, CURLOPT_USERPWD, $this->shopId . ':' . $this->secretKey);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200 || $httpCode == 201) {
            return json_decode($response, true);
        }

        return null;
    }

    public function getPaymentInfo($paymentId)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.yookassa.ru/v3/payments/' . $paymentId);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_USERPWD, $this->shopId . ':' . $this->secretKey);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            return json_decode($response, true);
        }

        return null;
    }
}
