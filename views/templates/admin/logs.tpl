<div class="panel" id="fieldset_logs">
	<div class="panel-heading">
		<i class="icon-file-text"></i>{l s='API debug logs' mod='dpdgeopost'}
	</div>

	<div class="form-wrapper">
		{if $debugLogDates}
			<div class="form-group">
				<label>{l s='Available log days' mod='dpdgeopost'}</label>
				<div>
					{foreach from=$debugLogDates item=debugLogDate}
						<a class="btn btn-default{if $selectedDebugLogDate == $debugLogDate} active{/if}"
						   href="{$debugLogBaseUrl|escape:'htmlall':'UTF-8'}&debug_log_date={$debugLogDate|escape:'htmlall':'UTF-8'}">
							{$debugLogDate|escape:'htmlall':'UTF-8'}
						</a>
					{/foreach}
				</div>
			</div>

			<div class="form-group dpd-debug-log-list-wrapper">
				<label>{l s='Log contents' mod='dpdgeopost'}</label>
				{if $debugLogEntries}
					<p class="dpd-debug-log-summary text-muted">
						{l s='Showing %1$d–%2$d of %3$d' sprintf=[$logsShowingFrom, $logsShowingTo, $totalLogEntries] mod='dpdgeopost'}
					</p>
					<table class="table dpd-debug-log-table">
						<thead>
							<tr>
								<th>{l s='Date' mod='dpdgeopost'}</th>
								<th>{l s='Type' mod='dpdgeopost'}</th>
								<th>{l s='Method' mod='dpdgeopost'}</th>
								<th>{l s='HTTP' mod='dpdgeopost'}</th>
								<th>{l s='Duration' mod='dpdgeopost'}</th>
							</tr>
						</thead>
						<tbody>
							{foreach from=$debugLogEntries item=debugLogEntry name=debugLogEntries}
								<tr class="dpd-debug-log-entry" data-log-index="{$smarty.foreach.debugLogEntries.iteration|escape:'htmlall':'UTF-8'}" title="{$debugLogEntry.summary|escape:'htmlall':'UTF-8'}">
									<td>{$debugLogEntry.date|escape:'htmlall':'UTF-8'}</td>
									<td>{$debugLogEntry.type|escape:'htmlall':'UTF-8'}</td>
									<td>{$debugLogEntry.method|escape:'htmlall':'UTF-8'}</td>
									<td>{$debugLogEntry.http_code|escape:'htmlall':'UTF-8'}</td>
									<td>{$debugLogEntry.duration|escape:'htmlall':'UTF-8'}</td>
								</tr>
							{/foreach}
						</tbody>
					</table>
					<div class="dpd-debug-log-details-sources" style="display:none;">
						{foreach from=$debugLogEntries item=debugLogEntry name=debugLogEntriesDetails}
							<textarea id="dpd-debug-log-details-{$smarty.foreach.debugLogEntriesDetails.iteration|escape:'htmlall':'UTF-8'}" class="dpd-debug-log-details-source">{$debugLogEntry.details|escape:'htmlall':'UTF-8'}</textarea>
						{/foreach}
					</div>
					{if $totalLogPages > 1}
						<nav class="dpd-debug-log-pagination" aria-label="{l s='Log pagination' mod='dpdgeopost'}">
							{if $currentLogPage > 1}
								<a class="btn btn-default" href="{$logPageBaseUrl|escape:'htmlall':'UTF-8'}&log_page={$currentLogPage - 1}">&laquo; {l s='Previous' mod='dpdgeopost'}</a>
							{else}
								<span class="btn btn-default disabled">&laquo; {l s='Previous' mod='dpdgeopost'}</span>
							{/if}

							{foreach from=$logPaginationItems item=paginationItem}
								{if $paginationItem.type == 'ellipsis'}
									<span class="btn btn-default disabled">&hellip;</span>
								{else}
									{if $paginationItem.is_current}
										<span class="btn btn-primary">{$paginationItem.number|intval}</span>
									{else}
										<a class="btn btn-default" href="{$logPageBaseUrl|escape:'htmlall':'UTF-8'}&log_page={$paginationItem.number|intval}">{$paginationItem.number|intval}</a>
									{/if}
								{/if}
							{/foreach}

							{if $currentLogPage < $totalLogPages}
								<a class="btn btn-default" href="{$logPageBaseUrl|escape:'htmlall':'UTF-8'}&log_page={$currentLogPage + 1}">{l s='Next' mod='dpdgeopost'} &raquo;</a>
							{else}
								<span class="btn btn-default disabled">{l s='Next' mod='dpdgeopost'} &raquo;</span>
							{/if}
						</nav>
					{/if}
				{else}
					<p class="alert alert-info">{l s='No log entries were found for the selected day.' mod='dpdgeopost'}</p>
				{/if}
			</div>
		{else}
			<p class="alert alert-info">{l s='No debug logs have been recorded yet.' mod='dpdgeopost'}</p>
		{/if}
	</div>

	<div class="dpd-debug-log-modal" style="display:none;">
		<div class="dpd-debug-log-modal-backdrop"></div>
		<div class="dpd-debug-log-modal-dialog panel">
			<div class="panel-heading">
				<span>{l s='Log details' mod='dpdgeopost'}</span>
				<button type="button" class="close dpd-debug-log-modal-close">&times;</button>
			</div>
			<div class="panel-body">
				<pre class="dpd-debug-log-modal-content" style="max-height: 70vh; overflow: auto; white-space: pre-wrap;"></pre>
			</div>
		</div>
	</div>

	<script type="text/javascript">
		(function() {
			var modal = document.querySelector('.dpd-debug-log-modal');
			var modalContent = document.querySelector('.dpd-debug-log-modal-content');
			var closeButtons = document.querySelectorAll('.dpd-debug-log-modal-close, .dpd-debug-log-modal-backdrop');
			var entryLinks = document.querySelectorAll('.dpd-debug-log-entry');

			for (var j = 0; j < entryLinks.length; j++) {
				entryLinks[j].addEventListener('click', function(event) {
					event.preventDefault();
					var logIndex = this.getAttribute('data-log-index');
					var source = document.getElementById('dpd-debug-log-details-' + logIndex);
					if (!modal || !modalContent || !source) {
						return;
					}
					modalContent.textContent = source.value;
					modal.style.display = 'block';
				});
			}

			for (var k = 0; k < closeButtons.length; k++) {
				closeButtons[k].addEventListener('click', function() {
					if (modal) {
						modal.style.display = 'none';
					}
				});
			}
		})();
	</script>

	<style>
		.dpd-debug-log-table tbody tr.dpd-debug-log-entry {
			cursor: pointer;
		}
		.dpd-debug-log-table tbody tr.dpd-debug-log-entry:hover {
			background-color: #f5f5f5;
		}
		.dpd-debug-log-pagination {
			margin-top: 10px;
			display: flex;
			flex-wrap: wrap;
			gap: 4px;
		}
		.dpd-debug-log-summary {
			margin-bottom: 8px;
		}
		.dpd-debug-log-modal {
			position: fixed;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			z-index: 9999;
		}
		.dpd-debug-log-modal-backdrop {
			position: absolute;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background: rgba(0, 0, 0, 0.45);
		}
		.dpd-debug-log-modal-dialog {
			position: relative;
			width: 90%;
			max-width: 1000px;
			margin: 4vh auto;
			z-index: 10000;
		}
		.dpd-debug-log-modal-close {
			float: right;
			background: transparent;
			border: 0;
			font-size: 24px;
			line-height: 1;
		}
	</style>
</div>
