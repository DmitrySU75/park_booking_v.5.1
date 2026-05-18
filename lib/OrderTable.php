<?php

/**
 * Файл: /local/modules/avs_booking/lib/OrderTable.php
 */

namespace AVS\Booking;

use Bitrix\Main\Entity;
use Bitrix\Main\Type;

class OrderTable extends Entity\DataManager
{
    public static function getTableName()
    {
        return 'avs_booking_orders';
    }

    public static function getMap()
    {
        return [
            new Entity\IntegerField('ID', [
                'primary' => true,
                'autocomplete' => true
            ]),
            new Entity\StringField('ORDER_NUMBER', [
                'required' => true,
                'unique' => true
            ]),
            new Entity\IntegerField('PAVILION_ID', [
                'required' => true
            ]),
            new Entity\StringField('PAVILION_NAME'),
            new Entity\StringField('LEGAL_ENTITY', [
                'required' => true
            ]),
            new Entity\StringField('CLIENT_NAME', [
                'required' => true
            ]),
            new Entity\StringField('CLIENT_PHONE', [
                'required' => true
            ]),
            new Entity\StringField('CLIENT_EMAIL'),
            new Entity\StringField('CLIENT_TG_ID'),
            new Entity\DatetimeField('START_TIME', [
                'required' => true
            ]),
            new Entity\DatetimeField('END_TIME', [
                'required' => true
            ]),
            new Entity\DatetimeField('NEW_START_TIME'),
            new Entity\DatetimeField('NEW_END_TIME'),
            new Entity\IntegerField('NEW_PAVILION_ID'),
            new Entity\StringField('NEW_PAVILION_NAME'),
            new Entity\DatetimeField('EXTENDED_END_TIME'),
            new Entity\FloatField('PRICE', [
                'required' => true
            ]),
            new Entity\FloatField('DEPOSIT_AMOUNT', [
                'required' => true
            ]),
            new Entity\FloatField('DISCOUNT_AMOUNT'),
            new Entity\StringField('DISCOUNT_CODE'),
            new Entity\StringField('STATUS', [
                'required' => true,
                'default_value' => 'pending'
            ]),
            new Entity\StringField('PAYMENT_ID'),
            new Entity\StringField('PAYMENT_STATUS', [
                'default_value' => 'pending'
            ]),
            new Entity\FloatField('PAID_AMOUNT', [
                'default_value' => 0
            ]),
            new Entity\StringField('LIBREBOOKING_RESERVATION_ID'),
            new Entity\StringField('RENTAL_TYPE'),
            new Entity\IntegerField('DURATION_HOURS'),
            new Entity\TextField('COMMENT'),
            new Entity\DatetimeField('CREATED_AT', [
                'default_value' => new Type\DateTime()
            ]),
            new Entity\DatetimeField('UPDATED_AT', [
                'default_value' => new Type\DateTime()
            ]),
            new Entity\DatetimeField('DELETED_AT'),
            new Entity\IntegerField('DELETED_BY')
        ];
    }
}
