define([
    'jquery',
    'mage/translate',
    'Magento_Ui/js/modal/modal',
    'jquery-ui-modules/widget'
], function ($, $t, modal) {
    'use strict';

    /**
     * Audit log: "View" opens a popup with the event's request and response payloads.
     * The payloads are rendered server-side, escaped and pretty-printed, in a hidden
     * container next to each button; the popup shows a copy of that container.
     */
    $.widget('acme.acmeRefundAudit', {
        options: {
            selectors: {
                trigger: '[data-role="show-payload"]'
            }
        },

        /** Widget bootstrap. */
        _create: function () {
            this._on(this.element, {
                'click [data-role="show-payload"]': '_open'
            });
        },

        /**
         * @param {jQuery.Event} event
         */
        _open: function (event) {
            var trigger = $(event.currentTarget),
                source = $(trigger.data('target')),
                content = $('<div></div>').append(source.children().clone());

            modal({
                type: 'popup',
                title: trigger.data('title') || $t('Request and response'),
                modalClass: 'acme-refund-payload-modal',
                buttons: [{
                    text: $t('Close'),
                    class: 'action-secondary',
                    click: function () {
                        this.closeModal();
                    }
                }],
                closed: function () {
                    content.remove();
                }
            }, content);

            content.modal('openModal');
        }
    });

    return $.acme.acmeRefundAudit;
});
