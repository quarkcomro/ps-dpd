(function () {
    'use strict';

    function onReady(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
            return;
        }

        callback();
    }

    function getLockerContainer() {
        return document.getElementById('dpdgeopost_locker');
    }

    function normalizeErrorMessage(error, fallbackMessage) {
        if (!error) {
            return fallbackMessage;
        }

        if (typeof error === 'string') {
            return error;
        }

        if (typeof error.message === 'string' && error.message !== '') {
            return error.message;
        }

        return fallbackMessage;
    }

    function getOfficeLabel(office) {
        if (!office || typeof office !== 'object') {
            return '';
        }

        if (typeof office.nameEn === 'string' && office.nameEn !== '') {
            return office.nameEn;
        }

        if (typeof office.name === 'string' && office.name !== '') {
            return office.name;
        }

        return '';
    }

    function getOfficeAddressLabel(office) {
        if (
            office
            && office.address
            && typeof office.address.fullAddressString === 'string'
            && office.address.fullAddressString !== ''
        ) {
            return office.address.fullAddressString;
        }

        return getOfficeLabel(office);
    }

    function renderMessage(container, type, message) {
        var alert = document.createElement('div');
        alert.className = 'alert alert-' + type;
        alert.textContent = message;

        container.innerHTML = '';
        container.appendChild(alert);
        container.style.display = '';
    }

    function replaceOfficePlaceholder(template, officeLabel) {
        if (typeof template !== 'string' || template === '') {
            return officeLabel;
        }

        return template.replace('%office%', officeLabel);
    }

    function emitCartUpdate(response) {
        if (typeof prestashop === 'undefined' || typeof prestashop.emit !== 'function') {
            return;
        }

        prestashop.emit('updateCart', {
            reason: {},
            resp: response
        });
    }

    function persistLockerSelection(container, office) {
        var ajaxUrl = window._DPDGEOPOST_AJAX_URI_;
        var token = window._DPD_TOKEN_;
        var params = new URLSearchParams();

        params.append('ajax', 'true');
        params.append('token', token || '');
        params.append('action', 'update_locker');
        params.append('cart_id', container.dataset.cartId || '');
        params.append('delivery_address_id', container.dataset.deliveryAddressId || '');
        params.append('dpd_office_id', office.id || '');
        params.append('dpd_office_type', office.type || '');
        params.append('dpd_office_name', getOfficeLabel(office));

        return window.fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: params.toString()
        }).then(function (response) {
            return response.json();
        });
    }

    function initLockerMap() {
        var container = getLockerContainer();

        if (!container || container.dataset.dpdLockerInitialized === '1') {
            return;
        }

        container.dataset.dpdLockerInitialized = '1';

        window.addEventListener('message', function (event) {
            var office = event && event.data ? event.data : null;
            var fallbackErrorMessage = container.dataset.genericErrorMessage || 'Locker selection could not be saved. Please try again.';

            if (!office || typeof office !== 'object' || typeof office.id === 'undefined') {
                return;
            }

            persistLockerSelection(container, office)
                .then(function (response) {
                    if (response && response.error) {
                        renderMessage(container, 'danger', normalizeErrorMessage(response.error, fallbackErrorMessage));
                        return;
                    }

                    renderMessage(
                        container,
                        'success',
                        replaceOfficePlaceholder(
                            container.dataset.selectedAddressTemplate,
                            getOfficeAddressLabel(office)
                        )
                    );
                    emitCartUpdate(response);
                })
                .catch(function () {
                    renderMessage(container, 'danger', fallbackErrorMessage);
                });
        }, false);
    }

    onReady(initLockerMap);
}());