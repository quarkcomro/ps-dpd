{*
	HelperList already wraps its output in <div class="panel col-lg-12"> with its own
	panel-heading driven by $helper->title / $helper->title_icon (set in the controller's
	renderHelperList()). We don't add an outer panel-heading here — that was producing the
	duplicate "Shipping list" title. The Request-courier button sits as a sibling block
	below the helper output and is styled to look like a panel-footer continuation.
*}
<div id="fieldset_shipments">
	{$helper_list_html nofilter}

	<div class="dpd-shipment-list-footer">
		<button type="button" class="btn btn-default" id="displayPickupDialog">
			<i class="icon-calendar"></i> {l s='Request DPD Courier' mod='dpdgeopost'}
		</button>
	</div>
</div>

<div id="dpdgeopost_pickup_dialog" class="dpd-modal">
	<div class="dpd-modal__header">
		<h2>{l s='Arrange DPD Geopost pickup' mod='dpdgeopost'}</h2>
		<button type="button" class="dpd-modal__close dpd-close-pickup-dialog" aria-label="Close">&times;</button>
	</div>

	<div class="dpd-modal__body">
		<div id="dpdgeopost_pickup_dialog_mssg"></div>

		<div class="dpd-form-group">
			<label class="dpd-form-label" for="dpdgeopost_pickup_datetime">
				{l s='Pickup date' mod='dpdgeopost'}
				<span class="dpd-required">*</span>
			</label>
			<input type="text" id="dpdgeopost_pickup_datetime" class="form-control"
				autocomplete="off"
				value="{$smarty.now|date_format:'%Y-%m-%d'}"
				name="dpdgeopost_pickup_data[date]" />
			<small class="dpd-form-help">{l s='Date the courier should pick up the parcel(s)' mod='dpdgeopost'}</small>
		</div>

		<div class="dpd-form-row">
			<div class="dpd-form-group dpd-form-col">
				<label class="dpd-form-label" for="dpdgeopost_pickup_fromtime">
					{l s='From time' mod='dpdgeopost'}
				</label>
				<input type="text" id="dpdgeopost_pickup_fromtime" class="form-control"
					autocomplete="off"
					value="09:00:00"
					name="dpdgeopost_pickup_data[fromTime]" />
			</div>
			<div class="dpd-form-group dpd-form-col">
				<label class="dpd-form-label" for="dpdgeopost_pickup_totime">
					{l s='To time' mod='dpdgeopost'}
				</label>
				<input type="text" id="dpdgeopost_pickup_totime" class="form-control"
					autocomplete="off"
					value="16:00:00"
					name="dpdgeopost_pickup_data[toTime]" />
			</div>
		</div>

		<div class="dpd-form-group">
			<label class="dpd-form-label" for="dpd_pickup_contact_name">
				{l s='Contact name' mod='dpdgeopost'}
				<span class="dpd-required">*</span>
			</label>
			<input type="text" id="dpd_pickup_contact_name" class="form-control"
				value="{$employee->firstname|escape:'htmlall':'UTF-8'}"
				name="dpdgeopost_pickup_data[contactName]" />
			<small class="dpd-form-help">{l s='Sender name' mod='dpdgeopost'}</small>
		</div>

		<div class="dpd-form-group">
			<label class="dpd-form-label" for="dpd_pickup_contact_email">
				{l s='Contact e-mail' mod='dpdgeopost'}
			</label>
			<input type="email" id="dpd_pickup_contact_email" class="form-control"
				value="{$employee->email|escape:'htmlall':'UTF-8'}"
				name="dpdgeopost_pickup_data[contactEmail]" />
			<small class="dpd-form-help">{l s='Sender email' mod='dpdgeopost'}</small>
		</div>

		<div class="dpd-form-group">
			<label class="dpd-form-label" for="dpd_pickup_contact_phone">
				{l s='Contact phone no.' mod='dpdgeopost'}
			</label>
			<input type="tel" id="dpd_pickup_contact_phone" class="form-control"
				name="dpdgeopost_pickup_data[contactPhone]" />
			<small class="dpd-form-help">{l s='Sender phone number' mod='dpdgeopost'}</small>
		</div>

		<p class="dpd-required-note">
			<span class="dpd-required">*</span> {l s='Required fields' mod='dpdgeopost'}
		</p>
	</div>

	<div class="dpd-modal__footer">
		<button type="button" class="btn btn-outline-secondary dpd-close-pickup-dialog" id="close_dpdgeopost_pickup_dialog">
			{l s='Cancel' mod='dpdgeopost'}
		</button>
		<button type="button" class="btn btn-primary" id="submit_dpdgeopost_pickup_dialog">
			{l s='Submit' mod='dpdgeopost'}
		</button>
	</div>
</div>

<script>
	var dpdgeopost_error_no_shipment_selected = '{l s='Select at least one shipment' mod='dpdgeopost' js=1}';
	var dpdgeopost_error_puckup_not_available = '{l s='To arrange pickup, manifest or label must be printed' mod='dpdgeopost' js=1}';
	var dpdgeopost_label_pickup_submitting = '{l s='Submitting…' mod='dpdgeopost' js=1}';
	var dpd_geopost_id_lang = '{$dpd_geopost_id_lang|escape:'htmlall':'UTF-8'}';

	$(document).ready(function(){
		$('#dpdgeopost_pickup_fromtime, #dpdgeopost_pickup_totime').datetimepicker({
			currentText: '{l s='Now' mod='dpdgeopost'}',
			closeText: '{l s='Done' mod='dpdgeopost'}',
			timeOnly: true,
			ampm: false,
			timeFormat: 'hh:mm:ss',
			timeSuffix: '',
			timeOnlyTitle: '{l s='Choose Time' mod='dpdgeopost'}',
			timeText: '{l s='Time' mod='dpdgeopost'}',
			hourText: '{l s='Hour' mod='dpdgeopost'}',
			minuteText: '{l s='Minute' mod='dpdgeopost'}',
		});

		$('#dpdgeopost_pickup_datetime').datepicker({
			dateFormat: 'yy-mm-dd'
		});
	});
</script>
