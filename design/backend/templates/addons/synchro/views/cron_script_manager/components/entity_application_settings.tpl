<div id="synchro_entity_application_settings"{if $script_data.script !== "synchro_import.products" && $script_data.script !== "synchro_import.categories"} class="hidden"{/if}>
    {include file="common/subheader.tpl" title=__("synchro.application_settings")}
    <fieldset>
        <div class="control-group">
            <label for="synchro_entities_per_portion" class="control-label">
                <span class="synchro-product-application-label{if $script_data.script !== "synchro_import.products"} hidden{/if}">{__("synchro.products_per_application_portion")}</span>
                <span class="synchro-category-application-label{if $script_data.script !== "synchro_import.categories"} hidden{/if}">{__("synchro.categories_per_application_portion")}</span>
            </label>
            <div class="controls">
                <input type="number" min="1" id="synchro_entities_per_portion" name="script_data[entities_per_portion]" value="{$script_data.entities_per_portion|default:30}" class="input-small">
                <p class="muted description">
                    <span class="synchro-product-application-label{if $script_data.script !== "synchro_import.products"} hidden{/if}">{__("synchro.products_per_application_portion_description")}</span>
                    <span class="synchro-category-application-label{if $script_data.script !== "synchro_import.categories"} hidden{/if}">{__("synchro.categories_per_application_portion_description")}</span>
                </p>
            </div>
        </div>
        <div id="synchro_application_parallel_setting" class="control-group{if $script_data.script === "synchro_import.products" && $script_data.is_test_import === "Y"} hidden{/if}">
            <label for="synchro_max_parallel_processes" class="control-label">{__("synchro.max_parallel_processes")}</label>
            <div class="controls">
                <input type="number" min="1" id="synchro_max_parallel_processes" name="script_data[max_parallel_processes]" value="{$script_data.max_parallel_processes|default:3}" class="input-small">
            </div>
        </div>
    </fieldset>
</div>
