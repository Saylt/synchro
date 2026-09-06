{$sync_provider_id = $smarty.request.sync_provider_id}

{capture name="mainbox"}
    <form class="form-edit cm-processed-form cm-check-changes" action="{""|fn_url}" method="post" id="synchro_feature_mapping_form">
        <input type="hidden" name="sync_provider_id" value="{$sync_provider_id}">
        <input type="hidden" name="import_id" value="{$synchro_import_id}">
        <input type="hidden" name="sync_data_settings[{$sync_provider_id}][import_id]" value="{$synchro_import_id}">

        <p>{__("synchro.feature_mapping_description")}</p>

        {if $synchro_import_id && $synchro_feature_mappings}
            <div class="table-responsive-wrapper">
                <table class="table table-middle table--relative table-responsive">
                    <thead>
                    <tr>
                        <th>{__("synchro.imported_feature")}</th>
                        <th>{__("synchro.feature_group")}</th>
                        <th>{__("synchro.feature_variants_count")}</th>
                        <th>{__("synchro.feature_mapping_action")}</th>
                        <th>{__("synchro.local_features")}</th>
                    </tr>
                    </thead>
                    <tbody>
                    {foreach $synchro_feature_mappings as $feature_mapping}
                        <tr>
                            <td data-th="{__("synchro.imported_feature")}">
                                <strong>{$feature_mapping.name}</strong>
                                <div class="muted"><code>{$feature_mapping.external_id}</code></div>
                            </td>
                            <td data-th="{__("synchro.feature_group")}">
                                {$feature_mapping.group_name|default:__("none")}
                            </td>
                            <td data-th="{__("synchro.feature_variants_count")}">
                                {$feature_mapping.variants_count}
                            </td>
                            <td data-th="{__("synchro.feature_mapping_action")}">
                                <select name="feature_mappings[{$feature_mapping.external_id}][action]">
                                    {foreach $synchro_mapping_actions as $action => $action_name}
                                        <option value="{$action}" {if $feature_mapping.action === $action}selected{/if}>
                                            {$action_name}
                                        </option>
                                    {/foreach}
                                </select>
                            </td>
                            <td data-th="{__("synchro.local_features")}">
                                {include file="views/product_features/components/picker/picker.tpl"
                                    picker_id="synchro_feature_{$feature_mapping.external_id}"
                                    input_name="feature_mappings[{$feature_mapping.external_id}][local_feature_ids][]"
                                    item_ids=$feature_mapping.local_feature_ids
                                    multiple=true
                                    show_advanced=true
                                    close_on_select=false
                                    allow_clear=true
                                    width="100%"
                                }
                            </td>
                        </tr>
                    {/foreach}
                    </tbody>
                </table>
            </div>
        {else}
            <p class="no-items">{__("synchro.no_completed_product_import")}</p>
        {/if}
    </form>
{/capture}

{capture name="buttons"}
    {if $synchro_import_id && $synchro_feature_mappings}
        {include file="buttons/button.tpl"
            but_permission_data="sync_data.update?sync_provider_id={$sync_provider_id}"
            but_role="submit-link"
            but_name="dispatch[sync_data.update]"
            but_target_form="synchro_feature_mapping_form"
            but_text=__("save")
            but_meta="btn-primary"
        }
    {/if}
{/capture}

{include file="common/mainbox.tpl"
    title=$provider_data.name
    content=$smarty.capture.mainbox
    buttons=$smarty.capture.buttons
    show_all_storefront=false
}
