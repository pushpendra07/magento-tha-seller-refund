define([
    'jquery',
    'mage/translate',
    'Magento_Ui/js/modal/confirm',
    'jquery-ui-modules/widget'
], function ($, $t, confirm) {
    'use strict';

    /**
     * Refund detail actions: register the cash refund, replay an ERP operation, cancel.
     * Every action asks for confirmation, disables the controls while in flight, and on
     * success reloads the page so the screen shows the server state (FRD section 14).
     */
    $.widget('acme.acmeRefundActions', {
        options: {
            completeUrl: '',
            retryUrl: '',
            cancelUrl: '',
            formKey: '',
            selectors: {
                complete: '[data-role="complete"]',
                retry: '[data-role="retry"]',
                cancel: '[data-role="cancel"]',
                txnNumber: '[data-role="txn-number"]',
                txnDate: '[data-role="txn-date"]',
                message: '[data-role="action-message"]',
                buttons: 'button'
            }
        },

        /** Widget bootstrap. */
        _create: function () {
            this.inFlight = false;
            this._on(this.element.find(this.options.selectors.complete), {click: '_onComplete'});
            this._on(this.element.find(this.options.selectors.retry), {click: '_onRetry'});
            this._on(this.element.find(this.options.selectors.cancel), {click: '_onCancel'});
        },

        /** Register the offline cash refund (BR-10 needs both values). */
        _onComplete: function () {
            var number = $.trim(this.element.find(this.options.selectors.txnNumber).val()),
                date = this.element.find(this.options.selectors.txnDate).val();

            if (number === '' || date === '') {
                this._message($t('Enter the bank transaction number and date.'), true);

                return;
            }

            this._confirmAndPost(
                $t('Register the cash refund with transaction %1 on %2?').replace('%1', number).replace('%2', date),
                this.options.completeUrl,
                {transaction_number: number, transaction_date: date}
            );
        },

        /** Replay the pending ERP operation for this refund. */
        _onRetry: function () {
            this._confirmAndPost($t('Queue the ERP operation for this refund again?'), this.options.retryUrl, {});
        },

        /** Void the refund before any ERP call. */
        _onCancel: function () {
            this._confirmAndPost($t('Cancel this refund? This cannot be undone.'), this.options.cancelUrl, {});
        },

        /**
         * @param {String} question
         * @param {String} url
         * @param {Object} data
         */
        _confirmAndPost: function (question, url, data) {
            var self = this;

            if (this.inFlight) {
                return;
            }

            confirm({
                title: $t('Confirm'),
                content: question,
                actions: {
                    confirm: function () {
                        self._post(url, data);
                    }
                }
            });
        },

        /**
         * @param {String} url
         * @param {Object} data
         */
        _post: function (url, data) {
            var self = this;

            this._setBusy(true);
            $.ajax({
                url: url,
                type: 'POST',
                dataType: 'json',
                data: $.extend({form_key: this.options.formKey}, data)
            }).done(function (response) {
                if (!response.ok) {
                    self._setBusy(false);
                    self._message(response.message || $t('The action could not be completed.'), true);

                    return;
                }
                self._message(response.message, false);
                window.location.reload();
            }).fail(function () {
                self._setBusy(false);
                self._message($t('The request failed. Refresh the page to see the current state.'), true);
            });
        },

        /** @param {Boolean} busy */
        _setBusy: function (busy) {
            this.inFlight = busy;
            this.element.find(this.options.selectors.buttons).prop('disabled', busy);
        },

        /**
         * @param {String} text
         * @param {Boolean} isError
         */
        _message: function (text, isError) {
            this.element.find(this.options.selectors.message)
                .text(text)
                .toggleClass('message-error error', isError)
                .toggleClass('message-success success', !isError)
                .prop('hidden', false);
        }
    });

    return $.acme.acmeRefundActions;
});
