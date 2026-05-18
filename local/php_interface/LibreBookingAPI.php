<?php

/**
 * Файл: /local/php_interface/LibreBookingAPI.php
 * Финальная версия - с правильным созданием бронирований
 */

use Bitrix\Main\Config\Option;

class LibreBookingAPI
{
    private $apiUrl;
    private $username;
    private $password;
    private $sessionToken;
    private $userId;
    private $lastAuthTime = 0;
    private $authLifetime = 3600;

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

    public function authenticate()
    {
        if ($this->sessionToken && $this->userId && (time() - $this->lastAuthTime) < $this->authLifetime) {
            return true;
        }

        if (!$this->apiUrl || !$this->username || !$this->password) {
            throw new Exception('Настройки API LibreBooking не заполнены');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl . '/Authentication/Authenticate');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
            'username' => $this->username,
            'password' => $this->password
        )));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            $data = json_decode($response, true);
            $this->sessionToken = isset($data['sessionToken']) ? $data['sessionToken'] : null;
            $this->userId = isset($data['userId']) ? $data['userId'] : null;
            $this->lastAuthTime = time();
            return true;
        }

        throw new Exception('Authentication failed. HTTP Code: ' . $httpCode);
    }

    public function getSessionToken()
    {
        return $this->sessionToken;
    }

    public function getUserId()
    {
        return $this->userId;
    }

    public function checkAvailability($resourceId, $startTime, $endTime, $excludeReservationId = null)
    {
        try {
            $this->authenticate();
        } catch (Exception $e) {
            return false;
        }

        $url = $this->apiUrl . '/Resources/' . $resourceId . '/Availability?' . http_build_query(array(
            'startDateTime' => $startTime,
            'endDateTime' => $endTime
        ));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-Booked-SessionToken: ' . $this->sessionToken,
            'X-Booked-UserId: ' . $this->userId
        ));
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
                    $resId = null;
                    if (isset($resource['resource']['resourceId'])) {
                        $resId = $resource['resource']['resourceId'];
                    }
                    if ($resId == $resourceId) {
                        return false;
                    }
                }
            }
            return true;
        }

        return false;
    }

    public function createReservation($resourceId, $startTime, $endTime, $userData)
    {
        $this->authenticate();

        $postData = array(
            'resourceId' => (int)$resourceId,
            'startDateTime' => $startTime,
            'endDateTime' => $endTime,
            'title' => isset($userData['title']) ? $userData['title'] : 'Бронирование с сайта',
            'description' => isset($userData['comment']) ? $userData['comment'] : '',
            'firstName' => isset($userData['name']) ? $userData['name'] : '',
            'lastName' => isset($userData['lastName']) ? $userData['lastName'] : '',
            'email' => isset($userData['email']) ? $userData['email'] : '',
            'phone' => isset($userData['phone']) ? $userData['phone'] : '',
            'userId' => $this->userId,
            'allowParticipation' => false,
            'termsAccepted' => true
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl . '/Reservations/');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-Booked-SessionToken: ' . $this->sessionToken,
            'X-Booked-UserId: ' . $this->userId
        ));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200 || $httpCode == 201) {
            $data = json_decode($response, true);
            return isset($data['referenceNumber']) ? $data['referenceNumber'] : null;
        }

        throw new Exception('Reservation creation failed. HTTP Code: ' . $httpCode);
    }

    public function getReservations($startDateTime = null, $endDateTime = null, $resourceId = null, $userId = null)
    {
        try {
            $this->authenticate();
        } catch (Exception $e) {
            return array();
        }

        $queryParams = array();
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
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-Booked-SessionToken: ' . $this->sessionToken,
            'X-Booked-UserId: ' . $this->userId
        ));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            $data = json_decode($response, true);
            return isset($data['reservations']) ? $data['reservations'] : array();
        }

        return array();
    }

    public function getReservation($referenceNumber)
    {
        try {
            $this->authenticate();
        } catch (Exception $e) {
            return null;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl . '/Reservations/' . $referenceNumber);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-Booked-SessionToken: ' . $this->sessionToken,
            'X-Booked-UserId: ' . $this->userId
        ));
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

    public function cancelReservation($referenceNumber)
    {
        $this->authenticate();

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl . '/Reservations/' . $referenceNumber);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-Booked-SessionToken: ' . $this->sessionToken,
            'X-Booked-UserId: ' . $this->userId
        ));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode == 200;
    }
}
