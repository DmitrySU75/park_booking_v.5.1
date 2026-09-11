<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

$arComponentParameters = [
    'GROUPS' => [],
    'PARAMETERS' => [
        'ELEMENT_ID' => [
            'NAME' => 'ID беседки',
            'TYPE' => 'STRING',
            'DEFAULT' => '',
            'PARENT' => 'BASE',
        ],
        'SUCCESS_PAGE' => [
            'NAME' => 'Страница успеха',
            'TYPE' => 'STRING',
            'DEFAULT' => '/booking-success/',
            'PARENT' => 'BASE',
        ],
        'CACHE_TIME' => [
            'DEFAULT' => 3600,
        ],
    ],
];
