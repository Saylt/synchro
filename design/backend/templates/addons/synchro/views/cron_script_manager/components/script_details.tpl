{if $script_data}
    <div class="control-group">
        <span class="control-label">{__("synchro.inner_status")}</span>
        <div class="controls"><p>{__("synchro.{$script_data.inner_status}")}</p></div>
    </div>
    <div class="control-group">
        <span class="control-label">{__("synchro.progress_status")}</span>
        <div class="controls"><p>{$script_data.progress_status|default:"—"}</p></div>
    </div>
    <div class="control-group">
        <span class="control-label">{__("created")}</span>
        <div class="controls"><p>{$script_data.created|date_format:"`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}</p></div>
    </div>
    <div class="control-group">
        <span class="control-label">{__("synchro.last_launch")}</span>
        <div class="controls">
            <p>{if $script_data.last_launch}{$script_data.last_launch|date_format:"`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}{else}{__("never")}{/if}</p>
        </div>
    </div>
    <div class="control-group">
        <span class="control-label">{__("synchro.total_execution_time")}</span>
        <div class="controls"><p>{$metric_data.summary.execution_time_formatted}</p></div>
    </div>
    <div class="control-group">
        <span class="control-label">{__("synchro.peak_memory_usage")}</span>
        <div class="controls"><p>{$metric_data.summary.peak_memory_usage_formatted}</p></div>
    </div>
    {if $metric_data.history}
        <div class="control-group">
            <span class="control-label">{__("synchro.run_history")}</span>
            <div class="controls">
                <table class="table table-condensed">
                    <thead>
                    <tr>
                        <th>{__("synchro.last_launch")}</th>
                        <th>{__("status")}</th>
                        <th>{__("synchro.execution_time")}</th>
                        <th>{__("synchro.peak_memory_usage")}</th>
                    </tr>
                    </thead>
                    <tbody>
                    {foreach $metric_data.history as $metric}
                        <tr>
                            <td>{$metric.started_at_formatted}</td>
                            <td>{if $metric.status}{__("synchro.`$metric.status`")}{else}—{/if}</td>
                            <td>{$metric.execution_time_formatted}</td>
                            <td>{$metric.peak_memory_usage_formatted}</td>
                        </tr>
                    {/foreach}
                    </tbody>
                </table>
            </div>
        </div>
    {/if}
{/if}
