(function($) {
    'use strict';
    $(document).ready(function() {
        window.walletUpData = window.walletUpData || {};
        
        window.walletUpAjax = function(action, data, callback) {
            var formData = new FormData();
            formData.append('action', action);
            formData.append('nonce', window.walletUpData.nonce);
            
            if (data) {
                for (var key in data) {
                    if (data.hasOwnProperty(key)) {
                        formData.append(key, data[key]);
                    }
                }
            }
            
            $.ajax({
                url: window.walletUpData.ajaxUrl,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (callback && typeof callback === 'function') {
                        callback(null, response);
                    }
                },
                error: function(xhr, status, error) {
                    if (callback && typeof callback === 'function') {
                        callback(error, null);
                    }
                }
            });
        };
    });
})(jQuery);