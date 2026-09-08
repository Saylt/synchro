{$synchro_cron_manager = $app['addons.synchro.cron_manager']}
{$run_mode = $script_data.run_mode|default:"periodic"}
{script src="js/addons/synchro/cron_script_manager.js"}

<form action="{""|fn_url}" method="post" name="cron_script_form" class="form-horizontal form-edit cm-disable-empty-files">
    <input type="hidden" name="script_id" value="{$script_data.script_id|default:0}">

    {include file="addons/synchro/views/cron_script_manager/components/information.tpl"}
    {include file="addons/synchro/views/cron_script_manager/components/product_import_settings.tpl"}
    {include file="addons/synchro/views/cron_script_manager/components/category_import_settings.tpl"}
    {include file="addons/synchro/views/cron_script_manager/components/period_settings.tpl"}

    <div class="buttons-container">
        {if $script_data}
            {assign var="but_text" value=__("save")}
        {else}
            {assign var="but_text" value=__("create")}
        {/if}
        {include file="buttons/save_cancel.tpl" but_text=$but_text but_name="dispatch[cron_script_manager.update]" cancel_action="close"}
    </div>
</form>
