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
        $('#synchro_test_page_setting', $form).toggle(is_test_import);
        $('#synchro_page_limit_setting', $form).toggle(!is_test_import);
        $('.synchro-portion-setting', $form).toggle(!is_test_import);
        $('.synchro-portion-input', $form).toggle(!is_test_import && uses_portions);
        $('#cron_script_period', $form).toggle(is_periodic);
        $('#cron_script_period_week_days_label', $form).toggleClass('cm-required', is_periodic);
    }

    $.ceEvent('on', 'ce.commoninit', function (context) {
        $('form[name="cron_script_form"]', context).each(function () {
            var $form = $(this);

            $form.off('change.synchroCronScriptManager')
                .on('change.synchroCronScriptManager', '#cron_script_script, #cron_script_run_mode, #synchro_is_test_import, #synchro_use_portions', function () {
                    syncForm($form);
                });
            syncForm($form);
        });
    });
}(Tygh, Tygh.$));
