<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

$arComponentDescription = [
    'NAME' => 'Форма бронирования беседки',
    'DESCRIPTION' => 'Форма бронирования беседки с проверкой доступности',
    'ICON' => '/images/icon.gif',
    'SORT' => 10,
    'CACHE_PATH' => 'Y',
    'PATH' => [
        'ID' => 'avs_booking',
        'NAME' => 'AVS Booking'
    ],
];
