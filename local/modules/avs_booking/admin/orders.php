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
        $result = Order::extendTime((int)$_POST['order_id'], $_POST['new_end_time']);
        if ($result['success']) {
            CAdminMessage::ShowMessage(['MESSAGE' => 'Время продлено', 'TYPE' => 'OK']);
        } else {
            CAdminMessage::ShowMessage(['MESSAGE' => $result['error'], 'TYPE' => 'ERROR']);
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

$filter = [];
if ($_GET['status']) $filter['STATUS'] = $_GET['status'];
if ($_GET['pavilion_id']) $filter['PAVILION_ID'] = (int)$_GET['pavilion_id'];

$orders = Order::getList($filter, 100, 0);

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
    }

    .extend-form {
        display: inline-block;
        margin-left: 10px;
    }

    .filter-form {
        margin-bottom: 20px;
        padding: 10px;
        background: #f5f5f5;
        border-radius: 4px;
    }
</style>

<h1>Управление бронированиями</h1>

<form method="get" class="filter-form">
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
    <input type="text" name="pavilion_id" placeholder="ID беседки" value="<?= htmlspecialcharsbx($_GET['pavilion_id'] ?? '') ?>" size="10">
    <input type="submit" value="Фильтр">
</form>

<table class="order-table">
    <thead>
        <tr>
            <th>ID</th>
            <th>Номер</th>
            <th>ID беседки</th>
            <th>Беседка</th>
            <th>Клиент</th>
            <th>Телефон</th>
            <th>Начало</th>
            <th>Конец</th>
            <th>Сумма</th>
            <th>Оплачено</th>
            <th>Статус</th>
            <th>Действия</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($orders as $order): ?>
            <?php
            $statusClass = '';
            switch ($order['STATUS']) {
                case 'pending':
                    $statusClass = 'status-pending';
                    break;
                case 'paid':
                    $statusClass = 'status-paid';
                    break;
                case 'confirmed':
                    $statusClass = 'status-confirmed';
                    break;
                case 'active':
                    $statusClass = 'status-active';
                    break;
                case 'completed':
                    $statusClass = 'status-completed';
                    break;
                case 'cancelled':
                    $statusClass = 'status-cancelled';
                    break;
                case 'deleted':
                    $statusClass = 'status-deleted';
                    break;
            }
            ?>
            <tr class="<?= $statusClass ?>">
                <td><?= $order['ID'] ?></td>
                <td><?= htmlspecialcharsbx($order['ORDER_NUMBER']) ?></td>
                <td><?= $order['PAVILION_ID'] ?></td>
                <td><?= htmlspecialcharsbx($order['PAVILION_NAME']) ?></td>
                <td><?= htmlspecialcharsbx($order['CLIENT_NAME']) ?></td>
                <td><?= htmlspecialcharsbx($order['CLIENT_PHONE']) ?></td>
                <td><?= $order['START_TIME'] ?></td>
                <td><?= $order['END_TIME'] ?></td>
                <td><?= number_format($order['PRICE'], 0, '.', ' ') ?> руб.</td>
                <td><?= number_format($order['PAID_AMOUNT'], 0, '.', ' ') ?> руб.</td>
                <td><?= $order['STATUS'] ?></td>
                <td>
                    <?php if ($order['STATUS'] != 'deleted' && $order['STATUS'] != 'cancelled' && $order['STATUS'] != 'completed'): ?>
                        <form method="post" style="display: inline-block;">
                            <?= bitrix_sessid_post() ?>
                            <input type="hidden" name="action" value="status">
                            <input type="hidden" name="order_id" value="<?= $order['ID'] ?>">
                            <select name="status" onchange="this.form.submit()">
                                <option value="pending" <?= $order['STATUS'] == 'pending' ? 'selected' : '' ?>>Ожидает</option>
                                <option value="paid" <?= $order['STATUS'] == 'paid' ? 'selected' : '' ?>>Оплачено</option>
                                <option value="confirmed" <?= $order['STATUS'] == 'confirmed' ? 'selected' : '' ?>>Подтвердить</option>
                                <option value="active" <?= $order['STATUS'] == 'active' ? 'selected' : '' ?>>Активно</option>
                                <option value="completed" <?= $order['STATUS'] == 'completed' ? 'selected' : '' ?>>Завершить</option>
                                <option value="cancelled">Отменить</option>
                            </select>
                        </form>

                        <?php if ($order['STATUS'] == 'paid' || $order['STATUS'] == 'confirmed' || $order['STATUS'] == 'active'): ?>
                            <form method="post" class="extend-form" onsubmit="return confirm('Продлить время?')">
                                <?= bitrix_sessid_post() ?>
                                <input type="hidden" name="action" value="extend">
                                <input type="hidden" name="order_id" value="<?= $order['ID'] ?>">
                                <input type="datetime-local" name="new_end_time" value="<?= date('Y-m-d\TH:i', strtotime($order['END_TIME'])) ?>">
                                <input type="submit" value="Продлить" class="btn-small">
                            </form>
                        <?php endif; ?>

                        <form method="post" style="display: inline-block;" onsubmit="return confirm('Удалить заказ?')">
                            <?= bitrix_sessid_post() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="order_id" value="<?= $order['ID'] ?>">
                            <input type="submit" value="Удалить" class="btn-small" style="background:#f44336; color:white;">
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($orders)): ?>
            <tr>
                <td colspan="12" style="text-align:center;">Нет заказов</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<?
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
?>