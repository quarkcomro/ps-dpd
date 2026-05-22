<?php

if (!defined('_PS_VERSION_'))
	exit;

require_once(_PS_MODULE_DIR_ . 'dpdgeopost/config.api.php');
require_once(_DPDGEOPOST_CLASSES_DIR_ . 'controller.php');
require_once(_DPDGEOPOST_MODULE_DIR_ . 'dpdgeopost.rest.php');
require_once(_DPDGEOPOST_CLASSES_DIR_ . 'messages.controller.php');
require_once(_DPDGEOPOST_CLASSES_DIR_ . 'DpdGeoPostPickupPoint.php');

require_once(_DPDGEOPOST_MODELS_DIR_ . 'ObjectModel.php');
require_once(_DPDGEOPOST_MODELS_DIR_ . 'CSV.php');
require_once(_DPDGEOPOST_MODELS_DIR_ . 'Configuration.php');
require_once(_DPDGEOPOST_MODELS_DIR_ . 'Shipment.php');
require_once(_DPDGEOPOST_MODELS_DIR_ . 'Manifest.php');
require_once(_DPDGEOPOST_MODELS_DIR_ . 'Parcel.php');
require_once(_DPDGEOPOST_MODELS_DIR_ . 'Pickup.php');
require_once(_DPDGEOPOST_MODELS_DIR_ . 'Carrier.php');
require_once(_DPDGEOPOST_MODELS_DIR_ . 'PostcodeSearch.php');
require_once(_DPDGEOPOST_MODELS_DIR_ . 'DpdPostcodeAddress.php');
require_once(_DPDGEOPOST_MODELS_DIR_ . 'Pudo.php');
require_once(_DPDGEOPOST_MODELS_DIR_ . 'DpdAddressSearch.php');
require_once(_DPDGEOPOST_MODELS_DIR_ . 'DpdAddressSearch.php');



class DpdGeopost extends CarrierModule
{
	private $_html = '';
	public  $module_url;

	public  $id_carrier; // mandatory field for carrier recognision in front office
	private static $parcels = array(); // used to cache parcel setup for price calculation in front office
	private static $products = array(); // used to cache producrs
	private static $carriers = array(); // DPD carriers prices cache, used in front office

	private static $addresses = array();



	public function __construct()
	{
		$this->name = 'dpdgeopost';
		$this->tab = 'shipping_logistics';
		$this->version = '1.0.1';
		$this->author = 'DPD Romania';
		$this->ps_versions_compliancy = array('min' => '8.0.0', 'max' => '9.1.0');
        $this->bootstrap = true;
		parent::__construct();

		$this->displayName = $this->l('DPD GeoPost');
		$this->description = $this->l('DPD GeoPost shipping module');


		if (defined('_PS_ADMIN_DIR_')) {
			$this->module_url = $this->getModuleLink('AdminModules');
		}

		$this->checkDbStructure();
		$this->ensureCustomerAddressFormHooksRegistered();
		$this->ensureAdminOrderHooksRegistered();
	}

	public function install()
	{
		if (!function_exists('curl_init')) {
            $this->_errors[] = $this->l('Missing php-curl extension.');
            return false;
        }

		$sql = '
			CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . _DPDGEOPOST_CSV_DB_ . '` (
				`id_csv` int(11) NOT NULL AUTO_INCREMENT,
				`id_shop` int(11) NOT NULL,
				`date_add` datetime DEFAULT NULL,
				`date_upd` datetime DEFAULT NULL,
				`country` varchar(255) NOT NULL,
				`region` varchar(255) NOT NULL,
				`zip` varchar(255) NOT NULL,
				`weight_from` varchar(255) NOT NULL,
				`weight_to` varchar(255) NOT NULL,
				`shipping_price` varchar(255) NOT NULL,
				`shipping_price_percentage` varchar(255) NOT NULL DEFAULT 0,
				`currency` varchar(255) NOT NULL,
				`method_id` varchar(11) NOT NULL,
				`cod_surcharge` varchar(255) NOT NULL DEFAULT "0",
				`cod_surcharge_percentage` varchar(255) NOT NULL DEFAULT "0",
				`cod_min_surcharge` varchar(255) NOT NULL DEFAULT "0",
				PRIMARY KEY (`id_csv`)
			) ENGINE=InnoDB  DEFAULT CHARSET=utf8';

		if (!Db::getInstance()->execute($sql)) {
            $this->_errors[] = $this->l('Error creating ' . _DPDGEOPOST_CSV_DB_ . ' table');
            return false;
        }

		$sql = '
			CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . _DPDGEOPOST_PARCEL_DB_ . '` (
				`id_parcel` int(10) NOT NULL AUTO_INCREMENT,
				`id_order` int(10) NOT NULL,
				`parcelReferenceNumber` varchar(30) NOT NULL,
				`id_product` int(10) NOT NULL,
				`id_product_attribute` int(10) NOT NULL,
				`date_add` datetime DEFAULT NULL,
				`date_upd` datetime DEFAULT NULL,
				PRIMARY KEY (`id_parcel`)
			) ENGINE=InnoDB  DEFAULT CHARSET=utf8';

		if (!Db::getInstance()->execute($sql)) {
            $this->_errors[] = $this->l('Error creating ' . _DPDGEOPOST_PARCEL_DB_ . ' table');
            return false;
        }

		$sql = '
			CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . _DPDGEOPOST_CARRIER_DB_ . '` (
				`id_dpd_geopost_carrier` int(10) NOT NULL AUTO_INCREMENT,
				`id_carrier` int(10) NOT NULL,
				`id_reference` int(10) NOT NULL,
				`date_add` datetime NOT NULL,
				`date_upd` datetime NOT NULL,
				PRIMARY KEY (`id_dpd_geopost_carrier`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8';

		if (!Db::getInstance()->execute($sql)) {
            $this->_errors[] = $this->l('Error creating ' . _DPDGEOPOST_CARRIER_DB_ . ' table');
            return false;
        }

		$sql = '
			CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . _DPDGEOPOST_SHIPMENT_DB_ . '` (
				`id_shipment` BIGINT(20) NOT NULL,
				`id_order` int(10) NOT NULL,
				`id_manifest` BIGINT(20) NOT NULL DEFAULT "0",
				`label_printed` int(1) NOT NULL DEFAULT "0",
				`date_pickup` datetime DEFAULT NULL,
				PRIMARY KEY (`id_order`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8';

		if (!Db::getInstance()->execute($sql)) {
            $this->_errors[] = $this->l('Error creating ' . _DPDGEOPOST_SHIPMENT_DB_ . ' table');
            return false;
        }

		$sql = '
			CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . _DPDGEOPOST_REFERENCE_DB_ . '` (
				`id_order` int(10) NOT NULL,
				`reference` varchar(9) NOT NULL,
				PRIMARY KEY (`id_order`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8';

		if (!Db::getInstance()->execute($sql)) {
            $this->_errors[] = $this->l('Error creating ' . _DPDGEOPOST_SHIPMENT_DB_ . ' table');
            return false;
        }

		require_once(_DPDGEOPOST_MODULE_DIR_ . DIRECTORY_SEPARATOR . 'upgrade' . DIRECTORY_SEPARATOR . 'Upgrade-1.0.php');
		require_once(_DPDGEOPOST_MODULE_DIR_ . DIRECTORY_SEPARATOR . 'upgrade' . DIRECTORY_SEPARATOR . 'Upgrade-1.1.php');
		require_once(_DPDGEOPOST_MODULE_DIR_ . DIRECTORY_SEPARATOR . 'upgrade' . DIRECTORY_SEPARATOR . 'Upgrade-1.2.php');
		require_once(_DPDGEOPOST_MODULE_DIR_ . DIRECTORY_SEPARATOR . 'upgrade' . DIRECTORY_SEPARATOR . 'Upgrade-1.3.php');
		require_once(_DPDGEOPOST_MODULE_DIR_ . DIRECTORY_SEPARATOR . 'upgrade' . DIRECTORY_SEPARATOR . 'Upgrade-1.4.php');
		require_once(_DPDGEOPOST_MODULE_DIR_ . DIRECTORY_SEPARATOR . 'upgrade' . DIRECTORY_SEPARATOR . 'Upgrade-2.1.php');
		require_once(_DPDGEOPOST_MODULE_DIR_ . DIRECTORY_SEPARATOR . 'upgrade' . DIRECTORY_SEPARATOR . 'Upgrade-2.2.php');
		require_once(_DPDGEOPOST_MODULE_DIR_ . DIRECTORY_SEPARATOR . 'upgrade' . DIRECTORY_SEPARATOR . 'Upgrade-2.3.php');
        require_once(_DPDGEOPOST_MODULE_DIR_ . DIRECTORY_SEPARATOR . 'upgrade' . DIRECTORY_SEPARATOR . 'Upgrade-2.9.php');
        require_once(_DPDGEOPOST_MODULE_DIR_ . DIRECTORY_SEPARATOR . 'upgrade' . DIRECTORY_SEPARATOR . 'Upgrade-3.0.php');

		upgrade_module_1_0(null);
		upgrade_module_1_1(null);
		upgrade_module_1_2(null);
		upgrade_module_1_3(null);
		upgrade_module_1_4(null);
		upgrade_module_2_1(null);
		upgrade_module_2_2(null);
		upgrade_module_2_3(null);
        upgrade_module_2_9(null);
        upgrade_module_3_0();

		$current_date = date('Y-m-d H:i:s');
		$currency = Currency::getDefaultCurrency();

		$shops = Shop::getShops();

		foreach (array_keys($shops) as $id_shop) {
			$sql = "
				INSERT INTO `" . _DB_PREFIX_ . _DPDGEOPOST_CSV_DB_ . "`
					(`id_shop`, `date_add`, `date_upd`, `country`, `region`, `zip`, `weight_from`, `weight_to`, `shipping_price`, `currency`, `method_id`)
				VALUES
					('" . (int)$id_shop . "', '" . pSQL($current_date) . "', '" . pSQL($current_date) . "', '*', '*', '*', '0', '0.5', 0, '" . pSQL($currency->iso_code) . "', '" . (int)_DPDGEOPOST_CLASSIC_ID_ . "'),
					('" . (int)$id_shop . "', '" . pSQL($current_date) . "', '" . pSQL($current_date) . "', '*', '*', '*', '0', '0.5', 0, '" . pSQL($currency->iso_code) . "', '" . (int)_DPDGEOPOST_INTERNATIONAL_ID_ . "'),
					('" . (int)$id_shop . "', '" . pSQL($current_date) . "', '" . pSQL($current_date) . "', '*', '*', '*', '0', '0.5', 0, '" . pSQL($currency->iso_code) . "', '" . (int)_DPDGEOPOST_REGIONAL_EXPRESS_ID_ . "'),
					('" . (int)$id_shop . "', '" . pSQL($current_date) . "', '" . pSQL($current_date) . "', '*', '*', '*', '0', '0.5', 0, '" . pSQL($currency->iso_code) . "', '*')
				";

			if (!Db::getInstance()->execute($sql)) {
                $this->_errors[] = $this->l('Error creating inserting shops in ' . _DPDGEOPOST_CSV_DB_ . ' table');
                return false;
            }
		}

		if (!parent::install()) {
            return false;
        }

		if (!$this->registerHook('paymentTop')) {
                $this->_errors[] = $this->l('Could not register paymentTop hook');
                return false;
            }

		$this->registerHook('displayBackOfficeHeader');
		$this->registerHook('header');
		$this->registerHook('displayFooter');
		$this->registerHook('actionFrontControllerSetMedia');
		$this->registerHook('displayHeader');
		$this->registerHook('extraCarrier');
		$this->registerHook('actionCarrierUpdate');
		$this->registerHook('displayCarrierExtraContent');
		$this->registerHook('displayCarrierList');
		$this->registerHook('actionValidateStepComplete');
		$this->registerHook('displayAdditionalCustomerAddressFields');
		$this->registerHook('additionalCustomerAddressFields');
		$this->registerHook('actionValidateCustomerAddressForm');
		$this->registerHook('actionSubmitCustomerAddressForm');
		$this->registerHook('actionCustomerAddressFormBuilderModifier');
		$this->registerHook('actionAfterCreateCustomerAddressFormHandler');
		$this->registerHook('actionAfterUpdateCustomerAddressFormHandler');
		$this->registerHook('displayAdminOrderTabLink');
		$this->registerHook('displayAdminOrderTabContent');

		$this->installStates();
		if (!(bool)$this->registerHook('displayAdminOrder')) {
            $this->_errors[] = $this->l('Could not register displayAdminOrder hook');
        }

        return true;
	}

	public function uninstall()
	{
		require_once(_DPDGEOPOST_CLASSES_DIR_ . 'service.php');
		require_once(_DPDGEOPOST_CLASSES_DIR_ . 'dpd_classic.service.php');
		require_once(_DPDGEOPOST_CLASSES_DIR_ . 'dpd_classic_cod.service.php');
		require_once(_DPDGEOPOST_CLASSES_DIR_ . 'dpd_locco.service.php');
		require_once(_DPDGEOPOST_CLASSES_DIR_ . 'dpd_locco_cod.service.php');
		require_once(_DPDGEOPOST_CLASSES_DIR_ . 'dpd_international.service.php');
		require_once(_DPDGEOPOST_CLASSES_DIR_ . 'dpd_international_cod.service.php');
		require_once(_DPDGEOPOST_CLASSES_DIR_ . 'dpd_regionalexpress.service.php');
		require_once(_DPDGEOPOST_CLASSES_DIR_ . 'dpd_regionalexpress_cod.service.php');
		require_once(_DPDGEOPOST_CLASSES_DIR_ . 'dpd_hungary.service.php');
		require_once(_DPDGEOPOST_CLASSES_DIR_ . 'dpd_hungary_cod.service.php');
		require_once(_DPDGEOPOST_CLASSES_DIR_ . 'dpd_standard_locker.service.php');

        return
            parent::uninstall() &&
			$this->unregisterHook('extraCarrier') &&
			$this->unregisterHook('actionCarrierUpdate') &&
			$this->unregisterHook('displayCarrierExtraContent') &&
            $this->registerHook('displayBackOfficeHeader') &&
            $this->registerHook('header') &&
            $this->registerHook('displayFooter') &&
            $this->registerHook('actionFrontControllerSetMedia') &&
            $this->registerHook('displayHeader') &&
            $this->registerHook('displayCarrierList') &&
            $this->registerHook('actionValidateStepComplete') &&
            $this->registerHook('displayAdditionalCustomerAddressFields');


            DpdGeopostCarrierClassicService::delete() &&
			DpdGeopostCarrierClassicCODService::delete() &&
			DpdGeopostCarrierLoccoService::delete() &&
			DpdGeopostCarrierLoccoCODService::delete() &&
			DpdGeopostCarrierInternationalService::delete() &&
			DpdGeopostCarrierInternationalCODService::delete() &&
			DpdGeopostCarrierRegionalExpressService::delete() &&
			DpdGeopostCarrierRegionalExpressCODService::delete() &&
			DpdGeopostCarrierHungaryService::delete() &&
			DpdGeopostCarrierHungaryCODService::delete() &&
            DpdGeopostCarrierStandardLockerService::delete() &&
			$this->dropTables() &&
			$this->dropTriggers() &&
			$this->dropColumns() &&
			DpdGeopostConfiguration::deleteConfiguration();
	}

    /**
     * @return bool
     */
	private function dropTables(): bool
	{
		try {
            DB::getInstance()->Execute('
			DROP TABLE IF EXISTS
				`' . _DB_PREFIX_ . _DPDGEOPOST_CSV_DB_ . '`,
				`' . _DB_PREFIX_ . _DPDGEOPOST_PARCEL_DB_ . '`,
				`' . _DB_PREFIX_ . _DPDGEOPOST_CARRIER_DB_ . '`,
				`' . _DB_PREFIX_ . _DPDGEOPOST_SHIPMENT_DB_ . '`,
				`' . _DB_PREFIX_ . _DPDGEOPOST_REFERENCE_DB_ . '`,
				`' . _DB_PREFIX_ . _DPDGEOPOST_REST_DPD_ADDRESS_DB_ . '`,
				`' . _DB_PREFIX_ . _DPDGEOPOST_REST_DPD_POSTCODES_DB_ . '`
		');
            return true;
        } catch (Throwable $ex) {
            $this->_errors[]  = $ex->getMessage();
            return false;
        }
	}


    /**
     * @return bool
     */
	private function dropColumns(): bool
	{
		try {
            $dbPrefix = _DB_PREFIX_;
            DB::getInstance()->Execute("ALTER TABLE `{$dbPrefix}address` DROP COLUMN IF EXISTS dpd_postcode");
            DB::getInstance()->Execute("ALTER TABLE `{$dbPrefix}address` DROP COLUMN IF EXISTS dpd_office");
            DB::getInstance()->Execute("ALTER TABLE `{$dbPrefix}address` DROP COLUMN IF EXISTS dpd_office_type");
            DB::getInstance()->Execute("ALTER TABLE `{$dbPrefix}address` DROP COLUMN IF EXISTS dpd_office_name");
            DB::getInstance()->Execute("ALTER TABLE `{$dbPrefix}address` DROP COLUMN IF EXISTS dpd_block");
            DB::getInstance()->Execute("ALTER TABLE `{$dbPrefix}address` DROP COLUMN IF EXISTS dpd_complex");
            DB::getInstance()->Execute("ALTER TABLE `{$dbPrefix}address` DROP COLUMN IF EXISTS dpd_street");
            DB::getInstance()->Execute("ALTER TABLE `{$dbPrefix}address` DROP COLUMN IF EXISTS dpd_site");
            DB::getInstance()->Execute("ALTER TABLE `{$dbPrefix}address` DROP COLUMN IF EXISTS dpd_state");
            DB::getInstance()->Execute("ALTER TABLE `{$dbPrefix}address` DROP COLUMN IF EXISTS dpd_country");
            DB::getInstance()->Execute("ALTER TABLE `{$dbPrefix}address` DROP COLUMN IF EXISTS dpd_shipment_type");

            return true;
        } catch (Throwable $ex) {
            $this->_errors[]  = $ex->getMessage();
            return false;
        }
	}

    /**
     * @return bool
     */
	private function dropTriggers(): bool
	{
        try {
            DB::getInstance()->Execute('
			DROP TRIGGER IF EXISTS `dpd_trigger_update_address`
		');
            return true;
        } catch (Throwable $ex) {
            $this->_errors[]  = $ex->getMessage();
            return false;
        }
	}

	/**
	 * module configuration page
	 * @return page HTML code
	 */

	private function setGlobalVariablesForAjax()
	{
		require_once(_DPDGEOPOST_CLASSES_DIR_ . 'csv.controller.php');
		$this->context->smarty->assign(array(
			'download_csv_action'	=> DpdGeopostCSVController::SETTINGS_DOWNLOAD_CSV_ACTION,
			'dpd_geopost_ajax_uri' 	=> $this->getAjaxFrontControllerUrl(),
			'dpd_geopost_pdf_uri' 	=> $this->getPdfFrontControllerUrl(),
			'dpd_geopost_token'		=> sha1(_COOKIE_KEY_ . $this->name),
			'dpd_geopost_id_shop' 	=> (int)$this->context->shop->id,
			'dpd_geopost_id_lang' 	=> (int)$this->context->language->id
		));
	}

	public function getContent()
	{

		$this->displayFlashMessagesIfIsset();

		$this->context->controller->addJS(_DPDGEOPOST_JS_URI_ . 'backoffice.js');
		$this->context->controller->addCSS(_DPDGEOPOST_CSS_URI_ . 'backoffice.css');

		$this->setGlobalVariablesForAjax();
		$this->context->smarty->assign('dpd_geopost_other', DpdGeopostConfiguration::OTHER);
		$this->_html .= $this->context->smarty->fetch(_DPDGEOPOST_TPL_DIR_ . 'admin/prepare.tpl');

		switch (Tools::getValue('menu')) {
			case 'configuration':
				require_once(_DPDGEOPOST_CLASSES_DIR_ . 'configuration.controller.php');
				DpdGeopostConfigurationController::init();

				$this->context->smarty->assign('path', array($this->displayName, $this->l('Settings')));
				$this->displayNavigation();
					$this->displayShopRestrictionWarning();

				$configuration_controller = new DpdGeopostConfigurationController();
				$this->_html .= $configuration_controller->getSettingsPage();
				break;
			case 'logs':
				require_once(_DPDGEOPOST_CLASSES_DIR_ . 'logs.controller.php');

				$this->context->smarty->assign('path', array($this->displayName, $this->l('Logs')));
				$this->displayNavigation();

				$logs_controller = new DpdGeopostLogsController();
				$this->_html .= $logs_controller->getLogsPage();
				break;
			case 'csv':
				require_once(_DPDGEOPOST_CLASSES_DIR_ . 'csv.controller.php');
				DpdGeopostCSVController::init();

				$this->context->smarty->assign('path', array($this->displayName, $this->l('Price rules')));
				$this->displayNavigation();

					if (Shop::getContext() != Shop::CONTEXT_SHOP) {
						$this->_html .= $this->displayWarnings(array($this->l('CSV management is disabled when all shops or group of shops are selected')));
						break;
					}
				$csv_controller = new DpdGeopostCSVController();
				$this->_html .= $csv_controller->getCSVPage();
				break;
			case 'help':
				$this->context->smarty->assign('path', array($this->displayName, $this->l('Help')));
				$this->displayNavigation();
				break;
			case 'postcodeUpdate':
				require_once(_DPDGEOPOST_CLASSES_DIR_ . 'postcode.controller.php');

				$this->context->smarty->assign('path', array($this->displayName, $this->l('Postcode update manager')));
				$this->displayNavigation();

				$postcode_controller = new DpdGeopostPostcodeController();
				$this->_html .= $postcode_controller->getPostcodeUpdateForm();
				break;
			case 'postcodeUpdate_upload':
				require_once(_DPDGEOPOST_CLASSES_DIR_ . 'postcode.controller.php');

				$postcode_controller = new DpdGeopostPostcodeController();
				$this->_html .= $postcode_controller->uploadAndImport();

				break;
			case 'postcodeUpdate_import':
				require_once(_DPDGEOPOST_CLASSES_DIR_ . 'postcode.controller.php');

				$postcode_controller = new DpdGeopostPostcodeController();
				$this->_html .= $postcode_controller->import();

				break;
			case 'shipment_list':
			default:
				require_once(_DPDGEOPOST_CLASSES_DIR_ . 'shipmentsList.controller.php');
				if (Tools::isSubmit('printManifest')) {
					$shipment_controller = new DpdGeopostShipmentController();
					$this->_html .= $shipment_controller->getShipmentList();
					$this->_html = '';
					break;
				}
				$this->context->controller->addJqueryUI(array(
					'ui.slider', // for datetimepicker
					'ui.datepicker' // for datetimepicker
				));

				$this->context->controller->addJS(array(
					_DPDGEOPOST_JS_URI_ . 'jquery.bpopup.min.js',
					_PS_JS_DIR_ . 'jquery/plugins/timepicker/jquery-ui-timepicker-addon.js' // for datetimepicker
				));

				$this->addCSS(_PS_JS_DIR_ . 'jquery/plugins/timepicker/jquery-ui-timepicker-addon.css'); // for datetimepicker

				$this->context->smarty->assign('path', array($this->displayName, $this->l('Shipments')));
				$this->displayNavigation();

				if (
					Configuration::getGlobalValue('PS_MULTISHOP_FEATURE_ACTIVE') &&
					count(Shop::getShops(0)) > 1 &&
					Shop::getContext() != Shop::CONTEXT_SHOP
				) {
					$this->_html .= $this->displayWarnings(array($this->l('Shipments functionality is disabled when all shops or group of shops are chosen')));
					break;
				}
				$shipment_controller = new DpdGeopostShipmentController();
				$this->_html .= $shipment_controller->getShipmentList();
				break;
		}

		return $this->_html;
	}

	private function displayShopRestrictionWarning()
	{
		if (Configuration::getGlobalValue('PS_MULTISHOP_FEATURE_ACTIVE') && count(Shop::getShops(0)) > 1 && Shop::getContext() == Shop::CONTEXT_GROUP)
			$this->_html .= $this->displayWarnings(array($this->l('You have chosen a group of shops, all the changes will be set for all shops in this group')));
		if (Configuration::getGlobalValue('PS_MULTISHOP_FEATURE_ACTIVE') && count(Shop::getShops(0)) > 1 && Shop::getContext() == Shop::CONTEXT_ALL)
			$this->_html .= $this->displayWarnings(array($this->l('You have chosen all shops, all the changes will be set for all shops')));
	}

	public function outputHTML($html)
	{
		$this->_html .= $html;
	}

	public function addCSS($css_uri)
	{
		$this->context->controller->addCSS($css_uri);
	}

	public function addJS($js_uri)
	{
		$this->context->controller->addJS($js_uri);
	}

	private function displayNavigation()
	{
		$this->context->smarty->assign('module_link', $this->module_url);
		$this->_html .= $this->context->smarty->fetch(_DPDGEOPOST_TPL_DIR_ . 'admin/navigation.tpl');
	}

	/* adds success message into session */
	public static function addFlashMessage($msg)
	{
		$messages_controller = new DpdGeopostMessagesController();
		$messages_controller->setSuccessMessage($msg);
	}

	public static function addFlashError($msg)
	{
		$messages_controller = new DpdGeopostMessagesController();

		if (is_array($msg)) {
			foreach ($msg as $message)
				$messages_controller->setErrorMessage($message);
		} else
			$messages_controller->setErrorMessage($msg);
	}

	/* displays success message only untill page reload */
	private function displayFlashMessagesIfIsset()
	{
		$messages_controller = new DpdGeopostMessagesController();

		if ($success_message = $messages_controller->getSuccessMessage())
			$this->_html .= $this->displayConfirmation($success_message);

		if ($error_message = $messages_controller->getErrorMessage())
			$this->_html .= $this->displayErrors($error_message);
	}

	public function displayErrors($errors)
	{
		$this->context->smarty->assign('errors', $errors);
		return $this->context->smarty->fetch(_DPDGEOPOST_TPL_DIR_ . 'admin/errors.tpl');
	}

	public function displayWarnings($warnings)
	{
		$this->context->smarty->assign('warnings', $warnings);
		return $this->context->smarty->fetch(_DPDGEOPOST_TPL_DIR_ . 'admin/warnings.tpl');
	}

	public static function getInputValue($name, $default_value = null)
	{

		return (Tools::isSubmit($name)) ? Tools::getValue($name) : $default_value;
	}

	public static function getMethodIdByCarrierId($id_carrier)
	{
		if (!$id_reference = self::getReferenceIdByCarrierId($id_carrier)) {
            return false;
        }
        switch ($id_reference) {
			case Configuration::get(DpdGeopostConfiguration::CARRIER_CLASSIC_ID):
				return _DPDGEOPOST_CLASSIC_ID_;

            case Configuration::get(DpdGeopostConfiguration::CARRIER_CARGO_REGIONAL_ID):
                return _DPDGEOPOST_CARGO_REGIONAL_ID_;

            case Configuration::get(DpdGeopostConfiguration::CARRIER_CLASSIC_INTERNATIONAL_CR_ID):
                return _DPDGEOPOST_CLASSIC_INTERNATIONAL_CR_ID_;

            case Configuration::get(DpdGeopostConfiguration::CARRIER_CLASIC_POLONIA_CR_ID):
                return _DPDGEOPOST_CLASIC_POLONIA_CR_ID_;

            case Configuration::get(DpdGeopostConfiguration::CARRIER_CARGO_NATIONAL_ID):
                return _DPDGEOPOST_CARGO_NATIONAL_ID_;

            case Configuration::get(DpdGeopostConfiguration::CARRIER_INTERNATIONAL_EXPRESS_ID):
                return _DPDGEOPOST_INTERNATIONAL_EXPRESS_ID_;


			case Configuration::get(DpdGeopostConfiguration::CARRIER_CLASSIC_1_PARCEL_ID):
				return _DPDGEOPOST_CLASSIC_1_PARCEL_ID_;
			case Configuration::get(DpdGeopostConfiguration::CARRIER_LOCCO_ID):
				return _DPDGEOPOST_LOCCO_ID_;
			case Configuration::get(DpdGeopostConfiguration::CARRIER_LOCCO_1_PARCEL_ID):
				return _DPDGEOPOST_LOCCO_1_PARCEL_ID_;
			case Configuration::get(DpdGeopostConfiguration::CARRIER_CLASSIC_BALKAN_ID):
				return _DPDGEOPOST_CLASSIC_BALKAN_ID_;
			case Configuration::get(DpdGeopostConfiguration::CARRIER_CLASSIC_INTERNATIONAL_ID):
				return _DPDGEOPOST_CLASSIC_INTERNATIONAL_ID_;
			case Configuration::get(DpdGeopostConfiguration::CARRIER_CLASSIC_PALLET_ONE_ROMANIA_ID):
				return _DPDGEOPOST_CLASSIC_PALLET_ONE_ROMANIA_ID_;
			case Configuration::get(DpdGeopostConfiguration::CARRIER_CLASSIC_POLAND_ID):
				return _DPDGEOPOST_CLASSIC_POLAND_ID_;
			case Configuration::get(DpdGeopostConfiguration::CARRIER_STANDARD_24_ID):
				return _DPDGEOPOST_STANDARD_24_ID_;
			case Configuration::get(DpdGeopostConfiguration::CARRIER_FASTIUS_EXPRESS_ID):
				return _DPDGEOPOST_FASTIUS_EXPRESS_ID_;
			case Configuration::get(DpdGeopostConfiguration::CARRIER_FASTIUS_EXPRESS_2H_ID):
				return _DPDGEOPOST_FASTIUS_EXPRESS_2H_ID_;
			case Configuration::get(DpdGeopostConfiguration::CARRIER_PALLET_ONE_ROMANIA_ID):
				return _DPDGEOPOST_PALLET_ONE_ROMANIA_ID_;
            case Configuration::get(DpdGeopostConfiguration::CARRIER_DPD_LOCKER_ID):
                return _DPDGEOPOST_LOCKER_ID_;
            case Configuration::get(DpdGeopostConfiguration::CARRIER_TIERS_ID):
                return _DPDGEOPOST_TIRES_ID_;
            default:
				return false;
		}
	}

    /**
     * @param $id_carrier
     * @return bool
     */
	public static function isCODCarrier($id_carrier): bool
	{

		if (!$id_reference = self::getReferenceIdByCarrierId($id_carrier))
			return false;

		switch ($id_reference) {
			case Configuration::get(DpdGeopostConfiguration::CARRIER_CLASSIC_COD_ID):
			case Configuration::get(DpdGeopostConfiguration::CARRIER_LOCCO_COD_ID):
			case Configuration::get(DpdGeopostConfiguration::CARRIER_INTERNATIONAL_COD_ID):
			case Configuration::get(DpdGeopostConfiguration::CARRIER_REGIONAL_EXPRESS_COD_ID):
			case Configuration::get(DpdGeopostConfiguration::CARRIER_HUNGARY_COD_ID):
				return true;
			default:
				return false;
		}
	}

	private static function getReferenceIdByCarrierId($id_carrier)
	{
		return Db::getInstance()->getValue(
			'
			SELECT `id_reference`
			FROM `' . _DB_PREFIX_ . 'carrier`
			WHERE `id_carrier`=' . (int)$id_carrier
		);
	}

	private function getCarriersForCurrentModule() {
	    $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
	        SELECT `id_carrier` 
	        FROM `' . _DB_PREFIX_ . 'carrier`
            WHERE external_module_name = \'dpdgeopost\'
	    ');

	    $carriers = array();
        foreach ($rows as $row) {
            $carriers[] = $row['id_carrier'];
	    }
        return $carriers;
    }

	private function getModuleLink($tab)
	{
		return $this->context->link->getAdminLink($tab) . '&configure=' . $this->name;
	}

	private function getAjaxFrontControllerUrl()
	{
		if (!isset($this->context->link) || !is_object($this->context->link)) {
			return _DPDGEOPOST_AJAX_URI_;
		}

		return $this->context->link->getModuleLink($this->name, 'ajax');
	}

	private function getPdfFrontControllerUrl()
	{
		if (!isset($this->context->link) || !is_object($this->context->link)) {
			return _DPDGEOPOST_PDF_URI_;
		}

		return $this->context->link->getModuleLink($this->name, 'pdf');
	}

	public static function getPaymentModules()
	{
		return Module::getPaymentModules();
	}

	public function hookDisplayBackOfficeHeader($params)
	{

		if (Tools::getValue('tab') == 'AdminAddresses' || Tools::getValue('tab') == 'adminaddresses') {

			// Include baseDir for v1.6
			$protocol_content =  $_SERVER['REQUEST_SCHEME'] . '://' . Tools::getHttpHost().__PS_BASE_URI__.(!Configuration::get('PS_REWRITING_SETTINGS') ? 'index.php' : '');
			$inlineScriptsbaseDir = '
			<script type="text/javascript">
				if (typeof baseDir === "undefined") { 
					var baseDirNew = "' . $protocol_content . '";
				};
			</script>';
			
			$includeScript = '<script type="text/javascript" src="' . _DPDGEOPOST_JS_URI_ . 'address-autocomplete.js"></script><script type="text/javascript" src="' . _DPDGEOPOST_JS_URI_ . 'jquery-ui.min.js"></script>';
			$includeCss = '<link href="' . _DPDGEOPOST_CSS_URI_ . 'address-autocomplete.css" rel="stylesheet" type="text/css"/>';
			$inlineScripts = '<script type="text/javascript">' .
				'
                                var dpd_token = "' . sha1(_COOKIE_KEY_ . 'dpdgeopost') . '";
                                var dpd_ajax_url = "' . $this->getAjaxFrontControllerUrl() . '";
                                var dpd_geopost_ajax_uri = dpd_ajax_url;
                                window._DPDGEOPOST_AJAX_URI_ = dpd_ajax_url;
                                window._DPD_GLOBAL_AJAX_ = dpd_ajax_url;
                                var dpd_search_postcode_test = "' . $this->l('DPD - search postcode') . '";
                                var dpd_search_postcode_empty_result_alert = "' . $this->l('There were no suggestions to the address given county. Please enter post code manually.') . '";
                                var dpd_address_validation_length = "' . $this->l('Address length should be less then 70 characters.') . '";
                                ' .
				'</script>';
			return $inlineScriptsbaseDir . $inlineScripts . $includeScript . '' . $includeCss;
		}
		return '';
	}

	public function hookDisplayAdminOrder($params)
	{
		if ($this->shouldUseModernAdminOrderTabs()) {
			return '';
		}

		return $this->renderAdminOrderContent((int) $params['id_order']);
	}

	public function hookDisplayAdminOrderTabLink($params)
	{
		$orderId = !empty($params['id_order']) ? (int) $params['id_order'] : 0;
		if ($orderId <= 0) {
			return '';
		}

		return '<li class="nav-item">'
			. '<a class="nav-link" id="' . $this->getAdminOrderTabLinkId() . '" data-toggle="tab" href="#' . $this->getAdminOrderTabContentId() . '" role="tab" aria-controls="' . $this->getAdminOrderTabContentId() . '" aria-selected="false">'
			. '<i class="material-icons">local_shipping</i> '
			. $this->l('DPD GeoPost')
			. '</a>'
			. '</li>';
	}

	public function hookDisplayAdminOrderTabContent($params)
	{
		$orderId = !empty($params['id_order']) ? (int) $params['id_order'] : 0;
		if ($orderId <= 0) {
			return '';
		}

		$content = $this->renderAdminOrderContent($orderId);
		if ($content === '') {
			return '';
		}

		return '<div class="tab-pane fade" id="' . $this->getAdminOrderTabContentId() . '" role="tabpanel" aria-labelledby="' . $this->getAdminOrderTabLinkId() . '">'
			. $content
			. '</div>';
	}

	private function shouldUseModernAdminOrderTabs()
	{
		return version_compare(_PS_VERSION_, '1.7.7.0', '>=');
	}

	private function getAdminOrderTabLinkId()
	{
		return 'dpdgeopostAdminOrderTab';
	}

	private function getAdminOrderTabContentId()
	{
		return 'dpdgeopostAdminOrderTabContent';
	}

	private function renderAdminOrderContent($orderId)
	{
		$this->displayFlashMessagesIfIsset();
		$order = new Order((int) $orderId);
		if (!Validate::isLoadedObject($order)) {
			return '';
		}

        $customer = new Customer($order->id_customer);
		$shipment = new DpdGeopostShipment((int) $orderId);


		$parcelIds = array();

		if($shipment->parcels && !empty($shipment->parcels)) {
			foreach($shipment->parcels as $parcel) {
				$parcelIds[] = $parcel['id'];
			}
		}

		$activeVouchers = $shipment->getActiveVouchers($parcelIds);
		$hasVouchers = false;
		if(!empty($activeVouchers)) {
			$hasVouchers = true;
		}

        $deliveryAddress = new Address($order->id_address_delivery);
        if (empty($deliveryAddress->dpd_country) && $deliveryAddress->country == 'Romania') {
            $deliveryAddress->dpd_country =  642;
        }
        $states = State::getStatesByIdCountry($deliveryAddress->id_country);
        $deliveryAddressStateName = '';
        if ((int) $deliveryAddress->id_state > 0) {
            $deliveryAddressState = new State((int) $deliveryAddress->id_state);
            if (Validate::isLoadedObject($deliveryAddressState)) {
                $deliveryAddressStateName = (string) $deliveryAddressState->name;
            }
        }
        if ($deliveryAddressStateName === '' && !empty($deliveryAddress->dpd_state)) {
            $deliveryAddressStateName = (string) $deliveryAddress->dpd_state;
        }

        // Auto-resolve DPD city/postcode IDs for orders where the customer never went
        // through DPD's checkout normalization flow (so dpd_site / dpd_postcode are empty
        // but address1 / city / postcode are filled normally). Without this, the BO
        // shipping form on this hook renders empty City/Postcode/Street dropdowns even
        // though the order has a perfectly valid Romanian address. Best-effort only —
        // if the WS lookup returns nothing or errors, leave the field empty as before
        // (DpdGeopostApiCache memoises listCities so the cost is one round trip per
        // distinct city per cache TTL). The street fallback at line ~884 below already
        // calls getStreetByName($name, $dpd_site); it just needs $dpd_site populated.
        if (empty($deliveryAddress->dpd_site) && !empty($deliveryAddress->city) && !empty($deliveryAddress->dpd_country)) {
            $addressLookupOptions = array(
                'name' => $deliveryAddress->city,
                'countryId' => (int) $deliveryAddress->dpd_country,
            );
            if ($deliveryAddressStateName !== '') {
                $addressLookupOptions['region'] = $deliveryAddressStateName;
            }
            $cityCandidates = (new DpdAddressSearch())->listCities($addressLookupOptions);
            if (is_array($cityCandidates) && !empty($cityCandidates)) {
                // Prefer an exact case-insensitive name match; fall back to the first row.
                $bestCity = $cityCandidates[0];
                foreach ($cityCandidates as $cityCandidate) {
                    if (isset($cityCandidate['name'])
                        && strcasecmp((string) $cityCandidate['name'], (string) $deliveryAddress->city) === 0
                    ) {
                        $bestCity = $cityCandidate;
                        break;
                    }
                }
                if (!empty($bestCity['id'])) {
                    $deliveryAddress->dpd_site = $bestCity['id'];
                }
                // Use the resolved site's postCode as a hint for the postcode dropdown
                // when the merchant hasn't selected one yet.
                if (empty($deliveryAddress->dpd_postcode) && !empty($bestCity['postCode'])) {
                    $deliveryAddress->dpd_postcode = $bestCity['postCode'];
                }
                // Use the resolved site's region as a fallback for the Region dropdown.
                // When the customer never picked a PS state (because Romania's address
                // format historically had no State:name token, so id_state stays 0),
                // listCities still tells us the region for that site — use it.
                if ($deliveryAddressStateName === '' && !empty($bestCity['region'])) {
                    $deliveryAddressStateName = (string) $bestCity['region'];
                    if (empty($deliveryAddress->dpd_state)) {
                        $deliveryAddress->dpd_state = $deliveryAddressStateName;
                    }
                }
            }
        }
        // Final fallback for postcode: PS's regular postcode field, when DPD lookup
        // didn't give us one. Better than rendering an empty dropdown.
        if (empty($deliveryAddress->dpd_postcode) && !empty($deliveryAddress->postcode)) {
            $deliveryAddress->dpd_postcode = $deliveryAddress->postcode;
        }

        if (!empty($deliveryAddress->dpd_office)) {
            $deliveryAddress->dpd_shipment_type = 'pickup';
        }

        if(is_array($states)) {
            $states = array_column($states, 'name');
        }

		$products = $shipment->getParcelsSetUp($order->getProductsDetail());

		if ($shipment->parcels) {
            DpdGeopostParcel::addParcelDataToProducts($products, $order->id);
        }

		$id_method = self::getMethodIdByCarrierId($order->id_carrier);

		$order_total_price = (float)$order->total_paid_tax_incl;

		$productsNameString = '';
		foreach ($products as $product) {
			$productsNameString .= '|' . $product['product_name'];
		}

		$extra_params = array();
		$sendInsuranceValue = Configuration::get(DpdGeopostConfiguration::SEND_INSURANCE_VALUE);
		if ($sendInsuranceValue) {
			$extra_params['highInsurance'] = array(
				'total_paid'        => $order_total_price,
				'currency_iso_code' => $this->context->currency->iso_code,
				'content'         => $productsNameString
			);
		}

		$price = $shipment->calculatePriceForOrder((int)$id_method, $order->id_address_delivery, $products, $extra_params);
		$price_no_currency = $shipment->calculatePriceForOrder((int)$id_method, $order->id_address_delivery, $products, $extra_params, false);

		$dpdAddress = new DpdGeopostDpdPostcodeAddress();
		$dpdAddress->loadDpdAddressByAddressId($order->id_address_delivery);

		$street = pSQL($deliveryAddress->address1) . (($deliveryAddress->address2) ? ' ' . pSQL($deliveryAddress->address2) : '');

		$carrier = new Carrier((int)$order->id_carrier, $order->id_lang);

        if($deliveryAddress->dpd_shipment_type == null) {
            $deliveryAddress->dpd_shipment_type = 'delivery';
        }

        $selectedOffice = '';
        $selectedOfficeId = '';
        if($deliveryAddress->dpd_office && $deliveryAddress->dpd_shipment_type == 'pickup') {
            $pudo = new DpdGeopostPudo();
            $officeById = $pudo->getOfficeById($deliveryAddress->dpd_office);

            if($officeById && !empty($officeById['office']) && isset($officeById['office']['id']) ) {
                $selectedOffice = $officeById['office']['nameEn'];
                $selectedOfficeId = $deliveryAddress->dpd_office;
            }
        }


        $dpdSearch = new DpdAddressSearch();


        $foundStreets = array();
        if($deliveryAddress->dpd_street) {
            $streetById = $dpdSearch->getStreetById($deliveryAddress->dpd_street);
            if(!empty($streetById)) $foundStreets = array($streetById);
        } else {
            $maybeStreetName = $this->extractStreetName($deliveryAddress->id);
            $foundStreets = $dpdSearch->getStreetByName($maybeStreetName, $deliveryAddress->dpd_site);
        }

        $streetNr = '';
        $streetBl = '';
        $streetAp = '';

        if (!empty($deliveryAddress->dpd_block)) {
            list($streetNr, $streetBl, $streetAp) = explode(':', $deliveryAddress->dpd_block);
        } else {
            // Fallback: parse Nr / Bl / Ap out of address1 + address2 when the customer
            // never went through DPD's normalization (so dpd_block is empty). The order
            // form's Nr/Bl/Ap inputs would otherwise stay blank even when address1 contains
            // "Strada Foo, nr 10 bl A2 ap 5".
            $parsed = $this->parseAddressDetailsFromText(
                trim((string) $deliveryAddress->address1 . ' ' . (string) $deliveryAddress->address2)
            );
            $streetNr = $parsed['nr'];
            $streetBl = $parsed['bl'];
            $streetAp = $parsed['ap'];
        }

		$path = array(0, 2, _DPDGEOPOST_JS_URI_);
        $assets = array();

        $assets[] = '<script src="'. _DPDGEOPOST_JS_URI_ . 'jquery.bpopup.min.js"></script>';
        $assets[] = '<script src="'. _DPDGEOPOST_JS_URI_ . 'select2.min.js"></script>';
        $assets[] = '<script src="'. _DPDGEOPOST_JS_URI_ . 'adminOrder.js"></script>';
        $assets[] = '<script src="'. _DPDGEOPOST_JS_URI_ . 'normalization_form.js"></script>';

        $assets[] = '<link rel="stylesheet" type="text/css" href="'. _DPDGEOPOST_CSS_URI_ . 'adminOrder.css" >';
        $assets[] = '<link rel="stylesheet" type="text/css" href="'. _DPDGEOPOST_CSS_URI_ . 'select2.min.css" >';
        $assets[] = '<link rel="stylesheet" type="text/css" href="'. _DPDGEOPOST_CSS_URI_ . 'normalization_form.css" >';

        $this->context->smarty->assign(array(
            'streetNr' => $streetNr,
            'streetBl' => $streetBl,
            'streetAp' => $streetAp,
            'foundStreets' => $foundStreets,
            'selectedOffice' => $selectedOffice,
            'selectedOfficeId' => $selectedOfficeId,
            'order' => $order,
            'deliveryAddress' => $deliveryAddress,
            'dpdAddress' => $dpdAddress,
            'order_country' => $deliveryAddress->country,
            'streetLengthErrors' => (strlen($street) > 70),
            'module_link' => $this->getModuleLink('AdminModules'),
            'settings' => new DpdGeopostConfiguration,
            'total_weight' => DpdGeopostShipment::convertWeight($order->getTotalWeight()),
            'shipment' => $shipment,
            'selected_shipping_method_id' => $id_method,
            'ws_shippingPrice' => $price > 0 ? $price : '---',
            'ws_shippingPrice_noCurrency' => $price_no_currency > 0 ? $price_no_currency : '---',
            'products' => $products,
            'customer_addresses' => $customer->getAddresses($this->context->language->id),
            'error_message' => reset(DpdGeopostShipment::$errors),
            'carrier_url' => $carrier->url,
            'deliveryAddressStateName' => $deliveryAddressStateName,
            'hasVouchers' => $hasVouchers,
            'activeVouchers' => $activeVouchers,
            'path' => implode(':', $path),
            'assets' => implode("\n", $assets),
            'states' => $states

        ));

		$this->setGlobalVariablesForAjax();

		return $this->context->smarty->fetch(_DPDGEOPOST_TPL_DIR_ . 'hook/adminOrder.tpl');
	}
	public function hookPaymentTop($params)
	{
		if (!$this->getMethodIdByCarrierId((int)$this->context->cart->id_carrier)) //Check if DPD carrier is chosen
			return;

		if (Configuration::get('PS_ALLOW_MULTISHIPPING'))
			return;


		if (!Validate::isLoadedObject($this->context->cart) || !$this->context->cart->id_carrier)
			return;

		$is_cod_carrier = $this->isCODCarrier((int)$this->context->cart->id_carrier);
		$cod_payment_method = Configuration::get(DpdGeopostConfiguration::COD_MODULE);

		$cache_id = 'exceptionsCache';
		$exceptionsCache = (Cache::isStored($cache_id)) ? Cache::retrieve($cache_id) : array(); // existing cache
		$controller = (Configuration::get('PS_ORDER_PROCESS_TYPE') == 0) ? 'order' : 'orderopc';
		$id_hook = Hook::getIdByName('displayPayment'); // ID of hook we are going to manipulate

		if ($paymentModules = self::getPaymentModules()) {
			foreach ($paymentModules as $module) {
				if (
					$module['name'] == $cod_payment_method && !$is_cod_carrier ||
					$module['name'] != $cod_payment_method && $is_cod_carrier
				) {
					$module_instance = Module::getInstanceByName($module['name']);

					if (Validate::isLoadedObject($module_instance)) {
						$key = (int)$id_hook . '-' . (int)$module_instance->id;
						$exceptionsCache[$key][$this->context->shop->id][] = $controller;
					}
				}
			}

			Cache::store($cache_id, $exceptionsCache);
		}
	}

	private function disablePaymentMethods()
	{
		$is_cod_carrier = $this->isCODCarrier((int)$this->context->cart->id_carrier);
		$cod_payment_method = Configuration::get(DpdGeopostConfiguration::COD_MODULE);

		if ($paymentModules = self::getPaymentModules()) {
			foreach ($paymentModules as $module) {
				if (
					$module['name'] == $cod_payment_method && !$is_cod_carrier ||
					$module['name'] != $cod_payment_method && $is_cod_carrier
				) {
					$module_instance = Module::getInstanceByName($module['name']);
					if (Validate::isLoadedObject($module_instance)) {
						$module_instance->active = 0;
						$module_instance->currencies = array();
					}
				}
			}
		}
	}

	public function hookUpdateCarrier($params)
	{
		$id_reference = (int)DpdGeopostCarrier::getReferenceByIdCarrier((int)$params['id_carrier']);
		$id_carrier = (int)$params['carrier']->id;

		$dpdgeopost_carrier = new DpdGeopostCarrier();
		$dpdgeopost_carrier->id_carrier = (int)$id_carrier;
		$dpdgeopost_carrier->id_reference = (int)$id_reference;
		$dpdgeopost_carrier->save();
	}

	public function getOrderShippingCost($cart, $price)
	{
        if (!Validate::isLoadedObject($cart)) {
            return false;
        }

        return $this->getOrderShippingCostExternal($cart);
	}

    /**
     * called when carrier price is required
     * @param $cart
     * @param $shipping_cost
     * @param $products
     * @return false|float|int|mixed|null
     */
	public function getPackageShippingCost($cart, $shipping_cost, $products)
	{
        if (!Validate::isLoadedObject($cart)) {
            return false;
        }

		if (!Configuration::get('PS_ALLOW_MULTISHIPPING')) {
            return $this->getOrderShippingCostExternal($cart);
        }

		return $this->getOrderShippingCostExternal($cart, is_array($products) ? $products : array());
	}

    private function getActiveCarrierIdForShippingCost($cart)
    {
        $carrierId = (int) $this->id_carrier;
        if ($carrierId <= 0 && Validate::isLoadedObject($cart)) {
            $carrierId = (int) $cart->id_carrier;
        }

        return $carrierId;
    }

    private function getTemporaryCodSurchargeAmount()
    {
        $value = null;

        if (isset($_SESSION) && is_array($_SESSION) && isset($_SESSION['cod_selected_price'])) {
            $value = $_SESSION['cod_selected_price'];
        } elseif (isset($this->context->cookie) && !empty($this->context->cookie->dpd_cod_selected_price)) {
            $value = $this->context->cookie->dpd_cod_selected_price;
        }

        if (!is_numeric($value)) {
            return 0.0;
        }

        return max(0.0, (float) $value);
    }

    private function resolveShippingZoneId($idAddressDelivery)
    {
        $idCountry = (int) Tools::getValue('id_country');
        if ($idCountry > 0) {
            return (int) Country::getIdZone($idCountry);
        }

        $zoneId = (int) Address::getZoneById((int) $idAddressDelivery);
        if ($zoneId > 0) {
            return $zoneId;
        }

        $defaultCountryId = (int) Configuration::get('PS_COUNTRY_DEFAULT');
        if ($defaultCountryId > 0) {
            return (int) Country::getIdZone($defaultCountryId);
        }

        return 0;
    }

    /**
     * @param $cart
     * @param $products
     * @param $calculate_for_cart
     * @return false|float|int|mixed|null
     */
	public function getOrderShippingCostExternal($cart, $products = array(), $calculate_for_cart = true)
	{
        if (!Validate::isLoadedObject($cart)) {
            return false;
        }

        $carrierId = $this->getActiveCarrierIdForShippingCost($cart);
        if ($carrierId <= 0) {
            return false;
        }

        if (!is_array($products)) {
            $products = array();
        }

        $cache_key = $this->getCacheKey($cart, $products);

		$id_address_delivery = empty($products) ? (int) $cart->id_address_delivery : (int) $this->getIdAddressDeliveryByProducts($products);
        if ($id_address_delivery <= 0) {
            $id_address_delivery = (int) $cart->id_address_delivery;
        }

		$zone = $this->resolveShippingZoneId($id_address_delivery);

        if (!$id_method = self::getMethodIdByCarrierId($carrierId)) {
			self::$carriers[$carrierId][$cache_key] = false;
			return false;
		}

        $carrier = new Carrier($carrierId);
		if (!Validate::isLoadedObject($carrier)) {
			return false;
        }

		$is_cod_method = $this->isCODCarrier($carrierId);
		$carrier_shipping_method = $carrier->getShippingMethod();
		$order_total_price = empty($products) ? $cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING) : $cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING, $products, $carrierId);
		$total_weight = empty($products) ? $cart->getTotalWeight() : $cart->getTotalWeight($products);
		$cart_total = $carrier_shipping_method == Carrier::SHIPPING_METHOD_WEIGHT ? DpdGeopostShipment::convertWeight($total_weight) : $order_total_price;

		$configuration = new DpdGeopostConfiguration();
		$price_rule = DpdGeopostShipment::getPriceRule($cart_total, $id_method, $id_address_delivery, $is_cod_method);
		$additional_shipping_cost = $this->calculateAdditionalShippingCost($cart, $products);
		$additional_shipping_cost = Tools::convertPrice($additional_shipping_cost);

		$handling_charges = $carrier->shipping_handling ? Configuration::get('PS_SHIPPING_HANDLING') : 0;
		$handling_charges = Tools::convertPrice($handling_charges);

        $extraPriceOnCodSurcharge = $this->getTemporaryCodSurchargeAmount();

        $payer = DpdGeopostConfiguration::getSettingStatic(DpdGeopostConfiguration::COURIER_SERVICE_PAYER, DpdGeopostConfiguration::COURIER_SERVICE_PAYER_SENDER);
        if (strtolower($payer) == strtolower(DpdGeopostConfiguration::COURIER_SERVICE_PAYER_RECIPIENT)) {
            $configuration->price_calculation_method = DpdGeopostConfiguration::WEB_SERVICES;
        }

        if ($configuration->price_calculation_method == DpdGeopostConfiguration::PRESTASHOP) {
			if ($carrier_shipping_method == Carrier::SHIPPING_METHOD_WEIGHT) {
				$carrier_price = $carrier->getDeliveryPriceByWeight($cart_total, $zone);
            } else {
				$carrier_price = $carrier->getDeliveryPriceByPrice($cart_total, $zone);
            }

			$default_currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
			$carrier_price = $this->convertPriceByCurrency($carrier_price, $default_currency->iso_code);
			$shipping_price_with_charges = $carrier_price + $additional_shipping_cost + $handling_charges;

			if (!empty($price_rule) && $is_cod_method) {
				if ($price_rule['cod_surcharge'] !== '') {
					$this->convertPriceByCurrency($price_rule['cod_surcharge'], $price_rule['currency']);
                } elseif ($price_rule['cod_surcharge_percentage'] !== '') {
					$percentage_starting_price = $configuration->cod_percentage_calculation == DpdGeopostConfiguration::COD_PERCENTAGE_CALCULATION_CART ? $order_total_price : $order_total_price + $shipping_price_with_charges;
					$this->calculateCODSurchargePercentage($percentage_starting_price, $price_rule['cod_surcharge_percentage'], $price_rule['cod_min_surcharge'], $price_rule['currency']);
				}
			}

            $price = $shipping_price_with_charges + $extraPriceOnCodSurcharge;
            return $price;
		} elseif ($configuration->price_calculation_method == DpdGeopostConfiguration::WEB_SERVICES) {
			$shipment = new DpdGeopostShipment;

			if (!self::$parcels) {
				$cart_products = empty($products) ? $cart->getProducts() : $products;
				self::$products = $cart_products;
				self::$parcels = $shipment->putProductsToParcels($cart_products);
			}

			$extra_params = array();
			if ($is_cod_method) {
				$extra_params['cod'] = array(
					'total_paid'        => $order_total_price,
					'currency_iso_code' => $this->context->currency->iso_code,
					'reference'         => (int) $this->context->cart->id,
				);
			}

			$productsNameString = '';
			if (count(self::$products)) {
				foreach (self::$products as $key => $product) {
					$productsNameString .= '|' . $product['name'];
				}
			}
			$sendInsuranceValue = Configuration::get(DpdGeopostConfiguration::SEND_INSURANCE_VALUE);
			if ($sendInsuranceValue) {
				$extra_params['highInsurance'] = array(
					'total_paid'        => $order_total_price,
					'currency_iso_code' => $this->context->currency->iso_code,
					'content'         => $productsNameString,
				);
			}

            $result = $shipment->calculate($id_method, $id_address_delivery, self::$parcels, null, $extra_params);
            if ($result === false || !isset($result['price']) || !isset($result['id_currency'])) {
				self::$carriers[$carrierId][$cache_key] = false;
				return false;
			}

			$result_price_in_default_currency = Tools::convertPrice($result['price'], new Currency((int) $result['id_currency']), false);
			$result['price'] = Tools::convertPrice($result_price_in_default_currency);
			$result['price'] += $additional_shipping_cost + $handling_charges;

			if (!empty($price_rule)) {
				if ($price_rule['shipping_price_percentage'] !== '') {
					$surcharge = $result['price'] * $price_rule['shipping_price_percentage'] / 100;
					$result['price'] += $surcharge;
				}

				if ($is_cod_method && $price_rule['cod_surcharge'] !== '') {
					$result['price'] += $this->convertPriceByCurrency($price_rule['cod_surcharge'], $price_rule['currency']);
                } elseif ($is_cod_method && $price_rule['cod_surcharge_percentage'] !== '') {
					$percentage_starting_price = $configuration->cod_percentage_calculation == DpdGeopostConfiguration::COD_PERCENTAGE_CALCULATION_CART ? $order_total_price : $order_total_price + $result['price'];
					$result['price'] += $this->calculateCODSurchargePercentage($percentage_starting_price, $price_rule['cod_surcharge_percentage'], $price_rule['cod_min_surcharge'], $price_rule['currency']);
				}
			}

            return $result['price'] + $extraPriceOnCodSurcharge;
		} elseif ($configuration->price_calculation_method == DpdGeopostConfiguration::CSV) {
			if (empty($price_rule)) {
				return false;
            }

			if ($price_rule['shipping_price'] !== '') {
				$carrier_price = $this->convertPriceByCurrency($price_rule['shipping_price'], $price_rule['currency']);
            } elseif ($price_rule['shipping_price_percentage'] !== '') {
				$carrier_price = $order_total_price * $price_rule['shipping_price_percentage'] / 100;
            } else {
				return false;
            }

			$shipping_price_with_charges = $carrier_price + $additional_shipping_cost + $handling_charges;
			$cod_price = 0;
			if ($is_cod_method) {
				if ($price_rule['cod_surcharge'] !== '') {
					$cod_price = $this->convertPriceByCurrency($price_rule['cod_surcharge'], $price_rule['currency']);
                } elseif ($price_rule['cod_surcharge_percentage'] !== '') {
					$percentage_starting_price = $configuration->cod_percentage_calculation == DpdGeopostConfiguration::COD_PERCENTAGE_CALCULATION_CART ? $order_total_price : $order_total_price + $shipping_price_with_charges;
					$cod_price = $this->calculateCODSurchargePercentage($percentage_starting_price, $price_rule['cod_surcharge_percentage'], $price_rule['cod_min_surcharge'], $price_rule['currency']);
				}
			}

			$price = $shipping_price_with_charges + $cod_price + $extraPriceOnCodSurcharge;
            return $price;
		}

		return false;
	}
	private function getCacheKey($cart, $products)
	{
		if (empty($products))
			$products = $cart->getProducts();

		$cache_key = '';

		foreach ($products as $product)
			for ($i = 0; $i < $product['cart_quantity']; $i++)
				$cache_key .= $product['id_product'] . '_' . $product['id_product_attribute'] . ';';

		return $cache_key;
	}

	private function getIdAddressDeliveryByProducts($products)
	{
		foreach ($products as $product)
			return $product['id_address_delivery'];
	}

	private function calculateAdditionalShippingCost($cart, $products)
	{
		$additional_shipping_price = 0;
		$cart_products = empty($products) ? $cart->getProducts() : $products;

		foreach ($cart_products as $product)
			$additional_shipping_price += (int)$product['cart_quantity'] * (float)$product['additional_shipping_cost'];

		return $additional_shipping_price;
	}

	/**
	 * Convert price from given currency to current context currency
	 *
	 * @param (float) $price - price which will be converted from given currency
	 * @param (string) $iso_code_currency - iso code of given currency
	 *
	 * @return (float) converted price without currency sign
	 */
	private function convertPriceByCurrency($price, $iso_code_currency)
	{
		$currency = new Currency(Currency::getIdByIsoCode($iso_code_currency));

		if (!Validate::isLoadedObject($currency))
			return 0;

		$price_in_default_currency = Tools::convertPrice($price, $currency, false);

		return Tools::convertPrice($price_in_default_currency);
	}

	/**
	 * Calculate percentage value of given price. If minimum value is higher than percentage
	 * value than it is returned minimum value converted into current context currency
	 *
	 * @param (float) $order_total_price - price which will be used to calculate percentage value
	 * @param (float) $cod_surcharge_percentage - percentage factor
	 * @param (float) $cod_min_surcharge - price in given currency which will be used as minimum value
	 * @param (string) $iso_code_currency - iso code of given currency
	 *
	 * @return (float) calculated price without currency sign
	 */
	private function calculateCODSurchargePercentage($order_total_price, $cod_surcharge_percentage, $cod_min_surcharge, $iso_code_currency)
	{
		$surcharge_percentage = $order_total_price * $cod_surcharge_percentage / 100;
		if ($cod_min_surcharge !== '' && ($min_surcharge = $this->convertPriceByCurrency($cod_min_surcharge, $iso_code_currency))  > $surcharge_percentage)
			$surcharge_percentage = $min_surcharge;

		return $surcharge_percentage;
	}


	private function isFrontOrderOrAddressController()
	{
		$controllerName = (string) Tools::getValue('controller');

		return in_array($controllerName, array('order', 'address'), true);
	}

	private function shouldLoadFrontNormalizerAssets()
	{
		return $this->isFrontOrderOrAddressController() && Configuration::get('DPD_SHOW_NORMALIZATION_FORM') != 'no';
	}

	private function registerFrontJavascriptDefinitions()
	{
		$definitions = array(
			'_DPDGEOPOST_AJAX_URI_' => $this->getAjaxFrontControllerUrl(),
			'_DPD_GLOBAL_AJAX_' => $this->getAjaxFrontControllerUrl(),
			'_DPD_TOKEN_' => sha1(_COOKIE_KEY_ . $this->name),
			'_ADMIN_AJAX_URL_' => _DPD_ADMIN_AJAX_URL_,
		);

		if ($this->shouldLoadFrontNormalizerAssets()) {
			$definitions['_DPD_CARRIERS'] = $this->getCarriersForCurrentModule();
			$definitions['_DPD_NORMALIZER_ON_CHECKOUT_'] = true;
		}

		Media::addJsDef($definitions);
	}

	public function hookHeader($params)
	{
		return '';
	}

    public function hookActionFrontControllerSetMedia($params)
    {
        if (!isset($this->context->controller) || !is_object($this->context->controller)) {
            return;
        }

        $this->registerFrontJavascriptDefinitions();

        $this->context->controller->registerJavascript(
            'module-'.$this->name.'-front',
            'modules/' . $this->name . '/js/front.js',
            array(
                'position' => 'footer',
                'priority' => 100,
                'version' => ''
            )
        );

        if ((string) Tools::getValue('controller') === 'order') {
            $this->context->controller->registerJavascript(
                'module-'.$this->name.'-locker-map',
                'modules/' . $this->name . '/js/locker_map.js',
                array(
                    'position' => 'footer',
                    'priority' => 105,
                    'version' => ''
                )
            );
        }
        if (!$this->shouldLoadFrontNormalizerAssets()) {
            return;
        }

        $this->context->controller->registerJavascript(
            'module-'.$this->name.'-select2',
            'modules/' . $this->name . '/js/select2.min.js',
            array(
                'position' => 'footer',
                'priority' => 110,
                'version' => ''
            )
        );

        $this->context->controller->registerJavascript(
            'module-'.$this->name.'-normalization',
            'modules/' . $this->name . '/js/normalization_form.js',
            array(
                'position' => 'footer',
                'priority' => 120,
                'version' => ''
            )
        );

        $this->context->controller->registerStylesheet(
            'module-'.$this->name.'-select2',
            'modules/' . $this->name . '/css/select2.min.css',
            array(
                'media' => 'all',
                'priority' => 110,
                'version' => ''
            )
        );

        $this->context->controller->registerStylesheet(
            'module-'.$this->name.'-normalization',
            'modules/' . $this->name . '/css/normalization_form.css',
            array(
                'media' => 'all',
                'priority' => 120,
                'version' => ''
            )
        );
    }

	public function hookDisplayFooter($params)
	{
		return '';
	}

    /**
     * Load the required css in header
     * @param $params
     * @return string
     */
	public function hookDisplayHeader($params)
	{
		return '';
	}


    /**
     * Load the required data in front header
     * @param $params
     * @return string
     */
    public function hookDisplayFrontHeader($params)
    {

    }

    private function addCheckoutErrorMessage($message)
    {
        $message = trim((string) $message);
        if ($message === '') {
            return;
        }

        if (!isset($this->context->controller->errors) || !is_array($this->context->controller->errors)) {
            $this->context->controller->errors = array();
        }

        $this->context->controller->errors[] = $message;
    }

    private function addCheckoutErrorMessages($messages, $fallbackMessage = '')
    {
        if (!is_array($messages)) {
            $messages = array($messages);
        }

        $hasMessage = false;
        foreach ($messages as $message) {
            if (is_array($message)) {
                foreach ($message as $nestedMessage) {
                    $nestedMessage = trim((string) $nestedMessage);
                    if ($nestedMessage === '') {
                        continue;
                    }

                    $this->addCheckoutErrorMessage($nestedMessage);
                    $hasMessage = true;
                }

                continue;
            }

            $message = trim((string) $message);
            if ($message === '') {
                continue;
            }

            $this->addCheckoutErrorMessage($message);
            $hasMessage = true;
        }

        if (!$hasMessage && $fallbackMessage !== '') {
            $this->addCheckoutErrorMessage($fallbackMessage);
        }
    }

    public function hookActionValidateStepComplete($params)
    {
        if (empty($params['step_name']) || $params['step_name'] !== 'delivery') {
            return;
        }

        if (empty($params['cart']) || !Validate::isLoadedObject($params['cart'])) {
            return;
        }

        $cart = new Cart((int) $params['cart']->id);
        if (!Validate::isLoadedObject($cart)) {
            return;
        }

        $id_method = self::getMethodIdByCarrierId((int) $params['cart']->id_carrier);
        if ($id_method !== _DPDGEOPOST_LOCKER_ID_) {
            return;
        }

        $address = new Address((int) $cart->id_address_delivery);
        if (!Validate::isLoadedObject($address)) {
            $this->addCheckoutErrorMessage($this->l('Please select a delivery address before choosing a locker.'));
            $params['completed'] = false;
            return;
        }

        if (empty($address->dpd_office)) {
            $this->addCheckoutErrorMessage($this->l('Please select one locker from the map'));
            $params['completed'] = false;
            return;
        }

        $shipment = new DpdGeopostShipment;
        $cart_products = $cart->getProducts();
        $parcels = $shipment->putProductsToParcels($cart_products);
        $result = $shipment->calculate($id_method, $cart->id_address_delivery, $parcels, null, array());
        if ($result !== false) {
            return;
        }

        $this->addCheckoutErrorMessages($shipment::$errors, 'Nu se poate face livre la officiul selectat.');
        $params['completed'] = false;
    }
	private function checkDbStructure()
	{
		//check if table `ps_dpdgeopost_shipment` exists; if so, make sure column `id_shipment` has type BIGINT
		$shipmentTableName = _DB_PREFIX_ . _DPDGEOPOST_SHIPMENT_DB_;
		$db = Db::getInstance();
		$queryTableExists = 'SELECT 1 FROM `' . $shipmentTableName . '`';
		$tableExists = false;
		try {
			$tableExists = $db->getValue($queryTableExists);
		} catch (Exception $ex) {
			//do nothing
		}

		if ($tableExists) {
			$queryColumnType = 'SHOW FIELDS FROM `' . $shipmentTableName . '` where Field =\'id_shipment\'';
			$idShipmentField = $db->executeS($queryColumnType);
			$field = $idShipmentField[0];

			if (strtolower($field['Type']) !== 'bigint(20)') {
				$queryAlterTable = 'ALTER TABLE `' . $shipmentTableName . '` CHANGE COLUMN `id_shipment` `id_shipment` BIGINT NOT NULL FIRST;';
				$db->execute($queryAlterTable);
			}


			$queryColumnType = 'SHOW FIELDS FROM `' . $shipmentTableName . '` where Field =\'id_manifest\'';
			$idManifestField = $db->executeS($queryColumnType);
			$field = $idManifestField[0];

			if (strtolower($field['Type']) !== 'bigint(20)') {
				$queryAlterTable = 'ALTER TABLE `' . $shipmentTableName . '` CHANGE COLUMN `id_manifest` `id_manifest` BIGINT NOT NULL AFTER `shipment_reference`;';
				$db->execute($queryAlterTable);
			}
		}
	}

	private function ensureCustomerAddressFormHooksRegistered()
	{
		if (!$this->id) {
			return;
		}

		foreach (array(
			'additionalCustomerAddressFields',
			'actionValidateCustomerAddressForm',
			'actionSubmitCustomerAddressForm',
			'actionCustomerAddressFormBuilderModifier',
			'actionAfterCreateCustomerAddressFormHandler',
			'actionAfterUpdateCustomerAddressFormHandler',
		) as $hookName) {
			if (!$this->isRegisteredInHook($hookName)) {
				$this->registerHook($hookName);
			}
		}
	}


	private function ensureAdminOrderHooksRegistered()
	{
		if (!$this->id) {
			return;
		}

		foreach (array(
			'displayAdminOrder',
			'displayAdminOrderTabLink',
			'displayAdminOrderTabContent',
		) as $hookName) {
			if (!$this->isRegisteredInHook($hookName)) {
				$this->registerHook($hookName);
			}
		}
	}
	private function getCustomerAddressFormCountryId(array $fields)
	{
		if (isset($fields['id_country']) && $fields['id_country'] instanceof FormField && (int) $fields['id_country']->getValue() > 0) {
			return (int) $fields['id_country']->getValue();
		}

		$requestCountryId = (int) Tools::getValue('id_country');
		if ($requestCountryId > 0) {
			return $requestCountryId;
		}

		if (Validate::isLoadedObject($this->context->country)) {
			return (int) $this->context->country->id;
		}

		return (int) Configuration::get('PS_COUNTRY_DEFAULT');
	}

	private function getCustomerAddressFormLabels()
	{
		return array(
			'dpd_country' => $this->l('Country'),
			'dpd_state' => $this->l('State'),
			'dpd_site' => $this->l('City'),
			'dpd_street' => $this->l('Street'),
			'dpd_complex' => $this->l('Complex'),
			'dpd_block' => $this->l('Block'),
			'dpd_office' => $this->l('DPD PickUp Office'),
			'dpd_postcode' => $this->l('Postcode'),
		);
	}

	private function getCustomerAddressOfficeOptions($countryId)
	{
		$options = array('' => $this->l('Select a DPD pickup office'));

		try {
			$pudo = new DpdGeopostPudo();
			$options += $pudo->listOfficesDropDownOptions(false, (int) $countryId);
		} catch (Exception $exception) {
			// Keep the address form usable even if the office lookup is temporarily unavailable.
		}

		return $options;
	}

	private function getCustomerAddressFormField($form, $fieldName)
	{
		$field = $form->getField($fieldName);
		if ($field) {
			return $field;
		}

		return $form->getField($this->name . '_' . $fieldName);
	}

	private function getPickupOfficeById($pickupOfficeId)
	{
		try {
			$pudo = new DpdGeopostPudo();
			return $pudo->getOfficeById($pickupOfficeId);
		} catch (Exception $exception) {
			return array();
		}
	}

	private function getAdminCustomerAddressPickupOfficeValue(array $params)
	{
		if (!empty($params['data']['dpd_office'])) {
			return trim((string) $params['data']['dpd_office']);
		}

		$addressId = !empty($params['id']) ? (int) $params['id'] : 0;
		if ($addressId <= 0) {
			return '';
		}

		$resolvedResourceAddressId = $this->resolveOrderOrCartAddressId($addressId, !empty($params['data']) && is_array($params['data']) ? $params['data'] : array());
		if ($resolvedResourceAddressId > 0) {
			$addressId = $resolvedResourceAddressId;
		}

		$address = new Address($addressId);
		if (!Validate::isLoadedObject($address)) {
			return '';
		}

		return trim((string) $address->dpd_office);
	}

	private function applyPickupOfficeSelectionToAddress(Address $address, $pickupOfficeId)
	{
		$pickupOfficeId = trim((string) $pickupOfficeId);

		if ($pickupOfficeId === '') {
			$address->dpd_office = '';
			$address->dpd_office_type = '';
			$address->dpd_office_name = '';
			$address->dpd_shipment_type = 'delivery';

			return;
		}

		$pickupOffice = $this->getPickupOfficeById($pickupOfficeId);
		if (empty($pickupOffice['office']['id'])) {
			$address->dpd_office = '';
			$address->dpd_office_type = '';
			$address->dpd_office_name = '';
			$address->dpd_shipment_type = 'delivery';

			return;
		}

		$address->dpd_office = $pickupOfficeId;
		$address->dpd_office_type = !empty($pickupOffice['office']['type']) ? $pickupOffice['office']['type'] : '';
		$address->dpd_office_name = !empty($pickupOffice['office']['nameEn']) ? $pickupOffice['office']['nameEn'] : (!empty($pickupOffice['office']['name']) ? $pickupOffice['office']['name'] : '');
		$address->dpd_shipment_type = 'pickup';
	}


	private function resolveOrderOrCartAddressId($resourceId, array $formData)
	{
		if (empty($formData['address_type'])) {
			return 0;
		}

		$addressType = (string) $formData['address_type'];
		$resourceId = (int) $resourceId;
		if ($resourceId <= 0) {
			return 0;
		}

		$order = new Order($resourceId);
		if (Validate::isLoadedObject($order)) {
			if ($addressType === 'delivery') {
				return (int) $order->id_address_delivery;
			}

			if ($addressType === 'invoice') {
				return (int) $order->id_address_invoice;
			}
		}

		$cart = new Cart($resourceId);
		if (Validate::isLoadedObject($cart)) {
			if ($addressType === 'delivery') {
				return (int) $cart->id_address_delivery;
			}

			if ($addressType === 'invoice') {
				return (int) $cart->id_address_invoice;
			}
		}

		return 0;
	}

	private function resolveAdminCustomerAddressId($addressId, array $formData)
	{
		$addressId = (int) $addressId;
		if ($addressId <= 0) {
			return 0;
		}
		$resolvedResourceAddressId = $this->resolveOrderOrCartAddressId($addressId, $formData);
		if ($resolvedResourceAddressId > 0) {
			return $resolvedResourceAddressId;
		}


		$currentAddress = new Address($addressId);
		if (Validate::isLoadedObject($currentAddress) && !(bool) $currentAddress->deleted) {
			return $addressId;
		}

		$customerId = 0;
		if (!empty($formData['id_customer'])) {
			$customerId = (int) $formData['id_customer'];
		} elseif (Validate::isLoadedObject($currentAddress)) {
			$customerId = (int) $currentAddress->id_customer;
		}

		if ($customerId <= 0) {
			return 0;
		}

		$query = new DbQuery();
		$query->select('id_address');
		$query->from('address');
		$query->where('deleted = 0');
		$query->where('id_customer = ' . (int) $customerId);

		if (Validate::isLoadedObject($currentAddress) && (bool) $currentAddress->deleted) {
			$query->where('id_address > ' . (int) $addressId);
		}

		$fieldMap = array(
			'alias' => 'alias',
			'first_name' => 'firstname',
			'last_name' => 'lastname',
			'address1' => 'address1',
			'city' => 'city',
			'postcode' => 'postcode',
		);

		foreach ($fieldMap as $formField => $dbField) {
			if (!array_key_exists($formField, $formData)) {
				continue;
			}

			$query->where(sprintf('`%s` = \'%s\'', bqSQL($dbField), pSQL((string) $formData[$formField])));
		}

		$query->orderBy('id_address DESC');

		return (int) Db::getInstance()->getValue($query);
	}

	private function persistAdminCustomerAddressPickupOffice($addressId, array $formData)
	{
		if (!array_key_exists('dpd_office', $formData)) {
			return;
		}

		$targetAddressId = $this->resolveAdminCustomerAddressId($addressId, $formData);
		if ($targetAddressId <= 0) {
			return;
		}

		$address = new Address($targetAddressId);
		if (!Validate::isLoadedObject($address)) {
			return;
		}

		$this->applyPickupOfficeSelectionToAddress($address, $formData['dpd_office']);
		$address->setFieldsToUpdate(array(
			'dpd_office' => true,
			'dpd_office_type' => true,
			'dpd_office_name' => true,
			'dpd_shipment_type' => true,
		));
		$address->update();
	}

	function hookExtraCarrier($params)
	{
		return;
	}

	public function hookActionCarrierUpdate($params)
	{
		return '';
	}

	public function hookDisplayCarrierList($params)
	{
        // return $this->hookDisplayCarrierList($params);
	}

    public function hookAdditionalCustomerAddressFields($params)
    {
        if (empty($params['fields']) || !is_array($params['fields'])) {
            return array();
        }

        $fields = &$params['fields'];
        $labels = $this->getCustomerAddressFormLabels();

        foreach ($labels as $fieldName => $label) {
            if (isset($fields[$fieldName]) && $fields[$fieldName] instanceof FormField) {
                $fields[$fieldName]->setLabel($label);
            }
        }

        $officeFieldExists = isset($fields['dpd_office']) && $fields['dpd_office'] instanceof FormField;
        $pickupOfficeField = $officeFieldExists ? $fields['dpd_office'] : (new FormField())->setName('dpd_office');

        $pickupOfficeField
            ->setType('select')
            ->setLabel($labels['dpd_office'])
            ->setAvailableValues($this->getCustomerAddressOfficeOptions($this->getCustomerAddressFormCountryId($fields)));

        if ($officeFieldExists) {
            $fields['dpd_office'] = $pickupOfficeField;
            return array();
        }

        return array($pickupOfficeField);
    }

    public function hookActionValidateCustomerAddressForm($params)
    {
        if (empty($params['form']) || !method_exists($params['form'], 'getField')) {
            return true;
        }

        $pickupOfficeField = $this->getCustomerAddressFormField($params['form'], 'dpd_office');
        if (!$pickupOfficeField) {
            return true;
        }

        $pickupOfficeId = trim((string) $pickupOfficeField->getValue());
        if ($pickupOfficeId === '') {
            return true;
        }

        $pickupOffice = $this->getPickupOfficeById($pickupOfficeId);
        if (empty($pickupOffice['office']['id'])) {
            $pickupOfficeField->addError($this->l('The selected DPD pickup office is no longer available.'));
            return false;
        }

        return true;
    }

    public function hookActionSubmitCustomerAddressForm($params)
    {
        if (empty($params['address']) || !($params['address'] instanceof Address)) {
            return;
        }

        $this->applyPickupOfficeSelectionToAddress($params['address'], $params['address']->dpd_office);
    }

    public function hookDisplayAdditionalCustomerAddressFields($params)
    {
        return '';
    }

	public function hookActionCustomerAddressFormBuilderModifier($params)
	{
		if (empty($params['form_builder']) || !method_exists($params['form_builder'], 'add')) {
			return;
		}

		$params['form_builder']->add('dpd_office', \Symfony\Component\Form\Extension\Core\Type\TextType::class, array(
			'required' => false,
			'label' => $this->l('DPD Pickup Point'),
			'help' => $this->l('Enter a DPD pickup office ID for pickup delivery. Leave blank for normal delivery.'),
			'empty_data' => '',
			'data' => $this->getAdminCustomerAddressPickupOfficeValue($params),
		));

		if (!$params['form_builder']->has('dpd_office')) {
			return;
		}

		$params['form_builder']->get('dpd_office')->addEventListener(
			\Symfony\Component\Form\FormEvents::POST_SUBMIT,
			function (\Symfony\Component\Form\FormEvent $event) {
				$pickupOfficeId = trim((string) $event->getForm()->getData());
				if ($pickupOfficeId === '') {
					return;
				}

				$pickupOffice = $this->getPickupOfficeById($pickupOfficeId);
				if (!empty($pickupOffice['office']['id'])) {
					return;
				}

				$event->getForm()->addError(new \Symfony\Component\Form\FormError(
					$this->l('The selected DPD pickup office is no longer available.')
				));
			}
		);
	}

	public function hookActionAfterCreateCustomerAddressFormHandler($params)
	{
		if (empty($params['id']) || empty($params['form_data']) || !is_array($params['form_data'])) {
			return;
		}

		$this->persistAdminCustomerAddressPickupOffice((int) $params['id'], $params['form_data']);
	}

	public function hookActionAfterUpdateCustomerAddressFormHandler($params)
	{
		if (empty($params['id']) || empty($params['form_data']) || !is_array($params['form_data'])) {
			return;
		}

		$this->persistAdminCustomerAddressPickupOffice((int) $params['id'], $params['form_data']);
	}

	public function hookDisplayCarrierExtraContent($params)
	{
        if (empty($params['carrier']['id_reference'])) {
            return '';
        }

        if (empty($params['cart']) || !Validate::isLoadedObject($params['cart'])) {
            return '';
        }

        if ((int) $params['carrier']['id_reference'] == (int) Configuration::get(DpdGeopostConfiguration::CARRIER_DPD_LOCKER_ID)) {
            $cart = new Cart((int) $params['cart']->id);
            if (!Validate::isLoadedObject($cart) || (int) $cart->id_address_delivery <= 0) {
                return '';
            }

            $address = new Address((int) $cart->id_address_delivery);
            if (!Validate::isLoadedObject($address)) {
                return '';
            }

            $countryInDb = new Country((int) $address->id_country);
            $countryId = Validate::isLoadedObject($countryInDb) ? $this->getCountryId($countryInDb->iso_code) : 642;
            $pudo = new DpdGeopostPudo();
            $result = $pudo->listSites($address->city, $countryId);

            $this->smarty->assign('dpd_id_cart', $cart->id);
            $this->smarty->assign('dpd_id_delivery_address', $cart->id_address_delivery);
            $this->smarty->assign('dpd_office_name', $address->dpd_office_name);
            if (isset($result['sites']) && count($result['sites']) > 0 ) {
                $this->smarty->assign('dpd_site_id', $result['sites'][0]['id']);
            } else {
                $this->smarty->assign('dpd_site_id', 0);
            }
            $this->smarty->assign('dpd_country_id', $countryId);
            $this->smarty->assign('dpd_locker_selected_address_template', $this->l('Ati selectat livrare la %office%!'));
            $this->smarty->assign('dpd_locker_generic_error_message', $this->l('Locker selection could not be saved. Please try again.'));

            return $this->display(__FILE__, 'locker_options_map.tpl');
        }

        if (Configuration::get('DPD_SHOW_NORMALIZATION_FORM') == "no") {
            return '';
        }

		$pudo = new DpdGeopostPudo();
		$offices = array();

		$address = new Address($params['cart']->id_address_delivery);
		$state = new State($address->id_state);

		// Build the list of states for the address's country and pick the one currently
		// selected on the order. This mirrors the admin hook around line 809-819 so the
		// public template can render a Region dropdown between Country and City. Without
		// these two assigns the public template (displayCarrierList.tpl) has no data to
		// populate that dropdown, which is why it was missing entirely.
		$states = State::getStatesByIdCountry($address->id_country);
		$selectedStateName = '';
		if (Validate::isLoadedObject($state)) {
			$selectedStateName = (string) $state->name;
		}
		if ($selectedStateName === '' && !empty($address->dpd_state)) {
			$selectedStateName = (string) $address->dpd_state;
		}
		if (is_array($states)) {
			$states = array_column($states, 'name');
		} else {
			$states = array();
		}

		$address->dpd_postcode = $address->postcode;
        //$convert_address_str = explode(',', $address->address1);

        $re = '/\D*(\s?)/mi';
        preg_match_all($re, $address->address1, $matches);

        $street_types = array(
            'str.', 'ale.', 'int.', 'fdt.', 'pta.', 'bld.', 'drm.', 'cal.', 'sos.', 'psj.', 'spl.',
            'str ', 'ale ', 'int ', 'fdt ', 'pta ', 'bld ', 'drm ', 'cal ', 'sos ', 'psj ', 'spl '
        );
        $convert_address_str = '';

        if(isset($matches[0]) && isset($matches[0][0])) {
            $convert_address_str = trim($matches[0][0]);
        }

		$convert_address_nr = '';
		$convert_address_bl = '';
		$convert_address_ap = '';


		$addressSearch = new DpdAddressSearch();

		$countryInDb = new Country($address->id_country);
		$countriesInService = array();

		if($countryInDb) {
			if(is_array($countryInDb->name)) {
				$countryNameInDb = false;
				foreach($countryInDb->name as $countryName) {
					$countryNameInDb = $countryName;
					break;
				}
				$countriesInService = $addressSearch->listCountries(array('name'=> $countryNameInDb ));
			} else if(is_scalar($countryInDb->name)) {
				$countriesInService = $addressSearch->listCountries(array('name'=> $countryInDb->name ));
			}

		}

		$countryWsID = false;
		if(!empty($countriesInService)) {
			$countryWsID = $countriesInService[0]['id'];

			$streetTypesWs = $countriesInService[0]['streetTypes'];

			//$street_types = array();
			foreach($streetTypesWs as $st) {
				if(isset($st['name']) && $st['name'] )  {
                    $street_types[] = $st['name'];
                    $street_types[] = str_ireplace('.', ' ', $st['name']);
                }

				if(isset($st['nameEn']) && $st['nameEn'] ) {
				    $street_types[] = $st['nameEn'];
                    $street_types[] = str_ireplace('.', ' ', $st['nameEn']);
                }
			}

		}

		$this->context->smarty->assign('country_id', $countryWsID);

		if(!in_array( $countryWsID, array('642', '100') ))
		{
			return $this->context->smarty->fetch(_DPDGEOPOST_TPL_DIR_ . 'hook/displayCarrierList.tpl');
		}
		$this->context->smarty->assign('dpd_should_normalize', $countryWsID);


		$listCitiesRequest = array('name' => trim($address->city), 'countryId' => $countryWsID);
		if($state) {
			$listCitiesRequest['region'] = trim($state->name);
		}


		$cities = $addressSearch->listCities($listCitiesRequest);

		foreach($street_types as $street_type) {
			if(stripos($convert_address_str, $street_type) === 0) {
				$convert_address_str = str_ireplace($street_type, '', $convert_address_str);

				break;
			}
		}

		$convert_address_str = trim($convert_address_str);

        $city = '';
        $streetIsRequired = true;
//        $address->dpd_street = '';
//        $address->dpd_site = '';
//        $address->dpd_block = '';

		if(empty($cities)) {
			$this->context->smarty->assign('city_error', "City {$address->city} not found. Please select one from the list above");
			$this->context->smarty->assign('street_error', "Street {$convert_address_str} in {$address->city} not found. Please select one from the list above");
		} else {
			$city = $cities[0];
			$city_id = $city['id'];

            $offices = $pudo->listOffices(false, false, $city_id);

            if(stripos($convert_address_str, 'nr.') !== FALSE) {
                $parts = explode('nr.', $convert_address_str);
                $convert_address_str = trim($parts[0]);
            }

			$streets = $addressSearch->listStreets(array('name' => $convert_address_str, 'siteId' => $city_id));

			$address->dpd_site = $city_id;
			$this->context->smarty->assign('city_id', $city_id);

			if(empty($streets)) {
			    /* we didn't find a street by name
                 employ the following strategy
                 - get all streets for site:
			     --- no street found, street becomes optional and whatever it is in Address street name is used in addressNote
			     --- just one street found, street id becomes default, just use it
			     --- more than one street, continue as usual.
			    */

			    $allStreetsInSite = $addressSearch->listStreets(array('siteId' => $city_id));

			    if(empty($allStreetsInSite)) {
			        // no street found, street becomes optional and whatever it is in Address street name is used in addressNote
                    $streetIsRequired  = false;
                }

			    if(count($allStreetsInSite) == 1) {
                    $streetIsRequired  = false;
                    $address->dpd_street = $allStreetsInSite[0]['id'];
                    $this->context->smarty->assign('street_id', $allStreetsInSite[0]['id']);
                }

			    if(count($allStreetsInSite) >= 2) {
                    $this->context->smarty->assign('street_error', "Street {$convert_address_str} in {$address->city} not found. Please select one from the list above");
                    $streetIsRequired = true;

                }

			} else {
                $street = $streets[0];
                $streeIsFound = false;
                foreach ($streets as $streetFromApi) {
                    if($streetFromApi['id'] == $address->dpd_street) {
                        $street = $streetFromApi;
                        $streeIsFound = true;
                        break;
                    }
                }

				if(count($streets) > 1) {
					$nr_streets = count($streets);
					$this->context->smarty->assign('street_error', "We found {$nr_streets} streets named {$convert_address_str} in {$address->city}. Please select the right one from the list above");

				} else {
					$address->dpd_street = $streets[0]['id'];
					$this->context->smarty->assign('street_id', $streets[0]['id']);
				}
                $streetIsRequired = true;
			}
		}



//		$address->address1 = $convert_address_str;

		$str_number = preg_replace("/[^0-9]/", "",$convert_address_nr);
		$bl_number = preg_replace("/[^0-9]/", "",$convert_address_bl);
		$ap_number = preg_replace("/[^0-9]/", "",$convert_address_ap);
		if($convert_address_nr) $address->address1 .= ', nr. ' . $str_number;
		if($convert_address_bl) $address->address1 .= ', bl. ' . $bl_number;
		if($convert_address_ap) $address->address1 .= ', ap. ' . $ap_number;


//		$address->dpd_block = "{$str_number}:{$bl_number}:{$ap_number}";
        $address->dpd_postcode = $city['postCode'];

        $selectedPuDo = false;
        if($address->dpd_office) {
            $selectedPuDo  = $addressSearch->getOfficeById($address->dpd_office);
        }

        $foundStreets =  array();

        if($address->dpd_street) {
            $streetById = $addressSearch->getStreetById($address->dpd_street);
            if(!empty($streetById)) $foundStreets = array($streetById);
        } else {
            $maybeStreetName = $this->extractStreetName($address->id);
            // Bugfix: the second arg to getStreetByName is the DPD site (city) ID, not
            // the dpd_block string ("streetNr:bl:ap"). Passing dpd_block here was making
            // getStreetByName return nothing on the public side, so the streets dropdown
            // rendered empty even when the city was correctly resolved (line 2229 sets
            // $address->dpd_site = $city_id earlier in this same hook). Match the admin
            // handler at the equivalent spot (~line 890).
            $foundStreets = $addressSearch->getStreetByName($maybeStreetName, $address->dpd_site);
        }

        list($streetNr, $streetBl, $streetAp) = explode(':', $address->dpd_block);
		// $address->update();

        $this->context->smarty->assign('foundStreets', $foundStreets);
        $this->context->smarty->assign('streetNr', $streetNr);
        $this->context->smarty->assign('streetBl', $streetBl);
        $this->context->smarty->assign('streetAp', $streetAp);



        $this->context->smarty->assign('selectedPuDo', $selectedPuDo );
        $this->context->smarty->assign('country_ws_id', $countryWsID);
        $this->context->smarty->assign('country_name', $address->country);
		$this->context->smarty->assign('street_is_required', $streetIsRequired);
		$this->context->smarty->assign('ws_city', $city);
		$this->context->smarty->assign('ws_postcode', $city['postCode']);
		$this->context->smarty->assign('offices', $offices);
		$this->context->smarty->assign('address', $address);
		$this->context->smarty->assign('converted_address_str', $convert_address_str);
		$this->context->smarty->assign('converted_address_nr', preg_replace("/[^0-9]/", "", $convert_address_nr) );
		$this->context->smarty->assign('converted_address_bl', preg_replace("/[^0-9]/", "", $convert_address_bl) );
		$this->context->smarty->assign('converted_address_ap', preg_replace("/[^0-9]/", "", $convert_address_ap) );
		$this->context->smarty->assign('state_name', $state->name);
		$this->context->smarty->assign('states', $states);
		$this->context->smarty->assign('selectedStateName', $selectedStateName);

		return $this->context->smarty->fetch(_DPDGEOPOST_TPL_DIR_ . 'hook/displayCarrierList.tpl');
//        return $this->context->smarty->fetch(_DPDGEOPOST_TPL_DIR_ . 'hook/displayCarrierListTestLoad.tpl');

	}

    public function installStates() {
        $countries_table = _DB_PREFIX_.'countries';
        $states_table = _DB_PREFIX_.'counties';

        $countriesAndStates = array(
            'BG' => array(
                array('name' => 'Blagoevgrad', 'iso'=>'E') ,
                array('name' => 'Burgas', 'iso'=>'AA') ,
                array('name' => 'Dobrich', 'iso'=>'TX') ,
                array('name' => 'Gabrovo', 'iso'=>'EB') ,
                array('name' => 'Haskovo', 'iso'=>'X') ,
                array('name' => 'Kardzhali', 'iso'=>'K') ,
                array('name' => 'Kyustendil', 'iso'=>'KH') ,
                array('name' => 'Lovech', 'iso'=>'OB') ,
                array('name' => 'Montana', 'iso'=>'M'),
                array('name' => 'Pazardzhik', 'iso'=>'PA'),
                array('name' => 'Pernik', 'iso'=>'PK'),
                array('name' => 'Pleven', 'iso'=>'Eh'),
                array('name' => 'Plovdiv', 'iso'=>'PB'),
                array('name' => 'Razgrad', 'iso'=>'PP'),
                array('name' => 'Ruse', 'iso'=>'P'),
                array('name' => 'Shumen', 'iso'=>'H') ,
                array('name' => 'Silistra', 'iso'=>'CC') ,
                array('name' => 'Sliven', 'iso'=>'CH'),
                array('name' => 'Smolyan', 'iso'=>'CM'),
                array('name' => 'Sofia City Province', 'iso'=>'SCP'),
                array('name' => 'Sofia', 'iso'=>'CO'),
                array('name' => 'Stara Zagora', 'iso'=>'CT'),
                array('name' => 'Targovishte', 'iso'=>'T'),
                array('name' => 'Varna', 'iso'=>'B'),
                array('name' => 'Veliko Tarnovo', 'iso'=>'BT'),
                array('name' => 'Vidin', 'iso'=>'BH'),
                array('name' => 'Vratsa', 'iso'=>'BP'),
                array('name' => 'Yambol', 'iso'=>'Y'),
            )
        );



        foreach ($countriesAndStates as $countryISO => $stateData) {

            $countryIdByISO = Country::getByIso($countryISO);
            $states = State::getStatesByIdCountry($countryIdByISO);

            if(count($states) > 0) continue;

            $country = new Country($countryIdByISO);
            foreach ($stateData as $stateElements) {
                $state = new State();
                $state->id_country = $countryIdByISO;
                $state->name = $stateElements['name'];
                $state->iso_code = $stateElements['iso'];
                $state->id_zone = $country->id_zone;
                $state->add();
            }


        }

    }

    /**
     * Parse Nr / Bl / Ap out of free-text address lines.
     *
     * Customers commonly type addresses like "Strada Foo, nr 10 bl A2 sc 1 ap 5"
     * or "Foo nr.10/A bl. B2 ap. 5". The order form's Nr/Bl/Ap inputs are
     * normally fed from dpd_block (a "nr:bl:ap" colon-string set during DPD
     * normalization), but when the address never went through that flow we
     * still want the inputs pre-populated so the merchant doesn't have to
     * re-type them on every order.
     *
     * Supported tokens (case-insensitive, full or abbreviated, with or without
     * a trailing dot, with whitespace optional after):
     *   nr | numarul | numar         → number
     *   bl | bloc                    → block
     *   ap | apartament              → apartment
     *
     * The captured value can be alphanumeric (e.g. "10A", "B-2") and may
     * include a hyphen, since real Romanian addresses use those forms.
     *
     * @param string $text concatenated address1 + address2
     * @return array{nr:string,bl:string,ap:string}
     */
    private function parseAddressDetailsFromText($text)
    {
        $result = array('nr' => '', 'bl' => '', 'ap' => '');
        if ($text === '' || $text === null) {
            return $result;
        }

        // Each pattern uses \b on BOTH sides of the keyword so we don't match inside
        // larger words ("Block 10" or "apparent" must NOT trigger bl/ap). Alternation
        // is ordered longest-first so "apartament" wins over "ap" when both could
        // match. The captured value must start with a digit — Romanian addresses
        // always use digit-prefixed numbers/blocks/apts ("10", "10A", "B2", "B-2"),
        // and this also keeps us from grabbing the next street word as the value.
        if (preg_match('/\b(?:numarul|numar|nr)\b\.?\s*(\d[A-Za-z0-9\-\/]*)/iu', $text, $m)) {
            $result['nr'] = trim($m[1], '-/');
        }
        if (preg_match('/\b(?:bloc|bl)\b\.?\s*([A-Za-z0-9][A-Za-z0-9\-\/]*)/iu', $text, $m)) {
            // Block tags can start with a letter (e.g. "Bl. A2"), so allow letter-prefix.
            $result['bl'] = trim($m[1], '-/');
        }
        if (preg_match('/\b(?:apartament|apart|ap)\b\.?\s*(\d[A-Za-z0-9\-\/]*)/iu', $text, $m)) {
            $result['ap'] = trim($m[1], '-/');
        }

        return $result;
    }

    public function extractStreetName($deliveryAddress) {
        $address = new Address($deliveryAddress);
        $raw = (string) $address->address1;

        // Step 1: chop off everything after the first number/block/apartment marker.
        // Customers commonly type "Strada Foo, nr 10" / "Foo nr.10 bl. A2 ap. 5" — DPD's
        // listStreets only matches against the street name, not the whole address line,
        // so we need just the name portion. Split on ',' or ';' (which usually precede
        // suffixes), or on whitespace immediately followed by an address-suffix keyword.
        $parts = preg_split(
            '/\s*[,;]\s*|\s+(?:nr\.?|numarul|bloc|bl\.?|scara|sc\.?|etaj|et\.?|ap\.?|apartament)\b.*$/iu',
            $raw,
            2
        );
        $convert_address_str = isset($parts[0]) ? trim($parts[0]) : '';

        // Step 2: also drop any residual digits at the end (e.g. "Foo Street 10" with
        // no separator). Stops at the first digit, returns everything before it.
        if (preg_match('/^(.*?)\s*\d/u', $convert_address_str, $m)) {
            $convert_address_str = trim($m[1]);
        }
        // Trim trailing punctuation/whitespace.
        $convert_address_str = trim($convert_address_str, " \t\n\r\0\x0B,.;-");

        // Step 3: strip the leading street-type word. Cover both full Romanian forms
        // (Strada, Bulevardul, Calea, Soseaua, Aleea, Splaiul, Piata, Drumul, Intrarea,
        // Fundatura, Pasajul) and the abbreviations DPD's WS uses ("str.", "bld.", ...).
        // Without the full forms, "Strada Mircea cel Batran" stays as
        // "Strada Mircea cel Batran" and DPD's listStreets returns no matches.
        $street_types = array(
            // Full Romanian words (what customers normally type)
            'Strada', 'Bulevardul', 'Calea', 'Soseaua', 'Aleea', 'Splaiul',
            'Piata', 'Drumul', 'Intrarea', 'Fundatura', 'Pasajul',
            // Common abbreviated forms (with and without trailing dot)
            'str.', 'str', 'ale.', 'ale', 'int.', 'int', 'fdt.', 'fdt',
            'pta.', 'pta', 'bld.', 'bld', 'bd.', 'bd', 'bvd.', 'bvd',
            'drm.', 'drm', 'cal.', 'cal', 'sos.', 'sos', 'psj.', 'psj',
            'spl.', 'spl',
        );

        // Augment with whatever street types DPD's WS reports for the country, so we
        // also strip the canonical forms returned by listCountries (e.g. "Bd-ul").
        $countryWsID = $address->dpd_country;
        if ($countryWsID) {
            $countryInDb = new Country($address->id_country);
            $countriesInService = array();
            if ($countryInDb && Validate::isLoadedObject($countryInDb)) {
                $countryNameInDb = is_array($countryInDb->name)
                    ? reset($countryInDb->name)
                    : $countryInDb->name;
                if ($countryNameInDb) {
                    $countriesInService = (new DpdAddressSearch())->listCountries(
                        array('name' => $countryNameInDb)
                    );
                }
            }
            if (!empty($countriesInService) && !empty($countriesInService[0]['streetTypes'])) {
                foreach ($countriesInService[0]['streetTypes'] as $st) {
                    if (!empty($st['name'])) {
                        $street_types[] = $st['name'];
                        $street_types[] = str_ireplace('.', ' ', $st['name']);
                    }
                    if (!empty($st['nameEn'])) {
                        $street_types[] = $st['nameEn'];
                        $street_types[] = str_ireplace('.', ' ', $st['nameEn']);
                    }
                }
            }
        }

        // Sort by length desc so "Bulevardul" matches before "Bd."; otherwise "bd."
        // would never match "Bulevardul ..." but a shorter prefix like "B" (if any)
        // would match too eagerly. Length-desc ordering is the standard fix.
        usort($street_types, function ($a, $b) {
            return strlen((string) $b) - strlen((string) $a);
        });

        foreach ($street_types as $street_type) {
            $st_trim = rtrim((string) $street_type);
            if ($st_trim === '') {
                continue;
            }
            if (stripos($convert_address_str, $st_trim) === 0) {
                $convert_address_str = trim(substr($convert_address_str, strlen($st_trim)));
                break;
            }
        }

        return trim($convert_address_str, " \t\n\r\0\x0B,.;-");
    }

    public function getCountryId($code)
    {
        switch ($code) {
            case 'RO':
                return 642;
            case 'BG':
                return 100;
            case 'GR':
                return 300;
            case 'HU':
                return 348;
            case 'PL':
                return 616;
            case 'SI':
                return 705;
            case 'SK':
                return 703;
            case 'CZ':
                return 203;
            case 'HR':
                return 191;
            case 'AT':
                return 40;
            case 'IT':
                return 380;
            case 'DE':
                return 276;
            case 'ES':
                return 724;
            case 'FR':
                return 250;
            case 'NL':
                return 528;
            case 'BE':
                return 56;
            case 'EE':
                return 233;
            case 'DK':
                return 208;
            case 'LU':
                return 442;
            case 'LV':
                return 428;
            case 'LT':
                return 440;
            case 'FI':
                return 246;
            case 'PT':
                return 620;
            case 'SE':
                return 752;
            default:
                return 642;
        }
    }

    public function getAllowedCountryCodes()
    {
        return ['RO', 'BG', 'GR', 'HU', 'PL', 'SL', 'SK', 'CZ', 'HR', 'AU', 'IT', 'DE', 'ES', 'FR','NL', 'BE', 'EE', 'DK', 'LU', 'LV', 'LT', 'FI', 'PT', 'SE'];
    }

}



