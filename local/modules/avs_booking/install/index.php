<?php

/**
 * Файл: /local/modules/avs_booking/install/index.php
 * Установщик модуля
 */

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\EventManager;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Option;

Loc::loadMessages(__FILE__);

class avs_booking extends CModule
{
    public $MODULE_ID = 'avs_booking';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'AVS Booking System';
    public $MODULE_DESCRIPTION = 'Модуль бронирования беседок с поддержкой тарифов, скидок и уведомлений';
    public $PARTNER_NAME = 'AVS Group';
    public $PARTNER_URI = 'https://avsgroup.ru';

    public function __construct()
    {
        $arModuleVersion = [];
        include(__DIR__ . '/version.php');
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
    }

    public function DoInstall()
    {
        global $DB, $APPLICATION;

        if (!CheckVersion(ModuleManager::getVersion('main'), '20.0.0')) {
            $APPLICATION->ThrowException('Требуется версия главного модуля не ниже 20.0.0');
            return false;
        }

        $this->InstallDB();
        $this->InstallEvents();
        $this->InstallFiles();
        $this->InstallOptions();

        ModuleManager::registerModule($this->MODULE_ID);

        return true;
    }

    public function DoUninstall()
    {
        global $DB, $APPLICATION;

        $context = \Bitrix\Main\Application::getInstance()->getContext();
        $request = $context->getRequest();

        if ($request['savedata'] != 'Y') {
            $this->UnInstallDB();
        }

        $this->UnInstallEvents();
        $this->UnInstallFiles();
        $this->UnInstallOptions();

        ModuleManager::unRegisterModule($this->MODULE_ID);

        return true;
    }

    public function InstallDB()
    {
        global $DB;

        $DB->Query("SET NAMES 'utf8'");
        $DB->Query("SET CHARACTER SET utf8");

        $errors = $DB->RunSQLBatch(__DIR__ . '/db/install.sql');
        if (!empty($errors)) {
            return $errors;
        }

        $eventTypes = [
            'AVS_BOOKING_NEW_ORDER' => [
                'NAME' => 'Новое бронирование',
                'DESCRIPTION' => '#ORDER_NUMBER# - Номер заказа<br>#CLIENT_NAME# - Имя клиента<br>#CLIENT_PHONE# - Телефон<br>#PAVILION_NAME# - Беседка<br>#START_TIME# - Начало<br>#END_TIME# - Конец<br>#PRICE# - Сумма'
            ],
            'AVS_BOOKING_PAYMENT_SUCCESS' => [
                'NAME' => 'Успешная оплата',
                'DESCRIPTION' => '#ORDER_NUMBER# - Номер заказа<br>#CLIENT_NAME# - Имя клиента<br>#AMOUNT# - Сумма'
            ],
            'AVS_BOOKING_REMINDER' => [
                'NAME' => 'Напоминание о бронировании',
                'DESCRIPTION' => '#ORDER_NUMBER# - Номер заказа<br>#START_TIME# - Время начала<br>#PAVILION_NAME# - Беседка'
            ],
            'AVS_BOOKING_CONFIRMATION' => [
                'NAME' => 'Подтверждение бронирования',
                'DESCRIPTION' => '#ORDER_NUMBER# - Номер заказа<br>#CLIENT_NAME# - Имя клиента<br>#PAVILION_NAME# - Беседка<br>#START_TIME# - Начало<br>#END_TIME# - Конец<br>#PRICE# - Сумма'
            ]
        ];

        foreach ($eventTypes as $eventName => $data) {
            \CEventType::Add([
                'LID' => 'ru',
                'EVENT_NAME' => $eventName,
                'NAME' => $data['NAME'],
                'DESCRIPTION' => $data['DESCRIPTION']
            ]);
        }

        return true;
    }

    public function UnInstallDB()
    {
        global $DB;
        $errors = $DB->RunSQLBatch(__DIR__ . '/db/uninstall.sql');
        if (!empty($errors)) {
            return $errors;
        }

        $eventTypes = [
            'AVS_BOOKING_NEW_ORDER',
            'AVS_BOOKING_PAYMENT_SUCCESS',
            'AVS_BOOKING_REMINDER',
            'AVS_BOOKING_CONFIRMATION'
        ];

        foreach ($eventTypes as $eventName) {
            \CEventType::Delete($eventName);
        }

        return true;
    }

    public function InstallEvents()
    {
        $eventManager = EventManager::getInstance();
        $eventManager->registerEventHandler('avs_booking', 'OnAfterOrderCreate', 'avs_booking', 'AVSBookingEvents', 'onOrderCreate');
        $eventManager->registerEventHandler('avs_booking', 'OnAfterOrderUpdate', 'avs_booking', 'AVSBookingEvents', 'onOrderUpdate');
        return true;
    }

    public function UnInstallEvents()
    {
        $eventManager = EventManager::getInstance();
        $eventManager->unRegisterEventHandler('avs_booking', 'OnAfterOrderCreate', 'avs_booking', 'AVSBookingEvents', 'onOrderCreate');
        $eventManager->unRegisterEventHandler('avs_booking', 'OnAfterOrderUpdate', 'avs_booking', 'AVSBookingEvents', 'onOrderUpdate');
        return true;
    }

    public function InstallFiles()
    {
        CopyDirFiles(__DIR__ . '/../admin', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin', true, true);
        CopyDirFiles(__DIR__ . '/../bitrix/components', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/components', true, true);
        return true;
    }

    public function UnInstallFiles()
    {
        DeleteDirFiles($_SERVER['DOCUMENT_ROOT'] . '/local/modules/avs_booking/admin', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin');
        return true;
    }

    public function InstallOptions()
    {
        Option::set($this->MODULE_ID, 'api_url', '');
        Option::set($this->MODULE_ID, 'api_username', '');
        Option::set($this->MODULE_ID, 'api_password', '');
        Option::set($this->MODULE_ID, 'api_key', '');
        Option::set($this->MODULE_ID, 'api_allowed_ips', '');
        Option::set($this->MODULE_ID, 'beton_systems_shop_id', '');
        Option::set($this->MODULE_ID, 'beton_systems_secret_key', '');
        Option::set($this->MODULE_ID, 'park_victory_shop_id', '');
        Option::set($this->MODULE_ID, 'park_victory_secret_key', '');
        Option::set($this->MODULE_ID, 'b24_webhook_url', '');
        Option::set($this->MODULE_ID, 'admin_email', '');
        Option::set($this->MODULE_ID, 'manager_email', '');
        Option::set($this->MODULE_ID, 'tg_bot_token', '');
        Option::set($this->MODULE_ID, 'tg_manager_chat_id', '');
        Option::set($this->MODULE_ID, 'summer_period_start', date('Y') . '-06-01');
        Option::set($this->MODULE_ID, 'summer_period_end', date('Y') . '-08-31');
        Option::set($this->MODULE_ID, 'summer_end_hour', '23');
        Option::set($this->MODULE_ID, 'winter_end_hour', '22');
        Option::set($this->MODULE_ID, 'default_deposit', '2000');
        Option::set($this->MODULE_ID, 'high_deposit_pavilions', '5,6');
        Option::set($this->MODULE_ID, 'high_deposit_amount', '5000');
        Option::set($this->MODULE_ID, 'min_hours', '4');
        Option::set($this->MODULE_ID, 'weekend_restriction', 'no');
        Option::set($this->MODULE_ID, 'weekend_price_modifier', '1.2');
        Option::set($this->MODULE_ID, 'holiday_dates', '');
        Option::set($this->MODULE_ID, 'price_periods_iblock_id', '0');
        return true;
    }

    public function UnInstallOptions()
    {
        Option::delete($this->MODULE_ID);
        return true;
    }
}

class AVSBookingEvents
{
    public static function onOrderCreate($orderId, $data)
    {
        if (\Bitrix\Main\Loader::includeModule('avs_booking')) {
            $notification = new AVSNotificationService();
            $order = \AVS\Booking\Order::get($orderId);
            if ($order) {
                $notification->sendNewOrderNotification($order);
                $notification->sendClientConfirmation($order);
            }
        }
    }

    public static function onOrderUpdate($orderId, $data)
    {
        if (\Bitrix\Main\Loader::includeModule('avs_booking')) {
            if (isset($data['status']) && $data['status'] == 'paid') {
                $notification = new AVSNotificationService();
                $order = \AVS\Booking\Order::get($orderId);
                if ($order) {
                    $notification->sendPaymentSuccessNotification($order);
                }
            }
        }
    }
}
