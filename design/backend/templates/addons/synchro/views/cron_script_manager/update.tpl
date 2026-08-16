{$synchro_cron_manager = $app['addons.synchro.cron_manager']}

<form action="{""|fn_url}"
    method="post"
    name="cron_script_form"
    class="form-horizontal form-edit cm-disable-empty-files"
>
    <input type="hidden" name="script_id" value="{$script_data.script_id|default:0}">

    {include file="common/subheader.tpl" title=__("information")}

    <fieldset>
        <div class="control-group">
            <label for="cron_script_script" class="control-label cm-required">{__("script")}</label>
            <div class="controls">
                <input
                    type="text"
                    name="script_data[script]"
                    id="cron_script_script"
                    value="{$script_data.script}"
                    class="span9 main-input"
                />
            </div>
        </div>

        <div class="control-group">
            <label for="cron_script_script_type" class="control-label">
                {__("synchro.run_type")}
                {include
                    file="common/tooltip.tpl"
                    tooltip=__("synchro.cron_script_type_tooltip")|nl2br
                }
            </label>
            <div class="controls">
                <select name="script_data[script_type]" id="cron_script_script_type" class="input-large">
                    {foreach from=$synchro_cron_manager->getSetElements("script_type", "cron_scripts") item="m"}
                        <option value="{$m}"{if $script_data.script_type == $m} selected="selected"{/if}>{__("synchro.{$m}")}</option>
                    {/foreach}
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
                    <label for="cron_script_period_week_days" class="control-label cm-required">{__("synchro.week_days")}</label>
                    <div class="controls">
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
