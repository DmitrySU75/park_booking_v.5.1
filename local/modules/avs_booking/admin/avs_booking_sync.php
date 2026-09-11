<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;

$module_id = 'avs_booking';
if ($APPLICATION->GetGroupRight($module_id) < 'W') {
    $APPLICATION->AuthForm('Доступ запрещен');
}

Loader::includeModule($module_id);
Loader::includeModule('iblock');

$logFile = $_SERVER['DOCUMENT_ROOT'] . '/upload/avs_booking_debug.log';
file_put_contents($logFile, date('Y-m-d H:i:s') . " [sync_page] Page loaded\n", FILE_APPEND);

$APPLICATION->SetTitle('Синхронизация с LibreBooking');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && $_POST['action'] === 'sync_resources') {
    $result = syncResources();
    if ($result['success']) {
        CAdminMessage::ShowMessage(['MESSAGE' => "Синхронизация завершена. Создано: {$result['created']}, Обновлено: {$result['updated']}, Ошибок: {$result['errors']}", 'TYPE' => 'OK']);
    } else {
        CAdminMessage::ShowMessage(['MESSAGE' => $result['error'], 'TYPE' => 'ERROR']);
    }
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');

function syncResources()
{
    global $logFile;
    $result = ['success' => true, 'created' => 0, 'updated' => 0, 'errors' => 0];

    $apiUrl = rtrim(Option::get('avs_booking', 'api_url', ''), '/');
    $username = Option::get('avs_booking', 'api_username', '');
    $password = Option::get('avs_booking', 'api_password', '');
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [syncResources] API URL: $apiUrl, user: $username\n", FILE_APPEND);

    if (empty($apiUrl) || empty($username) || empty($password)) {
        $result['success'] = false;
        $result['error'] = 'Настройки API LibreBooking не заполнены.';
        return $result;
    }

    $auth = authenticate($apiUrl, $username, $password);
    if (!$auth) {
        $result['success'] = false;
        $result['error'] = 'Ошибка аутентификации в LibreBooking API.';
        return $result;
    }
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [syncResources] Auth OK, userId={$auth['userId']}\n", FILE_APPEND);

    $pavilions = getPavilionsFromBitrix();
    if (empty($pavilions)) {
        $result['success'] = false;
        $result['error'] = 'Нет активных беседок для синхронизации (инфоблок 12).';
        return $result;
    }
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [syncResources] Found " . count($pavilions) . " pavilions\n", FILE_APPEND);

    $scheduleId = getScheduleId($apiUrl, $auth);
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [syncResources] Schedule ID: $scheduleId\n", FILE_APPEND);

    foreach ($pavilions as $pavilion) {
        $resource = getResourceByPublicId($apiUrl, $auth, $pavilion['article']);
        if ($resource) {
            file_put_contents($logFile, date('Y-m-d H:i:s') . " [syncResources] Resource exists: {$pavilion['name']} (ID: {$resource['resource_id']})\n", FILE_APPEND);
            $updated = updateResource($apiUrl, $auth, $resource['resource_id'], $pavilion, $scheduleId);
            if ($updated) {
                $result['updated']++;
                file_put_contents($logFile, date('Y-m-d H:i:s') . " [syncResources] Updated: {$pavilion['name']}\n", FILE_APPEND);
            } else {
                $result['errors']++;
                file_put_contents($logFile, date('Y-m-d H:i:s') . " [syncResources] FAILED update: {$pavilion['name']}\n", FILE_APPEND);
            }
            saveResourceIdToBitrix($pavilion['id'], $resource['resource_id']);
        } else {
            file_put_contents($logFile, date('Y-m-d H:i:s') . " [syncResources] Resource not found, creating: {$pavilion['name']}\n", FILE_APPEND);
            $resourceId = createResource($apiUrl, $auth, $scheduleId, $pavilion);
            if ($resourceId) {
                $result['created']++;
                saveResourceIdToBitrix($pavilion['id'], $resourceId);
                file_put_contents($logFile, date('Y-m-d H:i:s') . " [syncResources] Created: {$pavilion['name']} (ID: $resourceId)\n", FILE_APPEND);
            } else {
                $result['errors']++;
                file_put_contents($logFile, date('Y-m-d H:i:s') . " [syncResources] FAILED create: {$pavilion['name']}\n", FILE_APPEND);
            }
        }
    }

    file_put_contents($logFile, date('Y-m-d H:i:s') . " [syncResources] Final result: created={$result['created']}, updated={$result['updated']}, errors={$result['errors']}\n", FILE_APPEND);
    return $result;
}

function authenticate($apiUrl, $username, $password)
{
    global $logFile;
    $ch = curl_init($apiUrl . '/Authentication/Authenticate');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['username' => $username, 'password' => $password]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_POSTREDIR, 3);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [authenticate] HTTP $httpCode, response: " . substr($response, 0, 200) . "\n", FILE_APPEND);
    if ($httpCode == 200) return json_decode($response, true);
    return false;
}

function getScheduleId($apiUrl, $auth)
{
    global $logFile;
    $ch = curl_init($apiUrl . '/Schedules');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-Booked-SessionToken: ' . $auth['sessionToken'], 'X-Booked-UserId: ' . $auth['userId']]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $schedules = json_decode($response, true);
    if (is_array($schedules) && !empty($schedules)) return $schedules[0]['id'];
    return 1;
}

function getResourceByPublicId($apiUrl, $auth, $publicId)
{
    global $logFile;
    if (empty($publicId)) return null;
    $ch = curl_init($apiUrl . '/Resources?publicId=' . urlencode($publicId));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-Booked-SessionToken: ' . $auth['sessionToken'], 'X-Booked-UserId: ' . $auth['userId']]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode == 200) {
        $data = json_decode($response, true);
        if (is_array($data) && !empty($data)) return $data[0];
    }
    return null;
}

function updateResource($apiUrl, $auth, $resourceId, $pavilion, $scheduleId)
{
    global $logFile;
    $data = [
        'name' => $pavilion['name'],
        'scheduleId' => (int)$scheduleId,
        'autoAssign' => true,
        'allowMultiday' => false,
        'requiresApproval' => false,
        'autoAssignPermission' => true,
        'sortOrder' => (int)$pavilion['id'],
        'color' => $pavilion['color'],
        'maxParticipants' => (int)$pavilion['capacity'],
        'statusId' => 1,
        'publicId' => $pavilion['article'],
        'description' => "Артикул: {$pavilion['article']}\nПарк: {$pavilion['park']}\nВместимость: {$pavilion['capacity']} чел.\nПредоплата: {$pavilion['deposit']} руб.\nЦены:\n- Почасовая: {$pavilion['price_hour']} руб.\n- Полный день: {$pavilion['price_day']} руб.\n- Ночь: {$pavilion['price_night']} руб."
    ];
    $ch = curl_init($apiUrl . '/Resources/' . $resourceId);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-Booked-SessionToken: ' . $auth['sessionToken'], 'X-Booked-UserId: ' . $auth['userId']]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_POSTREDIR, 3);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $httpCode == 200;
}

function createResource($apiUrl, $auth, $scheduleId, $pavilion)
{
    global $logFile;
    $data = [
        'name' => $pavilion['name'],
        'scheduleId' => (int)$scheduleId,
        'autoAssign' => true,
        'allowMultiday' => false,
        'requiresApproval' => false,
        'autoAssignPermission' => true,
        'sortOrder' => (int)$pavilion['id'],
        'color' => $pavilion['color'],
        'maxParticipants' => (int)$pavilion['capacity'],
        'statusId' => 1,
        'publicId' => $pavilion['article'],
        'description' => "Артикул: {$pavilion['article']}\nПарк: {$pavilion['park']}\nВместимость: {$pavilion['capacity']} чел.\nПредоплата: {$pavilion['deposit']} руб.\nЦены:\n- Почасовая: {$pavilion['price_hour']} руб.\n- Полный день: {$pavilion['price_day']} руб.\n- Ночь: {$pavilion['price_night']} руб."
    ];
    $ch = curl_init($apiUrl . '/Resources');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-Booked-SessionToken: ' . $auth['sessionToken'], 'X-Booked-UserId: ' . $auth['userId']]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_POSTREDIR, 3);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode == 200 || $httpCode == 201) {
        $result = json_decode($response, true);
        return $result['resourceId'] ?? $result['id'] ?? null;
    }
    return null;
}

function getPavilionsFromBitrix()
{
    $result = [];
    $res = CIBlockElement::GetList(
        ['SORT' => 'ASC', 'NAME' => 'ASC'],
        ['IBLOCK_ID' => 12, 'ACTIVE' => 'Y'],
        false,
        false,
        ['ID', 'NAME', 'PROPERTY_PARK', 'PROPERTY_ARTICLE', 'PROPERTY_VMESTIMOST_NUM', 'PROPERTY_PRICE_HOUR', 'PROPERTY_PRICE', 'PROPERTY_PRICE_NIGHT', 'PROPERTY_DEPOSIT_AMOUNT']
    );
    while ($el = $res->Fetch()) {
        $park = $el['PROPERTY_PARK_VALUE'];
        $fullName = $park ? "{$park} - {$el['NAME']}" : $el['NAME'];
        $capacity = (int)$el['PROPERTY_VMESTIMOST_NUM_VALUE'];
        if ($capacity <= 0) $capacity = 10;
        $result[] = [
            'id' => $el['ID'],
            'name' => $fullName,
            'short_name' => $el['NAME'],
            'park' => $park,
            'article' => $el['PROPERTY_ARTICLE_VALUE'],
            'capacity' => $capacity,
            'deposit' => (float)$el['PROPERTY_DEPOSIT_AMOUNT_VALUE'],
            'price_hour' => (float)$el['PROPERTY_PRICE_HOUR_VALUE'],
            'price_day' => (float)$el['PROPERTY_PRICE_VALUE'],
            'price_night' => (float)$el['PROPERTY_PRICE_NIGHT_VALUE'],
            'color' => getColorByPark($park)
        ];
    }
    return $result;
}

function getColorByPark($parkName)
{
    $colors = ['Шарташ' => '#4CAF50', 'Парк Победы' => '#2196F3', 'Лесной' => '#8BC34A', 'Озёрный' => '#00BCD4', 'Центральный' => '#FF9800', 'Северный' => '#9C27B0'];
    return $colors[$parkName] ?? '#CCCCCC';
}

function saveResourceIdToBitrix($pavilionId, $resourceId)
{
    CIBlockElement::SetPropertyValuesEx($pavilionId, 12, ['LIBREBOOKING_RESOURCE_ID' => $resourceId]);
}
?>

<style>
    .sync-panel {
        background: #f5f5f5;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        margin: 20px 0;
    }

    .sync-info {
        background: #e3f2fd;
        border-left: 4px solid #2196f3;
        padding: 15px;
        margin: 20px 0;
    }

    .sync-warning {
        background: #fff3e0;
        border-left: 4px solid #ff9800;
        padding: 15px;
        margin: 20px 0;
    }
</style>

<h1>Синхронизация с LibreBooking</h1>

<?php
$apiUrl = Option::get('avs_booking', 'api_url', '');
$username = Option::get('avs_booking', 'api_username', '');
$password = Option::get('avs_booking', 'api_password', '');
if (empty($apiUrl) || empty($username) || empty($password)): ?>
    <div class="sync-warning"><strong>⚠️ Настройки API не заполнены!</strong>
        <p>Заполните <strong>URL API LibreBooking</strong>, <strong>Логин</strong> и <strong>Пароль</strong> в <a href="/bitrix/admin/settings.php?mid=avs_booking&lang=ru">настройках модуля</a>.</p>
    </div>
<?php endif; ?>

<div class="sync-info"><strong>ℹ️ Что делает синхронизация:</strong>
    <ul>
        <li>Проверяет наличие беседки в LibreBooking по уникальному артикулу (public_id).</li>
        <li>Если беседка существует – обновляет её параметры.</li>
        <li>Если нет – создаёт новую, записывая артикул в public_id.</li>
        <li>Сохраняет ID ресурса в свойство LIBREBOOKING_RESOURCE_ID.</li>
    </ul>
</div>

<div class="sync-panel">
    <form method="post"><?= bitrix_sessid_post() ?><input type="hidden" name="action" value="sync_resources"><input type="submit" value="Начать синхронизацию" class="adm-btn-save" onclick="return confirm('Запустить синхронизацию? Это может занять время.')" <?= (empty($apiUrl) || empty($username) || empty($password)) ? 'disabled' : '' ?>></form>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'); ?>