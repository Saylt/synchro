{capture name="mainbox"}
    {include file="common/pagination.tpl" save_current_page=true}

    {$c_url = $config.current_url|fn_query_remove:"sort_by":"sort_order"}
    {$timestamp_url = $c_url|cat:"&sort_by=timestamp&sort_order="|cat:$search.sort_order}
    {$level_url = $c_url|cat:"&sort_by=level&sort_order="|cat:$search.sort_order}
    {$source_url = $c_url|cat:"&sort_by=source&sort_order="|cat:$search.sort_order}
    {$date_format = $settings.Appearance.date_format|cat:", "|cat:$settings.Appearance.time_format}
    {if $settings.DHTML.admin_ajax_based_pagination == "Y"}
        {$ajax_class = "cm-ajax"}
    {/if}

    <table class="table">
        <thead>
        <tr>
            <th>
                <a
                    class="{$ajax_class}{if $search.sort_by === "timestamp"} sort-link-{$search.sort_order}{/if}"
                    href="{$timestamp_url|fn_url}"
                    rev="pagination_contents"
                >{__("date")}</a>
            </th>
            <th>
                <a
                    class="{$ajax_class}{if $search.sort_by === "level"} sort-link-{$search.sort_order}{/if}"
                    href="{$level_url|fn_url}"
                    rev="pagination_contents"
                >{__("synchro.log_level")}</a>
            </th>
            <th>
                <a
                    class="{$ajax_class}{if $search.sort_by === "source"} sort-link-{$search.sort_order}{/if}"
                    href="{$source_url|fn_url}"
                    rev="pagination_contents"
                >{__("synchro.log_source")}</a>
            </th>
            <th>{__("synchro.log_message")}</th>
            <th>{__("synchro.log_context")}</th>
        </tr>
        </thead>
        <tbody>
        {foreach $logs as $log}
            {$level_name = "synchro.log_level_"|cat:$log.level}
            <tr>
                <td>{$log.timestamp|date_format:$date_format}</td>
                <td>{__($level_name)}</td>
                <td>{$log.source|escape}</td>
                <td>{$log.message|escape}</td>
                <td>
                    {if $log.context}
                        <a class="cm-combination" id="sw_log_context_{$log.log_id}">
                            {__("synchro.log_context")}
                        </a>
                        <div id="log_context_{$log.log_id}" class="hidden">
                            <pre>{$log.context|escape}</pre>
                        </div>
                    {else}
                        —
                    {/if}
                </td>
            </tr>
        {foreachelse}
            <tr class="no-items">
                <td colspan="5"><p>{__("no_data")}</p></td>
            </tr>
        {/foreach}
        </tbody>
    </table>

    {include file="common/pagination.tpl" save_current_page=true}

    {capture name="sidebar"}
        {include file="addons/synchro/views/synchro_logs/components/search_form.tpl"}
    {/capture}
{/capture}

{include
    file="common/mainbox.tpl"
    title=__("synchro.logs")
    content=$smarty.capture.mainbox
    sidebar=$smarty.capture.sidebar
}
