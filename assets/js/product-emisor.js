/**
 * Product Emisor Field JavaScript
 *
 * @package FE_Woo
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Initialize enhanced select for emisor field
        if (typeof $.fn.selectWoo !== 'undefined') {
            $('#fe_woo_emisor_id').selectWoo({
                allowClear: true,
                placeholder: 'Usar emisor por defecto'
            });
        }
    });

})(jQuery);
