#!/bin/bash

# ============================================
# AVS Booking - Быстрый установщик
# ============================================

set -e

# Цвета
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}"
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║                                                              ║"
echo "║     AVS BOOKING - БЫСТРАЯ УСТАНОВКА                          ║"
echo "║                                                              ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo -e "${NC}"

# Проверка прав
if [ "$EUID" -ne 0 ]; then 
    echo -e "${YELLOW}⚠ Рекомендуется запускать с правами root${NC}"
fi

# Проверка PHP
if ! command -v php &> /dev/null; then
    echo -e "${RED}✗ PHP не установлен${NC}"
    exit 1
fi

PHP_VERSION=$(php -v | head -n1 | cut -d' ' -f2)
echo -e "${GREEN}✓ PHP версия: ${PHP_VERSION}${NC}"

# Проверка необходимых расширений PHP
REQUIRED_EXTS=("curl" "json" "mbstring" "pdo_mysql" "gd")
for ext in "${REQUIRED_EXTS[@]}"; do
    if php -m | grep -qi "^${ext}$"; then
        echo -e "${GREEN}✓ Расширение ${ext}${NC}"
    else
        echo -e "${RED}✗ Расширение ${ext} отсутствует${NC}"
        echo -e "${YELLOW}  Установка: sudo apt-get install php-${ext}${NC}"
        MISSING=1
    fi
done

if [ -n "$MISSING" ]; then
    echo -e "${RED}Установите недостающие расширения и запустите скрипт снова${NC}"
    exit 1
fi

# Запрос конфигурации
echo -e "\n${BLUE}Введите параметры установки:${NC}"

read -p "Путь к корню проекта [$(pwd)]: " PROJECT_ROOT
PROJECT_ROOT=${PROJECT_ROOT:-$(pwd)}

read -p "Путь к Битрикс [${PROJECT_ROOT}/bitrix]: " BITRIX_ROOT
BITRIX_ROOT=${BITRIX_ROOT:-${PROJECT_ROOT}/bitrix}

read -p "Путь для установки LibreBooking [/booking]: " BOOKING_PATH
BOOKING_PATH=${BOOKING_PATH:-/booking}

read -p "База данных MySQL host [localhost]: " DB_HOST
DB_HOST=${DB_HOST:-localhost}

read -p "Имя базы данных [librebooking]: " DB_NAME
DB_NAME=${DB_NAME:-librebooking}

read -p "Пользователь БД [librebooking_user]: " DB_USER
DB_USER=${DB_USER:-librebooking_user}

read -sp "Пароль пользователя БД: " DB_PASSWORD
echo ""

read -p "URL сайта [https://your-domain.ru]: " SITE_URL
SITE_URL=${SITE_URL:-https://your-domain.ru}

# Генерация паролей
API_PASSWORD=$(openssl rand -base64 16)
SALT=$(openssl rand -hex 32)
INSTALL_PASSWORD=$(openssl rand -base64 12)

# Создание конфигурации
CONFIG_FILE="/tmp/avs_install_config.php"

cat > "$CONFIG_FILE" << EOF
<?php
\$config = [
    'project_root' => '${PROJECT_ROOT}',
    'bitrix_root' => '${BITRIX_ROOT}',
    'booking_path' => '${BOOKING_PATH}',
    'db_host' => '${DB_HOST}',
    'db_name' => '${DB_NAME}',
    'db_user' => '${DB_USER}',
    'db_password' => '${DB_PASSWORD}',
    'api_username' => 'api_user',
    'api_password' => '${API_PASSWORD}',
    'timezone' => 'Asia/Yekaterinburg',
    'language' => 'ru_RU',
    'site_url' => '${SITE_URL}',
    'salt' => '${SALT}',
    'install_password' => '${INSTALL_PASSWORD}'
];
EOF

echo -e "\n${GREEN}✓ Конфигурация создана${NC}"

# Запуск PHP установщика
echo -e "\n${BLUE}Запуск установки...${NC}\n"

php "$CONFIG_FILE"

echo -e "\n${GREEN}════════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}                  УСТАНОВКА ЗАВЕРШЕНА                              ${NC}"
echo -e "${GREEN}════════════════════════════════════════════════════════════════${NC}"
echo ""
echo -e "${YELLOW}📝 Сохраненные пароли:${NC}"
echo "   API пользователь: api_user"
echo "   API пароль: ${API_PASSWORD}"
echo "   Пароль установщика LibreBooking: ${INSTALL_PASSWORD}"
echo ""
echo -e "${YELLOW}💾 Сохраните эти пароли в надежном месте!${NC}"
echo ""

# Очистка
rm -f "$CONFIG_FILE"