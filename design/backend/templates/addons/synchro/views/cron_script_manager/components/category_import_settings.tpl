<div id="synchro_category_import_settings"{if $script_data.script !== "synchro_import.categories"} class="hidden"{/if}>
    <fieldset>
        <div class="control-group">
            <label for="synchro_category_post_process" class="control-label">{__("synchro.after_finish")}</label>
            <div class="controls">
                <select id="synchro_category_post_process" name="script_data[post_process]" class="input-large"{if $script_data.script !== "synchro_import.categories"} disabled="disabled"{/if}>
                    <option value="">{__("none")}</option>
                    <option value="synchro_import.apply_categories"{if $script_data.post_process === "synchro_import.apply_categories"} selected="selected"{/if}>{__("synchro.apply_categories")}</option>
                </select>
                <p class="muted description">{__("synchro.after_finish_description")}</p>
            </div>
        </div>
    </fieldset>
</div>
