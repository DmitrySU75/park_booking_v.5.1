<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;

$module_id = 'avs_booking';

if ($APPLICATION->GetGroupRight($module_id) < 'W') {
    $APPLICATION->AuthForm('Доступ запрещен');
}

Loader::includeModule($module_id);

$arAllOptions = [
    ['api_url', 'URL API LibreBooking', '', ['text', 100]],
    ['api_username', 'Логин API LibreBooking', '', ['text', 50]],
    ['api_password', 'Пароль API LibreBooking', '', ['password', 50]],
    ['api_key', 'API ключ для внешних запросов', '', ['text', 50]],
    ['api_allowed_ips', 'Разрешенные IP для API (через запятую)', '', ['text', 255]],
    ['beton_systems_shop_id', 'Shop ID (ЮKassa) - Бетонные Системы', '', ['text', 50]],
    ['beton_systems_secret_key', 'Secret key (ЮKassa) - Бетонные Системы', '', ['password', 50]],
    ['park_victory_shop_id', 'Shop ID (ЮKassa) - Парк Победы', '', ['text', 50]],
    ['park_victory_secret_key', 'Secret key (ЮKassa) - Парк Победы', '', ['password', 50]],
    ['b24_webhook_url', 'Webhook Битрикс24', '', ['text', 255]],
    ['admin_email', 'Email администратора', '', ['text', 100]],
    ['manager_email', 'Email менеджера', '', ['text', 100]],
    ['tg_bot_token', 'Telegram Bot Token', '', ['text', 100]],
    ['tg_manager_chat_id', 'Telegram Chat ID менеджера', '', ['text', 50]],
    ['summer_period_start', 'Начало летнего периода (дд.мм)', '01.06', ['text', 10]],
    ['summer_period_end', 'Конец летнего периода (дд.мм)', '31.08', ['text', 10]],
    ['summer_end_hour', 'Час окончания работы летом', '23', ['text', 5]],
    ['winter_end_hour', 'Час окончания работы зимой', '22', ['text', 5]],
    ['default_deposit', 'Сумма аванса по умолчанию (руб)', '2000', ['text', 10]],
    ['high_deposit_pavilions', 'ID беседок с повышенным авансом (через запятую)', '5,6', ['text', 255]],
    ['high_deposit_amount', 'Сумма повышенного аванса (руб)', '5000', ['text', 10]],
    ['min_hours', 'Минимальное количество часов аренды', '4', ['text', 5]],
    ['weekend_restriction', 'Ограничение в выходные дни', 'no', ['selectbox', ['no' => 'Нет', 'full_day_only' => 'Только полный день']]],
    ['weekend_price_modifier', 'Модификатор цены в выходные', '1.2', ['text', 10]],
    ['holiday_dates', 'Праздничные даты (YYYY-MM-DD, через запятую)', '', ['textarea', 5]],
    ['price_periods_iblock_id', 'ID инфоблока ценовых периодов', '0', ['text', 10]]
];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && check_bitrix_sessid()) {
    foreach ($arAllOptions as $option) {
        $name = $option[0];
        $value = $_REQUEST[$name] ?? '';
        Option::set($module_id, $name, $value);
    }
    echo '<div class="adm-info-message" style="text-align:center">Настройки сохранены</div>';
}

$tabControl = new CAdminTabControl('tabControl', [
    ['DIV' => 'edit1', 'TAB' => 'Основные настройки', 'TITLE' => 'Основные настройки модуля'],
    ['DIV' => 'edit2', 'TAB' => 'API и интеграции', 'TITLE' => 'Настройки API и интеграций'],
    ['DIV' => 'edit3', 'TAB' => 'Уведомления', 'TITLE' => 'Настройки уведомлений'],
    ['DIV' => 'edit4', 'TAB' => 'Тарифы и цены', 'TITLE' => 'Настройки тарифов']
]);

$APPLICATION->SetTitle('Настройки модуля AVS Booking');

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
?>

<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= htmlspecialcharsbx($module_id) ?>&lang=<?= LANGUAGE_ID ?>">
    <?= bitrix_sessid_post() ?>
    <?
    $tabControl->Begin();

    $tabControl->BeginNextTab();
    $basicOptions = array_slice($arAllOptions, 0, 5);
    foreach ($basicOptions as $Option):
        $name = $Option[0];
        $val = Option::get($module_id, $name);
        $type = $Option[3];
    ?>
        <tr>
            <td width="40%" class="adm-detail-content-cell-l"><?= $Option[1] ?>:</td>
            <td width="60%" class="adm-detail-content-cell-r">
                <input type="<?= $type[0] ?>" name="<?= $name ?>" value="<?= htmlspecialcharsbx($val) ?>" size="<?= $type[1] ?>">
            </td>
        </tr>
    <? endforeach; ?>

    <?
    $tabControl->BeginNextTab();
    $apiOptions = array_slice($arAllOptions, 5, 4);
    foreach ($apiOptions as $Option):
        $name = $Option[0];
        $val = Option::get($module_id, $name);
        $type = $Option[3];
    ?>
        <tr>
            <td width="40%" class="adm-detail-content-cell-l"><?= $Option[1] ?>:</td>
            <td width="60%" class="adm-detail-content-cell-r">
                <input type="<?= $type[0] ?>" name="<?= $name ?>" value="<?= htmlspecialcharsbx($val) ?>" size="<?= $type[1] ?>">
            </td>
        </tr>
    <? endforeach; ?>

    <?
    $tabControl->BeginNextTab();
    $notifyOptions = array_slice($arAllOptions, 9, 5);
    foreach ($notifyOptions as $Option):
        $name = $Option[0];
        $val = Option::get($module_id, $name);
        $type = $Option[3];
    ?>
        <tr>
            <td width="40%" class="adm-detail-content-cell-l"><?= $Option[1] ?>:</td>
            <td width="60%" class="adm-detail-content-cell-r">
                <? if ($type[0] == 'textarea'): ?>
                    <textarea name="<?= $name ?>" rows="3" cols="40"><?= htmlspecialcharsbx($val) ?></textarea>
                <? else: ?>
                    <input type="<?= $type[0] ?>" name="<?= $name ?>" value="<?= htmlspecialcharsbx($val) ?>" size="<?= $type[1] ?>">
                <? endif; ?>
            </td>
        </tr>
    <? endforeach; ?>

    <?
    $tabControl->BeginNextTab();
    $tariffOptions = array_slice($arAllOptions, 14);
    foreach ($tariffOptions as $Option):
        $name = $Option[0];
        $val = Option::get($module_id, $name);
        $type = $Option[3];
    ?>
        <tr>
            <td width="40%" class="adm-detail-content-cell-l"><?= $Option[1] ?>:</td>
            <td width="60%" class="adm-detail-content-cell-r">
                <? if ($type[0] == 'selectbox'): ?>
                    <select name="<?= $name ?>">
                        <? foreach ($type[1] as $key => $label): ?>
                            <option value="<?= $key ?>" <?= $val == $key ? 'selected' : '' ?>><?= $label ?></option>
                        <? endforeach; ?>
                    </select>
                <? elseif ($type[0] == 'textarea'): ?>
                    <textarea name="<?= $name ?>" rows="3" cols="50"><?= htmlspecialcharsbx($val) ?></textarea>
                <? else: ?>
                    <input type="<?= $type[0] ?>" name="<?= $name ?>" value="<?= htmlspecialcharsbx($val) ?>" size="<?= $type[1] ?>">
                <? endif; ?>
            </td>
        </tr>
    <? endforeach; ?>

    <?
    $tabControl->Buttons();
    ?>
    <input type="submit" name="save" value="Сохранить" class="adm-btn-save">
    <?
    $tabControl->End();
    ?>
</form>

<?
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
?>