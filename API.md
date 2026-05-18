# API документация модуля AVS Booking

## Базовый URL

https://ваш-домен.ru/local/modules/avs_booking/api.php

## Аутентификация

Для всех запросов требуется передавать API-ключ в заголовке:

```bash
X-API-Key: ваш_секретный_ключ
```

API-ключ настраивается в модуле: **Настройки** → **Настройки модулей** → **AVS Booking** → **API ключ для внешних запросов**

---

## Эндпоинты

### 1. Создание заказа (create_order)

**Метод:** `POST`  
**URL:** `?action=create_order`

#### Параметры запроса

| Параметр      | Тип    | Обязательный | Описание                               |
| ------------- | ------ | ------------ | -------------------------------------- |
| pavilion_id   | int    | Да           | ID беседки в инфоблоке                 |
| client_name   | string | Да           | Имя клиента                            |
| client_phone  | string | Да           | Телефон клиента                        |
| period_start  | string | Да           | Начало бронирования (ISO 8601)         |
| period_end    | string | Да           | Конец бронирования (ISO 8601)          |
| price         | float  | Да           | Сумма бронирования                     |
| client_email  | string | Нет          | Email клиента                          |
| rental_type   | string | Нет          | Тип аренды (hourly/full_day/night)     |
| status        | string | Нет          | Статус заказа (pending/paid/confirmed) |
| comment       | string | Нет          | Комментарий                            |
| discount_code | string | Нет          | Промокод                               |

#### Пример запроса (cURL)

```bash
curl -X POST "https://park.na4u.ru/local/modules/avs_booking/api.php?action=create_order" \
  -H "X-API-Key: ваш_ключ" \
  -H "Content-Type: application/json" \
  -d '{
    "pavilion_id": 116,
    "client_name": "Иван Петров",
    "client_phone": "+7 900 123-45-67",
    "client_email": "ivan@example.com",
    "period_start": "2026-05-20T10:00:00+05:00",
    "period_end": "2026-05-20T14:00:00+05:00",
    "price": 3800,
    "rental_type": "hourly",
    "comment": "День рождения"
  }
```

#### Пример ответа (успех)

```json
{
  "success": true,
  "data": {
    "order_id": 123,
    "order_number": "ORD-20260520123456-7890",
    "status": "pending",
    "price": 3800,
    "deposit_amount": 2000
  }
}
```

#### Пример ответа (ошибка)

```json
{
  "success": false,
  "error": "Выбранное время недоступно"
}
```

### 2. Обновление статуса заказа (update_status)

Метод: `bash POST или PUT `
URL: `bash ?action=update_status `

#### Параметры запроса

| Параметр     | Тип    | Обязательный | Описание            |
| ------------ | ------ | ------------ | ------------------- |
| order_id     | int    | Да\*         | ID заказа в системе |
| order_number | string | Да\*         | Номер заказа        |
| status       | string | Да           | Новый статус        |

\*Достаточно указать либо order_id, либо order_number

Допустимые статусы: pending, paid, confirmed, cancelled, completed

Пример запроса
bash
curl -X POST "https://park.na4u.ru/local/modules/avs_booking/api.php?action=update_status" \
 -H "X-API-Key: ваш_ключ" \
 -H "Content-Type: application/json" \
 -d '{
"order_number": "ORD-20260520123456-7890",
"status": "confirmed"
}'
Пример ответа
json
{
"success": true,
"data": {
"order_id": 123,
"order_number": "ORD-20260520123456-7890",
"old_status": "pending",
"new_status": "confirmed"
}
} 3. Изменение заказа (update_order)
Метод: POST или PUT
URL: ?action=update_order

Параметры запроса
Параметр Тип Обязательный Описание
order_id int Да* ID заказа
order_number string Да* Номер заказа
new_start_time string Нет Новое время начала
new_end_time string Нет Новое время окончания
new_pavilion_id int Нет Новая беседка
\*Достаточно указать либо order_id, либо order_number

Пример запроса (изменение времени)
bash
curl -X POST "https://park.na4u.ru/local/modules/avs_booking/api.php?action=update_order" \
 -H "X-API-Key: ваш*ключ" \
 -H "Content-Type: application/json" \
 -d '{
"order_number": "ORD-20260520123456-7890",
"new_start_time": "2026-05-21T15:00:00+05:00",
"new_end_time": "2026-05-21T19:00:00+05:00"
}'
Пример запроса (смена беседки)
bash
curl -X POST "https://park.na4u.ru/local/modules/avs_booking/api.php?action=update_order" \
 -H "X-API-Key: ваш*ключ" \
 -H "Content-Type: application/json" \
 -d '{
"order_number": "ORD-20260520123456-7890",
"new_pavilion_id": 117
}'
Пример ответа
json
{
"success": true,
"data": {
"order_id": 123,
"order_number": "ORD-20260520123456-7890",
"changes": {
"time": {
"old_start": "2026-05-20T10:00:00+05:00",
"old_end": "2026-05-20T14:00:00+05:00",
"new_start": "2026-05-21T15:00:00+05:00",
"new_end": "2026-05-21T19:00:00+05:00"
}
},
"status": "pending"
}
} 4. Получение списка заказов (get_orders)
Метод: GET
URL: ?action=get_orders

Параметры запроса
Параметр Тип Обязательный Описание
start*date string Да Начало периода (YYYY-MM-DD)
end_date string Да Конец периода (YYYY-MM-DD)
status string Нет Фильтр по статусу
pavilion_id int Нет Фильтр по беседке
legal_entity string Нет Фильтр по юр. лицу
Пример запроса
bash
curl -X GET "https://park.na4u.ru/local/modules/avs_booking/api.php?action=get_orders&start_date=2026-05-01&end_date=2026-05-31&status=paid" \
 -H "X-API-Key: ваш*ключ"
Пример ответа
json
{
"success": true,
"data": {
"orders": [
{
"id": 123,
"order_number": "ORD-20260520123456-7890",
"pavilion_id": 116,
"pavilion_name": "Беседка №38 Шарташ",
"legal_entity": "beton_systems",
"client_name": "Иван Петров",
"client_phone": "+7 900 123-45-67",
"client_email": "ivan@example.com",
"period_start": "2026-05-20T10:00:00+05:00",
"period_end": "2026-05-20T14:00:00+05:00",
"price": 3800,
"deposit_amount": 2000,
"paid_amount": 2000,
"status": "paid",
"payment_status": "succeeded",
"rental_type": "hourly",
"duration_hours": 4,
"created_at": "2026-05-20T10:30:00+05:00",
"updated_at": "2026-05-20T10:35:00+05:00"
}
],
"total": 1,
"period": {
"start": "2026-05-01",
"end": "2026-05-31"
}
}
} 5. Получение информации об оплате (get_payment_info)
Метод: GET
URL: ?action=get_payment_info

Параметры запроса
Параметр Тип Обязательный Описание
order_id int Да* ID заказа
order_number string Да* Номер заказа
\*Достаточно указать либо order_id, либо order_number

Пример запроса
bash
curl -X GET "https://park.na4u.ru/local/modules/avs_booking/api.php?action=get_payment_info&order_number=ORD-20260520123456-7890" \
 -H "X-API-Key: ваш_ключ"
Пример ответа
json
{
"success": true,
"data": {
"order_id": 123,
"order_number": "ORD-20260520123456-7890",
"pavilion_name": "Беседка №38 Шарташ",
"price": 3800,
"deposit_amount": 2000,
"paid_amount": 2000,
"payment_id": "2def0b6b-000f-5000-9000-1c6a6b86b2a4",
"payment_status": "succeeded",
"legal_entity": "beton_systems",
"status": "paid",
"requires_payment": false
}
} 6. Обновление цен беседок (update_prices)
Метод: POST или PUT
URL: ?action=update_prices

Параметры запроса
Параметр Тип Обязательный Описание
effective*from string Да Дата начала действия цен (YYYY-MM-DD)
prices array Да Массив ценовых изменений
Структура элемента prices
Параметр Тип Обязательный Описание
pavilion_id int Да ID беседки
price_hour float Нет Цена за час
price_day float Нет Цена за день
price_night float Нет Цена за ночь
Пример запроса
bash
curl -X POST "https://park.na4u.ru/local/modules/avs_booking/api.php?action=update_prices" \
 -H "X-API-Key: ваш*ключ" \
 -H "Content-Type: application/json" \
 -d '{
"effective_from": "2026-06-01",
"prices": [
{
"pavilion_id": 116,
"price_hour": 1100,
"price_day": 9900,
"price_night": 4200
},
{
"pavilion_id": 117,
"price_hour": 1200,
"price_day": 10900
}
]
}'
Пример ответа
json
{
"success": true,
"data": {
"effective_from": "2026-06-01",
"updated": [
{
"pavilion_id": 116,
"pavilion_name": "Беседка №38 Шарташ",
"effective_from": "2026-06-01",
"new_prices": {
"hourly_price": 1100,
"full_day_price": 9900,
"night_price": 4200
}
}
],
"updated_count": 2,
"errors": []
}
}
Коды ошибок
HTTP код Описание
200 Успешный запрос
400 Неверные параметры запроса
401 Неверный API-ключ
404 Ресурс не найден
405 Метод не поддерживается
500 Внутренняя ошибка сервера
Форматы данных
Формат даты и времени
text
2026-05-20T10:00:00+05:00
2026-05-20 — дата

T — разделитель

10:00:00 — время

+05:00 — часовой пояс (Екатеринбург)

Статусы заказов
Статус Описание
pending Ожидает оплаты
paid Оплачен
confirmed Подтвержден менеджером
active Активен
completed Завершен
cancelled Отменен
deleted Удален (мягкое удаление)
Типы аренды
Тип Описание
hourly Почасовая аренда
full_day Полный день (10:00 до окончания работы)
night Ночь (01:00-09:00)
Юридические лица
Код Название
beton_systems ООО "Бетонные Системы"
park_victory СК "Парк победы" ООО
Логирование
Все запросы к API логируются в файл:

text
/upload/api_1c_debug.log
Формат лога:

text
2026-05-20 10:30:00 - [INFO] Create order: ORD-20260520123456-7890
2026-05-20 10:35:00 - [ERROR] Authentication failed: Invalid API key
