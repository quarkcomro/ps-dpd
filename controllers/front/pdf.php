<?php

/**
 * PDF dispatch front controller for dpdgeopost.
 *
 * The browser was rendering the PDF as garbled text because PS's normal
 * ModuleFrontController lifecycle (init → setMedia → display) starts emitting
 * the response (cookies, sometimes whitespace) before postProcess() runs, so
 * the legacy dpdgeopost.pdf.php script's `header('Content-Type: application/pdf')`
 * calls were arriving too late and getting silently dropped.
 *
 * Strategy: short-circuit the lifecycle by doing all the work in init(), which
 * is the earliest hookable point in `Controller::run()`. We drain any output
 * buffers, set headers ourselves, hand off to the legacy script, and exit.
 * No `display()` / `displayAjax()` runs because we exit first.
 */
class DpdGeopostPdfModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $display_header = false;
    public $display_footer = false;

    public function init()
    {
        // Drain every level of output buffering PS may have started before we
        // got control. Subsequent header() calls only work if no body bytes
        // have been flushed to the client yet.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Set Content-Type / Content-Disposition HERE, before requiring the
        // legacy script. Discovered via curl that the legacy dpdgeopost.pdf.php's
        // own header() calls were silently dropped on the way out (response was
        // arriving as Content-Type: text/html with valid PDF bytes in the body),
        // so we set the headers up-front from the same DPD_GEOPOST_PRINT_FORMAT
        // config the legacy script reads. If the legacy script also calls
        // header() for the same name, PHP replaces by default — its values win.
        // If it can't (the actual cause of the bug), our values stick.
        if (!headers_sent()) {
            header_remove('Content-Type');
            header_remove('Content-Disposition');
            header_remove('Cache-Control');
            header_remove('Pragma');
            header_remove('Expires');

            $idOrder = (int) Tools::getValue('id_order');
            $isVouchers = (bool) Tools::getValue('printVouchers');
            $filenamePrefix = $isVouchers ? 'shipment_vouchers_' : 'shipment_labels_';
            $format = strtoupper((string) Configuration::get('DPD_GEOPOST_PRINT_FORMAT'));
            switch ($format) {
                case 'HTML':
                    header('Content-Type: text/html; charset=UTF-8');
                    $ext = '.html';
                    break;
                case 'ZPL':
                    // Treat ZPL as a generic binary download — no registered MIME.
                    header('Content-Type: application/octet-stream');
                    $ext = '.zpl';
                    break;
                case 'PDF':
                default:
                    header('Content-Type: application/pdf');
                    $ext = '.pdf';
                    break;
            }
            header('Content-Disposition: attachment; filename="' . $filenamePrefix . $idOrder . $ext . '"');
        }

        // Hand off to the legacy script for token validation + WS call + body output.
        require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'dpdgeopost.pdf.php';
        exit;
    }

    public function postProcess()
    {
        // Should never reach here — init() exits — but kept as belt-and-braces
        // in case PS routes around init() in some edge case.
        $this->init();
    }
}
