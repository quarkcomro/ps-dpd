<form method="post" action="{$action}">
    <P>Cash on Delivery through DPD.</P>
    <P>Extra charge: {$currency->iso_code} {$DPD_COD_SURCHARGE}</P>
    <p><b>You'll have a total to pay of</b>: {$currency->iso_code} {$total_to_pay}</p>
    <input type="hidden" name="dpd_cart_id" id="dpd_cart_id" value="{$cart_id}" />
    <input type="hidden" name="dpd_product_id" value="{$product_id}" />
</form>
