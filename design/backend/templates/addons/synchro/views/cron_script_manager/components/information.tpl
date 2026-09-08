{include file="common/subheader.tpl" title=__("information")}

<fieldset>
    <div class="control-group">
        <label for="cron_script_script" class="control-label cm-required">{__("synchro.task")}</label>
        <div class="controls">
            <select name="script_data[script]" id="cron_script_script" class="span9 main-input">
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
            <select name="script_data[run_mode]" id="cron_script_run_mode" class="input-large">
                <option value="periodic"{if $run_mode === "periodic"} selected="selected"{/if}>{__("synchro.periodic")}</option>
                <option value="once"{if $run_mode === "once"} selected="selected"{/if}>{__("synchro.once")}</option>
            </select>
        </div>
    </div>

    <div class="control-group">
        <label for="cron_script_description" class="control-label">{__("description")}</label>
        <div class="controls">
            <textarea id="cron_script_description" name="script_data[description]" rows="4" class="span9">{$script_data.description}</textarea>
        </div>
    </div>

    {include file="common/select_status.tpl" input_name="script_data[status]" id="cron_script_status" obj=$script_data}
    {include file="addons/synchro/views/cron_script_manager/components/script_details.tpl"}
</fieldset>
