{capture name="section"}

<form name="cron_script_manager_search_form" action="{""|fn_url}" method="get">

{if $smarty.request.redirect_url}
<input type="hidden" name="redirect_url" value="{$smarty.request.redirect_url}" />
{/if}

<table cellpadding="0" cellspacing="0" border="0" class="search-header">
<tr>
	<td class="search-field nowrap">
		<label for="elm_script">{__("script")}:</label>
		<div class="break">
			<input class="search-input-text" type="text" name="script" id="elm_script" value="{$search.script}" />
			{include file="buttons/search.tpl" search="Y" but_name=$dispatch}
		</div>
	</td>
	<td class="search-field">
		<label for="elm_script_type">{__("type")}:</label>
		<select name="script_type" id="elm_script_type">
			<option value="">--</option>

			{foreach from=$synchro_cron_manager->getSetElements("script_type", "cron_scripts") item="script_type"}
				<option value="{$script_type}"{if $search.script_type == $script_type} selected="selected"{/if}>{__("synchro.{$script_type}")}</option>
			{/foreach}
		</select>
	</td>
	<td class="search-field">
		<label for="elm_status">{__("status")}:</label>
		<select name="status" id="elm_status">
			<option value="">--</option>
			<option value="A" {if $search.status === "A"}selected="selected"{/if}>{__("active")}</option>
			<option value="D" {if $search.status === "D"}selected="selected"{/if}>{__("disabled")}</option>
		</select>
	</td>
	<td class="search-field">
		<label for="elm_inner_status">{__("inner_status")}:</label>
		<select name="inner_status" id="elm_inner_status">
			<option value="">--</option>
			{foreach from=$synchro_cron_manager->getSetElements("inner_status", "cron_scripts") item="inner_status"}
				<option value="{$inner_status}"{if $search.inner_status == $inner_status} selected="selected"{/if}>{__($inner_status)}</option>
			{/foreach}
		</select>
	</td>
	<td class="buttons-container">
		{include file="buttons/search.tpl" but_name="dispatch[$dispatch]" but_role="submit"}
	</td>
</tr>
</table>

{capture name="advanced_search"}

<table cellpadding="0" cellspacing="0" border="0" width="100%">
<tr>
	<td colspan="2">
		<div class="search-field">
			<label for="elm_create">{__("created")}:</label>
			{include file="common/period_selector.tpl" period=$search.period form_name="cron_script_manager_search_form"}
		</div>

		<div class="search-field">
			<label for="elm_create">{__("synchro.last_launch")}:</label>
			{include file="common/period_selector.tpl" period=$search.launch_period prefix="launch_" form_name="cron_script_manager_search_form"}
		</div>

		<div class="search-field">
			<label for="cron_script_period_week_days">{__("synchro.week_days")}:</label>
			{html_checkboxes name="period_week_days" options=$synchro_cron_manager->getSetElements("period_week_days", "cron_scripts", true) selected=$search.period_week_days columns=4}
		</div>

		<div class="search-field">
			<label for="elm_period_hours_begin">{__("hours")}:</label>
			<div class="nowrap">
				<select	name="period_hours_begin" id="elm_period_hours_begin">
					<option value="">--</option>
					{foreach from=$synchro_cron_manager->getSetElements("period_hours_begin", "cron_scripts") item="m"}
						<option value="{$m}"{if $search.period_hours_begin == $m} selected="selected"{/if}>{$m}</option>
					{/foreach}
				</select>
				<label for="elm_period_hours_begin" class="label-html">:00</label>

				<label class="label-html">&ndash;</label>

				<select	name="period_hours_end" id="elm_period_hours_end">
					<option value="">--</option>
					{foreach from=$synchro_cron_manager->getSetElements("period_hours_end", "cron_scripts") item="m"}
						<option value="{$m}"{if $search.period_hours_end == $m} selected="selected"{/if}>{$m}</option>
					{/foreach}
				</select>
				<label for="elm_period_hours_end" class="label-html">:00</label>
			</div>
		</div>

		<div class="search-field">
			<label for="elm_period_hours">{__("synchro.refresh_time")}:</label>
			<div class="nowrap">
				<select	name="refresh_hours" id="elm_period_hours">
					<option value="">--</option>
					{foreach from=$synchro_cron_manager->getSetElements("refresh_hours", "cron_scripts") item="m"}
						<option value="{$m}"{if $search.refresh_hours == $m} selected="selected"{/if}>{$m}</option>
					{/foreach}
				</select>
				<label class="label-html" for="elm_period_hours">{__("hours")}</label>

				<select	name="refresh_minutes" id="elm_period_minutes">
					<option value="">--</option>
					{foreach from=$synchro_cron_manager->getSetElements("refresh_minutes", "cron_scripts") item="m"}
						<option value="{$m}"{if $search.refresh_minutes == $m} selected="selected"{/if}>{$m}</option>
					{/foreach}
				</select>
				<label for="elm_period_minutes" class="label-html">{__("minutes")}</label>
			</div>
		</div>

	</td>
</tr>

</table>

{/capture}
{include file="common/advanced_search.tpl" content=$smarty.capture.advanced_search dispatch=$dispatch view_type="cron_script_manager"}

</form>

{/capture}
{include file="common/section.tpl" section_content=$smarty.capture.section}
