<div id="synchro_manufacturer_import_settings"{if $script_data.script !== "synchro_import.manufacturers"} class="hidden"{/if}>
    <fieldset>
        <div class="control-group">
            <label for="synchro_manufacturer_post_process" class="control-label">{__("synchro.after_finish")}</label>
            <div class="controls">
                <select id="synchro_manufacturer_post_process" name="script_data[post_process]" class="input-large"{if $script_data.script !== "synchro_import.manufacturers"} disabled="disabled"{/if}>
                    <option value="">{__("none")}</option>
                    <option value="synchro_import.apply_manufacturers"{if $script_data.post_process === "synchro_import.apply_manufacturers"} selected="selected"{/if}>{__("synchro.apply_manufacturers")}</option>
                </select>
                <p class="muted description">{__("synchro.after_finish_description")}</p>
            </div>
        </div>
    </fieldset>
</div>
