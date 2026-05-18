<?php

/**
 * Файл: /local/modules/avs_booking/lib/NotificationService.php
 */

class AVSNotificationService
{
    private $adminEmail;
    private $managerEmail;
    private $b24Webhook;
    private $tgBotToken;
    private $tgManagerChatId;

    public function __construct()
    {
        $this->adminEmail = \Bitrix\Main\Config\Option::get('avs_booking', 'admin_email', '');
        $this->managerEmail = \Bitrix\Main\Config\Option::get('avs_booking', 'manager_email', '');
        $this->b24Webhook = \Bitrix\Main\Config\Option::get('avs_booking', 'b24_webhook_url', '');
        $this->tgBotToken = \Bitrix\Main\Config\Option::get('avs_booking', 'tg_bot_token', '');
        $this->tgManagerChatId = \Bitrix\Main\Config\Option::get('avs_booking', 'tg_manager_chat_id', '');
    }

    public function sendNewOrderNotification($order)
    {
        $message = "🆕 НОВОЕ БРОНИРОВАНИЕ\n\n";
        $message .= "Номер: {$order['ORDER_NUMBER']}\n";
        $message .= "Беседка: {$order['PAVILION_NAME']}\n";
        $message .= "Клиент: {$order['CLIENT_NAME']}\n";
        $message .= "Телефон: {$order['CLIENT_PHONE']}\n";
        $message .= "Начало: {$order['START_TIME']}\n";
        $message .= "Окончание: {$order['END_TIME']}\n";
        $message .= "Сумма: {$order['PRICE']} руб.\n";
        $message .= "Аванс: {$order['DEPOSIT_AMOUNT']} руб.\n";
        $message .= "Тип: {$order['RENTAL_TYPE']}\n";

        if ($this->managerEmail) {
            mail($this->managerEmail, 'Новое бронирование #' . $order['ORDER_NUMBER'], $message, 'Content-Type: text/plain; charset=utf-8');
        }

        if ($this->adminEmail && $this->adminEmail !== $this->managerEmail) {
            mail($this->adminEmail, 'Новое бронирование #' . $order['ORDER_NUMBER'], $message, 'Content-Type: text/plain; charset=utf-8');
        }

        $this->sendToBitrix24($order);
        $this->sendToTelegram($message);
    }

    public function sendClientConfirmation($order)
    {
        $message = "✅ Ваше бронирование подтверждено!\n\n";
        $message .= "Номер: {$order['ORDER_NUMBER']}\n";
        $message .= "Беседка: {$order['PAVILION_NAME']}\n";
        $message .= "Дата: " . date('d.m.Y', strtotime($order['START_TIME'])) . "\n";
        $message .= "Время: " . date('H:i', strtotime($order['START_TIME'])) . " - " . date('H:i', strtotime($order['END_TIME'])) . "\n";
        $message .= "Сумма: {$order['PRICE']} руб.\n";
        $message .= "Аванс: {$order['DEPOSIT_AMOUNT']} руб.\n\n";
        $message .= "Ссылка для оплаты: https://" . $_SERVER['HTTP_HOST'] . "/payment/?order_id={$order['ID']}\n";

        if ($order['CLIENT_EMAIL']) {
            \CEvent::Send('AVS_BOOKING_CONFIRMATION', 's1', [
                'ORDER_NUMBER' => $order['ORDER_NUMBER'],
                'CLIENT_NAME' => $order['CLIENT_NAME'],
                'PAVILION_NAME' => $order['PAVILION_NAME'],
                'START_TIME' => $order['START_TIME'],
                'END_TIME' => $order['END_TIME'],
                'PRICE' => $order['PRICE']
            ], 'Y', '', [$order['CLIENT_EMAIL']]);
        }
    }

    public function sendPaymentSuccessNotification($order)
    {
        $message = "💳 Оплата получена!\n\n";
        $message .= "Номер бронирования: {$order['ORDER_NUMBER']}\n";
        $message .= "Беседка: {$order['PAVILION_NAME']}\n";
        $message .= "Сумма: {$order['PAID_AMOUNT']} руб.\n\n";
        $message .= "Спасибо за бронирование! Ждем вас!\n";

        if ($order['CLIENT_EMAIL']) {
            \CEvent::Send('AVS_BOOKING_PAYMENT_SUCCESS', 's1', [
                'ORDER_NUMBER' => $order['ORDER_NUMBER'],
                'CLIENT_NAME' => $order['CLIENT_NAME'],
                'AMOUNT' => $order['PAID_AMOUNT']
            ], 'Y', '', [$order['CLIENT_EMAIL']]);
        }
    }

    public function sendConfirmationNotification($order)
    {
        $message = "📋 Бронирование подтверждено менеджером!\n\n";
        $message .= "Номер: {$order['ORDER_NUMBER']}\n";
        $message .= "Беседка: {$order['PAVILION_NAME']}\n";
        $message .= "Дата: " . date('d.m.Y', strtotime($order['START_TIME'])) . "\n";
        $message .= "Время: " . date('H:i', strtotime($order['START_TIME'])) . " - " . date('H:i', strtotime($order['END_TIME'])) . "\n";

        if ($order['CLIENT_EMAIL']) {
            \CEvent::Send('AVS_BOOKING_CONFIRMATION', 's1', [
                'ORDER_NUMBER' => $order['ORDER_NUMBER'],
                'CLIENT_NAME' => $order['CLIENT_NAME'],
                'PAVILION_NAME' => $order['PAVILION_NAME'],
                'START_TIME' => $order['START_TIME'],
                'END_TIME' => $order['END_TIME'],
                'PRICE' => $order['PRICE']
            ], 'Y', '', [$order['CLIENT_EMAIL']]);
        }
    }

    private function sendToBitrix24($order)
    {
        if (!$this->b24Webhook) return;

        $leadData = [
            'TITLE' => 'Бронирование беседки ' . $order['PAVILION_NAME'],
            'NAME' => $order['CLIENT_NAME'],
            'PHONE' => [['VALUE' => $order['CLIENT_PHONE'], 'VALUE_TYPE' => 'WORK']],
            'COMMENTS' => "Бронирование #{$order['ORDER_NUMBER']}\nНачало: {$order['START_TIME']}\nОкончание: {$order['END_TIME']}\nСумма: {$order['PRICE']} руб.",
            'SOURCE_ID' => 'WEB'
        ];

        $ch = curl_init($this->b24Webhook . '/crm.lead.add.json');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['fields' => $leadData]));
        curl_exec($ch);
        curl_close($ch);
    }

    private function sendToTelegram($message)
    {
        if ($this->tgBotToken && $this->tgManagerChatId) {
            $url = "https://api.telegram.org/bot{$this->tgBotToken}/sendMessage";
            $data = [
                'chat_id' => $this->tgManagerChatId,
                'text' => $message,
                'parse_mode' => 'HTML'
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_exec($ch);
            curl_close($ch);
        }
    }
}
