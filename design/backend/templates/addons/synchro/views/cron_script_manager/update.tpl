{$synchro_cron_manager = $app['addons.synchro.cron_manager']}

<form action="{""|fn_url}" method="post" name="cron_script_form" class="form-highlight cm-disable-empty-files">

<input type="hidden" name="script_id" value="{$script_data.script_id|default:0}">

<div class="tabs cm-j-tabs">
	<ul>
		<li id="tab" class="cm-js cm-active"><a>{__("general")}</a></li>
	</ul>
</div>
<div class="cm-tabs-content" id="tabs_content">

	{include file="common/subheader.tpl" title=__("information")}

	<div class="form-field">
		<label for="cron_script_script" class="cm-required">{__("script")}:</label>
		<input type="text" name="script_data[script]" id="cron_script_script" value="{$script_data.script}" class="input-text-large main-input" />
	</div>

	<div class="form-field">
		<label for="cron_script_script_type">{__("synchro.run_type")}{include file="common/tooltip.tpl" tooltip=__("synchro.cron_script_type_tooltip")|nl2br}:</label>
		<select	name="script_data[script_type]" id="cron_script_script_type">
			{foreach from=$synchro_cron_manager->getSetElements("script_type", "cron_scripts") item="m"}
				<option value="{$m}"{if $script_data.script_type == $m} selected="selected"{/if}>{__("synchro.{$m}")}</option>
			{/foreach}
		</select>
	</div>

	<div class="form-field">
		<label for="cron_script_description">{__("description")}:</label>
		<textarea id="cron_script_description" name="script_data[description]" cols="55" rows="4" class="input-textarea-long">{$script_data.description}</textarea>
	</div>

	{include file="common/select_status.tpl" input_name="script_data[status]" id="cron_script_status" obj=$script_data}

	{if $script_data}
		<div class="form-field">
			<label>{__("inner_status")}:</label>
			<span>{__($script_data.inner_status)}</span>
		</div>
		<div class="form-field">
			<label>{__("created")}:</label>
			<span>{$script_data.created|date_format:"`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}</span>
		</div>
		<div class="form-field">
			<label>{__("synchro.last_launch")}:</label>
			<span>
				{if $script_data.last_launch}
					{$script_data.last_launch|date_format:"`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}
				{else}
					{__("never")}
				{/if}
			</span>
		</div>
	{/if}

	{include file="common/subheader.tpl" title=__("period")}

    <div class="form-field">
        <label for="cron_script_period_month_days">{__("synchro.month_days")}:</label>
        <input type="hidden" name="script_data[period_month_days]" value="">
        <select	name="script_data[period_month_days][]" id="cron_script_period_month_days" multiple="multiple">
            {foreach from=$synchro_cron_manager->getSetElements("period_month_days", "cron_scripts") item="m"}
                <option value="{$m}"{if $m|in_array:$script_data.period_month_days} selected="selected"{/if}>{$m}</option>
            {/foreach}
        </select>
    </div>

	<div class="form-field">
		<label for="cron_script_period_week_days" class="cm-required">{__("synchro.week_days")}:</label>
		<select	name="script_data[period_week_days][]" id="cron_script_period_week_days" multiple="multiple">
			{foreach from=$synchro_cron_manager->getSetElements("period_week_days", "cron_scripts") item="m"}
				<option value="{$m}"{if $m|in_array:$script_data.period_week_days} selected="selected"{/if}>{__("synchro.{$m}")}</option>
			{/foreach}
		</select>
	</div>

	<div class="form-field">
		<label for="cron_script_period_hours_begin">{__("hours")}:</label>
		<div class="select-field">
			<select	name="script_data[period_hours_begin]" id="cron_script_period_hours_begin">
				{foreach from=$synchro_cron_manager->getSetElements("period_hours_begin", "cron_scripts") item="m"}
					<option value="{$m}"{if $script_data.period_hours_begin == $m} selected="selected"{/if}>{$m}</option>
				{/foreach}
			</select>
			<label>:00</label>
			<label>&ndash;</label>
			<select	name="script_data[period_hours_end]" id="cron_script_period_hours_end">
				{foreach from=$synchro_cron_manager->getSetElements("period_hours_end", "cron_scripts") item="m"}
					<option value="{$m}"{if $script_data.period_hours_end == $m} selected="selected"{/if}>{$m}</option>
				{/foreach}
			</select>
			<label>:00</label>
		</div>
	</div>

	{include file="common/subheader.tpl" title=__("synchro.refresh_time")}

	<div class="form-field">
		<label for="cron_script_refresh_hours">{__("hours")}:</label>
		<select	name="script_data[refresh_hours]" id="cron_script_refresh_hours">
			{foreach from=$synchro_cron_manager->getSetElements("refresh_hours", "cron_scripts") item="m"}
				<option value="{$m}"{if $script_data.refresh_hours == $m} selected="selected"{/if}>{$m}</option>
			{/foreach}
		</select>
	</div>

	<div class="form-field">
		<label for="cron_script_refresh_minutes">{__("minutes")}:</label>
		<select	name="script_data[refresh_minutes]" id="cron_script_refresh_minutes">
			{foreach from=$synchro_cron_manager->getSetElements("refresh_minutes", "cron_scripts") item="m"}
				<option value="{$m}"{if $script_data.refresh_minutes == $m} selected="selected"{/if}>{$m}</option>
			{/foreach}
		</select>
	</div>

</div>

<div class="buttons-container">
	{if $script_data}
		{assign var="but_text" value=__("save")}
	{else}
		{assign var="but_text" value=__("create")}
	{/if}
	{include file="buttons/save_cancel.tpl" but_text=$but_text but_name="dispatch[cron_script_manager.update]" cancel_action="close"}
</div>

</form>
