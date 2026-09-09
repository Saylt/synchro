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
	<th>{__("synchro.run_mode")}</th>
	<th>
		{__("synchro.repeat_on")}</th>
	<th>
		<a class="{$ajax_class}{if $search.sort_by == "rate"} sort-link-{$search.sort_order}{/if}" href="{"`$c_url`&amp;sort_by=rate&amp;sort_order=`$search.sort_order`"|fn_url}" rev="pagination_contents">{__("synchro.execution_rate")}</a></th>
	<th>
		<a class="{$ajax_class}{if $search.sort_by == "last_launch"} sort-link-{$search.sort_order}{/if}" href="{"`$c_url`&amp;sort_by=last_launch&amp;sort_order=`$search.sort_order`"|fn_url}" rev="pagination_contents">{__("synchro.last_launch")}</a></th>
	<th>{__("synchro.progress_status")}</th>
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
	<td>{__("synchro.{$s.run_mode}")}</td>
	<td>
		<div>
			{if $s.run_mode !== "periodic"}—
			{elseif $s.period_month_days}{__("synchro.month_days")}: {","|implode:$s.period_month_days}
			{elseif $s.period_week_days}{__("synchro.week_days")}: {$synchro_cron_manager->showShortWeekdays($s.period_week_days)}
			{else}{__("synchro.every_day")}
			{/if}
		</div>
	</td>
	<td>
		<div>
			{if $s.run_mode === "once"}
				{__("synchro.once")}
			{else}
				{if $s.refresh_hours || $s.refresh_minutes}
					{$s.period_hours_begin}:00 &ndash; {$s.period_hours_end}:00
					<br />
					{__("synchro.each")}
					{if $s.refresh_hours}
						{$s.refresh_hours} {__("hours")}
					{/if}
					{if $s.refresh_minutes}
					{$s.refresh_minutes} {__("minutes")}
					{/if}
				{else}
					{__("synchro.once_at")} {$s.period_hours_begin}:00
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
	<td>{$s.progress_status|default:"—"}</td>
	<td>
		{include file="common/select_popup.tpl" id=$s.script_id status=$s.status object_id_name="script_id" table="synchro_cron_scripts"}
	</td>
	<td class="nowrap">
		{capture name="tools_items"}
			{if $synchro_import_processes[$s.script_id] && $synchro_import_processes[$s.script_id].parent.status|in_array:["partial_success", "failed", "cancelled"]}
				<li><a href="{"cron_script_manager.retry_import?script_id=`$s.script_id`"|fn_url}">{__("synchro.retry_failed_processes")}</a></li>
			{/if}
			{if $s.inner_status|in_array:["queued", "in_progress", "waiting_children"]}
				<li><a class="cm-confirm" href="{"cron_script_manager.interrupt?script_id=`$s.script_id`"|fn_url}">{__("synchro.interrupt")}</a></li>
			{elseif $s.inner_status !== "stopping"}
				<li><a class="cm-confirm" href="{"cron_script_manager.launch?script_id=`$s.script_id`"|fn_url}">{__("synchro.launch_now")}</a></li>
			{/if}
			<li><a class="cm-confirm" href="{"cron_script_manager.delete?script_id=`$s.script_id`"|fn_url}">{__("delete")}</a></li>
		{/capture}
		{include file="common/table_tools_list.tpl" prefix=$s.script_id tools_list=$smarty.capture.tools_items href="cron_script_manager.update?script_id=`$s.script_id`" popup=true act="edit" id="cron_script_`$s.script_id`" text="{__("editing_task")}: `$s.script`"}
	</td>
</tr>
{if $synchro_import_processes[$s.script_id]}
	{$import_process_group = $synchro_import_processes[$s.script_id]}
	<tr class="no-border">
		<td></td>
		<td colspan="8">
			<div class="well well-small">
				<div>
					<strong>{__("synchro.import_processes")}</strong>
					<span class="muted">
						#{$import_process_group.parent.import_id} —
						{__("synchro.`$import_process_group.parent.status`")};
						{__("synchro.completed_processes", [
							"[completed]" => $import_process_group.completed_count,
							"[total]" => $import_process_group.total_count
						])};
						{__("synchro.import_plan_summary", [
							"[items]" => $import_process_group.parent.total_items,
							"[pages]" => $import_process_group.parent.total_pages,
							"[limit]" => $import_process_group.parent.page_limit
						])}
					</span>
				</div>
				<table class="table table-condensed table-middle">
					<thead>
					<tr>
						<th>{__("synchro.page_range")}</th>
						<th>{__("synchro.current_page")}</th>
						<th>{__("status")}</th>
						<th>{__("error")}</th>
						<th></th>
					</tr>
					</thead>
					<tbody>
					{foreach $import_process_group.children as $import_process}
						<tr>
							<td>{$import_process.page_from}–{$import_process.page_to}</td>
							<td>{$import_process.current_page|default:"—"}</td>
							<td>{__("synchro.`$import_process.status`")}</td>
							<td>{$import_process.error_message|default:"—"}</td>
							<td class="right nowrap">
								{if $import_process.status|in_array:["queued", "processing"]}
									<a class="btn cm-confirm" href="{"cron_script_manager.interrupt_process?import_id=`$import_process.import_id`"|fn_url}">
										{__("synchro.interrupt")}
									</a>
								{elseif $import_process.status|in_array:["failed", "cancelled"]}
									<a class="btn" href="{"cron_script_manager.retry_process?import_id=`$import_process.import_id`"|fn_url}">
										{__("synchro.retry")}
									</a>
								{/if}
							</td>
						</tr>
					{/foreach}
					</tbody>
				</table>
			</div>
		</td>
	</tr>
{/if}
{foreachelse}
<tr class="no-items">
<td colspan="9"><p>{__("no_data")}</p></td>
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
