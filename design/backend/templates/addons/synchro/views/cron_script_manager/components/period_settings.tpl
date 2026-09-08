<div id="cron_script_period"{if $run_mode === "once"} class="hidden"{/if}>
    {include file="common/subheader.tpl" title=__("period")}
    <fieldset>
        <div class="row-fluid">
            <div class="span6"><div class="control-group">
                <label for="cron_script_period_month_days" class="control-label">{__("synchro.month_days")}</label>
                <div class="controls">
                    <input type="hidden" name="script_data[period_month_days]" value="">
                    <select name="script_data[period_month_days][]" id="cron_script_period_month_days" class="input-large" multiple="multiple" size="7" style="max-width: 50%;">
                        {foreach from=$synchro_cron_manager->getSetElements("period_month_days", "cron_scripts") item="m"}
                            <option value="{$m}"{if $m|in_array:$script_data.period_month_days} selected="selected"{/if}>{$m}</option>
                        {/foreach}
                    </select>
                </div>
            </div></div>
            <div class="span6"><div class="control-group">
                <label for="cron_script_period_week_days" id="cron_script_period_week_days_label" class="control-label{if $run_mode === "periodic"}{/if}">{__("synchro.week_days")}</label>
                <div class="controls">
                    <input type="hidden" name="script_data[period_week_days]" value="">
                    <select name="script_data[period_week_days][]" id="cron_script_period_week_days" class="input-large" multiple="multiple" size="7" style="max-width: 50%;">
                        {foreach from=$synchro_cron_manager->getSetElements("period_week_days", "cron_scripts") item="m"}
                            <option value="{$m}"{if $m|in_array:$script_data.period_week_days} selected="selected"{/if}>{__("synchro.{$m}")}</option>
                        {/foreach}
                    </select>
                </div>
            </div></div>
        </div>
        <div class="row-fluid">
            <div class="span6"><div class="control-group">
                <label for="cron_script_period_hours_begin" class="control-label">{__("synchro.task_time")}</label>
                <div class="controls">
                    <select name="script_data[period_hours_begin]" id="cron_script_period_hours_begin" class="input-mini">
                        {foreach from=$synchro_cron_manager->getSetElements("period_hours_begin", "cron_scripts") item="m"}
                            <option value="{$m}"{if $script_data.period_hours_begin == $m} selected="selected"{/if}>{$m}</option>
                        {/foreach}
                    </select>
                    <span class="muted">:00&nbsp;&ndash;&nbsp;</span>
                    <select name="script_data[period_hours_end]" id="cron_script_period_hours_end" class="input-mini">
                        {foreach from=$synchro_cron_manager->getSetElements("period_hours_end", "cron_scripts") item="m"}
                            <option value="{$m}"{if $script_data.period_hours_end == $m} selected="selected"{/if}>{$m}</option>
                        {/foreach}
                    </select>
                    <span class="muted">:00</span>
                </div>
            </div></div>
            <div class="span6"><div class="control-group">
                <label for="cron_script_refresh_hours" class="control-label">{__("synchro.refresh_time")}</label>
                <div class="controls">
                    <select name="script_data[refresh_hours]" id="cron_script_refresh_hours" class="input-mini">
                        {foreach from=$synchro_cron_manager->getSetElements("refresh_hours", "cron_scripts") item="m"}
                            <option value="{$m}"{if $script_data.refresh_hours == $m} selected="selected"{/if}>{$m}</option>
                        {/foreach}
                    </select>
                    <span class="muted">{__("synchro.hours")}</span>
                    <select name="script_data[refresh_minutes]" id="cron_script_refresh_minutes" class="input-mini">
                        {foreach from=$synchro_cron_manager->getSetElements("refresh_minutes", "cron_scripts") item="m"}
                            <option value="{$m}"{if $script_data.refresh_minutes == $m} selected="selected"{/if}>{$m}</option>
                        {/foreach}
                    </select>
                    <span class="muted">{__("synchro.minutes")}</span>
                </div>
            </div></div>
        </div>
    </fieldset>
</div>
