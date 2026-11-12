define([
    'jquery',
    'mage/translate',
    'Magento_Ui/js/modal/confirm',
    'Magento_Ui/js/modal/alert',
    'jquery-ui-modules/widget'
], function ($, $t, confirm, alert) {
    'use strict';

    $.widget('acme.acmeRefundForm', {
        options: {
            calculateUrl: '',
            saveUrl: '',
            formKey: '',
            orderId: '',
            currency: 'JPY',
            selectors: {
                qtyInput: '[data-role="qty-input"]',
                lineRow: '[data-role="line-row"]',
                lineAmount: '[data-role="line-amount"]',
                remaining: '[data-role="remaining"]',
                grandTotal: '[data-role="grand-total"]',
                submit: '[data-role="submit"]',
                reason: '[data-role="reason"]',
                state: '[data-role="state"]',
                receiptLink: '[data-role="receipt-link"]',
                viewLink: '[data-role="view-link"]'
            }
        },

        /** Widget bootstrap. */
        _create: function () {
            this.inFlight = false;
            this.lastGrandTotal = '0';
            this.calcRequested = 0;
            this.calcPainted = 0;
            this._on(this.element.find(this.options.selectors.qtyInput), {change: '_recalculate'});
            this._on(this.element.find(this.options.selectors.submit), {click: '_onSubmit'});
        },

        /**
         * Only lines the operator selected (qty above zero) are sent; untouched lines keep
         * their default of 0 and must not reach the server as a zero-quantity refund line.
         *
         * @return {Object} order_item_id => requested qty
         */
        _collectItems: function () {
            var items = {};

            this.element.find(this.options.selectors.qtyInput).each(function () {
                var qty = $(this).val();

                if (qty !== '' && qty !== null && parseFloat(qty) > 0) {
                    items[$(this).data('orderItemId')] = qty;
                }
            });

            return items;
        },

        /**
         * Server-side recalculation on every quantity change. Responses can arrive out of order,
         * so each request is numbered and only the newest one may repaint the totals.
         */
        _recalculate: function () {
            var self = this,
                requestNo = ++this.calcRequested;

            $.ajax({
                url: this.options.calculateUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: this.options.formKey,
                    order_id: this.options.orderId,
                    items: this._collectItems()
                }
            }).done(function (response) {
                if (requestNo !== self.calcRequested) {
                    return;
                }
                if (response.ok && response.figures) {
                    self._paint(response.figures);
                    self.calcPainted = requestNo;
                }
            });
        },

        /** Repaint the per-line amounts and the grand total from the server figures. */
        _paint: function (figures) {
            var self = this;

            this.element.find(this.options.selectors.lineAmount).text('-');
            $.each(figures.lines, function (i, line) {
                self.element
                    .find(self.options.selectors.lineRow + '[data-order-item-id="' + line.order_item_id + '"]')
                    .find(self.options.selectors.lineAmount)
                    .text(self._money(line.grand_total));
            });

            this.lastGrandTotal = figures.refund.grand_total;
            this.element.find(this.options.selectors.grandTotal).text(this._money(figures.refund.grand_total));
        },

        /**
         * Advisory browser-side checks before the confirmation step (BR-03). The server
         * re-checks everything on save; these only give the operator a clear message early.
         *
         * @return {String} an error message, or an empty string when the selection is valid
         */
        _validateSelection: function () {
            var self = this,
                selected = 0,
                error = '';

            this.element.find(this.options.selectors.qtyInput).each(function () {
                var raw = $.trim($(this).val()),
                    qty = Number(raw),
                    row = $(this).closest(self.options.selectors.lineRow),
                    sku = $.trim(row.find('td').first().text()),
                    remaining = Number($.trim(row.find(self.options.selectors.remaining).text()));

                if (raw === '' || qty === 0) {
                    return true;
                }
                if (!Number.isInteger(qty) || qty < 0) {
                    error = $t('Enter a whole number of 0 or more for %1.').replace('%1', sku);

                    return false;
                }
                if (qty > remaining) {
                    error = $t('The refund quantity for %1 exceeds the remaining quantity %2.')
                        .replace('%1', sku)
                        .replace('%2', remaining);

                    return false;
                }
                selected++;

                return true;
            });

            if (!error && selected === 0) {
                error = $t('Select at least one item and a quantity to refund.');
            }

            return error;
        },

        /**
         * @param {String} message
         */
        _showError: function (message) {
            this.element.find(this.options.selectors.state).text('');
            if (!message) {
                return;
            }

            // Message text can come from the server or from line data, so it is escaped before
            // it is placed in the dialog's HTML content.
            alert({
                title: $t('Cannot submit the refund'),
                content: $('<div>').text(message).html()
            });
        },

        /** Confirmation step before any financial action. */
        _onSubmit: function () {
            var self = this,
                error;

            if (this.inFlight) {
                return;
            }

            error = this._validateSelection();
            this._showError(error);
            if (error) {
                return;
            }

            // The confirmation must show the total for what is on screen now, not an older one.
            if (this.calcPainted !== this.calcRequested) {
                this._showError($t('The refund total is still being calculated. Please try again in a moment.'));

                return;
            }

            confirm({
                title: $t('Confirm refund'),
                content: $t('Refund total:') + ' ' + this._money(this.lastGrandTotal),
                actions: {
                    confirm: function () {
                        self._save();
                    }
                }
            });
        },

        /** Issue the save. The button is disabled and relabelled before the request goes out. */
        _save: function () {
            var self = this,
                button = this.element.find(this.options.selectors.submit);

            this.inFlight = true;
            button.prop('disabled', true);
            button.find('span').text($t('Submitting...'));

            $.ajax({
                url: this.options.saveUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: this.options.formKey,
                    order_id: this.options.orderId,
                    reason_code: this.element.find(this.options.selectors.reason).val(),
                    items: this._collectItems()
                }
            }).done(function (response) {
                if (!response.ok) {
                    self._fail(response.message || $t('The refund could not be saved.'));

                    return;
                }
                self._renderServerState(response);
            }).fail(function () {
                self._fail($t('The refund request failed. Please retry.'));
            });
        },

        /**
         * Render the state the server declared. The confirmed/accepted state is shown only
         * when the server reports the create succeeded; otherwise the pending-retry state is
         * shown. The client never invents a lifecycle transition.
         */
        _renderServerState: function (response) {
            var state = this.element.find(this.options.selectors.state),
                link = this.element.find(this.options.selectors.receiptLink),
                viewLink = this.element.find(this.options.selectors.viewLink),
                button = this.element.find(this.options.selectors.submit);

            // The refund now exists. The button stays disabled so it cannot be submitted twice
            // (BR-05), but its label and the locked inputs show that the work is done.
            button.find('span').text($t('Refund Submitted'));
            this.element.find(this.options.selectors.qtyInput).prop('disabled', true);
            this.element.find(this.options.selectors.reason).prop('disabled', true);

            if (response.create_status === 'succeeded') {
                state.text($t('Refund submitted and accepted by the ERP.'));
            } else {
                state.text($t('Saved; ERP notification pending retry'));
            }

            // BR-11: the receipt is available as soon as the server confirms the record exists.
            if (response.receipt_url) {
                link.attr('href', response.receipt_url);
                link.prop('hidden', false);
            }

            if (response.redirect_url) {
                viewLink.attr('href', response.redirect_url);
                viewLink.prop('hidden', false);
            }
        },

        /** Only an error path re-enables the button. */
        _fail: function (message) {
            var button = this.element.find(this.options.selectors.submit);

            this.inFlight = false;
            button.prop('disabled', false);
            button.find('span').text($t('Submit Refund'));
            this._showError(message);
        },

        /** @return {String} */
        _money: function (amount) {
            var value = parseFloat(amount || 0);

            return this.options.currency + ' ' + value.toLocaleString('en-US', {maximumFractionDigits: 0});
        }
    });

    return $.acme.acmeRefundForm;
});
