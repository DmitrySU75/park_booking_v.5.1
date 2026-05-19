<?php

/**
 * Файл: /local/modules/avs_booking/admin/orders.php
 * Управление бронированиями
 */

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

use Bitrix\Main\Loader;
use AVS\Booking\Order;

$module_id = 'avs_booking';

if ($APPLICATION->GetGroupRight($module_id) < 'R') {
    $APPLICATION->AuthForm('Доступ запрещен');
}

Loader::includeModule($module_id);

$APPLICATION->SetTitle('Управление бронированиями');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    if ($_POST['action'] === 'extend' && $_POST['order_id']) {
        // Обработка продления
        $newEndTime = $_POST['new_end_time'] ?? '';
        $orderId = (int)$_POST['order_id'];
        
        if ($newEndTime) {
            $order = Order::get($orderId);
            if ($order && $order['STATUS'] != 'deleted') {
                $result = Order::update($orderId, ['end_time' => $newEndTime]);
                if ($result) {
                    CAdminMessage::ShowMessage(['MESSAGE' => 'Время продлено', 'TYPE' => 'OK']);
                } else {
                    CAdminMessage::ShowMessage(['MESSAGE' => 'Ошибка продления', 'TYPE' => 'ERROR']);
                }
            }
        }
    }

    if ($_POST['action'] === 'delete' && $_POST['order_id']) {
        $userId = $USER->GetID();
        if (Order::softDelete((int)$_POST['order_id'], $userId)) {
            CAdminMessage::ShowMessage(['MESSAGE' => 'Заказ удален', 'TYPE' => 'OK']);
        } else {
            CAdminMessage::ShowMessage(['MESSAGE' => 'Ошибка удаления', 'TYPE' => 'ERROR']);
        }
    }

    if ($_POST['action'] === 'status' && $_POST['order_id']) {
        if (Order::updateStatus((int)$_POST['order_id'], $_POST['status'])) {
            CAdminMessage::ShowMessage(['MESSAGE' => 'Статус обновлен', 'TYPE' => 'OK']);
        } else {
            CAdminMessage::ShowMessage(['MESSAGE' => 'Ошибка обновления статуса', 'TYPE' => 'ERROR']);
        }
    }
}

// Построение фильтра с поддержкой текстового поиска
$filter = [];

// Фильтр по статусу
if (!empty($_GET['status'])) {
    $filter['STATUS'] = $_GET['status'];
}

// Фильтр по ID беседки
if (!empty($_GET['pavilion_id'])) {
    $filter['PAVILION_ID'] = (int)$_GET['pavilion_id'];
}

// Текстовый поиск (поиск по имени клиента, телефону, номеру заказа, email)
$searchText = trim($_GET['search'] ?? '');
if (!empty($searchText)) {
    $filter['%CLIENT_NAME'] = $searchText;
    $filter['%CLIENT_PHONE'] = $searchText;
    $filter['%ORDER_NUMBER'] = $searchText;
    $filter['%CLIENT_EMAIL'] = $searchText;
    // OR логика для поиска
    $filter['LOGIC'] = 'OR';
}

// Фильтр по дате
if (!empty($_GET['date_from'])) {
    $filter['>=START_TIME'] = $_GET['date_from'] . ' 00:00:00';
}
if (!empty($_GET['date_to'])) {
    $filter['<=START_TIME'] = $_GET['date_to'] . ' 23:59:59';
}

$orders = Order::getList($filter, 200, 0);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
?>

<style>
    .order-table {
        width: 100%;
        border-collapse: collapse;
    }

    .order-table th,
    .order-table td {
        border: 1px solid #ddd;
        padding: 10px;
        text-align: left;
    }

    .order-table th {
        background: #f2f2f2;
    }

    .order-table tr:hover {
        background: #f9f9f9;
    }

    .status-pending {
        color: #ff9800;
        font-weight: bold;
    }

    .status-paid {
        color: #4caf50;
        font-weight: bold;
    }

    .status-confirmed {
        color: #2196f3;
        font-weight: bold;
    }

    .status-active {
        color: #2196f3;
        font-weight: bold;
    }

    .status-completed {
        color: #009688;
    }

    .status-cancelled {
        color: #f44336;
    }

    .status-deleted {
        color: #999;
        text-decoration: line-through;
    }

    .btn-small {
        padding: 3px 8px;
        margin: 2px;
        font-size: 12px;
        cursor: pointer;
    }

    .extend-form {
        display: inline-block;
        margin-left: 10px;
    }

    .filter-form {
        margin-bottom: 20px;
        padding: 15px;
        background: #f5f5f5;
        border-radius: 4px;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: flex-end;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
    }

    .filter-group label {
        font-size: 12px;
        margin-bottom: 3px;
        color: #666;
    }

    .filter-group input,
    .filter-group select {
        padding: 5px 8px;
        border: 1px solid #ccc;
        border-radius: 3px;
    }

    .filter-group input[type="text"] {
        min-width: 200px;
    }

    .filter-actions {
        display: flex;
        gap: 5px;
    }

    .badge {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: normal;
    }

    .badge-site {
        background: #2196f3;
        color: white;
    }

    .badge-1c {
        background: #ff9800;
        color: white;
    }

    .badge-manual {
        background: #9c27b0;
        color: white;
    }
</style>

<h1>Управление бронированиями</h1>

<form method="get" class="filter-form">
    <div class="filter-group">
        <label>🔍 Поиск (имя, телефон, номер, email)</label>
        <input type="text" name="search" value="<?= htmlspecialcharsbx($_GET['search'] ?? '') ?>" placeholder="Введите текст для поиска..." size="30">
    </div>

    <div class="filter-group">
        <label>📊 Статус</label>
        <select name="status">
            <option value="">Все статусы</option>
            <option value="pending" <?= ($_GET['status'] ?? '') == 'pending' ? 'selected' : '' ?>>Ожидает оплаты</option>
            <option value="paid" <?= ($_GET['status'] ?? '') == 'paid' ? 'selected' : '' ?>>Оплачено</option>
            <option value="confirmed" <?= ($_GET['status'] ?? '') == 'confirmed' ? 'selected' : '' ?>>Подтверждено</option>
            <option value="active" <?= ($_GET['status'] ?? '') == 'active' ? 'selected' : '' ?>>Активно</option>
            <option value="completed" <?= ($_GET['status'] ?? '') == 'completed' ? 'selected' : '' ?>>Завершено</option>
            <option value="cancelled" <?= ($_GET['status'] ?? '') == 'cancelled' ? 'selected' : '' ?>>Отменено</option>
            <option value="deleted" <?= ($_GET['status'] ?? '') == 'deleted' ? 'selected' : '' ?>>Удалено</option>
        </select>
    </div>

    <div class="filter-group">
        <label>🏕️ ID беседки</label>
        <input type="text" name="pavilion_id" value="<?= htmlspecialcharsbx($_GET['pavilion_id'] ?? '') ?>" placeholder="ID" size="8">
    </div>

    <div class="filter-group">
        <label>📅 Дата с</label>
        <input type="date" name="date_from" value="<?= htmlspecialcharsbx($_GET['date_from'] ?? '') ?>">
    </div>

    <div class="filter-group">
        <label>📅 Дата по</label>
        <input type="date" name="date_to" value="<?= htmlspecialcharsbx($_GET['date_to'] ?? '') ?>">
    </div>

    <div class="filter-actions">
        <input type="submit" value="Фильтр" class="adm-btn">
        <a href="?lang=<?= LANGUAGE_ID ?>" class="adm-btn">Сбросить</a>
    </div>
</form>

<?php if (!empty($searchText)): ?>
    <div style="margin-bottom: 15px; padding: 8px; background: #e3f2fd; border-radius: 4px;">
        🔍 Результаты поиска по запросу: <strong><?= htmlspecialcharsbx($searchText) ?></strong> (найдено: <?= count($orders) ?>)
    </div>
<?php endif; ?>

<table class="order-table">
    <thead>
        <tr>
            <th>ID</th>
            <th>Номер</th>
            <th>ID бес.</th>
            <th>Беседка</th>
            <th>Клиент / Телефон</th>
            <th>Email</th>
            <th>Начало</th>
            <th>Конец</th>
            <th>Сумма</th>
            <th>Оплачено</th>
            <th>Статус</th>
            <th>Источник</th>
            <th>Действия</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($orders as $order): ?>
            <?php
            $statusClass = '';
            $statusLabel = '';
            switch ($order['STATUS']) {
                case 'pending':
                    $statusClass = 'status-pending';
                    $statusLabel = '⏳ Ожидает оплаты';
                    break;
                case 'paid':
                    $statusClass = 'status-paid';
                    $statusLabel = '✅ Оплачено';
                    break;
                case 'confirmed':
                    $statusClass = 'status-confirmed';
                    $statusLabel = '📋 Подтверждено';
                    break;
                case 'active':
                    $statusClass = 'status-active';
                    $statusLabel = '🔥 Активно';
                    break;
                case 'completed':
                    $statusClass = 'status-completed';
                    $statusLabel = '✔️ Завершено';
                    break;
                case 'cancelled':
                    $statusClass = 'status-cancelled';
                    $statusLabel = '❌ Отменено';
                    break;
                case 'deleted':
                    $statusClass = 'status-deleted';
                    $statusLabel = '🗑️ Удалено';
                    break;
                default:
                    $statusLabel = $order['STATUS'];
            }

            // Определение источника бронирования
            $sourceClass = '';
            $sourceLabel = '';
            if (strpos($order['ORDER_NUMBER'], 'ORD-') === 0) {
                $sourceClass = 'badge-site';
                $sourceLabel = 'Сайт';
            } elseif (strpos($order['ORDER_NUMBER'], '1C-') === 0) {
                $sourceClass = 'badge-1c';
                $sourceLabel = '1С';
            } elseif (strpos($order['ORDER_NUMBER'], 'MAN-') === 0) {
                $sourceClass = 'badge-manual';
                $sourceLabel = 'Вручную';
            } else {
                $sourceLabel = 'API';
            }
            ?>
            <tr class="<?= $statusClass ?>">
                <td><?= $order['ID'] ?></td>
                <td><?= htmlspecialcharsbx($order['ORDER_NUMBER']) ?></td>
                <td><?= $order['PAVILION_ID'] ?></td>
                <td><?= htmlspecialcharsbx($order['PAVILION_NAME']) ?></td>
                <td>
                    <strong><?= htmlspecialcharsbx($order['CLIENT_NAME']) ?></strong><br>
                    <small><?= htmlspecialcharsbx($order['CLIENT_PHONE']) ?></small>
                </td>
                <td><?= htmlspecialcharsbx($order['CLIENT_EMAIL'] ?: '-') ?></td>
                <td><?= $order['START_TIME'] instanceof \Bitrix\Main\Type\DateTime ? $order['START_TIME']->format('d.m.Y H:i') : $order['START_TIME'] ?></td>
                <td><?= $order['END_TIME'] instanceof \Bitrix\Main\Type\DateTime ? $order['END_TIME']->format('d.m.Y H:i') : $order['END_TIME'] ?></td>
                <td><?= number_format($order['PRICE'], 0, '.', ' ') ?> руб.</td>
                <td><?= number_format($order['PAID_AMOUNT'], 0, '.', ' ') ?> руб.</td>
                <td class="<?= $statusClass ?>"><?= $statusLabel ?></td>
                <td><span class="badge <?= $sourceClass ?>"><?= $sourceLabel ?></span></td>
                <td>
                    <?php if ($order['STATUS'] != 'deleted' && $order['STATUS'] != 'cancelled' && $order['STATUS'] != 'completed'): ?>
                        <form method="post" style="display: inline-block;">
                            <?= bitrix_sessid_post() ?>
                            <input type="hidden" name="action" value="status">
                            <input type="hidden" name="order_id" value="<?= $order['ID'] ?>">
                            <select name="status" onchange="this.form.submit()" style="font-size:11px;">
                                <option value="pending" <?= $order['STATUS'] == 'pending' ? 'selected' : '' ?>>Ожидает</option>
                                <option value="paid" <?= $order['STATUS'] == 'paid' ? 'selected' : '' ?>>Оплачено</option>
                                <option value="confirmed" <?= $order['STATUS'] == 'confirmed' ? 'selected' : '' ?>>Подтвердить</option>
                                <option value="active" <?= $order['STATUS'] == 'active' ? 'selected' : '' ?>>Активно</option>
                                <option value="completed" <?= $order['STATUS'] == 'completed' ? 'selected' : '' ?>>Завершить</option>
                                <option value="cancelled">Отменить</option>
                            </select>
                        </form>

                        <?php if ($order['STATUS'] == 'paid' || $order['STATUS'] == 'confirmed' || $order['STATUS'] == 'active'): ?>
                            <form method="post" class="extend-form" onsubmit="return confirm('Продлить время бронирования?')">
                                <?= bitrix_sessid_post() ?>
                                <input type="hidden" name="action" value="extend">
                                <input type="hidden" name="order_id" value="<?= $order['ID'] ?>">
                                <input type="datetime-local" name="new_end_time" value="<?= $order['END_TIME'] instanceof \Bitrix\Main\Type\DateTime ? $order['END_TIME']->format('Y-m-d\TH:i') : date('Y-m-d\TH:i', strtotime($order['END_TIME'])) ?>" style="width:140px; font-size:11px;">
                                <input type="submit" value="Продлить" class="btn-small">
                            </form>
                        <?php endif; ?>

                        <form method="post" style="display: inline-block;" onsubmit="return confirm('Удалить заказ? Это действие нельзя отменить.')">
                            <?= bitrix_sessid_post() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="order_id" value="<?= $order['ID'] ?>">
                            <input type="submit" value="Удалить" class="btn-small" style="background:#f44336; color:white;">
                        </form>
                    <?php else: ?>
                        <span style="color:#999;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($orders)): ?>
            <tr>
                <td colspan="13" style="text-align:center; padding:30px; color:#999;">
                    <?= !empty($searchText) ? 'По вашему запросу ничего не найдено' : 'Нет заказов' ?>
                </td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<?php if (count($orders) >= 200): ?>
    <div style="margin-top: 15px; padding: 10px; background: #fff3e0; border-radius: 4px; text-align:center;">
        ⚠️ Показано максимум 200 записей. Уточните фильтр для более точного поиска.
    </div>
<?php endif; ?>

<?
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
?>
