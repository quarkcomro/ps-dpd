<?php
if (!defined('_PS_VERSION_'))
    exit;

/**
 * 1.0.1 — rename the locker carrier from "DPD Standard Locker" to
 * "DPD OOH Locker/Office/PUDO" everywhere PS stores it.
 *
 * The name was changed in code (classes/dpd_standard_locker.service.php and
 * classes/configuration.controller.php) in 1.0.0, but those literals are only
 * used when CREATING a fresh carrier row — and the install path here is
 * actually idempotent (un-deletes an existing row rather than creating a new
 * one with the new name; see classes/dpd_standard_locker.service.php:28-37
 * and classes/service.php:46-67). The uninstall path is also a soft-delete
 * (sets carrier.deleted=1, leaves carrier_lang untouched), so a stock
 * uninstall + reinstall doesn't propagate the rename either.
 *
 * Update both the active row and any soft-deleted rows for that carrier so
 * historical orders show consistent labels too. Match by string rather than
 * by Configuration::get(CARRIER_DPD_LOCKER_ID) because that points to a
 * single id and we may have orphaned rows from older installs.
 */
function upgrade_module_1_0_1($module)
{
    $oldName = 'DPD Standard Locker';
    $newName = 'DPD OOH Locker/Office/PUDO';

    Db::getInstance()->execute(
        'UPDATE `' . _DB_PREFIX_ . 'carrier`
            SET `name` = "' . pSQL($newName) . '"
          WHERE `name` = "' . pSQL($oldName) . '"'
    );

    Db::getInstance()->execute(
        'UPDATE `' . _DB_PREFIX_ . 'carrier_lang`
            SET `delay` = "' . pSQL($newName) . '"
          WHERE `delay` = "' . pSQL($oldName) . '"'
    );

    return true;
}
