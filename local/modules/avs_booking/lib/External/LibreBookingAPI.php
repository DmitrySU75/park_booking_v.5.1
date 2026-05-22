<?php

namespace AVS\Booking\External;

use Bitrix\Main\Config\Option;

/**
 * Класс для взаимодействия с API LibreBooking
 * 
 * @package AVS\Booking\External
 */
#[\AllowDynamicProperties]
class LibreBookingAPI
{
    private $apiUrl;
    private $username;
    private $password;
    private $sessionToken;
    private $userId;
    private $lastAuthTime = 0;
    private $authLifetime = 3600; // 1 час

    public function __construct($apiUrl = null, $username = null, $password = null)
    {
        if ($apiUrl === null) {
            $apiUrl = Option::get('avs_booking', 'api_url', '');
        }
        if ($username === null) {
            $username = Option::get('avs_booking', 'api_username', '');
        }
        if ($password === null) {
            $password = Option::get('avs_booking', 'api_password', '');
        }

        $this->apiUrl = rtrim($apiUrl, '/');
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Аутентификация в API, получение sessionToken и userId
     * @return bool
     * @throws \Exception
     */
    public function authenticate()
    {
        if ($this->sessionToken && $this->userId && (time() - $this->lastAuthTime) < $this->authLifetime) {
            return true;
        }

        if (!$this->apiUrl || !$this->username || !$this->password) {
            throw new \Exception('Настройки API LibreBooking не заполнены');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl . '/Authentication/Authenticate');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'username' => $this->username,
            'password' => $this->password
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            $data = json_decode($response, true);
            $this->sessionToken = $data['sessionToken'] ?? null;
            $this->userId = $data['userId'] ?? null;
            $this->lastAuthTime = time();
            return true;
        }

        throw new \Exception('Authentication failed. HTTP Code: ' . $httpCode);
    }

    public function getSessionToken()
    {
        return $this->sessionToken;
    }

    public function getUserId()
    {
        return $this->userId;
    }

    /**
     * Проверка доступности ресурса
     * @param int $resourceId
     * @param string $startTime ISO 8601
     * @param string $endTime ISO 8601
     * @param int|null $excludeReservationId
     * @return bool true – доступно, false – занято
     */
    public function checkAvailability($resourceId, $startTime, $endTime, $excludeReservationId = null)
    {
        try {
            $this->authenticate();
        } catch (\Exception $e) {
            return false;
        }

        $url = $this->apiUrl . '/Resources/' . $resourceId . '/Availability?' . http_build_query([
            'startDateTime' => $startTime,
            'endDateTime' => $endTime
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Booked-SessionToken: ' . $this->sessionToken,
            'X-Booked-UserId: ' . $this->userId
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            $data = json_decode($response, true);
            if (isset($data['resources']) && is_array($data['resources'])) {
                foreach ($data['resources'] as $resource) {
                    $resId = $resource['resource']['resourceId'] ?? null;
                    if ($resId == $resourceId) {
                        return false;
                    }
                }
            }
            return true;
        }

        return false;
    }

    /**
     * Создание бронирования в LibreBooking
     * @param int $resourceId
     * @param string $startTime
     * @param string $endTime
     * @param array $userData
     * @return string|null referenceNumber
     * @throws \Exception
     */
    public function createReservation($resourceId, $startTime, $endTime, $userData)
    {
        $this->authenticate();

        $postData = [
            'resourceId' => (int)$resourceId,
            'startDateTime' => $startTime,
            'endDateTime' => $endTime,
            'title' => $userData['title'] ?? 'Бронирование с сайта',
            'description' => $userData['comment'] ?? '',
            'firstName' => $userData['name'] ?? '',
            'lastName' => $userData['lastName'] ?? '',
            'email' => $userData['email'] ?? '',
            'phone' => $userData['phone'] ?? '',
            'userId' => $this->userId,
            'allowParticipation' => false,
            'termsAccepted' => true
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl . '/Reservations/');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Booked-SessionToken: ' . $this->sessionToken,
            'X-Booked-UserId: ' . $this->userId
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200 || $httpCode == 201) {
            $data = json_decode($response, true);
            return $data['referenceNumber'] ?? null;
        }

        throw new \Exception('Reservation creation failed. HTTP Code: ' . $httpCode);
    }

    /**
     * Получение списка бронирований
     * @param string|null $startDateTime
     * @param string|null $endDateTime
     * @param int|null $resourceId
     * @param int|null $userId
     * @return array
     */
    public function getReservations($startDateTime = null, $endDateTime = null, $resourceId = null, $userId = null)
    {
        try {
            $this->authenticate();
        } catch (\Exception $e) {
            return [];
        }

        $queryParams = [];
        if ($startDateTime) $queryParams['startDateTime'] = $startDateTime;
        if ($endDateTime) $queryParams['endDateTime'] = $endDateTime;
        if ($resourceId) $queryParams['resourceId'] = $resourceId;
        if ($userId) $queryParams['userId'] = $userId;

        $url = $this->apiUrl . '/Reservations/';
        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Booked-SessionToken: ' . $this->sessionToken,
            'X-Booked-UserId: ' . $this->userId
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            $data = json_decode($response, true);
            return $data['reservations'] ?? [];
        }

        return [];
    }

    /**
     * Получение конкретного бронирования по referenceNumber
     * @param string $referenceNumber
     * @return array|null
     */
    public function getReservation($referenceNumber)
    {
        try {
            $this->authenticate();
        } catch (\Exception $e) {
            return null;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl . '/Reservations/' . $referenceNumber);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Booked-SessionToken: ' . $this->sessionToken,
            'X-Booked-UserId: ' . $this->userId
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            return json_decode($response, true);
        }

        return null;
    }

    /**
     * Отмена бронирования
     * @param string $referenceNumber
     * @return bool
     */
    public function cancelReservation($referenceNumber)
    {
        $this->authenticate();

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl . '/Reservations/' . $referenceNumber);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Booked-SessionToken: ' . $this->sessionToken,
            'X-Booked-UserId: ' . $this->userId
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode == 200;
    }
}
