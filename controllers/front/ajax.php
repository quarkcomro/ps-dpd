<?php

class DpdGeopostAjaxModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function postProcess()
    {
        require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'dpdgeopost.ajax.php';
        exit;
    }
}