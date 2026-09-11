<?php

/**
 * Файл: admin/avs_booking_special_dates.php
 * Управление особыми датами
 */

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

use Bitrix\Main\Loader;

$module_id = 'avs_booking';
if ($APPLICATION->GetGroupRight($module_id) < 'W') {
    $APPLICATION->AuthForm('Доступ запрещен');
}
Loader::includeModule($module_id);
$APPLICATION->SetTitle('Управление особыми датами');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    if ($_POST['action'] === 'save') {
        $allowedTypes = isset($_POST['allowed_types']) ? implode(',', $_POST['allowed_types']) : '';
        $priceModifier = !empty($_POST['price_modifier']) ? floatval($_POST['price_modifier']) : null;
        $sql = "INSERT INTO avs_booking_special_dates 
                (PAVILION_ID, DATE, RESTRICTION_TYPE, ALLOWED_TYPES, PRICE_MODIFIER, DESCRIPTION, CREATED_AT, UPDATED_AT)
                VALUES (" . intval($_POST['pavilion_id']) . ", '" . $DB->ForSql($_POST['date']) . "', 
                '" . $DB->ForSql($_POST['restriction_type']) . "', '" . $DB->ForSql($allowedTypes) . "', 
                " . ($priceModifier !== null ? $priceModifier : 'NULL') . ", 
                '" . $DB->ForSql($_POST['description']) . "', NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    RESTRICTION_TYPE = '" . $DB->ForSql($_POST['restriction_type']) . "',
                    ALLOWED_TYPES = '" . $DB->ForSql($allowedTypes) . "',
                    PRICE_MODIFIER = " . ($priceModifier !== null ? $priceModifier : 'NULL') . ",
                    DESCRIPTION = '" . $DB->ForSql($_POST['description']) . "',
                    UPDATED_AT = NOW()";
        $DB->Query($sql);
        CAdminMessage::ShowMessage(['MESSAGE' => 'Ограничение сохранено', 'TYPE' => 'OK']);
    }
    if ($_POST['action'] === 'delete') {
        $DB->Query("DELETE FROM avs_booking_special_dates WHERE ID = " . intval($_POST['id']));
        CAdminMessage::ShowMessage(['MESSAGE' => 'Ограничение удалено', 'TYPE' => 'OK']);
    }
}

$restrictions = [];
$sql = "SELECT * FROM avs_booking_special_dates ORDER BY DATE ASC";
$result = $DB->Query($sql);
while ($row = $result->Fetch()) $restrictions[] = $row;

$gazebos = [];
if (Loader::includeModule('iblock')) {
    $res = CIBlockElement::GetList(['NAME' => 'ASC'], ['IBLOCK_ID' => 12, 'ACTIVE' => 'Y'], false, false, ['ID', 'NAME']);
    while ($el = $res->Fetch()) $gazebos[$el['ID']] = $el['NAME'];
}

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

    .special-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }

    .special-table th,
    .special-table td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: left;
    }

    .special-table th {
        background: #f2f2f2;
    }
</style>

<h1>Управление особыми датами</h1>

<h2>Добавить ограничение</h2>
<form method="post">
    <?= bitrix_sessid_post() ?><input type="hidden" name="action" value="save">
    <div class="form-row"><label>Беседка:</label><select name="pavilion_id" required>
            <option value="">Выберите беседку</option><?php foreach ($gazebos as $id => $name): ?><option value="<?= $id ?>"><?= htmlspecialcharsbx($name) ?> (ID: <?= $id ?>)</option><?php endforeach; ?>
        </select></div>
    <div class="form-row"><label>Дата:</label><input type="date" name="date" required></div>
    <div class="form-row"><label>Тип ограничения:</label><select name="restriction_type" id="restriction_type" required>
            <option value="full_day_only">Только полный день</option>
            <option value="no_hourly">Без почасовой</option>
            <option value="custom">Свои типы</option>
        </select></div>
    <div class="form-row" id="allowed_types_row" style="display:none;"><label>Разрешенные типы:</label><select name="allowed_types[]" multiple size="3">
            <option value="hourly">Почасовая</option>
            <option value="full_day">Полный день</option>
            <option value="night">Ночная</option>
        </select><small>Ctrl+click для множественного выбора</small></div>
    <div class="form-row"><label>Модификатор цены:</label><input type="number" name="price_modifier" step="0.1" placeholder="1.5"><small>Оставьте пустым для стандартной цены</small></div>
    <div class="form-row"><label>Описание:</label><input type="text" name="description" size="50" placeholder="Например: Праздничный день"></div>
    <div class="form-row"><input type="submit" value="Сохранить" class="adm-btn-save"></div>
</form>

<h2>Существующие ограничения</h2>
<table class="special-table">
    <thead>
        <tr>
            <th>ID</th>
            <th>Беседка</th>
            <th>Дата</th>
            <th>Тип</th>
            <th>Разрешенные типы</th>
            <th>Мод. цены</th>
            <th>Описание</th>
            <th>Действия</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($restrictions as $r): ?>
            <tr>
                <td><?= $r['ID'] ?></td>
                <td><?= htmlspecialcharsbx($gazebos[$r['PAVILION_ID']] ?? $r['PAVILION_ID']) ?> (ID: <?= $r['PAVILION_ID'] ?>)</td>
                <td><?= htmlspecialcharsbx($r['DATE']) ?></td>
                <td><?= htmlspecialcharsbx($r['RESTRICTION_TYPE']) ?></td>
                <td><?= htmlspecialcharsbx($r['ALLOWED_TYPES']) ?></td>
                <td><?= $r['PRICE_MODIFIER'] ?: '-' ?></td>
                <td><?= htmlspecialcharsbx($r['DESCRIPTION']) ?></td>
                <td>
                    <form method="post" onsubmit="return confirm('Удалить?')"><?= bitrix_sessid_post() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['ID'] ?>"><input type="submit" value="Удалить" class="adm-btn"></form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($restrictions)): ?><tr>
                <td colspan="8" style="text-align:center;">Нет ограничений</td>
            </tr><?php endif; ?>
    </tbody>
</table>

<script>
    document.getElementById('restriction_type').addEventListener('change', function() {
        document.getElementById('allowed_types_row').style.display = this.value === 'custom' ? 'block' : 'none';
    });
</script>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'); ?>