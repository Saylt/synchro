<div class="sidebar-row">
    <h6>{__("admin_search_title")}</h6>

    <form
        name="synchro_logs_search_form"
        action="{""|fn_url}"
        method="get"
        class="cm-disable-empty-all"
    >
        {capture name="simple_search"}
            <div class="sidebar-field">
                <label for="elm_log_level">{__("synchro.log_level")}</label>
                <select name="level" id="elm_log_level">
                    <option value="">--</option>
                    {foreach $log_levels as $level}
                        {$level_name = "synchro.log_level_"|cat:$level}
                        <option value="{$level}"{if $search.level === $level} selected="selected"{/if}>
                            {__($level_name)}
                        </option>
                    {/foreach}
                </select>
            </div>

            <div class="sidebar-field">
                <label for="elm_log_source">{__("synchro.log_source")}</label>
                <input type="text" name="source" id="elm_log_source" value="{$search.source|default:""|escape}" />
            </div>
        {/capture}

        {capture name="advanced_search"}
            <div class="group form-horizontal">
                <div class="control-group">
                    <label class="control-label">{__("date")}</label>
                    <div class="controls">
                        {include
                            file="common/period_selector.tpl"
                            period=$search.period
                            form_name="synchro_logs_search_form"
                        }
                    </div>
                </div>
            </div>
        {/capture}

        {include
            file="common/advanced_search.tpl"
            advanced_search=$smarty.capture.advanced_search
            simple_search=$smarty.capture.simple_search
            dispatch="synchro_logs.manage"
            view_type="synchro_logs"
        }
    </form>
</div>
