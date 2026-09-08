<div id="synchro_product_import_settings"{if $script_data.script !== "synchro_import.products"} class="hidden"{/if}>
    {include file="common/subheader.tpl" title=__("synchro.product_import_settings")}
    <fieldset>
        <div class="control-group synchro-portion-setting{if $script_data.is_test_import === "Y"} hidden{/if}">
            <label for="synchro_use_portions" class="control-label">{__("synchro.use_portions")}</label>
            <div class="controls">
                <input type="hidden" name="script_data[use_portions]" value="N">
                <input type="checkbox" id="synchro_use_portions" name="script_data[use_portions]" value="Y"{if $script_data.use_portions === "Y"} checked="checked"{/if}>
                <p class="muted description">{__("synchro.use_portions_description")}</p>
            </div>
        </div>
        <div class="control-group synchro-portion-input{if $script_data.use_portions !== "Y" || $script_data.is_test_import === "Y"} hidden{/if}">
            <label for="synchro_pages_per_portion" class="control-label">{__("synchro.pages_per_portion")}</label>
            <div class="controls"><input type="number" min="1" id="synchro_pages_per_portion" name="script_data[pages_per_portion]" value="{$script_data.pages_per_portion|default:100}" class="input-small"></div>
        </div>
        <div id="synchro_page_limit_setting" class="control-group{if $script_data.is_test_import === "Y"} hidden{/if}">
            <label for="synchro_page_limit" class="control-label">{__("synchro.page_limit")}</label>
            <div class="controls"><input type="number" min="1" id="synchro_page_limit" name="script_data[page_limit]" value="{$script_data.page_limit|default:200}" class="input-small"></div>
        </div>
        <div class="control-group synchro-portion-input{if $script_data.use_portions !== "Y" || $script_data.is_test_import === "Y"} hidden{/if}">
            <label for="synchro_max_parallel_processes" class="control-label">{__("synchro.max_parallel_processes")}</label>
            <div class="controls"><input type="number" min="1" id="synchro_max_parallel_processes" name="script_data[max_parallel_processes]" value="{$script_data.max_parallel_processes|default:3}" class="input-small"></div>
        </div>
        <div class="control-group">
            <label for="synchro_is_test_import" class="control-label">{__("synchro.test_import")}</label>
            <div class="controls">
                <input type="hidden" name="script_data[is_test_import]" value="N">
                <input type="checkbox" id="synchro_is_test_import" name="script_data[is_test_import]" value="Y"{if $script_data.is_test_import === "Y"} checked="checked"{/if}>
                <p class="muted description">{__("synchro.test_import_description")}</p>
            </div>
        </div>
        <div id="synchro_test_page_setting" class="control-group{if $script_data.is_test_import !== "Y"} hidden{/if}">
            <label for="synchro_test_page" class="control-label">{__("synchro.test_page")}</label>
            <div class="controls"><input type="number" min="1" id="synchro_test_page" name="script_data[test_page]" value="{$script_data.test_page|default:1}" class="input-small"></div>
        </div>
        <div class="control-group">
            <label for="synchro_product_post_process" class="control-label">{__("synchro.after_finish")}</label>
            <div class="controls">
                <select id="synchro_product_post_process" name="script_data[post_process]" class="input-large"{if $script_data.script !== "synchro_import.products"} disabled="disabled"{/if}>
                    <option value="">{__("none")}</option>
                    <option value="synchro_import.apply_products"{if $script_data.post_process === "synchro_import.apply_products"} selected="selected"{/if}>{__("synchro.apply_products")}</option>
                    <option value="synchro_import.actualize_products"{if $script_data.post_process === "synchro_import.actualize_products"} selected="selected"{/if}>{__("synchro.actualize_products")}</option>
                </select>
                <p class="muted description">{__("synchro.after_finish_description")}</p>
            </div>
        </div>
    </fieldset>
</div>
