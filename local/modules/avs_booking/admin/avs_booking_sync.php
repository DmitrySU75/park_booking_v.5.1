<?php
/**
 * Файл: /local/modules/avs_booking/admin/avs_booking_sync.php
 * Страница синхронизации беседок с LibreBooking
 */

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;

$module_id = 'avs_booking';

if ($APPLICATION->GetGroupRight($module_id) < 'W') {
    $APPLICATION->AuthForm('Доступ запрещен');
}

Loader::includeModule($module_id);
Loader::includeModule('iblock');

$APPLICATION->SetTitle('Синхронизация с LibreBooking');

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    if ($_POST['action'] === 'sync_resources') {
        $result = syncResources();
        if ($result['success']) {
            CAdminMessage::ShowMessage([
                'MESSAGE' => "Синхронизация завершена. Создано: {$result['created']}, Обновлено: {$result['updated']}, Ошибок: {$result['errors']}",
                'TYPE' => 'OK'
            ]);
        } else {
            CAdminMessage::ShowMessage(['MESSAGE' => $result['error'], 'TYPE' => 'ERROR']);
        }
    }
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');

/**
 * Функция синхронизации беседок
 */
function syncResources()
{
    $result = [
        'success' => true,
        'created' => 0,
        'updated' => 0,
        'errors' => 0
    ];
    
    // Получаем настройки API из модуля
    $apiUrl = Option::get('avs_booking', 'api_url', '');
    $username = Option::get('avs_booking', 'api_username', '');
    $password = Option::get('avs_booking', 'api_password', '');
    
    if (empty($apiUrl) || empty($username) || empty($password)) {
        $result['success'] = false;
        $result['error'] = 'Настройки API LibreBooking не заполнены. Заполните их в разделе "Настройки" модуля.';
        return $result;
    }
    
    // 1. Аутентификация
    $auth = authenticate($apiUrl, $username, $password);
    if (!$auth) {
        $result['success'] = false;
        $result['error'] = 'Ошибка аутентификации в LibreBooking API. Проверьте логин и пароль.';
        return $result;
    }
    
    // 2. Получаем беседки из инфоблока
    $pavilions = getPavilionsFromBitrix();
    if (empty($pavilions)) {
        $result['success'] = false;
        $result['error'] = 'Нет активных беседок для синхронизации (инфоблок 12)';
        return $result;
    }
    
    // 3. Получаем существующие ресурсы из LibreBooking
    $existingResources = getExistingResources($apiUrl, $auth);
    
    // 4. Синхронизация
    $scheduleId = getScheduleId($apiUrl, $auth);
    
    foreach ($pavilions as $pavilion) {
        if (isset($existingResources[$pavilion['name']])) {
            $result['updated']++;
        } else {
            // Создаём новый ресурс
            $resourceId = createResource($apiUrl, $auth, $scheduleId, $pavilion);
            if ($resourceId) {
                $result['created']++;
                // Сохраняем ID в свойство инфоблока
                saveResourceIdToBitrix($pavilion['id'], $resourceId);
            } else {
                $result['errors']++;
            }
        }
    }
    
    return $result;
}

/**
 * Аутентификация в API LibreBooking
 */
function authenticate($apiUrl, $username, $password)
{
    $ch = curl_init($apiUrl . '/Authentication/Authenticate');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'username' => $username,
        'password' => $password
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 200) {
        $data = json_decode($response, true);
        return $data;
    }
    
    return false;
}

/**
 * Получение ID расписания
 */
function getScheduleId($apiUrl, $auth)
{
    $ch = curl_init($apiUrl . '/Schedules');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Booked-SessionToken: ' . $auth['sessionToken'],
        'X-Booked-UserId: ' . $auth['userId']
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $schedules = json_decode($response, true);
    if (is_array($schedules) && !empty($schedules)) {
        return $schedules[0]['id'];
    }
    
    return 1;
}

/**
 * Получение существующих ресурсов из LibreBooking
 */
function getExistingResources($apiUrl, $auth)
{
    $result = [];
    
    $ch = curl_init($apiUrl . '/Resources');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Booked-SessionToken: ' . $auth['sessionToken'],
        'X-Booked-UserId: ' . $auth['userId']
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $resources = json_decode($response, true);
    if (is_array($resources)) {
        foreach ($resources as $resource) {
            if (isset($resource['name']) && isset($resource['id'])) {
                $result[$resource['name']] = $resource['id'];
            }
        }
    }
    
    return $result;
}

/**
 * Получение беседок из инфоблока Битрикс
 */
function getPavilionsFromBitrix()
{
    $result = [];
    
    $res = CIBlockElement::GetList(
        ['SORT' => 'ASC', 'NAME' => 'ASC'],
        ['IBLOCK_ID' => 12, 'ACTIVE' => 'Y'],
        false,
        false,
        [
            'ID',
            'NAME',
            'PROPERTY_PARK',
            'PROPERTY_ARTICLE',
            'PROPERTY_VMESTIMOST_NUM',
            'PROPERTY_PRICE_HOUR',
            'PROPERTY_PRICE',
            'PROPERTY_PRICE_NIGHT',
            'PROPERTY_DEPOSIT_AMOUNT'
        ]
    );
    
    while ($el = $res->Fetch()) {
        $parkName = $el['PROPERTY_PARK_VALUE'];
        $fullName = $parkName ? "{$parkName} - {$el['NAME']}" : $el['NAME'];
        
        $capacity = (int)$el['PROPERTY_VMESTIMOST_NUM_VALUE'];
        if ($capacity <= 0) {
            $capacity = 10;
        }
        
        $result[] = [
            'id' => $el['ID'],
            'name' => $fullName,
            'short_name' => $el['NAME'],
            'park' => $parkName,
            'article' => $el['PROPERTY_ARTICLE_VALUE'],
            'capacity' => $capacity,
            'deposit' => (float)$el['PROPERTY_DEPOSIT_AMOUNT_VALUE'],
            'price_hour' => (float)$el['PROPERTY_PRICE_HOUR_VALUE'],
            'price_day' => (float)$el['PROPERTY_PRICE_VALUE'],
            'price_night' => (float)$el['PROPERTY_PRICE_NIGHT_VALUE'],
            'color' => getColorByPark($parkName)
        ];
    }
    
    return $result;
}

/**
 * Определение цвета по парку
 */
function getColorByPark($parkName)
{
    $colors = [
        'Шарташ' => '#4CAF50',
        'Парк Победы' => '#2196F3',
        'Лесной' => '#8BC34A',
        'Озёрный' => '#00BCD4',
        'Центральный' => '#FF9800',
        'Северный' => '#9C27B0'
    ];
    
    return isset($colors[$parkName]) ? $colors[$parkName] : '#CCCCCC';
}

/**
 * Создание ресурса в LibreBooking
 */
function createResource($apiUrl, $auth, $scheduleId, $pavilion)
{
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
        'description' => "Артикул: {$pavilion['article']}\n" .
                        "Парк: {$pavilion['park']}\n" .
                        "Вместимость: {$pavilion['capacity']} чел.\n" .
                        "Предоплата: {$pavilion['deposit']} руб.\n" .
                        "Цены:\n" .
                        "- Почасовая: {$pavilion['price_hour']} руб.\n" .
                        "- Полный день: {$pavilion['price_day']} руб.\n" .
                        "- Ночь: {$pavilion['price_night']} руб."
    ];
    
    $ch = curl_init($apiUrl . '/Resources');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Booked-SessionToken: ' . $auth['sessionToken'],
        'X-Booked-UserId: ' . $auth['userId']
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 200 || $httpCode == 201) {
        $result = json_decode($response, true);
        return isset($result['resourceId']) ? $result['resourceId'] : (isset($result['id']) ? $result['id'] : null);
    }
    
    return null;
}

/**
 * Сохранение ID ресурса в свойство инфоблока
 */
function saveResourceIdToBitrix($pavilionId, $resourceId)
{
    CIBlockElement::SetPropertyValuesEx($pavilionId, 12, [
        'LIBREBOOKING_RESOURCE_ID' => $resourceId
    ]);
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
    .sync-success {
        background: #e8f5e9;
        border-left: 4px solid #4caf50;
        padding: 15px;
        margin: 20px 0;
    }
</style>

<h1>Синхронизация с LibreBooking</h1>

<?php
// Проверяем, заполнены ли настройки API
$apiUrl = Option::get('avs_booking', 'api_url', '');
$username = Option::get('avs_booking', 'api_username', '');
$password = Option::get('avs_booking', 'api_password', '');

if (empty($apiUrl) || empty($username) || empty($password)):
?>
    <div class="sync-warning">
        <strong>⚠️ Настройки API не заполнены!</strong>
        <p>Для синхронизации необходимо заполнить настройки API LibreBooking в разделе 
        <a href="/bitrix/admin/settings.php?mid=avs_booking&lang=ru">Настройки модуля → Основные настройки</a></p>
        <ul>
            <li>URL API LibreBooking</li>
            <li>Логин API LibreBooking</li>
            <li>Пароль API LibreBooking</li>
        </ul>
    </div>
<?php endif; ?>

<div class="sync-info">
    <strong>ℹ️ Что делает синхронизация:</strong>
    <ul>
        <li>Автоматически создаёт беседки из инфоблока в LibreBooking</li>
        <li>Обновляет информацию о вместимости и ценах</li>
        <li>Сохраняет соответствие ID в свойство LIBREBOOKING_RESOURCE_ID</li>
    </ul>
</div>

<div class="sync-warning">
    <strong>⚠️ Важно:</strong>
    <ul>
        <li>Перед синхронизацией убедитесь, что в настройках модуля указаны данные для подключения к LibreBooking API</li>
        <li>Синхронизация может занять несколько минут при большом количестве беседок</li>
        <li>Беседки создаются с названием "Парк - Название беседки"</li>
    </ul>
</div>

<div class="sync-panel">
    <form method="post">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="action" value="sync_resources">
        <input type="submit" value="Начать синхронизацию" class="adm-btn-save" 
               onclick="return confirm('Запустить синхронизацию беседок с LibreBooking? Это может занять некоторое время.')"
               <?= (empty($apiUrl) || empty($username) || empty($password)) ? 'disabled' : '' ?>>
    </form>
</div>

<?php
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
?>