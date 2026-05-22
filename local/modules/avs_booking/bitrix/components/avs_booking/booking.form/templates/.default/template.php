<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

$gazebo = $arResult['GAZEBO'];
$rentalTypes = $arResult['RENTAL_TYPES'];
$availableSlots = $arResult['AVAILABLE_SLOTS'];
$selectedDate = $arResult['SELECTED_DATE'];
$workEndHour = $arResult['WORK_END_HOUR'];
$minHours = $arResult['MIN_HOURS'];
$errors = $arResult['ERRORS'] ?? [];
$post = $arResult['POST'] ?? [];

CJSCore::Init(['jquery']);
?>

<div class="avs-booking-form">
    <h2>Бронирование беседки: <?= htmlspecialcharsbx($gazebo['name']) ?></h2>

    <div class="work-hours-info">
        ⏰ Время работы: 10:00 - <?= $workEndHour ?>:00
        <?php if ($minHours > 0): ?><br>Минимальная продолжительность аренды: <?= $minHours ?> часа<?php endif; ?>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="avs-booking-errors">
            <?php foreach ($errors as $error): ?>
                <div class="error-message"><?= htmlspecialcharsbx($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="" id="booking-form">
        <?= bitrix_sessid_post() ?>

        <div class="form-group">
            <label for="date">Дата бронирования *</label>
            <input type="date" name="date" id="date" value="<?= htmlspecialcharsbx($post['date'] ?: $selectedDate) ?>" min="<?= date('Y-m-d') ?>" required>
        </div>

        <div class="form-group">
            <label for="rental_type">Тип аренды *</label>
            <select name="rental_type" id="rental_type" required>
                <option value="">Выберите тип аренды</option>
                <?php foreach ($rentalTypes as $code => $type): ?>
                    <option value="<?= $code ?>" data-price="<?= $type['price'] ?>" <?= ($post['rental_type'] == $code) ? 'selected' : '' ?>>
                        <?= htmlspecialcharsbx($type['label']) ?> - <?= number_format($type['price'], 0, '.', ' ') ?> руб.
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group hourly-fields" style="display: none;">
            <label for="start_hour">Время начала</label>
            <select name="start_hour" id="start_hour">
                <option value="">Выберите время</option>
                <?php foreach ($availableSlots as $slot): ?>
                    <option value="<?= $slot['hour'] ?>" <?= ($post['start_hour'] == $slot['hour']) ? 'selected' : '' ?>><?= $slot['label'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group hourly-fields" style="display: none;">
            <label for="hours">Продолжительность (часов) *</label>
            <select name="hours" id="hours" disabled>
                <option value="">Сначала выберите время начала</option>
            </select>
            <small>Минимальная продолжительность: <?= $minHours ?> часа</small>
        </div>

        <div class="form-group">
            <label for="client_name">Ваше имя *</label>
            <input type="text" name="client_name" id="client_name" value="<?= htmlspecialcharsbx($post['client_name'] ?? '') ?>" required>
        </div>

        <div class="form-group">
            <label for="client_phone">Телефон *</label>
            <input type="tel" name="client_phone" id="client_phone" class="phone-mask" value="<?= htmlspecialcharsbx($post['client_phone'] ?? '') ?>" placeholder="+7(___) ___-__-__" required>
        </div>

        <div class="form-group">
            <label for="client_email">Email (необязательно)</label>
            <input type="email" name="client_email" id="client_email" value="<?= htmlspecialcharsbx($post['client_email'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label for="discount_code">Промокод</label>
            <div class="discount-wrapper">
                <input type="text" name="discount_code" id="discount_code" value="<?= htmlspecialcharsbx($post['discount_code'] ?? '') ?>" placeholder="Введите промокод">
                <button type="button" id="apply_discount" class="btn-small">Применить</button>
            </div>
        </div>

        <div class="form-group">
            <label for="comment">Комментарий</label>
            <textarea name="comment" id="comment" rows="3"><?= htmlspecialcharsbx($post['comment'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
            <button type="submit" class="btn-submit">Забронировать</button>
        </div>

        <div id="price-preview" class="price-preview" style="display: none;">
            <div>Сумма: <span class="price-value">0</span> руб.</div>
            <div class="deposit-info">Аванс: <span class="deposit-value">0</span> руб.</div>
            <div class="discount-info" style="display: none;">Скидка: <span class="discount-value">0</span> руб.</div>
        </div>
    </form>
</div>

<script>
    var gazeboId = <?= $gazebo['id'] ?>;
    var minHours = <?= $minHours ?>;
    var workEndHour = <?= $workEndHour ?>;

    $(document).ready(function() {
        $('#rental_type').change(function() {
            var rentalType = $(this).val();
            if (rentalType === 'hourly') {
                $('.hourly-fields').show();
                updateHoursSelectByStartHour();
                calculatePrice();
            } else {
                $('.hourly-fields').hide();
                if (rentalType !== '') calculatePrice();
            }
        });
        $('#start_hour').change(function() {
            updateHoursSelectByStartHour();
            calculatePrice();
            checkAvailability();
        });
        $('#hours').change(function() {
            calculatePrice();
            checkAvailability();
        });
        $('#apply_discount').click(function() {
            calculatePrice();
        });
        $('#discount_code').on('input', function() {
            calculatePrice();
        });

        function updateHoursSelectByStartHour() {
            var startHour = parseInt($('#start_hour').val());
            var $hoursSelect = $('#hours');
            if (!startHour) {
                $hoursSelect.html('<option value="">Сначала выберите время начала</option>').prop('disabled', true);
                return;
            }
            var maxPossibleHours = workEndHour - startHour;
            var options = '<option value="">Выберите продолжительность</option>';
            for (var i = minHours; i <= maxPossibleHours; i++) options += '<option value="' + i + '">' + i + ' час(ов)</option>';
            $hoursSelect.html(options).prop('disabled', false).val('');
        }

        function checkAvailability() {
            var rentalType = $('#rental_type').val(),
                date = $('#date').val(),
                startHour = $('#start_hour').val(),
                hours = $('#hours').val();
            if (!rentalType || !date) return;
            if (rentalType === 'hourly' && (!startHour || !hours)) {
                $('#price-preview').hide();
                return;
            }
            $.ajax({
                url: '/local/modules/avs_booking/ajax.php',
                method: 'POST',
                data: {
                    action: 'check_availability',
                    pavilion_id: gazeboId,
                    rental_type: rentalType,
                    date: date,
                    start_hour: startHour,
                    hours: hours
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        if (response.available) {
                            calculatePrice();
                            $('#price-preview').show();
                        } else {
                            $('#price-preview').hide();
                            alert('Выбранное время недоступно для бронирования');
                        }
                    } else if (response.error) {
                        $('#price-preview').hide();
                        console.error('Availability check error:', response.error);
                    }
                },
                error: function() {
                    console.error('AJAX error in checkAvailability');
                }
            });
        }

        function calculatePrice() {
            var rentalType = $('#rental_type').val(),
                date = $('#date').val(),
                hours = $('#hours').val(),
                discountCode = $('#discount_code').val();
            if (!rentalType || !date) return;
            if (rentalType === 'hourly' && !hours) return;
            $.ajax({
                url: '/local/modules/avs_booking/ajax.php',
                method: 'POST',
                data: {
                    action: 'get_price',
                    pavilion_id: gazeboId,
                    rental_type: rentalType,
                    date: date,
                    hours: hours,
                    discount_code: discountCode
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('.price-value').text(response.total_price.toLocaleString('ru-RU'));
                        $('.deposit-value').text(response.deposit_amount.toLocaleString('ru-RU'));
                        if (response.discount_amount > 0) {
                            $('.discount-value').text(response.discount_amount.toLocaleString('ru-RU'));
                            $('.discount-info').show();
                        } else $('.discount-info').hide();
                    } else if (response.error) {
                        $('#price-preview').hide();
                        alert(response.error);
                    }
                },
                error: function() {
                    console.error('AJAX error in calculatePrice');
                }
            });
        }

        $('#date').change(function() {
            var selectedDate = $(this).val();
            $.ajax({
                url: '/local/modules/avs_booking/ajax.php',
                method: 'POST',
                data: {
                    action: 'get_work_hours',
                    pavilion_id: gazeboId,
                    date: selectedDate
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        workEndHour = response.work_end_hour;
                        minHours = response.min_hours;
                        $('.work-hours-info').html('⏰ Время работы: 10:00 - ' + workEndHour + ':00<br>Минимальная продолжительность аренды: ' + minHours + ' часа');
                        if ($('#rental_type').val() === 'hourly' && $('#start_hour').val()) updateHoursSelectByStartHour();
                    }
                }
            });
            $.ajax({
                url: '/local/modules/avs_booking/ajax.php',
                method: 'POST',
                data: {
                    action: 'get_date_restrictions',
                    pavilion_id: gazeboId,
                    date: selectedDate
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data.is_special) {
                        var allowedTypes = response.data.allowed_types;
                        $('#rental_type option').each(function() {
                            var val = $(this).val();
                            if (val && allowedTypes.indexOf(val) === -1) $(this).hide();
                            else $(this).show();
                        });
                        if (response.data.description) {
                            $('.restriction-note').remove();
                            $('.work-hours-info').after('<div class="restriction-note">⚠️ ' + response.data.description + '</div>');
                        }
                    } else {
                        $('#rental_type option').show();
                        $('.restriction-note').remove();
                    }
                }
            });
        });

        var phoneInput = document.getElementById('client_phone');
        if (phoneInput) {
            phoneInput.addEventListener('input', function(e) {
                var x = e.target.value.replace(/\D/g, '').match(/(\d{0,1})(\d{0,3})(\d{0,3})(\d{0,2})(\d{0,2})/);
                if (x) e.target.value = '+7' + (x[2] ? '(' + x[2] + ')' : '') + (x[3] ? x[3] : '') + (x[4] ? '-' + x[4] : '') + (x[5] ? '-' + x[5] : '');
            });
            phoneInput.addEventListener('focus', function(e) {
                if (e.target.value === '') e.target.value = '+7(';
            });
        }
        if ($('#rental_type').val() === 'hourly') {
            $('.hourly-fields').show();
            if ($('#start_hour').val()) updateHoursSelectByStartHour();
        }
    });
</script>