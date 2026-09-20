{$sync_provider_id = $smarty.request.sync_provider_id}

{capture name="mainbox"}
    {capture name="tabsbox"}
        <div id="content_features">
            <p>{__("synchro.feature_mapping_description")}</p>

            <form action="{""|fn_url}" method="get" class="form-horizontal">
                <input type="hidden" name="dispatch" value="sync_data.update">
                <input type="hidden" name="sync_provider_id" value="{$sync_provider_id|escape}">
                <input type="hidden" name="sync_data_settings[{$sync_provider_id}][{$synchro_import_id}]" value="{$synchro_import_id}">
                <input type="hidden" name="selected_section" value="features">
                <div class="control-group">
                    <label class="control-label" for="synchro_feature_query">{__("search")}</label>
                    <div class="controls"><input type="text" id="synchro_feature_query" name="q" value="{$search.q|escape}" class="input-large"></div>
                </div>
                <div class="control-group">
                    <label class="control-label" for="synchro_mapping_status">{__("synchro.feature_mapping_status")}</label>
                    <div class="controls">
                        <select id="synchro_mapping_status" name="mapping_status">
                            <option value="all" {if $search.mapping_status === "all"}selected{/if}>{__("synchro.feature_mapping_status_all")}</option>
                            <option value="mapped" {if $search.mapping_status === "mapped"}selected{/if}>{__("synchro.feature_mapping_status_mapped")}</option>
                            <option value="unmapped" {if $search.mapping_status === "unmapped"}selected{/if}>{__("synchro.feature_mapping_status_unmapped")}</option>
                            <option value="skipped" {if $search.mapping_status === "skipped"}selected{/if}>{__("synchro.feature_mapping_status_skipped")}</option>
                        </select>
                        <button type="submit" class="btn">{__("search")}</button>
                    </div>
                </div>
            </form>

            {if $synchro_import_id}
                <form class="form-edit cm-processed-form cm-check-changes" action="{""|fn_url}" method="post" id="synchro_feature_mapping_form">
                    <input type="hidden" name="dispatch" value="sync_data.update">
                    <input type="hidden" name="sync_provider_id" value="{$sync_provider_id|escape}">
                    <input type="hidden" name="mapping_scope" value="features">
                    <input type="hidden" name="import_id" value="{$synchro_import_id}">
                    <input type="hidden" name="q" value="{$search.q|escape}">
                    <input type="hidden" name="mapping_status" value="{$search.mapping_status}">
                    <input type="hidden" name="items_per_page" value="{$search.items_per_page}">
                    <input type="hidden" name="page" value="{$search.page}">
                    <input type="hidden" name="selected_section" value="features">
                    <input type="hidden" name="sync_data_settings[{$sync_provider_id}][{$synchro_import_id}]" value="{$synchro_import_id}">

                    {include file="common/pagination.tpl" save_current_page=true}
                    <div class="table-responsive-wrapper">
                        <table class="table table-middle table--relative table-responsive">
                            <thead><tr>
                                <th class="left mobile-hide table__check-items-cell">{include file="common/check_items.tpl"}</th>
                                <th>{__("synchro.imported_feature")}</th><th>{__("synchro.feature_group")}</th><th>{__("synchro.feature_variants_count")}</th><th>{__("synchro.local_feature")}</th>
                            </tr></thead>
                            <tbody>
                            {foreach $synchro_feature_mappings as $feature_mapping}
                                <tr>
                                    <td class="left mobile-hide table__check-items-cell"><input type="checkbox" name="external_feature_ids[]" value="{$feature_mapping.external_id|escape}" class="cm-item"></td>
                                    <td data-th="{__("synchro.imported_feature")}"><strong>{$feature_mapping.name|escape}</strong><div class="muted"><code>{$feature_mapping.external_id|escape}</code></div></td>
                                    <td data-th="{__("synchro.feature_group")}">{$feature_mapping.group_name|default:__("none")|escape}</td>
                                    <td data-th="{__("synchro.feature_variants_count")}">{$feature_mapping.variants_count}</td>
                                    <td data-th="{__("synchro.local_feature")}">
                                        {if $feature_mapping.local_feature_id === null}<span class="muted">{__("synchro.feature_mapping_unresolved")}</span>
                                        {elseif !$feature_mapping.local_feature_id}<span class="label">{__("synchro.feature_mapping_skipped")}</span>
                                        {elseif $feature_mapping.is_local_feature_missing}<span class="label label-warning">{__("synchro.feature_mapping_missing_target", ["[feature_id]" => $feature_mapping.local_feature_id])}</span>
                                        {else}<strong>{$feature_mapping.local_feature_name|escape}</strong> <span class="muted">#{$feature_mapping.local_feature_id}</span>{/if}
                                    </td>
                                </tr>
                            {foreachelse}
                                <tr class="no-items"><td colspan="5"><p>{__("no_data")}</p></td></tr>
                            {/foreach}
                            </tbody>
                        </table>
                    </div>
                    {include file="common/pagination.tpl" save_current_page=true}

                    <div class="well form-horizontal">
                        <div class="control-group"><label class="control-label" for="synchro_local_feature">{__("synchro.local_feature")}</label><div class="controls">{include file="views/product_features/components/picker/picker.tpl" picker_id="synchro_local_feature" input_name="feature_local_feature_id" multiple=false show_advanced=true allow_clear=true search_data=["feature_types" => $synchro_target_feature_types] width="100%"}</div></div>
                        <div class="control-group"><label class="control-label" for="synchro_new_feature_name">{__("synchro.new_feature_name")}</label><div class="controls"><input type="text" id="synchro_new_feature_name" name="feature_new_feature_name" class="input-large"></div></div>
                        <div class="controls"><button type="submit" name="mapping_action" value="map" class="btn btn-primary">{__("synchro.map_selected_features")}</button><button type="submit" name="mapping_action" value="create" class="btn">{__("synchro.create_and_map_features")}</button><button type="submit" name="mapping_action" value="skip" class="btn">{__("synchro.skip_selected_features")}</button></div>
                    </div>
                </form>
            {else}
                <p class="no-items">{__("synchro.no_completed_product_import")}</p>
            {/if}
        </div>

        <div id="content_brands" class="hidden">
            <p>{__("synchro.brand_mapping_description")}</p>
            <form class="form-edit cm-processed-form cm-check-changes" action="{""|fn_url}" method="post" id="synchro_brand_mapping_form">
                <input type="hidden" name="dispatch" value="sync_data.update">
                <input type="hidden" name="sync_provider_id" value="{$sync_provider_id|escape}">
                <input type="hidden" name="mapping_scope" value="brands">
                <input type="hidden" name="external_feature_ids[]" value="manufacturers">
                <input type="hidden" name="selected_section" value="brands">
                <input type="hidden" name="sync_data_settings[{$sync_provider_id}][{$synchro_import_id}]" value="{$synchro_import_id}">
                <div class="well form-horizontal">
                    <div class="control-group"><label class="control-label">{__("synchro.brands")}</label><div class="controls">{if $synchro_brand_local_feature_id}<div>{$synchro_brand_local_feature.description|escape} <span class="muted">#{$synchro_brand_local_feature_id}</span></div>{else}<div class="muted">{__("synchro.feature_mapping_unresolved")}</div>{/if}</div></div>
                    <div class="control-group"><label class="control-label" for="synchro_brand_local_feature">{__("synchro.local_feature")}</label><div class="controls">{include file="views/product_features/components/picker/picker.tpl" picker_id="synchro_brand_local_feature" input_name="brand_local_feature_id" multiple=false show_advanced=true allow_clear=true search_data=["feature_types" => $synchro_brand_target_feature_types] width="100%"}</div></div>
                    <div class="control-group"><label class="control-label" for="synchro_brand_new_feature_name">{__("synchro.new_feature_name")}</label><div class="controls"><input type="text" id="synchro_brand_new_feature_name" name="brand_new_feature_name" class="input-large"></div></div>
                    <div class="controls"><button type="submit" name="mapping_action" value="map" class="btn btn-primary">{__("synchro.map_selected_features")}</button><button type="submit" name="mapping_action" value="create" class="btn">{__("synchro.create_and_map_features")}</button><button type="submit" name="mapping_action" value="skip" class="btn">{__("synchro.skip_selected_features")}</button></div>
                </div>
            </form>
        </div>
    {/capture}
    {include file="common/tabsbox.tpl" content=$smarty.capture.tabsbox group_name="synchro_feature_mapping" active_tab=$smarty.request.selected_section|default:"features" track=true}
{/capture}

{include file="common/mainbox.tpl" title=$provider_data.name content=$smarty.capture.mainbox show_all_storefront=false}
