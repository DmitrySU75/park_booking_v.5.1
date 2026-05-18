$(document).ready(function () {
    $('#rental_type').change(function () {
        var rentalType = $(this).val();
        if (rentalType === 'hourly') {
            $('.hourly-fields').show();
            updateHoursSelectByStartHour();
            calculatePrice();
        } else {
            $('.hourly-fields').hide();
            if (rentalType !== '') {
                calculatePrice();
            }
        }
    });

    $('#start_hour').change(function () {
        updateHoursSelectByStartHour();
        calculatePrice();
        checkAvailability();
    });

    $('#hours').change(function () {
        calculatePrice();
        checkAvailability();
    });

    $('#apply_discount').click(function () {
        calculatePrice();
    });

    $('#discount_code').on('input', function () {
        calculatePrice();
    });

    function updateHoursSelectByStartHour() {
        var startHour = parseInt($('#start_hour').val());
        var $hoursSelect = $('#hours');
        
        if (!startHour) {
            $hoursSelect.html('<option value="">Сначала выберите время начала</option>');
            $hoursSelect.prop('disabled', true);
            return;
        }
        
        var maxPossibleHours = workEndHour - startHour;
        var options = '<option value="">Выберите продолжительность</option>';
        
        for (var i = minHours; i <= maxPossibleHours; i++) {
            options += '<option value="' + i + '">' + i + ' час(ов)</option>';
        }
        
        $hoursSelect.html(options);
        $hoursSelect.prop('disabled', false);
        $hoursSelect.val('');
    }

    function checkAvailability() {
        var rentalType = $('#rental_type').val();
        var date = $('#date').val();
        var startHour = $('#start_hour').val();
        var hours = $('#hours').val();

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
            success: function (response) {
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
            error: function () {
                console.error('AJAX error in checkAvailability');
            }
        });
    }

    function calculatePrice() {
        var rentalType = $('#rental_type').val();
        var date = $('#date').val();
        var hours = $('#hours').val();
        var discountCode = $('#discount_code').val();

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
            success: function (response) {
                if (response.success) {
                    $('.price-value').text(response.total_price.toLocaleString('ru-RU'));
                    $('.deposit-value').text(response.deposit_amount.toLocaleString('ru-RU'));
                    if (response.discount_amount > 0) {
                        $('.discount-value').text(response.discount_amount.toLocaleString('ru-RU'));
                        $('.discount-info').show();
                    } else {
                        $('.discount-info').hide();
                    }
                } else if (response.error) {
                    $('#price-preview').hide();
                    alert(response.error);
                }
            },
            error: function () {
                console.error('AJAX error in calculatePrice');
            }
        });
    }

    $('#date').change(function () {
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
            success: function (response) {
                if (response.success) {
                    workEndHour = response.work_end_hour;
                    minHours = response.min_hours;
                    $('.work-hours-info').html('⏰ Время работы: 10:00 - ' + workEndHour + ':00<br>Минимальная продолжительность аренды: ' + minHours + ' часа');
                    if ($('#rental_type').val() === 'hourly' && $('#start_hour').val()) {
                        updateHoursSelectByStartHour();
                    }
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
            success: function (response) {
                if (response.success && response.data.is_special) {
                    var allowedTypes = response.data.allowed_types;
                    $('#rental_type option').each(function () {
                        var val = $(this).val();
                        if (val && allowedTypes.indexOf(val) === -1) {
                            $(this).hide();
                        } else {
                            $(this).show();
                        }
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
        phoneInput.addEventListener('input', function (e) {
            var x = e.target.value.replace(/\D/g, '').match(/(\d{0,1})(\d{0,3})(\d{0,3})(\d{0,2})(\d{0,2})/);
            if (x) {
                e.target.value = '+7' + (x[2] ? '(' + x[2] + ')' : '') +
                    (x[3] ? x[3] : '') + (x[4] ? '-' + x[4] : '') +
                    (x[5] ? '-' + x[5] : '');
            }
        });

        phoneInput.addEventListener('focus', function (e) {
            if (e.target.value === '') {
                e.target.value = '+7(';
            }
        });
    }
    
    if ($('#rental_type').val() === 'hourly') {
        $('.hourly-fields').show();
        if ($('#start_hour').val()) {
            updateHoursSelectByStartHour();
        }
    }
});