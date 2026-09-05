<div class="sidebar-row">
    <h6>{__("admin_search_title")}</h6>

    <form
        name="cron_script_manager_search_form"
        action="{""|fn_url}"
        method="get"
        class="cm-disable-empty-all"
    >
        {if $smarty.request.redirect_url}
            <input type="hidden" name="redirect_url" value="{$smarty.request.redirect_url}" />
        {/if}

        {capture name="simple_search"}
            <div class="sidebar-field">
                <label for="elm_script">{__("synchro.task")}</label>
                <select name="script" id="elm_script">
                    <option value="">--</option>
                    {foreach $synchro_cron_manager->getAvailableScripts() as $dispatch => $task}
                        <option value="{$dispatch}"{if $search.script === $dispatch} selected="selected"{/if}>{__($task.name)}</option>
                    {/foreach}
                </select>
            </div>

            <div class="sidebar-field">
                <label for="elm_run_mode">{__("synchro.run_mode")}</label>
                <select name="run_mode" id="elm_run_mode">
                    <option value="">--</option>
                    <option value="periodic"{if $search.run_mode === "periodic"} selected="selected"{/if}>{__("synchro.periodic")}</option>
                    <option value="once"{if $search.run_mode === "once"} selected="selected"{/if}>{__("synchro.once")}</option>
                </select>
            </div>

            <div class="sidebar-field">
                <label for="elm_status">{__("status")}</label>
                <select name="status" id="elm_status">
                    <option value="">--</option>
                    <option value="A"{if $search.status === "A"} selected="selected"{/if}>{__("active")}</option>
                    <option value="D"{if $search.status === "D"} selected="selected"{/if}>{__("disabled")}</option>
                </select>
            </div>

            <div class="sidebar-field">
                <label for="elm_inner_status">{__("synchro.inner_status")}</label>
                <select name="inner_status" id="elm_inner_status">
                    <option value="">--</option>
                    {foreach from=$synchro_cron_manager->getSetElements("inner_status", "cron_scripts") item="inner_status"}
                        <option value="{$inner_status}"{if $search.inner_status == $inner_status} selected="selected"{/if}>{__("synchro.{$inner_status}")}</option>
                    {/foreach}
                </select>
            </div>
        {/capture}

        {capture name="advanced_search"}
            <div class="group form-horizontal">
                <div class="control-group">
                    <label class="control-label">{__("created")}</label>
                    <div class="controls">
                        {include
                            file="common/period_selector.tpl"
                            period=$search.period
                            form_name="cron_script_manager_search_form"
                        }
                    </div>
                </div>

                <div class="control-group">
                    <label class="control-label">{__("synchro.last_launch")}</label>
                    <div class="controls">
                        {include
                            file="common/period_selector.tpl"
                            period=$search.launch_period
                            prefix="launch_"
                            form_name="cron_script_manager_search_form"
                        }
                    </div>
                </div>

                <div class="control-group">
                    <span class="control-label">{__("synchro.week_days")}</span>
                    <div class="controls checkbox-list">
                        {html_checkboxes
                            name="period_week_days"
                            options=$synchro_cron_manager->getSetElements("period_week_days", "cron_scripts", true)
                            selected=$search.period_week_days
                            columns=4
                        }
                    </div>
                </div>
            </div>

            <div class="group form-horizontal">
                <div class="control-group">
                    <label for="elm_period_hours_begin" class="control-label">{__("synchro.task_time")}</label>
                    <div class="controls nowrap">
                        <select name="period_hours_begin" id="elm_period_hours_begin" class="input-mini">
                            <option value="">--</option>
                            {foreach from=$synchro_cron_manager->getSetElements("period_hours_begin", "cron_scripts") item="m"}
                                <option value="{$m}"{if $search.period_hours_begin == $m} selected="selected"{/if}>{$m}</option>
                            {/foreach}
                        </select>
                        <span class="muted">:00&nbsp;&ndash;&nbsp;</span>
                        <select name="period_hours_end" id="elm_period_hours_end" class="input-mini">
                            <option value="">--</option>
                            {foreach from=$synchro_cron_manager->getSetElements("period_hours_end", "cron_scripts") item="m"}
                                <option value="{$m}"{if $search.period_hours_end == $m} selected="selected"{/if}>{$m}</option>
                            {/foreach}
                        </select>
                        <span class="muted">:00</span>
                    </div>
                </div>

                <div class="control-group">
                    <label for="elm_refresh_hours" class="control-label">{__("synchro.refresh_time")}</label>
                    <div class="controls nowrap">
                        <select name="refresh_hours" id="elm_refresh_hours" class="input-mini">
                            <option value="">--</option>
                            {foreach from=$synchro_cron_manager->getSetElements("refresh_hours", "cron_scripts") item="m"}
                                <option value="{$m}"{if $search.refresh_hours == $m} selected="selected"{/if}>{$m}</option>
                            {/foreach}
                        </select>
                        <span class="muted">{__("synchro.hours")}</span>
                        <select name="refresh_minutes" id="elm_refresh_minutes" class="input-mini">
                            <option value="">--</option>
                            {foreach from=$synchro_cron_manager->getSetElements("refresh_minutes", "cron_scripts") item="m"}
                                <option value="{$m}"{if $search.refresh_minutes == $m} selected="selected"{/if}>{$m}</option>
                            {/foreach}
                        </select>
                        <span class="muted">{__("synchro.minutes")}</span>
                    </div>
                </div>
            </div>
        {/capture}

        {include
            file="common/advanced_search.tpl"
            advanced_search=$smarty.capture.advanced_search
            simple_search=$smarty.capture.simple_search
            dispatch=$dispatch
            view_type="cron_script_manager"
        }
    </form>
</div>
