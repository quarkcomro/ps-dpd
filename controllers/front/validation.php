<?php

class DpdPaymentValidationModuleFrontController extends ModuleFrontController {

    public function postProcess()
    {
        $cart = $this->context->cart;
        //$_SESSION['cod_selected_price'] = 9000000;




        $current_country_id = $this->context->country->id;
        $dpd_cot_vat = floatval(Configuration::get('DPD_COD_VAT_' . $current_country_id));
        $dpd_cot_surcharge = floatval(Configuration::get('DPD_COD_SURCHARGE_' . $current_country_id));
        $codExtra = $dpd_cot_surcharge + floatval($dpd_cot_vat)/100 * $dpd_cot_surcharge;

        $_SESSION['cod_selected_price'] = $codExtra;
        $_SESSION['cod_selected_payment'] = true;

        $authorized = false;

        if(!$this->module->active || $cart->id_customer == 0 || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0
        ) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        foreach(Module::getPaymentModules() as $module) {
            if($module['name'] == 'dpdpayment') {
                $authorized = true;
                break;
            }
        }

        if(!$authorized) {
            die($this->l('This payment method is not available'));
        }

        $customer = new Customer($cart->id_customer);

        if(!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }


        $productsPrice = (float) $this->context->cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING, null, null, true);
        $shippingPrice = (float) $this->context->cart->getOrderTotal(false, Cart::ONLY_SHIPPING, null, null, true); // includes cod extra


        $total = $productsPrice + $shippingPrice;

        $this->module->validateOrder(
            (int) $this->context->cart->id,
            _PS_OS_PREPARATION_,
            $total,
            $this->module->displayName,
            null,
            null,
            (int) $this->context->currency->id,
            false,
            $customer->secure_key
        );

        unset($_SESSION['cod_selected_payment']);
        unset($_SESSION['cod_selected_price']);

        Tools::redirect('index.php?controller=order-confirmation&id_cart='.(int)$cart->id.'&id_module='.(int)$this->module->id.'&id_order='.$this->module->currentOrder.'&key='.$customer->secure_key);
    }

}