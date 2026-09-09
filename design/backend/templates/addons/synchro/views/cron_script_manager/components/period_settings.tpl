{$period_day_mode = "daily"}
{if $script_data.period_month_days}
    {$period_day_mode = "month_days"}
{elseif $script_data.period_week_days}
    {$period_day_mode = "week_days"}
{/if}
{$period_time_mode = "once"}
{if $script_data.refresh_hours || $script_data.refresh_minutes}
    {$period_time_mode = "interval"}
{/if}

<div id="cron_script_period"{if $run_mode === "once"} class="hidden"{/if}>
    {include file="common/subheader.tpl" title=__("period")}
    <fieldset>
        <div class="control-group">
            <label class="control-label">{__("synchro.repeat_on")}</label>
            <div class="controls">
                <label class="radio inline">
                    <input type="radio" name="script_data[period_day_mode]" value="daily"{if $period_day_mode === "daily"} checked="checked"{/if}>
                    {__("synchro.every_day")}
                </label>
                <label class="radio inline">
                    <input type="radio" name="script_data[period_day_mode]" value="week_days"{if $period_day_mode === "week_days"} checked="checked"{/if}>
                    {__("synchro.week_days")}
                </label>
                <label class="radio inline">
                    <input type="radio" name="script_data[period_day_mode]" value="month_days"{if $period_day_mode === "month_days"} checked="checked"{/if}>
                    {__("synchro.month_days")}
                </label>
            </div>
        </div>

        <div id="synchro_period_week_days_setting" class="control-group">
            <label for="cron_script_period_week_days" id="cron_script_period_week_days_label" class="control-label">{__("synchro.week_days")}</label>
            <div class="controls">
                <select name="script_data[period_week_days][]" id="cron_script_period_week_days" class="input-large" multiple="multiple" size="7" style="max-width: 50%;">
                    {foreach from=$synchro_cron_manager->getSetElements("period_week_days") item="m"}
                        <option value="{$m}"{if $m|in_array:$script_data.period_week_days} selected="selected"{/if}>{__("synchro.{$m}")}</option>
                    {/foreach}
                </select>
            </div>
        </div>

        <div id="synchro_period_month_days_setting" class="control-group">
            <label for="cron_script_period_month_days" id="cron_script_period_month_days_label" class="control-label">{__("synchro.month_days")}</label>
            <div class="controls">
                <select name="script_data[period_month_days][]" id="cron_script_period_month_days" class="input-large" multiple="multiple" size="7" style="max-width: 50%;">
                    {foreach from=$synchro_cron_manager->getSetElements("period_month_days") item="m"}
                        <option value="{$m}"{if $m|in_array:$script_data.period_month_days} selected="selected"{/if}>{$m}</option>
                    {/foreach}
                </select>
            </div>
        </div>

        <div class="control-group">
            <label class="control-label">{__("synchro.run_time")}</label>
            <div class="controls">
                <label class="radio inline">
                    <input type="radio" name="script_data[period_time_mode]" value="once"{if $period_time_mode === "once"} checked="checked"{/if}>
                    {__("synchro.once_at")}
                </label>
                <label class="radio inline">
                    <input type="radio" name="script_data[period_time_mode]" value="interval"{if $period_time_mode === "interval"} checked="checked"{/if}>
                    {__("synchro.repeat_in_period")}
                </label>
            </div>
        </div>

        <div class="row-fluid">
            <div class="span6"><div class="control-group">
                <label for="cron_script_period_hours_begin" id="cron_script_period_hours_begin_label" class="control-label">{__("synchro.start_time")}</label>
                <div class="controls">
                    <select name="script_data[period_hours_begin]" id="cron_script_period_hours_begin" class="input-mini">
                        {foreach from=$synchro_cron_manager->getSetElements("period_hours_begin") item="m"}
                            <option value="{$m}"{if $script_data.period_hours_begin == $m} selected="selected"{/if}>{$m}</option>
                        {/foreach}
                    </select>
                    <span class="muted">:00</span>
                </div>
            </div></div>
            <div id="synchro_period_hours_end_setting" class="span6"><div class="control-group">
                <label for="cron_script_period_hours_end" id="cron_script_period_hours_end_label" class="control-label">{__("synchro.end_time")}</label>
                <div class="controls">
                    <select name="script_data[period_hours_end]" id="cron_script_period_hours_end" class="input-mini">
                        {foreach from=$synchro_cron_manager->getSetElements("period_hours_end") item="m"}
                            {if $m > 0}
                                <option value="{$m}"{if $script_data.period_hours_end == $m} selected="selected"{/if}>{$m}</option>
                            {/if}
                        {/foreach}
                    </select>
                    <span class="muted">:00</span>
                </div>
            </div></div>
        </div>

        <div id="synchro_period_refresh_setting" class="control-group">
            <label for="cron_script_refresh_hours" id="cron_script_refresh_label" class="control-label">{__("synchro.repeat_interval")}</label>
            <div class="controls">
                <select name="script_data[refresh_hours]" id="cron_script_refresh_hours" class="input-mini">
                    {foreach from=$synchro_cron_manager->getSetElements("refresh_hours") item="m"}
                        <option value="{$m}"{if $script_data.refresh_hours == $m} selected="selected"{/if}>{$m}</option>
                    {/foreach}
                </select>
                <span class="muted">{__("synchro.hours")}</span>
                <select name="script_data[refresh_minutes]" id="cron_script_refresh_minutes" class="input-mini">
                    {foreach from=$synchro_cron_manager->getSetElements("refresh_minutes") item="m"}
                        <option value="{$m}"{if $script_data.refresh_minutes == $m} selected="selected"{/if}>{$m}</option>
                    {/foreach}
                </select>
                <span class="muted">{__("synchro.minutes")}</span>
            </div>
        </div>
    </fieldset>
</div>
