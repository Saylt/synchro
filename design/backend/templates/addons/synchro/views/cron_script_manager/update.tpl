{$synchro_cron_manager = $app['addons.synchro.cron_manager']}
{$run_mode = $script_data.run_mode|default:"periodic"}

<form action="{""|fn_url}"
    method="post"
    name="cron_script_form"
    class="form-horizontal form-edit cm-disable-empty-files"
>
    <input type="hidden" name="script_id" value="{$script_data.script_id|default:0}">

    {include file="common/subheader.tpl" title=__("information")}

    <fieldset>
        <div class="control-group">
            <label for="cron_script_script" class="control-label cm-required">{__("synchro.task")}</label>
            <div class="controls">
                <select
                    name="script_data[script]"
                    id="cron_script_script"
                    class="span9 main-input"
                    onchange="Tygh.$('#synchro_product_import_settings').toggle(this.value === 'synchro_import.products');"
                >
                    <option value="">--</option>
                    {foreach $synchro_cron_manager->getAvailableScripts() as $dispatch => $task}
                        {if empty($task.hidden) || $script_data.script === $dispatch}
                            <option value="{$dispatch}"{if $script_data.script === $dispatch} selected="selected"{/if}>{__($task.name)}</option>
                        {/if}
                    {/foreach}
                </select>
            </div>
        </div>

        <div class="control-group">
            <label for="cron_script_run_mode" class="control-label">{__("synchro.run_mode")}</label>
            <div class="controls">
                <select
                    name="script_data[run_mode]"
                    id="cron_script_run_mode"
                    class="input-large"
                    onchange="Tygh.$('#cron_script_period').toggle(this.value === 'periodic'); Tygh.$('#cron_script_period_week_days_label').toggleClass('cm-required', this.value === 'periodic');"
                >
                    <option value="periodic"{if $run_mode === "periodic"} selected="selected"{/if}>{__("synchro.periodic")}</option>
                    <option value="once"{if $run_mode === "once"} selected="selected"{/if}>{__("synchro.once")}</option>
                </select>
            </div>
        </div>

        <div class="control-group">
            <label for="cron_script_description" class="control-label">{__("description")}</label>
            <div class="controls">
                <textarea
                    id="cron_script_description"
                    name="script_data[description]"
                    rows="4"
                    class="span9"
                >{$script_data.description}</textarea>
            </div>
        </div>

        {include
            file="common/select_status.tpl"
            input_name="script_data[status]"
            id="cron_script_status"
            obj=$script_data
        }

        {if $script_data}
            <div class="control-group">
                <span class="control-label">{__("synchro.inner_status")}</span>
                <div class="controls">
                    <p>{__("synchro.{$script_data.inner_status}")}</p>
                </div>
            </div>

            <div class="control-group">
                <span class="control-label">{__("synchro.progress_status")}</span>
                <div class="controls">
                    <p>{$script_data.progress_status|default:"—"}</p>
                </div>
            </div>

            <div class="control-group">
                <span class="control-label">{__("created")}</span>
                <div class="controls">
                    <p>{$script_data.created|date_format:"`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}</p>
                </div>
            </div>

            <div class="control-group">
                <span class="control-label">{__("synchro.last_launch")}</span>
                <div class="controls">
                    <p>
                        {if $script_data.last_launch}
                            {$script_data.last_launch|date_format:"`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}
                        {else}
                            {__("never")}
                        {/if}
                    </p>
                </div>
            </div>
        {/if}
    </fieldset>

    <div
        id="synchro_product_import_settings"
        {if $script_data.script !== "synchro_import.products"}class="hidden"{/if}
    >
        {include file="common/subheader.tpl" title=__("synchro.product_import_settings")}

        <fieldset>
            <div class="control-group">
                <label for="synchro_use_portions" class="control-label">{__("synchro.use_portions")}</label>
                <div class="controls">
                    <input type="hidden" name="script_data[use_portions]" value="N">
                    <input
                        type="checkbox"
                        id="synchro_use_portions"
                        name="script_data[use_portions]"
                        value="Y"
                        {if $script_data.use_portions === "Y"}checked="checked"{/if}
                        onchange="Tygh.$('.synchro-portion-setting').toggle(this.checked);"
                    >
                    <p class="muted description">{__("synchro.use_portions_description")}</p>
                </div>
            </div>

            <div class="control-group synchro-portion-setting{if $script_data.use_portions !== "Y"} hidden{/if}">
                <label for="synchro_pages_per_portion" class="control-label">{__("synchro.pages_per_portion")}</label>
                <div class="controls">
                    <input
                        type="number"
                        min="1"
                        id="synchro_pages_per_portion"
                        name="script_data[pages_per_portion]"
                        value="{$script_data.pages_per_portion|default:100}"
                        class="input-small"
                    >
                </div>
            </div>

            <div
                id="synchro_page_limit_setting"
                class="control-group{if $script_data.is_test_import === "Y"} hidden{/if}"
            >
                <label for="synchro_page_limit" class="control-label">{__("synchro.page_limit")}</label>
                <div class="controls">
                    <input
                        type="number"
                        min="1"
                        id="synchro_page_limit"
                        name="script_data[page_limit]"
                        value="{$script_data.page_limit|default:200}"
                        class="input-small"
                    >
                </div>
            </div>

            <div class="control-group synchro-portion-setting{if $script_data.use_portions !== "Y"} hidden{/if}">
                <label for="synchro_max_parallel_processes" class="control-label">
                    {__("synchro.max_parallel_processes")}
                </label>
                <div class="controls">
                    <input
                        type="number"
                        min="1"
                        id="synchro_max_parallel_processes"
                        name="script_data[max_parallel_processes]"
                        value="{$script_data.max_parallel_processes|default:3}"
                        class="input-small"
                    >
                </div>
            </div>

            <div class="control-group">
                <label for="synchro_is_test_import" class="control-label">{__("synchro.test_import")}</label>
                <div class="controls">
                    <input type="hidden" name="script_data[is_test_import]" value="N">
                    <input
                        type="checkbox"
                        id="synchro_is_test_import"
                        name="script_data[is_test_import]"
                        value="Y"
                        {if $script_data.is_test_import === "Y"}checked="checked"{/if}
                        onchange="Tygh.$('#synchro_test_page_setting').toggle(this.checked); Tygh.$('#synchro_page_limit_setting').toggle(!this.checked);"
                    >
                    <p class="muted description">{__("synchro.test_import_description")}</p>
                </div>
            </div>

            <div
                id="synchro_test_page_setting"
                class="control-group{if $script_data.is_test_import !== "Y"} hidden{/if}"
            >
                <label for="synchro_test_page" class="control-label">{__("synchro.test_page")}</label>
                <div class="controls">
                    <input
                        type="number"
                        min="1"
                        id="synchro_test_page"
                        name="script_data[test_page]"
                        value="{$script_data.test_page|default:1}"
                        class="input-small"
                    >
                </div>
            </div>

            <div class="control-group">
                <label for="synchro_post_process" class="control-label">{__("synchro.after_finish")}</label>
                <div class="controls">
                    <select
                        id="synchro_post_process"
                        name="script_data[post_process]"
                        class="input-large"
                    >
                        <option value="">{__("none")}</option>
                        <option
                            value="synchro_import.apply_products"
                            {if $script_data.post_process === "synchro_import.apply_products"}selected="selected"{/if}
                        >{__("synchro.apply_products")}</option>
                        <option
                            value="synchro_import.actualize_products"
                            {if $script_data.post_process === "synchro_import.actualize_products"}selected="selected"{/if}
                        >{__("synchro.actualize_products")}</option>
                    </select>
                    <p class="muted description">{__("synchro.after_finish_description")}</p>
                </div>
            </div>
        </fieldset>
    </div>

    <div id="cron_script_period"{if $run_mode === "once"} class="hidden"{/if}>
        {include file="common/subheader.tpl" title=__("period")}

        <fieldset>
        <div class="row-fluid">
            <div class="span6">
                <div class="control-group">
                    <label for="cron_script_period_month_days" class="control-label">{__("synchro.month_days")}</label>
                    <div class="controls">
                        <input type="hidden" name="script_data[period_month_days]" value="">
                        <select
                            name="script_data[period_month_days][]"
                            id="cron_script_period_month_days"
                            class="input-large"
                            multiple="multiple"
                            size="7"
                            style="max-width: 50%;"
                        >
                            {foreach from=$synchro_cron_manager->getSetElements("period_month_days", "cron_scripts") item="m"}
                                <option value="{$m}"{if $m|in_array:$script_data.period_month_days} selected="selected"{/if}>{$m}</option>
                            {/foreach}
                        </select>
                    </div>
                </div>
            </div>

            <div class="span6">
                <div class="control-group">
                    <label
                        for="cron_script_period_week_days"
                        id="cron_script_period_week_days_label"
                        class="control-label{if $run_mode === "periodic"}{/if}"
                    >{__("synchro.week_days")}</label>
                    <div class="controls">
                        <input type="hidden" name="script_data[period_week_days]" value="">
                        <select
                            name="script_data[period_week_days][]"
                            id="cron_script_period_week_days"
                            class="input-large"
                            multiple="multiple"
                            size="7"
                            style="max-width: 50%;"
                        >
                            {foreach from=$synchro_cron_manager->getSetElements("period_week_days", "cron_scripts") item="m"}
                                <option value="{$m}"{if $m|in_array:$script_data.period_week_days} selected="selected"{/if}>{__("synchro.{$m}")}</option>
                            {/foreach}
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="row-fluid">
            <div class="span6">
                <div class="control-group">
                    <label for="cron_script_period_hours_begin" class="control-label">{__("synchro.task_time")}</label>
                    <div class="controls">
                        <select
                            name="script_data[period_hours_begin]"
                            id="cron_script_period_hours_begin"
                            class="input-mini"
                        >
                            {foreach from=$synchro_cron_manager->getSetElements("period_hours_begin", "cron_scripts") item="m"}
                                <option value="{$m}"{if $script_data.period_hours_begin == $m} selected="selected"{/if}>{$m}</option>
                            {/foreach}
                        </select>
                        <span class="muted">:00&nbsp;&ndash;&nbsp;</span>
                        <select
                            name="script_data[period_hours_end]"
                            id="cron_script_period_hours_end"
                            class="input-mini"
                        >
                            {foreach from=$synchro_cron_manager->getSetElements("period_hours_end", "cron_scripts") item="m"}
                                <option value="{$m}"{if $script_data.period_hours_end == $m} selected="selected"{/if}>{$m}</option>
                            {/foreach}
                        </select>
                        <span class="muted">:00</span>
                    </div>
                </div>
            </div>

            <div class="span6">
                <div class="control-group">
                    <label for="cron_script_refresh_hours" class="control-label">{__("synchro.refresh_time")}</label>
                    <div class="controls">
                        <select
                            name="script_data[refresh_hours]"
                            id="cron_script_refresh_hours"
                            class="input-mini"
                        >
                            {foreach from=$synchro_cron_manager->getSetElements("refresh_hours", "cron_scripts") item="m"}
                                <option value="{$m}"{if $script_data.refresh_hours == $m} selected="selected"{/if}>{$m}</option>
                            {/foreach}
                        </select>
                        <span class="muted">{__("synchro.hours")}</span>
                        <select
                            name="script_data[refresh_minutes]"
                            id="cron_script_refresh_minutes"
                            class="input-mini"
                        >
                            {foreach from=$synchro_cron_manager->getSetElements("refresh_minutes", "cron_scripts") item="m"}
                                <option value="{$m}"{if $script_data.refresh_minutes == $m} selected="selected"{/if}>{$m}</option>
                            {/foreach}
                        </select>
                        <span class="muted">{__("synchro.minutes")}</span>
                    </div>
                </div>
            </div>
        </div>
        </fieldset>
    </div>

    <div class="buttons-container">
        {if $script_data}
            {assign var="but_text" value=__("save")}
        {else}
            {assign var="but_text" value=__("create")}
        {/if}
        {include
            file="buttons/save_cancel.tpl"
            but_text=$but_text
            but_name="dispatch[cron_script_manager.update]"
            cancel_action="close"
        }
    </div>
</form>
