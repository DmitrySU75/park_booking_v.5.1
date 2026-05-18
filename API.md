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
