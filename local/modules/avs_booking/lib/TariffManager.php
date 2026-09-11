<?php

namespace AVS\Booking;

use Bitrix\Main\Config\Option;

class TariffManager
{
    public static function calculatePrice($pavilionId, $rentalType, $date, $hours = null, $discountCode = null)
    {
        $gazebo = \AVSBookingModule::getGazeboData($pavilionId);
        if (!$gazebo) return ['error' => 'Беседка не найдена'];

        $minHours = (int)Option::get('avs_booking', 'min_hours', 4);
        $total = $duration = 0;

        switch ($rentalType) {
            case 'hourly':
                if ($hours < $minHours) return ['error' => "Минимальная продолжительность аренды - {$minHours} часа"];
                $total = $gazebo['hourly_price'] * $hours;
                $duration = $hours;
                break;
            case 'full_day':
                $workEndHour = \AVSBookingModule::getWorkEndHour($pavilionId, $date);
                $duration = $workEndHour - 10;
                $total = $gazebo['full_day_price'];
                break;
            case 'night':
                $duration = 8;
                $total = $gazebo['night_price'];
                break;
            default:
                return ['error' => 'Неизвестный тип аренды'];
        }

        $restrictions = \AVSBookingModule::getDateRestrictions($pavilionId, $date);
        $priceModifier = $restrictions['price_modifier'] ?? 1;
        $total *= $priceModifier;

        $discount = 0;
        if ($discountCode) {
            $discountInfo = \AVSBookingDiscountManager::applyDiscount($discountCode, $total);
            if ($discountInfo['success']) {
                $discount = $discountInfo['discount_amount'];
                $total = $discountInfo['new_total'];
            }
        }

        return [
            'success' => true,
            'base_price' => $gazebo['hourly_price'],
            'total_price' => round($total, 2),
            'deposit_amount' => $gazebo['deposit_amount'],
            'discount_amount' => round($discount, 2),
            'duration_hours' => $duration,
            'price_modifier' => $priceModifier,
            'rental_type' => $rentalType
        ];
    }

    public static function calculateExtensionPrice($orderId, $newEndTime)
    {
        $order = Order::get($orderId);
        if (!$order) return ['error' => 'Заказ не найден'];
        $currentEnd = new \DateTime($order['END_TIME']->toString());
        $newEnd = new \DateTime($newEndTime);
        $additionalMinutes = ($newEnd->getTimestamp() - $currentEnd->getTimestamp()) / 60;
        if ($additionalMinutes <= 0) return ['error' => 'Новое время должно быть позже текущего'];
        $originalDuration = $order['DURATION_HOURS'];
        $hourlyRate = $order['PRICE'] / $originalDuration;
        $additionalPrice = ($hourlyRate / 60) * $additionalMinutes;
        return [
            'success' => true,
            'additional_minutes' => $additionalMinutes,
            'additional_hours' => round($additionalMinutes / 60, 1),
            'additional_price' => round($additionalPrice, 2),
            'new_total_price' => round($order['PRICE'] + $additionalPrice, 2)
        ];
    }
}
