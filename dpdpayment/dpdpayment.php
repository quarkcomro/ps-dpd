<?php

if (!defined('_PS_VERSION_'))
    exit;

class DpdPayment extends PaymentModule
{
    private $_html = '';
    private $_postErrors = array();

    public $address;

    const CONFIG_OS_DPD_CASH_ON_DELIVERY = 'PS_OS_COD_DPD_PAYMENT_VALIDATION';

    public function __construct()
    {
        $this->name = 'dpdpayment';
        $this->tab  = 'payments_gateways';
        $this->version = '3.0.0';
        $this->author = 'DPD Romania';
        $this->controllers = array('payment', 'validation');
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->bootstrap = true;
        $this->displayName = 'Numerar, la livrare / Ramburs';
        $this->description = 'DPD Cash On Delivery Module';
        $this->confirmUninstall = "Are you sure you want to uninstall this module ?";
        $this->ps_versions_compliancy = array('min' => '1.6.0', 'max' => _PS_VERSION_);

        parent::__construct();
    }

    public function install()
    {
        return
            parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn')
            && $this->registerHook('displayPdfInvoice')
            && $this->installOrderState();
            ;
    }

    public function uninstall()
    {
        return
            parent::uninstall() &&
            $this->unregisterHook('paymentOptions') &&
            $this->unregisterHook('paymentReturn') &&
            $this->unregisterHook('displayPdfInvoice');
    }

    public function installOrderState()
    {
        if (Configuration::getGlobalValue(DpdPayment::CONFIG_OS_DPD_CASH_ON_DELIVERY)) {
            $orderState = new OrderState((int) Configuration::getGlobalValue(Ps_Cashondelivery::CONFIG_OS_CASH_ON_DELIVERY));

            if (Validate::isLoadedObject($orderState) && $this->name === $orderState->module_name) {
                return true;
            }
        }

        return $this->createOrderState(
            static::CONFIG_OS_DPD_CASH_ON_DELIVERY,
            [
                'en' => 'Awaiting DPD Payment validation',
                'ro' => 'In asteptare validate DPD Payment',
            ],
            true === (bool) version_compare(_PS_VERSION_, '1.7.7.0', '>=') ? '#4169E1' : '#34219E'
        );
    }

    public function getContent()
    {

        $output = null;

        $activeCountries = Country::getCountries($this->context->language->id, true);


        if (Tools::isSubmit('submit' . $this->name)) {
            foreach($activeCountries as $activeCountry) {
                $dpd_cod_surcharge = strval(Tools::getValue('DPD_COD_SURCHARGE_'.$activeCountry['id_country']));
                $dpd_cod_vat = strval(Tools::getValue('DPD_COD_VAT_'.$activeCountry['id_country']));

                if ($dpd_cod_surcharge === false || !Validate::isGenericName($dpd_cod_surcharge)) {
                    $output .= $this->displayError($this->l('Invalid COD surcharge value for ' . $activeCountry['name']));
                } else if(!$dpd_cod_vat || empty($dpd_cod_vat) || !Validate::isGenericName($dpd_cod_vat)) {
                    $output .= $this->displayError($this->l('Invalid Vat tax for ' . $activeCountry['name']));
                }
                else {
                    Configuration::updateValue('DPD_COD_SURCHARGE_'.$activeCountry['id_country'], $dpd_cod_surcharge);
                    Configuration::updateValue('DPD_COD_VAT_'.$activeCountry['id_country'], $dpd_cod_vat);

                    $full_price = ( floatval($dpd_cod_vat)/100 * floatval($dpd_cod_surcharge) ) + floatval($dpd_cod_surcharge);

                    $this->installCODproduct($activeCountry, $full_price);

                    $output .= $this->displayConfirmation($this->l('Settings updated for '.$activeCountry['name']));
                }
            }
        }

        return $output . $this->displayForm();
    }

    public function displayForm()
    {
        // Get default language
        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');

        $inputs = array();

        $activeCountries = Country::getCountries($this->context->language->id, true);

        foreach ($activeCountries as $activeCountry) {
            $inputs[] = array(
                'type' => 'text',
                'label' =>  $activeCountry['name'] . ' '. $this->l('DPD COD Tax'),
                'name' => 'DPD_COD_SURCHARGE_'.$activeCountry['id_country'],
                'size' => 20,
                'required' => false
            );

            $inputs[] = array(
                'type' => 'text',
                'label' =>  $activeCountry['name'] . ' '. $this->l('VAT to be applied (in %)'),
                'name' => 'DPD_COD_VAT_'.$activeCountry['id_country'],
                'size' => 20,
                'required' => false
            );
        }

        // Init Fields form array
        $fieldsForm[0]['form'] = [
            'legend' => array(
                'title' => $this->l('Settings'),
            ),
            'input' => $inputs,
            'submit' => array(
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right'
            )
        ];

        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        // Language
        $helper->default_form_language = $defaultLang;
        $helper->allow_employee_form_lang = $defaultLang;

        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;        // false -> remove toolbar
        $helper->toolbar_scroll = true;      // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action = 'submit' . $this->name;
        $helper->toolbar_btn = [
            'save' => [
                'desc' => $this->l('Save'),
                'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&save' . $this->name .
                    '&token=' . Tools::getAdminTokenLite('AdminModules'),
            ],
            'back' => [
                'href' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            ]
        ];

        foreach($activeCountries as $activeCountry) {

            $helper->fields_value['DPD_COD_SURCHARGE_'.$activeCountry['id_country']] = Configuration::get('DPD_COD_SURCHARGE_'.$activeCountry['id_country']);
            $helper->fields_value['DPD_COD_VAT_'.$activeCountry['id_country']] = Configuration::get('DPD_COD_VAT_'.$activeCountry['id_country']);
        }

        // Load current value


        return $helper->generateForm($fieldsForm);
    }

    public function hookPaymentOptions($params)
    {

        if (!$this->active) {
            return;
        }

        $formAction = $this->context->link->getModuleLink($this->name, 'validation', array(), true);

        $currency = new Currency($params['cart']->id_currency);

        $cart = new Cart($params['cart']->id);
        $address = new Address($cart->id_address_delivery);

        $dpd_cot_vat = floatval(Configuration::get('DPD_COD_VAT_' . $address->id_country));
        $dpd_cot_surcharge = floatval(Configuration::get('DPD_COD_SURCHARGE_' . $address->id_country));

        $vatTax = $dpd_cot_vat * $dpd_cot_surcharge/100;
       
        $product_id = false;

        $this->smarty->assign([
            'action' => $formAction,
            'params' => $params,
            'cart' => $params['cart'],
            'cart_id' => $params['cart']->id,
            'product_id' => $product_id,
            'currency' => $currency,
            'cart_total' => $cart->getOrderTotal(),
            'total_to_pay' => $cart->getOrderTotal() + $dpd_cot_surcharge + floatval($vatTax),
            'DPD_COD_SURCHARGE' => $dpd_cot_surcharge + floatval($vatTax)
        ]);


        $paymentForm = $this->fetch('module:dpdpayment/views/templates/hook/payment_options.tpl');

        $newOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption;
        $newOption->setModuleName($this->displayName)
            ->setCallToActionText($this->displayName)
            ->setAction($formAction)
            ->setForm($paymentForm);

        $payment_options = array(
            $newOption
        );

        return $payment_options;
    }

    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return;
        }

        return $this->fetch('module:dpdpayment/views/templates/hook/payment_return.tpl');
    }

    private function installCODproduct($activeCountry, $price)
    {
        $res = true;

//        $existent_product_id = $this->isInstalledCODProduct($activeCountry);
//
//        $p = new Product($existent_product_id, false, Configuration::get('PS_LANG_DEFAULT'));
//        $p->reference = 'DPD_COD_'.$activeCountry['id_country'];
//        $p->name = 'Cash On Delivery Fee '.$activeCountry['name'];
//        $p->price = $price;
//        $p->id_tax_rules_group = 0;
//        $p->is_virtual = false;
//        $p->indexed = 0;
//        $p->active = false;
//        $p->minimal_quantity = 1;
//        $p->available_for_order = true;
//        $p->id_category_default = Configuration::get('PS_HOME_CATEGORY');
//        $p->link_rewrite = 'pr-dpd-cod-'.$activeCountry['id_country'];
//        $res &= $p->save();
//        $res &= $p->addToCategories(array(Configuration::get('PS_HOME_CATEGORY')));

        $res = Configuration::updateValue('DOD_COD_PRODUCT_'.$activeCountry['id_country'], $price);

        return $res;
    }

    public function hookDisplayPDFInvoice($params) {

        return '';
    }

    /**
     * Create custom OrderState used for payment
     *
     * @param string $configurationKey Configuration key used to store OrderState identifier
     * @param array $nameByLangIsoCode An array of name for all languages, default is en
     * @param string $color Color of the label
     * @param bool $isLogable consider the associated order as validated
     * @param bool $isPaid set the order as paid
     * @param bool $isInvoice allow a customer to download and view PDF versions of his/her invoices
     * @param bool $isShipped set the order as shipped
     * @param bool $isDelivery show delivery PDF
     * @param bool $isPdfDelivery attach delivery slip PDF to email
     * @param bool $isPdfInvoice attach invoice PDF to email
     * @param bool $isSendEmail send an email to the customer when his/her order status has changed
     * @param string $template Only letters, numbers and underscores are allowed. Email template for both .html and .txt
     * @param bool $isHidden hide this status in all customer orders
     * @param bool $isUnremovable Disallow delete action for this OrderState
     * @param bool $isDeleted Set OrderState deleted
     *
     * @return bool
     */
    private function createOrderState(
        $configurationKey,
        array $nameByLangIsoCode,
        $color,
        $isLogable = false,
        $isPaid = false,
        $isInvoice = false,
        $isShipped = false,
        $isDelivery = false,
        $isPdfDelivery = false,
        $isPdfInvoice = false,
        $isSendEmail = false,
        $template = '',
        $isHidden = false,
        $isUnremovable = true,
        $isDeleted = false
    ) {
        try {
            $tabNameByLangId = [];

            foreach ($nameByLangIsoCode as $langIsoCode => $name) {
                foreach (Language::getLanguages(false) as $language) {
                    if (Tools::strtolower($language['iso_code']) === $langIsoCode) {
                        $tabNameByLangId[(int)$language['id_lang']] = $name;
                    } elseif (isset($nameByLangIsoCode['en'])) {
                        $tabNameByLangId[(int)$language['id_lang']] = $nameByLangIsoCode['en'];
                    }
                }
            }

            $orderState = new OrderState();
            $orderState->module_name = $this->name;
            $orderState->name = $tabNameByLangId;
            $orderState->color = $color;
            $orderState->logable = $isLogable;
            $orderState->paid = $isPaid;
            $orderState->invoice = $isInvoice;
            $orderState->shipped = $isShipped;
            $orderState->delivery = $isDelivery;
            $orderState->pdf_delivery = $isPdfDelivery;
            $orderState->pdf_invoice = $isPdfInvoice;
            $orderState->send_email = $isSendEmail;
            $orderState->hidden = $isHidden;
            $orderState->unremovable = $isUnremovable;
            $orderState->template = $template;
            $orderState->deleted = $isDeleted;
            $result = (bool)$orderState->add();

            if (false === $result) {
                $this->_errors[] = sprintf(
                    'Failed to create OrderState %s',
                    $configurationKey
                );

                return false;
            }

            $result = (bool)Configuration::updateGlobalValue($configurationKey, (int)$orderState->id);

            if (false === $result) {
                $this->_errors[] = sprintf(
                    'Failed to save OrderState %s to Configuration',
                    $configurationKey
                );

                return false;
            }

           return true;
        } catch (\Throwable $ex) {
            $this->_errors[] = sprintf('Error: %s',
                    $ex->getMessage()
                );

            return false;
        }
    }
}
