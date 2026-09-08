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
{/if}
