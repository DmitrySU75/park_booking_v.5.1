<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
$orderId = isset($_REQUEST['order_id']) ? (int)$_REQUEST['order_id'] : 0;
$orderNumber = '';
if ($orderId > 0 && \Bitrix\Main\Loader::includeModule('avs_booking')) {
    $order = \AVS\Booking\Order::get($orderId);
    if ($order) $orderNumber = htmlspecialchars($order['ORDER_NUMBER'], ENT_QUOTES, 'UTF-8');
}
$successMessage = '';
if (isset($_SESSION['booking_success_message'])) {
    $successMessage = htmlspecialchars($_SESSION['booking_success_message'], ENT_QUOTES, 'UTF-8');
    unset($_SESSION['booking_success_message']);
}
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Бронирование создано</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            padding: 50px
        }

        .success {
            color: green;
            font-size: 24px;
            margin-bottom: 20px
        }

        .message {
            font-size: 18px;
            margin-bottom: 30px
        }

        .button {
            background: #2e7d32;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px
        }
    </style>
</head>

<body>
    <div class="success">✅ Бронирование успешно создано!</div>
    <div class="message"><?php if ($orderNumber): ?>Номер вашего бронирования: <strong><?= $orderNumber ?></strong><br><?php endif; ?><?php if ($successMessage): ?><?= $successMessage ?><br><?php endif; ?>Подтверждение отправлено на вашу электронную почту.</div><a href="/" class="button">Вернуться на главную</a>
</body>

</html><?php require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog.php'; ?>