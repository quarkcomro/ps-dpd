<?php
/** **/

if (!defined('_PS_VERSION_'))
	exit;


class DpdGeopostShipmentController extends DpdGeopostController
{
	const DEFAULT_ORDER_BY  = 'id_shipment';
	const DEFAULT_ORDER_WAY = 'desc';
	const FILENAME          = 'shipmentsList.controller';
	const LIST_TABLE        = 'shipment';

	private static $ORDERABLE_FIELDS = array(
		'id_shipment', 'date_shipped', 'id_order', 'date_add',
		'carrier', 'customer', 'date_pickup',
	);

	private static $FILTERABLE_FIELDS = array(
		'id_shipment', 'date_shipped', 'id_order', 'date_add',
		'carrier', 'customer', 'quantity', 'label', 'date_pickup',
	);

	private $orderLinkBase;

	public function __construct()
	{
		parent::__construct();
		$this->orderLinkBase = 'index.php?controller=AdminOrders&vieworder&token=' . Tools::getAdminTokenLite('AdminOrders');
		$this->init();
	}

	private function init()
	{
		if (Tools::isSubmit('printManifest')) {
			$this->handlePrintManifest();
		}

		if (Tools::isSubmit('submitBulkprintLabelsshipment')) {
			$this->handlePrintLabels();
		}

		if (Tools::isSubmit('submitBulkchangeOrderStatusshipment')) {
			$this->handleChangeOrderStatus();
		}
	}

	private function handlePrintManifest()
	{
		$shipmentIds = Tools::getValue('shipmentBox');

		if (!is_array($shipmentIds) || empty($shipmentIds)) {
			$this->module_instance->outputHTML(
				$this->module_instance->displayError($this->l('No selected shipments'))
			);
			return;
		}

		$manifest = new DpdGeopostManifest;
		$manifest->shipments = $shipmentIds;

		if ($manifest->printManifest()) {
			foreach ($shipmentIds as $shipmentId) {
				$shipment = new DpdGeopostShipment;
				$shipment->getAndSaveTrackingInfo($shipmentId);
			}
			Tools::redirectAdmin($this->module_instance->module_url . '&menu=shipment_list');
			exit();
		}

		$this->module_instance->outputHTML(
			$this->module_instance->displayError(
				reset(DpdGeopostManifest::$errors)
			)
		);
	}

	private function handlePrintLabels()
	{
		$shipmentIds = Tools::getValue('shipmentBox');

		if (!$shipmentIds) {
			$this->module_instance->outputHTML(
				$this->module_instance->displayError($this->l('Select at least one shipment'))
			);
			return;
		}

		foreach ($shipmentIds as $shipmentId) {
			$individualShipment = new DpdGeopostShipment(null, $shipmentId);
			$individualShipment->getAndSaveTrackingInfo($shipmentId);
		}

		$shipment = new DpdGeopostShipment;
		if ($pdfContent = $shipment->getLabelsPdf($shipmentIds)) {
			header('Content-type: application/pdf');
			header('Content-Disposition: attachment; filename="shipment_labels' . time() . '.pdf"');
			echo $pdfContent;
			die();
		}

		$this->module_instance->outputHTML(
			$this->module_instance->displayError(
				reset(DpdGeopostManifest::$errors)
			)
		);
	}

	private function handleChangeOrderStatus()
	{
		$shipmentIds = Tools::getValue('shipmentBox');

		if (!$shipmentIds) {
			$this->module_instance->outputHTML(
				$this->module_instance->displayError($this->l('Select at least one shipment'))
			);
			return;
		}

		foreach ($shipmentIds as $id_shipment) {
			$id_order = DpdGeopostShipment::getOrderIdByShipmentId((int)$id_shipment);

			if (!self::changeOrderStatusToShipped($id_order)) {
				self::$errors[] = sprintf($this->l('Can not continue: shipment #%d order status could not be updated'), $id_shipment);
				break;
			}
		}

		if (self::$errors) {
			$this->module_instance->outputHTML(
				$this->module_instance->displayError(reset(self::$errors))
			);
			return;
		}

		DpdGeopost::addFlashMessage($this->l('Selected orders statuses were successfully updated'));
		Tools::redirectAdmin($this->module_instance->module_url . '&menu=shipment_list');
	}

	public function getShipmentList()
	{
		$this->persistFilterState();

		$orderBy  = $this->readOrderBy();
		$orderWay = $this->readOrderWay();
		$page     = $this->readPage();
		$perPage  = $this->readPerPage();
		$start    = ($perPage * $page) - $perPage;

		$filter = $this->getFilterQuery(self::$FILTERABLE_FIELDS, self::LIST_TABLE);

		$shipmentModel = new DpdGeopostShipment();
		$shipments = $shipmentModel->getShipmentList($orderBy, $orderWay, $filter, $start, $perPage);
		$listTotal = count($shipmentModel->getShipmentList($orderBy, $orderWay, $filter, null, null));

		$shipments_count = count($shipments);
		for ($i = 0; $i < $shipments_count; $i++) {
			$order = new Order((int)$shipments[$i]['id_order']);
			$carrier = new Carrier((int)$order->id_carrier, $order->id_lang);
			$shipments[$i]['carrier_url'] = $carrier->url;
			// HelperList only fires a column's callback when array_key_exists($key, $row) is true
			// (HelperList.php:391). The 'actions' column has no SQL counterpart, so seed an empty
			// value here to ensure printRowActions() runs and emits the hidden pickup_available input.
			$shipments[$i]['actions'] = '';
		}

		$helperList = $this->renderHelperList($shipments, $listTotal, $orderBy, $orderWay, $perPage);

		$this->context->smarty->assign(array(
			'employee'         => $this->context->employee,
			'helper_list_html' => $helperList,
			'dpd_geopost_id_lang' => (int)$this->context->language->id,
		));

		return $this->context->smarty->fetch(_DPDGEOPOST_TPL_DIR_ . 'admin/shipment_list.tpl');
	}

	private function persistFilterState()
	{
		$prefix = self::LIST_TABLE . 'Filter_';

		if (Tools::isSubmit('submitFilter' . self::LIST_TABLE)) {
			foreach ($_POST as $key => $value) {
				if (strpos($key, $prefix) === 0) {
					$this->context->cookie->$key = is_array($value)
						? serialize($value)
						: pSQL($value);
				}
			}
		}

		if (Tools::isSubmit('submitReset' . self::LIST_TABLE)) {
			foreach (self::$FILTERABLE_FIELDS as $field) {
				$cookieKey = $prefix . $field;
				if ($this->context->cookie->__isset($cookieKey)) {
					$this->context->cookie->__unset($cookieKey);
				}
				$_POST[$cookieKey] = null;
			}
		}
	}

	private function readOrderBy()
	{
		$value = Tools::getValue(self::LIST_TABLE . 'Orderby', self::DEFAULT_ORDER_BY);
		return in_array($value, self::$ORDERABLE_FIELDS, true) ? $value : self::DEFAULT_ORDER_BY;
	}

	private function readOrderWay()
	{
		$value = Tools::strtolower(Tools::getValue(self::LIST_TABLE . 'Orderway', self::DEFAULT_ORDER_WAY));
		return in_array($value, array('asc', 'desc'), true) ? $value : self::DEFAULT_ORDER_WAY;
	}

	private function readPage()
	{
		$page = (int)Tools::getValue('submitFilter' . self::LIST_TABLE);
		return $page > 0 ? $page : 1;
	}

	private function readPerPage()
	{
		$perPage = (int)Tools::getValue(self::LIST_TABLE . '_pagination', $this->pagination[0]);
		return in_array($perPage, $this->pagination, true) ? $perPage : $this->pagination[0];
	}

	private function buildFieldsList()
	{
		return array(
			'id_shipment' => array(
				'title'   => $this->l('Shipment ID'),
				'align'   => 'center',
				'width'   => 100,
				'orderby' => true,
				'search'  => true,
			),
			'date_shipped' => array(
				'title'       => $this->l('Date shipped'),
				'align'       => 'center',
				'type'        => 'datetime',
				'orderby'     => true,
				'search'      => true,
				'filter_type' => 'date',
			),
			'id_order' => array(
				'title'           => $this->l('Order'),
				'align'           => 'center',
				'width'           => 90,
				'orderby'         => true,
				'search'          => true,
				'callback'        => 'printOrderLink',
				'callback_object' => $this,
			),
			'date_add' => array(
				'title'       => $this->l('Order date'),
				'align'       => 'center',
				'type'        => 'datetime',
				'orderby'     => true,
				'search'      => true,
				'filter_type' => 'date',
			),
			'carrier' => array(
				'title'   => $this->l('Carrier'),
				'align'   => 'center',
				'orderby' => true,
				'search'  => true,
			),
			'customer' => array(
				'title'   => $this->l('Customer'),
				'align'   => 'left',
				'orderby' => true,
				'search'  => true,
			),
			'quantity' => array(
				'title'  => $this->l('Total qty'),
				'align'  => 'center',
				'width'  => 70,
				'search' => true,
			),
			'label' => array(
				'title'           => $this->l('Label printed'),
				'align'           => 'center',
				'search'          => true,
				'filter_type'     => 'bool',
				'callback'        => 'printLabelStatus',
				'callback_object' => $this,
			),
			'date_pickup' => array(
				'title'       => $this->l('DPD pickup'),
				'align'       => 'center',
				'type'        => 'datetime',
				'orderby'     => true,
				'search'      => true,
				'filter_type' => 'date',
			),
			'actions' => array(
				'title'           => $this->l('Actions'),
				'align'           => 'center',
				'width'           => 100,
				'orderby'         => false,
				'search'          => false,
				'callback'        => 'printRowActions',
				'callback_object' => $this,
			),
		);
	}

	private function renderHelperList(array $shipments, $listTotal, $orderBy, $orderWay, $perPage)
	{
		$helper = new HelperList();
		$helper->module              = $this->module_instance;
		$helper->shopLinkType        = '';
		$helper->simple_header       = false;
		$helper->identifier          = 'id_shipment';
		$helper->table               = self::LIST_TABLE;
		$helper->title               = $this->l('Shipping list');
		$helper->title_icon          = 'icon-truck';
		$helper->no_link             = true;
		$helper->show_toolbar        = false;
		$helper->actions             = array();
		$helper->bulk_actions        = array(
			'printLabels' => array(
				'text' => $this->l('Print label(s)'),
				'icon' => 'icon-print',
			),
			'changeOrderStatus' => array(
				'text'    => $this->l('Change order status to shipped'),
				'icon'    => 'icon-truck',
				'confirm' => $this->l('Change order status for selected shipments?'),
			),
		);
		$helper->orderBy             = $orderBy;
		$helper->orderWay            = $orderWay;
		$helper->listTotal           = (int)$listTotal;
		$helper->_pagination         = $this->pagination;
		$helper->_default_pagination = (int)$perPage;

		$helper->currentIndex = 'index.php?controller=AdminModules&configure=dpdgeopost&menu=shipment_list';
		$helper->token        = Tools::getAdminTokenLite('AdminModules');

		return $helper->generateList($shipments, $this->buildFieldsList());
	}

	public function printOrderLink($value, $row)
	{
		if (!$value) {
			return '--';
		}
		return '<a href="' . Tools::safeOutput($this->orderLinkBase . '&id_order=' . (int)$value) . '">' . (int)$value . '</a>';
	}

	public function printLabelStatus($value, $row)
	{
		if ($value) {
			return '<span class="badge badge-success"><i class="icon-check"></i> ' . $this->l('Yes') . '</span>';
		}
		return '<span class="badge badge-default"><i class="icon-times"></i> ' . $this->l('No') . '</span>';
	}

	public function printRowActions($value, $row)
	{
		$orderId = (int)$row['id_order'];
		$viewHref = $this->orderLinkBase . '&id_order=' . $orderId;

		$manifestClosed = !empty($row['manifest']) && $row['manifest'] !== '0000-00-00 00:00:00';
		$pickupAvailable = ($manifestClosed || !empty($row['label'])) ? 1 : 0;

		$html = '<input type="hidden" name="pickup_available" value="' . $pickupAvailable . '" />';
		$html .= '<a class="btn btn-default" title="' . $this->l('View') . '" href="' . Tools::safeOutput($viewHref) . '">'
		       . '<i class="icon-eye"></i></a>';

		// DpdGeopostShipment::getShipmentList() hardcodes "1 AS shipping_number" in its SQL
		// (models/Shipment.php:1719), so $row['shipping_number'] is always 1. The actual DPD
		// parcel reference is id_shipment — the BIGINT returned by DPD's API and stored on
		// dpdgeopost_shipment.id_shipment. That's what belongs in the tracking URL placeholder.
		if (!empty($row['id_shipment']) && !empty($row['carrier_url'])) {
			$trackUrl = str_replace('@', (string)$row['id_shipment'], (string)$row['carrier_url']);
			$html .= ' <a class="btn btn-default" target="_blank" title="' . $this->l('Track Shipment') . '" '
			       . 'href="' . Tools::safeOutput($trackUrl) . '">'
			       . '<i class="icon-external-link"></i></a>';
		}

		return $html;
	}

	public static function changeOrderStatusToShipped($id_order)
	{
		if (!$id_order)
			return false;

		$order = new Order((int)$id_order);

		if ($order->current_state == Configuration::get('PS_OS_SHIPPING'))
			return true;

		if ($order->setCurrentState((int)Configuration::get('PS_OS_SHIPPING'), (int)Context::getContext()->employee->id) === false)
			return false;

		return true;
	}
}
