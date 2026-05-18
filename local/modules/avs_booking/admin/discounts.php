<?php

/**
 * Файл: /local/modules/avs_booking/admin/discounts.php
 * Управление скидками и промокодами
 */

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

use Bitrix\Main\Loader;

$module_id = 'avs_booking';

if ($APPLICATION->GetGroupRight($module_id) < 'W') {
    $APPLICATION->AuthForm('Доступ запрещен');
}

Loader::includeModule($module_id);

$APPLICATION->SetTitle('Управление скидками и промокодами');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    if ($_POST['action'] === 'save') {
        $data = [
            'code' => $_POST['code'],
            'name' => $_POST['name'],
            'discount_type' => $_POST['discount_type'],
            'discount_value' => floatval($_POST['discount_value']),
            'valid_from' => $_POST['valid_from'] ?: null,
            'valid_to' => $_POST['valid_to'] ?: null,
            'min_order_amount' => $_POST['min_order_amount'] ? floatval($_POST['min_order_amount']) : null,
            'max_uses' => $_POST['max_uses'] ? intval($_POST['max_uses']) : null,
            'active' => $_POST['active']
        ];

        if (AVSBookingDiscountManager::createDiscount($data)) {
            CAdminMessage::ShowMessage(['MESSAGE' => 'Промокод сохранен', 'TYPE' => 'OK']);
        } else {
            CAdminMessage::ShowMessage(['MESSAGE' => 'Ошибка сохранения', 'TYPE' => 'ERROR']);
        }
    }
}

$discounts = AVSBookingDiscountManager::getActiveDiscounts();

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
?>

<style>
    .form-row {
        margin-bottom: 15px;
    }

    .form-row label {
        display: inline-block;
        width: 150px;
        font-weight: bold;
    }

    .discount-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }

    .discount-table th,
    .discount-table td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: left;
    }

    .discount-table th {
        background: #f2f2f2;
    }
</style>

<h1>Управление скидками и промокодами</h1>

<h2>Добавить промокод</h2>
<form method="post">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="action" value="save">

    <div class="form-row">
        <label>Код промокода:</label>
        <input type="text" name="code" required style="text-transform:uppercase">
    </div>

    <div class="form-row">
        <label>Название:</label>
        <input type="text" name="name" required size="40">
    </div>

    <div class="form-row">
        <label>Тип скидки:</label>
        <select name="discount_type" required>
            <option value="percent">Процент (%)</option>
            <option value="fixed">Фиксированная сумма (руб)</option>
        </select>
    </div>

    <div class="form-row">
        <label>Значение скидки:</label>
        <input type="number" name="discount_value" step="0.01" required>
    </div>

    <div class="form-row">
        <label>Действителен с:</label>
        <input type="datetime-local" name="valid_from">
    </div>

    <div class="form-row">
        <label>Действителен до:</label>
        <input type="datetime-local" name="valid_to">
    </div>

    <div class="form-row">
        <label>Мин. сумма заказа:</label>
        <input type="number" name="min_order_amount" step="0.01">
    </div>

    <div class="form-row">
        <label>Макс. кол-во использований:</label>
        <input type="number" name="max_uses">
    </div>

    <div class="form-row">
        <label>Активен:</label>
        <select name="active">
            <option value="Y">Да</option>
            <option value="N">Нет</option>
        </select>
    </div>

    <div class="form-row">
        <input type="submit" value="Сохранить" class="adm-btn-save">
    </div>
</form>

<h2>Активные промокоды</h2>
<table class="discount-table">
    <thead>
        <tr>
            <th>Код</th>
            <th>Название</th>
            <th>Тип</th>
            <th>Значение</th>
            <th>Действителен</th>
            <th>Использований</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($discounts as $d): ?>
            <tr>
                <td><?= htmlspecialcharsbx($d['CODE']) ?></td>
                <td><?= htmlspecialcharsbx($d['NAME']) ?></td>
                <td><?= $d['DISCOUNT_TYPE'] == 'percent' ? 'Процент' : 'Фикс.' ?></td>
                <td><?= htmlspecialcharsbx($d['DISCOUNT_VALUE']) ?><?= $d['DISCOUNT_TYPE'] == 'percent' ? '%' : ' руб.' ?></td>
                <td>
                    <?php
                    if ($d['VALID_FROM']) echo "с " . date('d.m.Y H:i', strtotime($d['VALID_FROM'])) . "<br>";
                    if ($d['VALID_TO']) echo "по " . date('d.m.Y H:i', strtotime($d['VALID_TO']));
                    if (!$d['VALID_FROM'] && !$d['VALID_TO']) echo "без ограничений";
                    ?>
                </td>
                <td><?= $d['USES_COUNT'] ?><?= $d['MAX_USES'] ? " / " . $d['MAX_USES'] : '' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($discounts)): ?>
            <tr>
                <td colspan="6" style="text-align:center;">Нет активных промокодов</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<?
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
?>