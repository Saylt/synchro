{capture name="mainbox"}
{$synchro_cron_manager = $app['addons.synchro.cron_manager']}

<form action="{""|fn_url}" method="post" name="cron_script_manager_form" id="cron_script_manager_form" class="">

{include file="common/pagination.tpl" save_current_page=true}

{assign var="c_url" value=$config.current_url|fn_query_remove:"sort_by":"sort_order"}

{if $settings.DHTML.admin_ajax_based_pagination == "Y"}
	{assign var="ajax_class" value="cm-ajax"}
{/if}

<table cellpadding="0" cellspacing="0" border="0" width="100%" class="table sortable hidden-inputs">
<tr>
	<th class="center">
		<input type="checkbox" name="check_all" value="Y" title="{__("check_uncheck_all")}" class="checkbox cm-check-items" /></th>
	<th>
		<a class="{$ajax_class}{if $search.sort_by == "script"} sort-link-{$search.sort_order}{/if}" href="{"`$c_url`&amp;sort_by=script&amp;sort_order=`$search.sort_order`"|fn_url}" rev="pagination_contents">{__("script")}</a></th>
	<th>
		<a class="{$ajax_class}{if $search.sort_by == "month_days"} sort-link-{$search.sort_order}{/if}" href="{"`$c_url`&amp;sort_by=month_days&amp;sort_order=`$search.sort_order`"|fn_url}" rev="pagination_contents">{__("synchro.month_days")}</a></th>
	<th>
		<a class="{$ajax_class}{if $search.sort_by == "week_days"} sort-link-{$search.sort_order}{/if}" href="{"`$c_url`&amp;sort_by=week_days&amp;sort_order=`$search.sort_order`"|fn_url}" rev="pagination_contents">{__("synchro.week_days")}</a></th>
	<th>
		<a class="{$ajax_class}{if $search.sort_by == "rate"} sort-link-{$search.sort_order}{/if}" href="{"`$c_url`&amp;sort_by=rate&amp;sort_order=`$search.sort_order`"|fn_url}" rev="pagination_contents">{__("synchro.execution_rate")}</a></th>
	<th>
		<a class="{$ajax_class}{if $search.sort_by == "last_launch"} sort-link-{$search.sort_order}{/if}" href="{"`$c_url`&amp;sort_by=last_launch&amp;sort_order=`$search.sort_order`"|fn_url}" rev="pagination_contents">{__("synchro.last_launch")}</a></th>
	<th>
		<a class="{$ajax_class}{if $search.sort_by == "status"} sort-link-{$search.sort_order}{/if}" href="{"`$c_url`&amp;sort_by=status&amp;sort_order=`$search.sort_order`"|fn_url}" rev="pagination_contents">{__("status")}</a></th>
	<th>&nbsp;</th>
</tr>
{foreach $scripts as $s}
<tr {cycle values="class=\"table-row\", "}>
	<td class="center">
   		<input type="checkbox" name="script_ids[]" value="{$s.script_id}" class="checkbox cm-item" />
	</td>
	<td>
		<div>{$s.script}</div>
		{if $s.description}<span class="product-code-label">{$s.description}</span>{/if}
	</td>
	<td>
		<div>{","|implode:$s.period_month_days|default:__("all")}</div>
	</td>
	<td>
		<div>{Tygh\Addons\Synchro\CronManager::showShortWeekdays($s.period_week_days)}</div>
	</td>
	<td>
		<div>
			{$s.period_hours_begin}:00 {if $s.period_hours_begin != $s.period_hours_end}&ndash; {$s.period_hours_end}:00{/if}
			<br />
			{if $s.refresh_hours || $s.refresh_minutes}
				{__("synchro.each")}
				{if $s.refresh_hours}
					{$s.refresh_hours} {__("hours")}
				{/if}
				{if $s.refresh_minutes}
					{$s.refresh_minutes} {__("minutes")}
				{/if}
			{/if}
		</div>
	</td>
	<td>
		{if $s.last_launch}
			{$s.last_launch|date_format:"`$settings.Appearance.date_format`, `$settings.Appearance.time_format`"}
		{else}
			{__("never")}
		{/if}
		{if $s.inner_status != 'scheduled'}
			({__("synchro.`$s.inner_status`")})
		{/if}
	</td>
	<td>
		{include file="common/select_popup.tpl" id=$s.script_id status=$s.status object_id_name="script_id" table="cron_scripts"}
	</td>
	<td class="nowrap">
		{capture name="tools_items"}
			<li><a class="cm-confirm" href="{"cron_script_manager.launch?script_id=`$s.script_id`"|fn_url}">{__("synchro.launch_now")}</a></li>
			<li><a class="cm-confirm" href="{"cron_script_manager.delete?script_id=`$s.script_id`"|fn_url}">{__("delete")}</a></li>
		{/capture}
		{include file="common/table_tools_list.tpl" prefix=$s.script_id tools_list=$smarty.capture.tools_items href="cron_script_manager.update?script_id=`$s.script_id`" popup=true act="edit" id="cron_script_`$s.script_id`" text="{__("editing_task")}: `$s.script`"}
	</td>
</tr>
{foreachelse}
<tr class="no-items">
	<td colspan="11"><p>{__("no_data")}</p></td>
</tr>
{/foreach}
</table>

{include file="common/pagination.tpl"}

</form>

<div class="buttons-container buttons-bg">
	{if $scripts}
	<div class="float-left">
		{include
			file="buttons/button.tpl"
			but_name="dispatch[cron_script_manager.m_delete]"
			but_text=__("delete_selected")
			but_role="submit-link"
			but_target_form="cron_script_manager_form"
			but_meta="cm-process-items cm-confirm"
		}
	</div>
	{/if}

	<div class="float-right">
		{capture name="tools"}
			{capture name="add_script"}
				{include file="addons/synchro/views/cron_script_manager/update.tpl"}
			{/capture}
		{/capture}
		{include file="common/popupbox.tpl" id="add_script" link_text=__("synchro.add_task") text=__("synchro.new_task") content=$smarty.capture.add_script act="general"}
	</div>
</div>

{capture name="sidebar"}
	{include
		file="addons/synchro/views/cron_script_manager/components/cron_script_manager_search_form.tpl"
		dispatch="cron_script_manager.manage"
	}
{/capture}

{/capture}
{include
	file="common/mainbox.tpl"
	title=__("synchro.cron_script_manager")
	content=$smarty.capture.mainbox
	title_extra=$smarty.capture.title_extra
	tools=$smarty.capture.tools
	sidebar=$smarty.capture.sidebar
}
