<?php

/**
 * Файл: /local/modules/avs_booking/admin/avs_booking_dashboard.php
 * Дашборд AVS Booking с отображением реальной занятости из LibreBooking
 */

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

use Bitrix\Main\Loader;
use AVS\Booking\Order;

$module_id = 'avs_booking';

if ($APPLICATION->GetGroupRight($module_id) < 'R') {
    $APPLICATION->AuthForm('Доступ запрещен');
}

Loader::includeModule($module_id);

// Инициализация ajax-компонента для CSRF
CJSCore::Init(['ajax']);
$sessid = bitrix_sessid();

$logFile = $_SERVER['DOCUMENT_ROOT'] . '/upload/avs_booking_debug.log';
file_put_contents($logFile, date('Y-m-d H:i:s') . " [dashboard] Page loaded\n", FILE_APPEND);

$APPLICATION->SetTitle('Дашборд AVS Booking');

$today = date('Y-m-d');
$weekAgo = date('Y-m-d', strtotime('-7 days'));
$monthAgo = date('Y-m-d', strtotime('-30 days'));

$weekOrders = Order::getListByPeriod($weekAgo, $today);
$monthOrders = Order::getListByPeriod($monthAgo, $today);
$todayOrders = Order::getListByPeriod($today, $today);

$totalWeek = array_sum(array_column($weekOrders, 'PRICE'));
$totalMonth = array_sum(array_column($monthOrders, 'PRICE'));
$paidWeek = array_sum(array_column(array_filter($weekOrders, function ($o) {
    return $o['STATUS'] == 'paid';
}), 'PRICE'));

$statusCount = [
    'pending' => 0,
    'paid' => 0,
    'confirmed' => 0,
    'active' => 0,
    'completed' => 0,
    'cancelled' => 0
];

foreach ($weekOrders as $order) {
    $statusCount[$order['STATUS']]++;
}

// Статистика по источникам
$sourceStats = [
    'site' => 0,
    '1c' => 0,
    'manual' => 0,
    'librebooking' => 0
];

foreach ($weekOrders as $order) {
    if (strpos($order['ORDER_NUMBER'], 'ORD-') === 0) {
        $sourceStats['site']++;
    } elseif (strpos($order['ORDER_NUMBER'], '1C-') === 0) {
        $sourceStats['1c']++;
    } elseif (strpos($order['ORDER_NUMBER'], 'MAN-') === 0) {
        $sourceStats['manual']++;
    }
}

// Получение данных из LibreBooking
$libreBookings = [];
$libreBookingsToday = [];
$libreBookingError = null;

try {
    $api = new AVSBookingLibreBookingClient();
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [dashboard] LibreBooking API client created\n", FILE_APPEND);

    $pavilions = [];
    if (Loader::includeModule('iblock')) {
        $res = CIBlockElement::GetList(['NAME' => 'ASC'], ['IBLOCK_ID' => 12, 'ACTIVE' => 'Y'], false, false, ['ID', 'NAME', 'PROPERTY_LIBREBOOKING_RESOURCE_ID']);
        while ($el = $res->Fetch()) {
            if ($el['PROPERTY_LIBREBOOKING_RESOURCE_ID_VALUE']) {
                $pavilions[] = [
                    'id' => $el['ID'],
                    'name' => $el['NAME'],
                    'resource_id' => $el['PROPERTY_LIBREBOOKING_RESOURCE_ID_VALUE']
                ];
            }
        }
    }
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [dashboard] Found " . count($pavilions) . " pavilions\n", FILE_APPEND);

    $today = date('Y-m-d');
    $workEndHour = AVSBookingModule::getWorkEndHour(1, $today);
    $startTime = $today . 'T10:00:00+05:00';
    $endTime = $today . 'T' . $workEndHour . ':00:00+05:00';
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [dashboard] Checking availability from $startTime to $endTime\n", FILE_APPEND);

    foreach ($pavilions as $pavilion) {
        $available = $api->checkAvailability($pavilion['resource_id'], $startTime, $endTime);
        file_put_contents($logFile, date('Y-m-d H:i:s') . " [dashboard] Pavilion {$pavilion['name']} (resource_id={$pavilion['resource_id']}) available=" . ($available ? 'yes' : 'no') . "\n", FILE_APPEND);

        if (!$available) {
            $libreBookingsToday[] = [
                'pavilion_name' => $pavilion['name'],
                'status' => 'Занято',
                'source' => 'LibreBooking'
            ];
            $sourceStats['librebooking']++;
        }

        $libreBookings[] = [
            'pavilion_name' => $pavilion['name'],
            'resource_id' => $pavilion['resource_id'],
            'available_today' => $available
        ];
    }
} catch (Exception $e) {
    $libreBookingError = $e->getMessage();
    file_put_contents($logFile, date('Y-m-d H:i:s') . " [dashboard] LibreBooking error: " . $e->getMessage() . "\n", FILE_APPEND);
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
?>

<style>
    .dashboard-stats { display: flex; gap: 20px; margin-bottom: 30px; flex-wrap: wrap; }
    .stat-card { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; min-width: 200px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .stat-card h3 { margin: 0 0 10px 0; font-size: 14px; color: #666; }
    .stat-card .value { font-size: 28px; font-weight: bold; color: #333; }
    .stat-card .unit { font-size: 14px; color: #999; }
    .status-badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; }
    .status-pending { background: #fff3e0; color: #ff9800; }
    .status-paid { background: #e8f5e9; color: #4caf50; }
    .status-confirmed { background: #e3f2fd; color: #2196f3; }
    .status-active { background: #e3f2fd; color: #2196f3; }
    .status-completed { background: #e0f2f1; color: #009688; }
    .status-cancelled { background: #ffebee; color: #f44336; }
    .order-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    .order-table th, .order-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    .order-table th { background: #f2f2f2; }
    .warning { background: #fff3e0; border-left: 4px solid #ff9800; padding: 12px; margin-bottom: 20px; }
    .two-columns { display: flex; gap: 20px; margin-top: 20px; }
    .column { flex: 1; background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 15px; }
    .column h3 { margin-top: 0; padding-bottom: 10px; border-bottom: 1px solid #eee; }
    .sync-panel { background: #e8f5e9; border: 1px solid #a5d6a7; border-radius: 8px; padding: 15px; margin-bottom: 20px; }
</style>

<h1>Дашборд бронирований</h1>

<div class="sync-panel">
    <strong>🔄 Синхронизация с LibreBooking:</strong>
    <button onclick="quickSync()" class="adm-btn" id="syncBtn" style="margin-left: 10px;">📅 За последние 24 часа</button>
    <button onclick="fullSync()" class="adm-btn" id="fullSyncBtn">📆 За 30 дней</button>
    <span id="syncStatus" style="margin-left: 10px; color: #2e7d32;"></span>
</div>

<script>
var bxSessid = '<?= $sessid ?>';

function quickSync() {
    var btn = document.getElementById('syncBtn');
    var status = document.getElementById('syncStatus');
    btn.disabled = true;
    status.innerHTML = '⏳ Выполняется быстрая синхронизация...';

    fetch('/local/modules/avs_booking/ajax_sync.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=quick&sessid=' + bxSessid
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            status.innerHTML = '✅ Синхронизация завершена: +' + data.added + ' новых, обновлено ' + data.updated;
            setTimeout(() => location.reload(), 1500);
        } else {
            status.innerHTML = '❌ Ошибка: ' + data.error;
        }
        btn.disabled = false;
    })
    .catch(error => {
        console.error('Fetch error:', error);
        status.innerHTML = '❌ Ошибка сети: ' + error.message;
        btn.disabled = false;
    });
}

function fullSync() {
    var btn = document.getElementById('fullSyncBtn');
    var status = document.getElementById('syncStatus');
    btn.disabled = true;
    status.innerHTML = '⏳ Выполняется полная синхронизация (может занять время)...';

    fetch('/local/modules/avs_booking/ajax_sync.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=full&sessid=' + bxSessid
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            status.innerHTML = '✅ Полная синхронизация завершена: +' + data.added + ' новых, обновлено ' + data.updated;
            setTimeout(() => location.reload(), 2000);
        } else {
            status.innerHTML = '❌ Ошибка: ' + data.error;
        }
        btn.disabled = false;
    })
    .catch(error => {
        console.error('Fetch error:', error);
        status.innerHTML = '❌ Ошибка сети: ' + error.message;
        btn.disabled = false;
    });
}
</script>

<?php if ($libreBookingError): ?>
    <div class="warning">⚠️ Не удалось подключиться к LibreBooking API: <?= htmlspecialcharsbx($libreBookingError) ?></div>
<?php endif; ?>

<div class="dashboard-stats">
    <div class="stat-card"><h3>📱 Через сайт</h3><div class="value"><?= $sourceStats['site'] ?></div><div class="unit">броней за неделю</div></div>
    <div class="stat-card"><h3>🖥️ Из 1С</h3><div class="value"><?= $sourceStats['1c'] ?></div><div class="unit">броней за неделю</div></div>
    <div class="stat-card"><h3>📅 В LibreBooking</h3><div class="value"><?= $sourceStats['librebooking'] ?></div><div class="unit">броней сегодня</div></div>
</div>

<div class="dashboard-stats">
    <div class="stat-card"><h3>Бронирований за неделю</h3><div class="value"><?= count($weekOrders) ?></div></div>
    <div class="stat-card"><h3>На сумму (неделя)</h3><div class="value"><?= number_format($totalWeek, 0, '.', ' ') ?></div><div class="unit">руб.</div></div>
    <div class="stat-card"><h3>Оплачено (неделя)</h3><div class="value"><?= number_format($paidWeek, 0, '.', ' ') ?></div><div class="unit">руб.</div></div>
    <div class="stat-card"><h3>Бронирований за месяц</h3><div class="value"><?= count($monthOrders) ?></div></div>
    <div class="stat-card"><h3>На сумму (месяц)</h3><div class="value"><?= number_format($totalMonth, 0, '.', ' ') ?></div><div class="unit">руб.</div></div>
</div>

<div class="two-columns">
    <div class="column">
        <h3>📅 Бронирования сегодня (Битрикс) <?= count($todayOrders) ?></h3>
        <table class="order-table">
            <thead><tr><th>Время</th><th>Беседка</th><th>Клиент</th><th>Сумма</th><th>Статус</th></tr></thead>
            <tbody>
                <?php foreach ($todayOrders as $order): ?>
                <tr>
                    <td><?= date('H:i', strtotime($order['START_TIME'])) ?> - <?= date('H:i', strtotime($order['END_TIME'])) ?></td>
                    <td><?= htmlspecialcharsbx($order['PAVILION_NAME']) ?></td>
                    <td><?= htmlspecialcharsbx($order['CLIENT_NAME']) ?></td>
                    <td><?= number_format($order['PRICE'], 0, '.', ' ') ?> руб.</td>
                    <td><span class="status-badge status-<?= $order['STATUS'] ?>"><?= $order['STATUS'] ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($todayOrders)): ?>
                <tr><td colspan="5" style="text-align:center;">Нет бронирований на сегодня</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="column">
        <h3>🏕️ Занятость сегодня (LibreBooking)</h3>
        <?php if (!empty($libreBookingsToday)): ?>
        <table class="order-table">
            <thead><tr><th>Беседка</th><th>Статус</th><th>Источник</th></tr></thead>
            <tbody>
                <?php foreach ($libreBookingsToday as $booking): ?>
                <tr><td><?= htmlspecialcharsbx($booking['pavilion_name']) ?></td><td><span class="status-badge status-paid">Занято</span></td><td><?= htmlspecialcharsbx($booking['source']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p style="text-align:center; color:#999;">Все беседки свободны</p>
        <?php endif; ?>

        <h3>📊 Статус беседок на сегодня</h3>
        <table class="order-table">
            <thead><tr><th>Беседка</th><th>Статус</th></tr></thead>
            <tbody>
                <?php foreach ($libreBookings as $booking): ?>
                <tr><td><?= htmlspecialcharsbx($booking['pavilion_name']) ?></td><td><?php if ($booking['available_today']): ?><span class="status-badge status-completed">🟢 Свободна</span><?php else: ?><span class="status-badge status-paid">🔴 Занята</span><?php endif; ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<h2>Статусы бронирований за неделю</h2>
<table class="order-table" style="width:auto;">
    <thead><tr><th>Статус</th><th>Количество</th></tr></thead>
    <tbody>
        <tr><td><span class="status-badge status-pending">Ожидает оплаты</span></td><td><?= $statusCount['pending'] ?></td></tr>
        <tr><td><span class="status-badge status-paid">Оплачено</span></td><td><?= $statusCount['paid'] ?></td></tr>
        <tr><td><span class="status-badge status-confirmed">Подтверждено</span></td><td><?= $statusCount['confirmed'] ?></td></tr>
        <tr><td><span class="status-badge status-active">Активно</span></td><td><?= $statusCount['active'] ?></td></tr>
        <tr><td><span class="status-badge status-completed">Завершено</span></td><td><?= $statusCount['completed'] ?></td></tr>
        <tr><td><span class="status-badge status-cancelled">Отменено</span></td><td><?= $statusCount['cancelled'] ?></td></tr>
    </tbody>
</table>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'); ?>