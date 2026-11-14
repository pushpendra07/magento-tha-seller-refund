define([
    'jquery',
    'mage/translate',
    'Magento_Catalog/js/price-utils',
    'Magento_Ui/js/modal/confirm',
    'Magento_Ui/js/modal/alert',
    'jquery-ui-modules/widget'
], function ($, $t, priceUtils, confirm, alert) {
    'use strict';

    $.widget('acme.acmeRefundForm', {
        options: {
            calculateUrl: '',
            saveUrl: '',
            formKey: '',
            submissionToken: '',
            orderId: '',
            currency: 'JPY',
            priceFormat: null,
            selectors: {
                qtyInput: '[data-role="qty-input"]',
                qtyError: '[data-role="qty-error"]',
                lineRow: '[data-role="line-row"]',
                lineAmount: '[data-role="line-amount"]',
                grandTotal: '[data-role="grand-total"]',
                refundAll: '[data-role="refund-all"]',
                clear: '[data-role="clear"]',
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
            this._on(this.element.find(this.options.selectors.qtyInput), {change: '_onQtyChange'});
            this._on(this.element.find(this.options.selectors.refundAll), {click: '_refundAll'});
            this._on(this.element.find(this.options.selectors.clear), {click: '_clear'});
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
         * Check one quantity field: a whole number from 0 up to the remaining quantity (BR-03).
         * The server checks again on save; this tells the operator at once.
         *
         * @param {jQuery} input
         * @return {String} an error message, or an empty string when the value is valid
         */
        _validateField: function (input) {
            var raw = $.trim(input.val()),
                qty = Number(raw),
                remaining = Number(input.closest(this.options.selectors.lineRow).data('remaining'));

            if (raw === '' || qty === 0) {
                return '';
            }
            if (!Number.isInteger(qty) || qty < 0) {
                return $t('Enter a whole number of 0 or more.');
            }
            if (qty > remaining) {
                return $t('Maximum %1 (the remaining quantity).').replace('%1', remaining);
            }

            return '';
        },

        /**
         * Show or clear the inline error under one field.
         *
         * @param {jQuery} input
         * @param {String} message
         */
        _markField: function (input, message) {
            var error = input.closest('td').find(this.options.selectors.qtyError);

            input.toggleClass('mage-error', message !== '').attr('aria-invalid', message !== '');
            error.text(message).prop('hidden', message === '');
        },

        /**
         * Validate every field; returns true when all are valid.
         *
         * @return {Boolean}
         */
        _validateAll: function () {
            var self = this,
                valid = true;

            this.element.find(this.options.selectors.qtyInput).each(function () {
                var message = self._validateField($(this));

                self._markField($(this), message);
                valid = valid && message === '';
            });

            return valid;
        },

        /**
         * A quantity changed: validate it, and recalculate only when every field is valid. While a
         * field is invalid the amounts are blanked, so no total is shown that does not match the
         * quantities on screen, and any calculation still in flight is ignored.
         */
        _onQtyChange: function (event) {
            var input = $(event.currentTarget);

            this._markField(input, this._validateField(input));
            if (this._validateAll()) {
                this._recalculate();
            } else {
                this._clearAmounts();
            }
        },

        /** Blank the line amounts and the grand total until the quantities are valid again. */
        _clearAmounts: function () {
            var s = this.options.selectors;

            this.calcRequested++;
            this.lastGrandTotal = '0';
            this.element.find([s.lineAmount, s.grandTotal].join(',')).html('&mdash;');
        },

        /** Fill every editable line with its remaining quantity; one recalculation for all. */
        _refundAll: function () {
            var self = this;

            this.element.find(this.options.selectors.qtyInput).each(function () {
                $(this).val($(this).closest(self.options.selectors.lineRow).data('remaining'));
            });
            this._validateAll();
            this._recalculate();
        },

        /** Reset every line to 0; one recalculation for all. */
        _clear: function () {
            this.element.find(this.options.selectors.qtyInput).val('0');
            this._validateAll();
            this._recalculate();
        },

        /**
         * Server-side recalculation (FRD 10.1). Responses can arrive out of order, so each request
         * is numbered and only the newest one may repaint the totals.
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
                } else if (response.message) {
                    self._setState(response.message, 'warning');
                }
            });
        },

        /** Repaint the per-line amounts and the grand total from the server figures. */
        _paint: function (figures) {
            var self = this,
                s = this.options.selectors;

            this.element.find(s.lineAmount).html('&mdash;');
            $.each(figures.lines, function (i, line) {
                self.element
                    .find(s.lineRow + '[data-order-item-id="' + line.order_item_id + '"]')
                    .find(s.lineAmount)
                    .text(self._money(line.grand_total));
            });

            this.lastGrandTotal = figures.refund.grand_total;
            this.element.find(s.grandTotal).text(this._money(figures.refund.grand_total));
            this._setState('', '');
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

            if (!this._validateAll()) {
                this.element.find(this.options.selectors.qtyInput).each(function () {
                    var message = self._validateField($(this));

                    if (message) {
                        error = $(this).closest(self.options.selectors.lineRow).data('sku') + ': ' + message;

                        return false;
                    }

                    return true;
                });

                return error;
            }

            this.element.find(this.options.selectors.qtyInput).each(function () {
                if (Number($.trim($(this).val())) > 0) {
                    selected++;
                }
            });
            if (selected === 0) {
                return $t('Select at least one item and a quantity to refund.');
            }
            if (!this.element.find(this.options.selectors.reason).val()) {
                return $t('Select a refund reason.');
            }

            return '';
        },

        /**
         * Show a Magento-styled message under the totals, or hide it.
         *
         * @param {String} text
         * @param {String} type success|warning|error|''
         */
        _setState: function (text, type) {
            var state = this.element.find(this.options.selectors.state);

            state.attr('class', 'message' + (type ? ' message-' + type + ' ' + type : ''));
            state.text(text).prop('hidden', !text);
        },

        /**
         * @param {String} message
         */
        _showError: function (message) {
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
            if (error) {
                this._showError(error);

                return;
            }

            // The confirmation must show the total for what is on screen now, not an older one.
            if (this.calcPainted !== this.calcRequested) {
                this._showError($t('The refund total is still being calculated. Please try again in a moment.'));

                return;
            }

            confirm({
                title: $t('Confirm refund'),
                content: $('<div>').text($t('Refund total:') + ' ' + this._money(this.lastGrandTotal)).html(),
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
                    submission_token: this.options.submissionToken,
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
         * Render the state the server declared. The accepted state is shown only when the server
         * reports the create succeeded; other outcomes show their own recovery action. The client
         * never invents a lifecycle transition (FRD 14).
         */
        _renderServerState: function (response) {
            var s = this.options.selectors,
                button = this.element.find(s.submit),
                messages, status, message;

            // The refund now exists. The button stays disabled so it cannot be submitted twice
            // (BR-05), and the inputs lock to show that the work is done.
            button.find('span').text($t('Refund Submitted'));
            this.element.find(s.qtyInput + ',' + s.reason + ',' + s.refundAll + ',' + s.clear)
                .prop('disabled', true);

            messages = {
                succeeded: $t('Refund submitted and accepted by the ERP.'),
                business_rejected: $t(
                    'Saved, but the ERP rejected the refund. Open its details; no automatic retry.'
                ),
                conflict: $t(
                    'Saved, but the ERP reported a conflict. Open the refund details for review.'
                ),
                invalid_payload: $t(
                    'Saved, but the ERP request is invalid. Open its details; no automatic retry.'
                ),
                retries_exhausted: $t(
                    'Saved, but the ERP retry limit was reached. Open the refund details for review.'
                ),
                retryable_error: $t('Saved; the ERP had a temporary error. A retry is scheduled.'),
                pending: $t('Saved; the ERP request is being processed.'),
                not_started: $t('Saved; waiting for the ERP request to be processed.')
            };
            status = response.create_status;
            message = messages[status] || $t('Saved. Open the refund details to check the current status.');

            if (response.status === 'failed' && status !== 'business_rejected') {
                message = $t('The refund failed. Open the refund details before taking further action.');
            }
            this._setState(message, status === 'succeeded' && response.status !== 'failed' ? 'success' : 'warning');

            // BR-11: the receipt is available as soon as the server confirms the record exists.
            if (response.receipt_url) {
                this.element.find(s.receiptLink).attr('href', response.receipt_url).prop('hidden', false);
            }
            if (response.redirect_url) {
                this.element.find(s.viewLink).attr('href', response.redirect_url).prop('hidden', false);
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

        /**
         * Format an amount with Magento's price format for the order currency.
         *
         * @param {String|Number} amount
         * @return {String}
         */
        _money: function (amount) {
            var value = parseFloat(amount || 0);

            if (this.options.priceFormat) {
                return priceUtils.formatPrice(value, this.options.priceFormat);
            }

            return this.options.currency + ' ' + value.toLocaleString('en-US', {maximumFractionDigits: 0});
        }
    });

    return $.acme.acmeRefundForm;
});
