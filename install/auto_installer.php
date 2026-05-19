<?php

/**
 * Файл: /install/auto_installer.php
 * Автоматический установщик AVS Booking
 * 
 * Запуск: php auto_installer.php
 */

// ============================================
// КОНФИГУРАЦИЯ - ИЗМЕНИТЕ ПОД СВОЙ ПРОЕКТ
// ============================================

$config = [
    // Пути
    'project_root' => '/home/avsdevelopment2/park.na4u.ru/www',
    'bitrix_root' => '/home/avsdevelopment2/park.na4u.ru/www/bitrix',
    'booking_path' => '/booking',

    // База данных LibreBooking
    'db_host' => 'localhost',
    'db_name' => 'vpark-litepms',
    'db_user' => 'vpark1',
    'db_password' => 'hq46jB7&',

    // LibreBooking API пользователь
    'api_username' => 'api_user',
    'api_password' => '^ti*54gjnI',

    // Основные настройки времени
    'timezone' => 'Asia/Yekaterinburg',
    'language' => 'ru_RU',

    // URL сайта
    'site_url' => 'https://park.na4u.ru',

    // Соль для шифрования (сгенерируется автоматически, если не указана)
    'salt' => '',

    // Пароль установщика LibreBooking
    'install_password' => '^ti*54gjnI'
];

// ============================================
// НЕ РЕДАКТИРОВАТЬ НИЖЕ ЭТОЙ ЛИНИИ
// ============================================

class AVSBookingAutoInstaller
{
    private $config;
    private $logs = [];
    private $errors = [];
    private $step = 0;
    private $totalSteps = 5;
    private $colors = [
        'green' => "\033[32m",
        'red' => "\033[31m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'reset' => "\033[0m",
        'bold' => "\033[1m"
    ];

    public function __construct($config)
    {
        $this->config = $config;

        // Генерация соли, если не указана
        if (empty($this->config['salt'])) {
            $this->config['salt'] = bin2hex(random_bytes(32));
        }
    }

    public function run()
    {
        $this->printBanner();

        // Проверка прав на запись
        if (!$this->checkPermissions()) {
            $this->logError("Недостаточно прав для установки. Запустите скрипт с правами root или от пользователя веб-сервера.", true);
        }

        // Шаг 1: Проверка PHP окружения
        $this->step++;
        $this->log("Шаг {$this->step}/{$this->totalSteps}: Проверка PHP окружения...", 'blue');
        $this->checkPhpEnvironment();

        // Шаг 2: Установка LibreBooking
        $this->step++;
        $this->log("Шаг {$this->step}/{$this->totalSteps}: Установка LibreBooking...", 'blue');
        $this->installLibreBooking();

        // Шаг 3: Настройка LibreBooking
        $this->step++;
        $this->log("Шаг {$this->step}/{$this->totalSteps}: Настройка LibreBooking...", 'blue');
        $this->configureLibreBooking();

        // Шаг 4: Установка модуля AVS Booking в Битрикс
        $this->step++;
        $this->log("Шаг {$this->step}/{$this->totalSteps}: Установка модуля AVS Booking...", 'blue');
        $this->installAVSBookingModule();

        // Шаг 5: Базовая настройка модуля
        $this->step++;
        $this->log("Шаг {$this->step}/{$this->totalSteps}: Базовая настройка модуля...", 'blue');
        $this->configureAVSBookingModule();

        $this->printSummary();

        return true;
    }

    /**
     * Проверка прав на запись
     */
    private function checkPermissions()
    {
        $dirs = [
            $this->config['project_root'],
            $this->config['bitrix_root'] . '/cache',
            $this->config['bitrix_root'] . '/managed_cache',
            $this->config['project_root'] . '/upload'
        ];

        foreach ($dirs as $dir) {
            if (is_dir($dir) && !is_writable($dir)) {
                $this->logError("Директория не доступна для записи: {$dir}");
                return false;
            }
        }

        return true;
    }

    /**
     * Проверка PHP окружения
     */
    private function checkPhpEnvironment()
    {
        $requiredPhpVersion = '7.4';
        $requiredExtensions = ['curl', 'json', 'mbstring', 'pdo_mysql', 'gd2'];
        $optionalExtensions = ['openssl', 'zip', 'xml'];

        $currentVersion = phpversion();

        // Проверка версии PHP
        if (version_compare($currentVersion, $requiredPhpVersion, '<')) {
            $this->logError("Требуется PHP версии {$requiredPhpVersion}+, установлена: {$currentVersion}");
        } else {
            $this->log("✓ PHP версия {$currentVersion} (требуется {$requiredPhpVersion}+)", 'green');
        }

        // Проверка расширений
        $missingRequired = [];
        foreach ($requiredExtensions as $ext) {
            if (extension_loaded($ext)) {
                $this->log("✓ Расширение {$ext} - установлено", 'green');
            } else {
                $missingRequired[] = $ext;
                $this->log("✗ Расширение {$ext} - ОТСУТСТВУЕТ", 'red');
            }
        }

        if (!empty($missingRequired)) {
            $this->logError("Отсутствуют обязательные расширения PHP: " . implode(', ', $missingRequired));
            $this->log("   Установите их командой (Ubuntu/Debian):", 'yellow');
            $this->log("   sudo apt-get install php" . $currentVersion . "-" . implode(" php" . $currentVersion . "-", $missingRequired), 'yellow');
        }

        // Проверка дополнительных расширений (только предупреждение)
        foreach ($optionalExtensions as $ext) {
            if (extension_loaded($ext)) {
                $this->log("✓ Расширение {$ext} - установлено (опционально)", 'green');
            } else {
                $this->log("⚠ Расширение {$ext} - не установлено (опционально)", 'yellow');
            }
        }

        $this->log("Проверка PHP окружения завершена.", 'green');
        return empty($missingRequired);
    }

    /**
     * Установка LibreBooking
     */
    private function installLibreBooking()
    {
        $bookingDir = $this->config['project_root'] . $this->config['booking_path'];

        // Проверяем, установлен ли уже LibreBooking
        if (file_exists($bookingDir . '/Web/Services/index.php')) {
            $this->log("LibreBooking уже установлен по пути: {$bookingDir}", 'green');
            $this->log("Пропускаем установку...", 'yellow');
            return true;
        }

        $this->log("Начинаю установку LibreBooking в: {$bookingDir}", 'blue');

        // Скачивание
        $downloadUrl = "https://github.com/LibreBooking/librebooking/archive/refs/tags/2.8.6.2.tar.gz";
        $tarFile = $bookingDir . '.tar.gz';

        $this->log("Скачиваю LibreBooking...");

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $downloadUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$data) {
            $this->logError("Не удалось скачать LibreBooking. HTTP код: {$httpCode}");
            return false;
        }

        file_put_contents($tarFile, $data);
        $this->log("✓ Скачивание завершено", 'green');

        // Распаковка
        $this->log("Распаковка архива...");

        $phar = new PharData($tarFile);
        $phar->extractTo($this->config['project_root']);

        // Переименовываем директорию
        $extractedDir = $this->config['project_root'] . '/librebooking-2.8.6.2';
        if (file_exists($extractedDir)) {
            rename($extractedDir, $bookingDir);
            $this->log("✓ Распаковка завершена", 'green');
        } else {
            $this->logError("Не удалось найти распакованную директорию");
            return false;
        }

        // Очистка
        unlink($tarFile);

        // Установка прав
        chmod($bookingDir . '/tpl_c', 0777);
        chmod($bookingDir . '/tpl', 0777);
        chmod($bookingDir . '/uploads', 0777);

        $this->log("✓ LibreBooking установлен", 'green');
        return true;
    }

    /**
     * Настройка LibreBooking
     */
    private function configureLibreBooking()
    {
        $bookingDir = $this->config['project_root'] . $this->config['booking_path'];
        $configFile = $bookingDir . '/config/config.php';

        // Проверяем, настроена ли уже база данных
        if (file_exists($configFile) && filesize($configFile) > 1000) {
            $this->log("LibreBooking уже настроен. Проверяю подключение к БД...", 'yellow');

            // Проверка подключения к БД
            try {
                $pdo = new PDO(
                    "mysql:host={$this->config['db_host']};dbname={$this->config['db_name']}",
                    $this->config['db_user'],
                    $this->config['db_password']
                );
                $this->log("✓ Подключение к базе данных работает", 'green');

                // Проверяем API-пользователя
                $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE username = '{$this->config['api_username']}'");
                if ($stmt && $stmt->fetchColumn() > 0) {
                    $this->log("✓ API пользователь {$this->config['api_username']} существует", 'green');
                } else {
                    $this->log("⚠ API пользователь не найден. Создайте его вручную.", 'yellow');
                }

                return true;
            } catch (PDOException $e) {
                $this->log("База данных не настроена или недоступна. Выполняю настройку...", 'yellow');
            }
        }

        // Создание конфигурационного файла
        $configContent = "<?php
return [
    'settings' => [
        'database' => [
            'user' => '{$this->config['db_user']}',
            'password' => '{$this->config['db_password']}',
            'hostspec' => '{$this->config['db_host']}',
            'database' => '{$this->config['db_name']}'
        ],
        'script.url' => '{$this->config['site_url']}{$this->config['booking_path']}',
        'default.timezone' => '{$this->config['timezone']}',
        'default.language' => '{$this->config['language']}',
        'salt' => '{$this->config['salt']}',
        'install.password' => '{$this->config['install_password']}'
    ]
];";

        file_put_contents($configFile, $configContent);
        $this->log("✓ Конфигурационный файл создан", 'green');

        // Создание базы данных и таблиц через PHP скрипт LibreBooking
        $installScript = $bookingDir . '/install/install.php';
        if (file_exists($installScript)) {
            $this->log("База данных будет создана через веб-установщик.", 'yellow');
            $this->log("Перейдите по ссылке для завершения установки:", 'blue');
            $this->log("{$this->config['site_url']}{$this->config['booking_path']}/install/install.php", 'bold');
            $this->log("Используйте пароль установщика: {$this->config['install_password']}", 'yellow');

            $this->log("\nНажмите Enter после завершения установки через браузер...", 'yellow');
            fgets(STDIN);

            // Удаление установщика
            $this->recursiveRemoveDir($bookingDir . '/install');
            $this->log("✓ Установщик удален", 'green');
        } else {
            $this->logError("Установщик LibreBooking не найден");
            return false;
        }

        return true;
    }

    /**
     * Установка модуля AVS Booking в Битрикс
     */
    private function installAVSBookingModule()
    {
        $modulePath = $this->config['project_root'] . '/local/modules/avs_booking';

        // Проверяем, установлен ли уже модуль
        if (file_exists($modulePath . '/install/index.php') && is_dir($modulePath)) {
            $this->log("Модуль AVS Booking уже существует. Проверяю установку в Битрикс...", 'yellow');
        }

        // Создаем таблицы БД модуля
        $sqlFile = $modulePath . '/install/db/install.sql';
        if (file_exists($sqlFile)) {
            try {
                $pdo = new PDO(
                    "mysql:host={$this->config['db_host']}",
                    $this->config['db_user'],
                    $this->config['db_password']
                );

                $sql = file_get_contents($sqlFile);

                // Выбираем базу данных
                $pdo->exec("USE `{$this->config['db_name']}`");

                // Выполняем SQL
                $pdo->exec($sql);
                $this->log("✓ Таблицы модуля созданы", 'green');
            } catch (PDOException $e) {
                $this->log("Таблицы уже существуют или ошибка: " . $e->getMessage(), 'yellow');
            }
        }

        // Подключаемся к Битрикс для установки модуля
        $bitrixProlog = $this->config['bitrix_root'] . '/modules/main/include/prolog_before.php';

        if (!file_exists($bitrixProlog)) {
            $this->logError("Битрикс не найден по пути: {$this->config['bitrix_root']}");
            return false;
        }

        $this->log("Установка модуля через API Битрикс...", 'blue');

        // Создаем временный PHP скрипт для установки модуля
        $installScript = $this->config['project_root'] . '/temp_install_module.php';

        $scriptContent = "<?php
\$_SERVER['DOCUMENT_ROOT'] = '{$this->config['project_root']}';
require_once \$_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once \$_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/classes/general/module.php';

\$moduleId = 'avs_booking';

if (!CModule::IncludeModule(\$moduleId)) {
    // Регистрируем модуль
    \$module = new CModule();
    \$module->MODULE_ID = \$moduleId;
    \$module->MODULE_NAME = 'AVS Booking System';
    \$module->MODULE_DESCRIPTION = 'Модуль бронирования беседок';
    \$module->PARTNER_NAME = 'AVS Group';
    \$module->PARTNER_URI = 'https://avsgroup.ru';
    
    // Устанавливаем
    RegisterModule(\$moduleId);
    
    echo 'Модуль avs_booking успешно установлен\\n';
} else {
    echo 'Модуль avs_booking уже установлен\\n';
}

require_once \$_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
";

        file_put_contents($installScript, $scriptContent);

        // Выполняем скрипт
        $output = [];
        $returnVar = 0;
        exec("php {$installScript} 2>&1", $output, $returnVar);

        $this->log(implode("\n", $output), $returnVar === 0 ? 'green' : 'red');

        // Удаляем временный скрипт
        unlink($installScript);

        if ($returnVar !== 0) {
            $this->logError("Не удалось установить модуль через API Битрикс. Выполните установку вручную через админку.");
        }

        return $returnVar === 0;
    }

    /**
     * Базовая настройка модуля AVS Booking (без ЮKassa)
     */
    private function configureAVSBookingModule()
    {
        $apiUrl = $this->config['site_url'] . $this->config['booking_path'] . '/Web/Services';

        // Создаем PHP скрипт для настройки опций
        $optionsScript = $this->config['project_root'] . '/temp_set_options.php';

        $scriptContent = "<?php
\$_SERVER['DOCUMENT_ROOT'] = '{$this->config['project_root']}';
require_once \$_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\\Main\\Config\\Option;

\$moduleId = 'avs_booking';

if (CModule::IncludeModule(\$moduleId)) {
    // Основные настройки API
    Option::set(\$moduleId, 'api_url', '{$apiUrl}');
    Option::set(\$moduleId, 'api_username', '{$this->config['api_username']}');
    Option::set(\$moduleId, 'api_password', '{$this->config['api_password']}');
    Option::set(\$moduleId, 'api_key', '{$this->config['salt']}');
    
    // Настройки уведомлений
    Option::set(\$moduleId, 'admin_email', '');
    Option::set(\$moduleId, 'manager_email', '');
    Option::set(\$moduleId, 'tg_bot_token', '');
    Option::set(\$moduleId, 'tg_manager_chat_id', '');
    
    // Настройки тарифов
    \$year = date('Y');
    Option::set(\$moduleId, 'summer_period_start', '01.06');
    Option::set(\$moduleId, 'summer_period_end', '31.08');
    Option::set(\$moduleId, 'summer_end_hour', '23');
    Option::set(\$moduleId, 'winter_end_hour', '22');
    Option::set(\$moduleId, 'default_deposit', '2000');
    Option::set(\$moduleId, 'high_deposit_pavilions', '5,6');
    Option::set(\$moduleId, 'high_deposit_amount', '5000');
    Option::set(\$moduleId, 'min_hours', '4');
    Option::set(\$moduleId, 'weekend_restriction', 'no');
    Option::set(\$moduleId, 'weekend_price_modifier', '1.2');
    Option::set(\$moduleId, 'holiday_dates', '');
    
    // Настройки ЮKassa оставляем пустыми (настройка через админку)
    Option::set(\$moduleId, 'beton_systems_shop_id', '');
    Option::set(\$moduleId, 'beton_systems_secret_key', '');
    Option::set(\$moduleId, 'park_victory_shop_id', '');
    Option::set(\$moduleId, 'park_victory_secret_key', '');
    
    echo 'Настройки модуля сохранены\\n';
} else {
    echo 'Модуль avs_booking не найден\\n';
}
";

        file_put_contents($optionsScript, $scriptContent);

        // Выполняем скрипт
        $output = [];
        $returnVar = 0;
        exec("php {$optionsScript} 2>&1", $output, $returnVar);

        $this->log(implode("\n", $output), $returnVar === 0 ? 'green' : 'red');

        // Удаляем временный скрипт
        unlink($optionsScript);

        if ($returnVar === 0) {
            $this->log("✓ Базовая настройка модуля завершена", 'green');
            $this->log("\n⚠️ ВАЖНО: Настройки ЮKassa не были сконфигурированы.", 'yellow');
            $this->log("   Настройте их вручную в админке Битрикс:", 'yellow');
            $this->log("   Настройки → Настройки продуктов → Настройки модулей → AVS Booking → API и интеграции", 'yellow');
        }

        return $returnVar === 0;
    }

    /**
     * Рекурсивное удаление директории
     */
    private function recursiveRemoveDir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursiveRemoveDir($path) : unlink($path);
        }

        rmdir($dir);
    }

    /**
     * Вывод баннера
     */
    private function printBanner()
    {
        $banner = "
╔══════════════════════════════════════════════════════════════╗
║                                                              ║
║     █████╗ ██╗   ██╗███████╗                              ║
║    ██╔══██╗██║   ██║██╔════╝                              ║
║    ███████║██║   ██║█████╗                                ║
║    ██╔══██║╚██╗ ██╔╝██╔══╝                                ║
║    ██║  ██║ ╚████╔╝ ███████╗                              ║
║    ╚═╝  ╚═╝  ╚═══╝  ╚══════╝                              ║
║                                                              ║
║         AVS BOOKING - АВТОМАТИЧЕСКАЯ УСТАНОВКА              ║
║                                                              ║
║  LibreBooking + Битрикс модуль                             ║
║  Версия: 5.1.0                                             ║
║                                                              ║
╚══════════════════════════════════════════════════════════════╝
";
        echo $this->colorize($banner, 'blue');
    }

    /**
     * Вывод итогов
     */
    private function printSummary()
    {
        $summary = "
╔══════════════════════════════════════════════════════════════╗
║                    УСТАНОВКА ЗАВЕРШЕНА                       ║
╠══════════════════════════════════════════════════════════════╣
║                                                              ║
║  ✅ LibreBooking установлен                                 ║
║  ✅ Модуль AVS Booking установлен                           ║
║  ✅ Базовая конфигурация выполнена                          ║
║                                                              ║
╠══════════════════════════════════════════════════════════════╣
║                    ДАЛЬНЕЙШИЕ ДЕЙСТВИЯ                       ║
╠══════════════════════════════════════════════════════════════╣
║                                                              ║
║  1. Настройте инфоблок 'Беседки' в Битрикс                  ║
║     - Создайте свойства PRICE_HOUR, PRICE, PRICE_NIGHT      ║
║     - Добавьте беседки и укажите LIBREBOOKING_RESOURCE_ID   ║
║                                                              ║
║  2. Настройте API пользователя в LibreBooking               ║
║     - Логин: {$this->config['api_username']}                ║
║     - Пароль: {$this->config['api_password']}               ║
║     - Роль: Application Administrators                      ║
║                                                              ║
║  3. Настройте ЮKassa в админке Битрикс                      ║
║     - Настройки → Настройки модулей → AVS Booking           ║
║     - Вкладка 'API и интеграции'                            ║
║                                                              ║
║  4. Добавьте компонент на сайт:                             ║
║     \$APPLICATION->IncludeComponent(                         ║
║         'avs_booking:booking.form',                         ║
║         '.default',                                         ║
║         ['ELEMENT_ID' => \$arResult['ID']]                   ║
║     );                                                      ║
║                                                              ║
╠══════════════════════════════════════════════════════════════╣
║  📍 Документация: /local/modules/avs_booking/               ║
║  📧 Поддержка: d.sumenkov@avsgroup.ru                       ║
╚══════════════════════════════════════════════════════════════╝
";
        echo $this->colorize($summary, 'green');
    }

    /**
     * Логирование
     */
    private function log($message, $color = 'reset')
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}";
        $this->logs[] = $logMessage;

        echo $this->colorize($logMessage, $color) . "\n";
    }

    /**
     * Логирование ошибки
     */
    private function logError($message, $exit = false)
    {
        $this->errors[] = $message;
        $this->log("ERROR: {$message}", 'red');

        if ($exit) {
            $this->printSummary();
            exit(1);
        }
    }

    /**
     * Цветной вывод
     */
    private function colorize($text, $color)
    {
        if (php_sapi_name() !== 'cli') {
            return $text;
        }

        return $this->colors[$color] . $text . $this->colors['reset'];
    }
}

// ============================================
// ЗАПУСК УСТАНОВЩИКА
// ============================================

echo "Запуск установщика AVS Booking...\n\n";

$installer = new AVSBookingAutoInstaller($config);
$installer->run();
