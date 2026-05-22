<?php

/**
 * Файл: /local/modules/avs_booking/lib/DiscountManager.php
 */

class AVSBookingDiscountManager
{
    public static function applyDiscount($code, $amount)
    {
        global $DB;

        $code = strtoupper(trim($code));
        $now = date('Y-m-d H:i:s');

        $sql = "SELECT * FROM avs_booking_discounts 
                WHERE CODE = '" . $DB->ForSql($code) . "' 
                AND ACTIVE = 'Y'
                AND (VALID_FROM IS NULL OR VALID_FROM <= '" . $now . "')
                AND (VALID_TO IS NULL OR VALID_TO >= '" . $now . "')
                AND (MAX_USES IS NULL OR USES_COUNT < MAX_USES)
                AND (MIN_ORDER_AMOUNT IS NULL OR MIN_ORDER_AMOUNT <= " . floatval($amount) . ")";

        $result = $DB->Query($sql);

        if ($discount = $result->Fetch()) {
            $discountAmount = 0;

            if ($discount['DISCOUNT_TYPE'] == 'percent') {
                $discountAmount = $amount * ($discount['DISCOUNT_VALUE'] / 100);
            } else {
                $discountAmount = $discount['DISCOUNT_VALUE'];
            }

            $discountAmount = min($discountAmount, $amount);

            $DB->Query("UPDATE avs_booking_discounts SET USES_COUNT = USES_COUNT + 1 WHERE ID = " . $discount['ID']);

            return [
                'success' => true,
                'discount_id' => $discount['ID'],
                'discount_code' => $discount['CODE'],
                'discount_name' => $discount['NAME'],
                'discount_amount' => round($discountAmount, 2),
                'new_total' => round($amount - $discountAmount, 2)
            ];
        }

        return ['success' => false, 'error' => 'Промокод недействителен'];
    }

    public static function createDiscount($data)
    {
        global $DB;

        $pavilionIds = isset($data['pavilion_ids']) && is_array($data['pavilion_ids'])
            ? implode(',', $data['pavilion_ids']) : null;

        $sql = "INSERT INTO avs_booking_discounts 
                (CODE, NAME, DISCOUNT_TYPE, DISCOUNT_VALUE, VALID_FROM, VALID_TO, 
                 MIN_ORDER_AMOUNT, MAX_USES, PAVILION_IDS, ACTIVE, CREATED_AT)
                VALUES (
                    '" . $DB->ForSql(strtoupper($data['code'])) . "',
                    '" . $DB->ForSql($data['name']) . "',
                    '" . $DB->ForSql($data['discount_type']) . "',
                    " . floatval($data['discount_value']) . ",
                    " . ($data['valid_from'] ? "'" . $DB->ForSql($data['valid_from']) . "'" : "NULL") . ",
                    " . ($data['valid_to'] ? "'" . $DB->ForSql($data['valid_to']) . "'" : "NULL") . ",
                    " . ($data['min_order_amount'] ? floatval($data['min_order_amount']) : "NULL") . ",
                    " . ($data['max_uses'] ? intval($data['max_uses']) : "NULL") . ",
                    " . ($pavilionIds ? "'" . $DB->ForSql($pavilionIds) . "'" : "NULL") . ",
                    '" . $DB->ForSql($data['active']) . "',
                    NOW()
                )";

        $DB->Query($sql);
        return $DB->AffectedRowsCount() > 0;
    }

    public static function getActiveDiscounts()
    {
        global $DB;

        $now = date('Y-m-d H:i:s');
        $sql = "SELECT * FROM avs_booking_discounts 
                WHERE ACTIVE = 'Y'
                AND (VALID_FROM IS NULL OR VALID_FROM <= '" . $now . "')
                AND (VALID_TO IS NULL OR VALID_TO >= '" . $now . "')";

        $result = $DB->Query($sql);
        $discounts = [];
        while ($row = $result->Fetch()) {
            $discounts[] = $row;
        }
        return $discounts;
    }
}
