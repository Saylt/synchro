(function (_, $) {
    function syncForm($form) {
        var script = $('#cron_script_script', $form).val();
        var is_product_import = script === 'synchro_import.products';
        var is_category_import = script === 'synchro_import.categories';
        var is_test_import = $('#synchro_is_test_import', $form).is(':checked');
        var uses_portions = $('#synchro_use_portions', $form).is(':checked');
        var is_periodic = $('#cron_script_run_mode', $form).val() === 'periodic';

        $('#synchro_product_import_settings', $form).toggle(is_product_import);
        $('#synchro_product_post_process', $form).prop('disabled', !is_product_import);
        $('#synchro_category_import_settings', $form).toggle(is_category_import);
        $('#synchro_category_post_process', $form).prop('disabled', !is_category_import);
        $('#synchro_entity_application_settings', $form).toggle(is_product_import || is_category_import);
        $('.synchro-product-application-label', $form).toggle(is_product_import);
        $('.synchro-category-application-label', $form).toggle(is_category_import);
        $('#synchro_application_parallel_setting', $form).toggle(!(is_product_import && is_test_import));
        $('#synchro_test_page_setting', $form).toggle(is_test_import);
        $('#synchro_page_limit_setting', $form).toggle(!is_test_import);
        $('.synchro-portion-setting', $form).toggle(!is_test_import);
        $('.synchro-portion-input', $form).toggle(!is_test_import && uses_portions);
        syncScheduleSettings($form, is_periodic);
    }

    function syncScheduleSettings($form, is_periodic) {
        var $period = $('#cron_script_period', $form);
        var day_mode = $('input[name="script_data[period_day_mode]"]:checked', $period).val() || 'daily';
        var time_mode = $('input[name="script_data[period_time_mode]"]:checked', $period).val() || 'once';
        var uses_week_days = day_mode === 'week_days';
        var uses_month_days = day_mode === 'month_days';
        var uses_interval = time_mode === 'interval';

        $period.toggle(is_periodic);
        $(':input', $period).prop('disabled', !is_periodic);
        $('#synchro_period_week_days_setting', $period).toggle(uses_week_days);
        $('#cron_script_period_week_days', $period).prop('disabled', !is_periodic || !uses_week_days);
        $('#cron_script_period_week_days_label', $period).toggleClass('cm-required', is_periodic && uses_week_days);
        $('#synchro_period_month_days_setting', $period).toggle(uses_month_days);
        $('#cron_script_period_month_days', $period).prop('disabled', !is_periodic || !uses_month_days);
        $('#cron_script_period_month_days_label', $period).toggleClass('cm-required', is_periodic && uses_month_days);
        $('#synchro_period_hours_end_setting, #synchro_period_refresh_setting', $period).toggle(uses_interval);
        $('#cron_script_period_hours_end, #cron_script_refresh_hours, #cron_script_refresh_minutes', $period)
            .prop('disabled', !is_periodic || !uses_interval);
        syncIntervalSettings($period, is_periodic && uses_interval);
        $('#cron_script_period_hours_begin_label', $period).toggleClass('cm-required', is_periodic);
        $('#cron_script_period_hours_end_label, #cron_script_refresh_label', $period)
            .toggleClass('cm-required', is_periodic && uses_interval);
    }

    function syncIntervalSettings($period, uses_interval) {
        var $end = $('#cron_script_period_hours_end', $period);
        var start_hour = parseInt($('#cron_script_period_hours_begin', $period).val(), 10);

        $end.find('option').each(function () {
            $(this).prop('disabled', parseInt(this.value, 10) <= start_hour);
        });
        if (uses_interval && $end.find('option:selected').prop('disabled')) {
            $end.val($end.find('option:not(:disabled)').first().val());
        }
        if (
            uses_interval
            && $('#cron_script_refresh_hours', $period).val() === '0'
            && $('#cron_script_refresh_minutes', $period).val() === '0'
        ) {
            $('#cron_script_refresh_minutes', $period).val('1');
        }
    }

    $.ceEvent('on', 'ce.commoninit', function (context) {
        $('form[name="cron_script_form"]', context).each(function () {
            var $form = $(this);

            $form.off('change.synchroCronScriptManager')
                .on('change.synchroCronScriptManager', [
                    '#cron_script_script',
                    '#cron_script_run_mode',
                    '#synchro_is_test_import',
                    '#synchro_use_portions',
                    '#cron_script_period_hours_begin',
                    'input[name="script_data[period_day_mode]"]',
                    'input[name="script_data[period_time_mode]"]'
                ].join(', '), function () {
                    syncForm($form);
                });
            syncForm($form);
        });
    });
}(Tygh, Tygh.$));
