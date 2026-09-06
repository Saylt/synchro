{$sync_provider_id = $smarty.request.sync_provider_id}

{capture name="mainbox"}
    <form class="form-edit cm-processed-form cm-check-changes" action="{""|fn_url}" method="post" id="synchro_feature_mapping_form">
        <input type="hidden" name="sync_provider_id" value="{$sync_provider_id}">
        <input type="hidden" name="import_id" value="{$synchro_import_id}">
        <input type="hidden" name="sync_data_settings[{$sync_provider_id}][import_id]" value="{$synchro_import_id}">
        <input type="hidden" name="dispatch" value="sync_data.update">

        <p>{__("synchro.feature_mapping_description")}</p>

        {if $synchro_import_id && $synchro_feature_mappings}
            <div class="table-responsive-wrapper">
                <table class="table table-middle table--relative table-responsive">
                    <thead>
                    <tr>
                        <th class="left mobile-hide table__check-items-cell">
                            {include file="common/check_items.tpl"}
                        </th>
                        <th>{__("synchro.imported_feature")}</th>
                        <th>{__("synchro.feature_group")}</th>
                        <th>{__("synchro.feature_variants_count")}</th>
                        <th>{__("synchro.local_feature")}</th>
                    </tr>
                    </thead>
                    <tbody>
                    {foreach $synchro_feature_mappings as $feature_mapping}
                        <tr>
                            <td class="left mobile-hide table__check-items-cell">
                                <input type="checkbox"
                                    name="external_feature_ids[]"
                                    value="{$feature_mapping.external_id}"
                                    class="cm-item"
                                >
                            </td>
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
                            <td data-th="{__("synchro.local_feature")}">
                                {if $feature_mapping.local_feature_id === null}
                                    <span class="muted">{__("synchro.feature_mapping_unresolved")}</span>
                                {elseif !$feature_mapping.local_feature_id}
                                    <span class="label">{__("synchro.feature_mapping_skipped")}</span>
                                {elseif $feature_mapping.is_local_feature_missing}
                                    <span class="label label-warning">
                                        {__("synchro.feature_mapping_missing_target", ["[feature_id]" => $feature_mapping.local_feature_id])}
                                    </span>
                                {else}
                                    <strong>{$feature_mapping.local_feature_name}</strong>
                                    <span class="muted">#{$feature_mapping.local_feature_id}</span>
                                {/if}
                            </td>
                        </tr>
                    {/foreach}
                    </tbody>
                </table>
            </div>

            <div class="well form-horizontal">
                <div class="control-group">
                    <label class="control-label" for="synchro_local_feature">
                        {__("synchro.local_feature")}
                    </label>
                    <div class="controls">
                        {include file="views/product_features/components/picker/picker.tpl"
                            picker_id="synchro_local_feature"
                            input_name="local_feature_id"
                            multiple=false
                            show_advanced=true
                            allow_clear=true
                            search_data=["feature_types" => $synchro_target_feature_types]
                            width="100%"
                        }
                    </div>
                </div>

                <div class="control-group">
                    <label class="control-label" for="synchro_new_feature_name">
                        {__("synchro.new_feature_name")}
                    </label>
                    <div class="controls">
                        <input type="text"
                            id="synchro_new_feature_name"
                            name="new_feature_name"
                            value=""
                            class="input-large"
                        >
                    </div>
                </div>

                <div class="controls">
                    <button type="submit" name="mapping_action" value="map" class="btn btn-primary">
                        {__("synchro.map_selected_features")}
                    </button>
                    <button type="submit" name="mapping_action" value="create" class="btn">
                        {__("synchro.create_and_map_features")}
                    </button>
                    <button type="submit" name="mapping_action" value="skip" class="btn">
                        {__("synchro.skip_selected_features")}
                    </button>
                </div>
            </div>
        {else}
            <p class="no-items">{__("synchro.no_completed_product_import")}</p>
        {/if}
    </form>
{/capture}

{include file="common/mainbox.tpl"
    title=$provider_data.name
    content=$smarty.capture.mainbox
    show_all_storefront=false
}
