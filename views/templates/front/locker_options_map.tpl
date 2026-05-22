<div
    id="dpdgeopost_locker"
    class="dpdgeopost-locker"
    data-cart-id="{$dpd_id_cart|escape:'htmlall':'UTF-8'}"
    data-delivery-address-id="{$dpd_id_delivery_address|escape:'htmlall':'UTF-8'}"
    data-selected-address-template="{$dpd_locker_selected_address_template|escape:'htmlall':'UTF-8'}"
    data-generic-error-message="{$dpd_locker_generic_error_message|escape:'htmlall':'UTF-8'}"
    {if empty($dpd_office_name)}style="display: none"{/if}
>
    {if !empty($dpd_office_name)}
        <div class="alert alert-success">Ati selectat livrare la {$dpd_office_name|escape:'htmlall':'UTF-8'}</div>
    {/if}
</div>
{if $dpd_site_id > 0}
    <iframe id="frameOfficeLocator" name="frameOfficeLocator" src="https://services.dpd.ro/office_locator_widget_v3/office_locator.php?lang=en&showAddressForm=0&siteID={$dpd_site_id|escape:'htmlall':'UTF-8'}&showOfficesList=0&selectOfficeButtonCaption=Select this office&countryId={$dpd_country_id|escape:'htmlall':'UTF-8'}" width="665px" height="500px"></iframe>
{else}
    <iframe id="frameOfficeLocator" name="frameOfficeLocator" src="https://services.dpd.ro/office_locator_widget_v3/office_locator.php?lang=en&showAddressForm=0&showOfficesList=0&selectOfficeButtonCaption=Select this office&countryId={$dpd_country_id|escape:'htmlall':'UTF-8'}" width="665px" height="500px"></iframe>
{/if}