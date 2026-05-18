<?php

/**
 * Файл: /local/modules/avs_booking/admin/menu.php
 */

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

$module_id = 'avs_booking';

if ($APPLICATION->GetGroupRight($module_id) >= 'R') {
    $aMenu = [
        'parent_menu' => 'global_menu_services',
        'sort' => 100,
        'text' => 'AVS Booking',
        'title' => 'Система бронирования беседок',
        'icon' => 'sys_menu_icon',
        'items_id' => 'menu_avs_booking',
        'items' => [
            [
                'text' => 'Дашборд',
                'url' => 'avs_booking_dashboard.php?lang=' . LANGUAGE_ID,
                'title' => 'Статистика и обзор',
                'items_id' => 'avs_booking_dashboard',
            ],
            [
                'text' => 'Бронирования',
                'url' => 'avs_booking_orders.php?lang=' . LANGUAGE_ID,
                'title' => 'Управление бронированиями',
                'items_id' => 'avs_booking_orders',
            ],
            [
                'text' => 'Особые даты',
                'url' => 'avs_booking_special_dates.php?lang=' . LANGUAGE_ID,
                'title' => 'Ограничения по датам',
                'items_id' => 'avs_booking_special_dates',
            ],
            [
                'text' => 'Скидки и промокоды',
                'url' => 'avs_booking_discounts.php?lang=' . LANGUAGE_ID,
                'title' => 'Управление скидками',
                'items_id' => 'avs_booking_discounts',
            ],
            [
                'text' => 'Настройки',
                'url' => '/bitrix/admin/settings.php?mid=avs_booking&lang=' . LANGUAGE_ID,
                'title' => 'Настройки модуля',
                'items_id' => 'avs_booking_settings',
            ]
        ]
    ];

    return $aMenu;
}

return false;
