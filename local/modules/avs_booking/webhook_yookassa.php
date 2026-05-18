<?php

/**
 * Файл: /local/modules/avs_booking/webhook_yookassa.php
 * Вебхук для обработки уведомлений от ЮKassa
 */

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use AVS\Booking\Payment;
use Bitrix\Main\Config\Option;

CModule::IncludeModule('avs_booking');

function verifyYookassaSignature($requestBody, $signatureHeader, $secretKey)
{
    if (empty($signatureHeader)) {
        return false;
    }

    $parts = explode(',', $signatureHeader);
    $timestamp = '';
    $signature = '';

    foreach ($parts as $part) {
        if (strpos($part, 't=') === 0) {
            $timestamp = substr($part, 2);
        }
        if (strpos($part, 'v1=') === 0) {
            $signature = substr($part, 3);
        }
    }

    if (empty($timestamp) || empty($signature)) {
        return false;
    }

    $signedData = $timestamp . '.' . $requestBody;
    $expectedSignature = hash_hmac('sha256', $signedData, $secretKey);

    return hash_equals($expectedSignature, $signature);
}

function ipInRange($ip, $range)
{
    if (strpos($range, '/') === false) {
        return $ip === $range;
    }

    list($range, $netmask) = explode('/', $range, 2);
    $rangeDecimal = ip2long($range);
    $ipDecimal = ip2long($ip);
    $wildcardDecimal = pow(2, (32 - $netmask)) - 1;
    $netmaskDecimal = ~$wildcardDecimal;

    return ($ipDecimal & $netmaskDecimal) == ($rangeDecimal & $netmaskDecimal);
}

$source = file_get_contents('php://input');
$data = json_decode($source, true);

if (!$data) {
    http_response_code(400);
    die('Invalid JSON');
}

$legalEntity = $data['object']['metadata']['legal_entity'] ?? null;

$secretKey = '';
switch ($legalEntity) {
    case AVS_LEGAL_BETON_SYSTEMS:
        $secretKey = Option::get('avs_booking', 'beton_systems_secret_key', '');
        break;
    case AVS_LEGAL_PARK_VICTORY:
        $secretKey = Option::get('avs_booking', 'park_victory_secret_key', '');
        break;
    default:
        if (isset($data['object']['id'])) {
            $orders = \AVS\Booking\Order::getList(['PAYMENT_ID' => $data['object']['id']], 1, 0);
            if (!empty($orders)) {
                $secretKey = Option::get('avs_booking', $orders[0]['LEGAL_ENTITY'] . '_secret_key', '');
            }
        }
        break;
}

$signatureHeader = $_SERVER['HTTP_YOKASSA_SIGNATURE'] ?? '';
$signatureValid = false;

if ($secretKey && !empty($signatureHeader)) {
    $signatureValid = verifyYookassaSignature($source, $signatureHeader, $secretKey);
}

$allowedIps = ['185.71.76.0/27', '185.71.77.0/27', '77.75.153.0/25', '77.75.154.0/25', '77.75.156.0/25', '77.75.157.0/25'];
$clientIp = $_SERVER['REMOTE_ADDR'];

$ipValid = false;
foreach ($allowedIps as $ipRange) {
    if (ipInRange($clientIp, $ipRange)) {
        $ipValid = true;
        break;
    }
}

if (!$signatureValid) {
    \Bitrix\Main\Diag\Debug::writeToFile(
        ['ip' => $clientIp, 'signature' => $signatureHeader, 'legal_entity' => $legalEntity],
        'Yookassa webhook signature verification failed',
        'avs_booking.log'
    );
    http_response_code(403);
    die('Invalid signature');
}

if (!$ipValid) {
    \Bitrix\Main\Diag\Debug::writeToFile(
        ['ip' => $clientIp, 'legal_entity' => $legalEntity],
        'Yookassa webhook IP not allowed',
        'avs_booking.log'
    );
    http_response_code(403);
    die('Forbidden');
}

Payment::handleWebhook();

echo 'OK';

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php');
