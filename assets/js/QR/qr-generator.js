(function($) {
    'use strict';
    
    window.walletUpQRGenerator = {
        init: function(selector) {
            const container = $(selector);
            if (!container.length) return;
            
            this.setupStyleOptions(container);
            this.setupColorPickers(container);
            this.setupLogoPreview(container);
            this.setupLivePreview(container);
            this.setupResetButton(container);
        },
        
        setupStyleOptions: function(container) {
            container.find('.walletup-style-option').on('click', function() {
                const style = $(this).data('style');
                
                container.find('.walletup-qrcode-style-input').val(style);
                
                container.find('.walletup-style-option').removeClass('selected');
                $(this).addClass('selected');
                
                if (container.data('live-preview')) {
                    walletUpQRGenerator.triggerPreview(container);
                }
            });
        },
        
        setupColorPickers: function(container) {
            if ($.fn.spectrum) {
                container.find('.walletup-qrcode-color-input').spectrum({
                    type: 'component',
                    showInput: true,
                    showInitial: true,
                    preferredFormat: 'hex',
                    allowEmpty: false,
                    change: function() {
                        if (container.data('live-preview')) {
                            walletUpQRGenerator.triggerPreview(container);
                        }
                    }
                });
                
                container.find('.walletup-qrcode-bg-input').spectrum({
                    type: 'component',
                    showInput: true,
                    showInitial: true,
                    preferredFormat: 'hex',
                    allowEmpty: false,
                    change: function() {
                        if (container.data('live-preview')) {
                            walletUpQRGenerator.triggerPreview(container);
                        }
                    }
                });
            }
        },
        
        setupLogoPreview: function(container) {
            container.find('.walletup-qrcode-logo-input').on('change', function(e) {
                const file = e.target.files[0];
                if (!file) return;
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    container.find('.walletup-logo-preview-container')
                        .show()
                        .find('.walletup-logo-preview-img')
                        .attr('src', e.target.result);
                        
                    const punchout = container.find('.walletup-qrcode-logo-punchout').prop('checked');
                    if (punchout) {
                        container.find('.walletup-logo-preview-container')
                            .addClass('walletup-logo-punchout-preview');
                    } else {
                        container.find('.walletup-logo-preview-container')
                            .removeClass('walletup-logo-punchout-preview');
                    }
                };
                reader.readAsDataURL(file);
                
                if (container.data('live-preview')) {
                    setTimeout(function() {
                        walletUpQRGenerator.triggerPreview(container);
                    }, 500); // Slight delay to ensure file is read
                }
            });
            
            container.find('.walletup-qrcode-logo-punchout').on('change', function() {
                const checked = $(this).prop('checked');
                
                if (checked) {
                    container.find('.walletup-logo-preview-container')
                        .addClass('walletup-logo-punchout-preview');
                } else {
                    container.find('.walletup-logo-preview-container')
                        .removeClass('walletup-logo-punchout-preview');
                }
                
                if (container.data('live-preview')) {
                    walletUpQRGenerator.triggerPreview(container);
                }
            });
        },
        
        setupLivePreview: function(container) {
            container.data('live-preview', true);
            
            const dataInput = container.find('.walletup-qrcode-data-input');
            
            const debouncedPreview = this.debounce(function() {
                walletUpQRGenerator.triggerPreview(container);
            }, 500);
            
            dataInput.on('input', debouncedPreview);
            
            container.find('select, input[type="range"]').on('change', function() {
                if (container.data('live-preview')) {
                    walletUpQRGenerator.triggerPreview(container);
                }
            });
            
            container.find('input[type="range"]').on('input', function() {
                const value = $(this).val();
                $(this).closest('.walletup-qrcode-field')
                    .find('.walletup-range-current-value')
                    .text(value);
                    
                if (container.data('live-preview')) {
                    debouncedPreview();
                }
            });
        },
        
        setupResetButton: function(container) {
            container.find('.walletup-qrcode-reset').on('click', function() {
                const defaultSize = container.data('default-size') || 'medium';
                const defaultColor = container.data('default-color') || '#674FBF';
                const defaultBg = container.data('default-bg') || '#F4F4FF';
                const defaultStyle = container.data('default-style') || 'rounded';
                
                container.find('.walletup-qrcode-size-select').val(defaultSize);
                container.find('.walletup-qrcode-color-input').val(defaultColor);
                container.find('.walletup-qrcode-bg-input').val(defaultBg);
                
                container.find('.walletup-qrcode-style-input').val(defaultStyle);
                container.find('.walletup-style-option').removeClass('selected');
                container.find('.walletup-style-option[data-style="' + defaultStyle + '"]').addClass('selected');
                
                container.find('.walletup-qrcode-error-level').val('high');
                container.find('.walletup-qrcode-margin').val(10);
                container.find('.walletup-qrcode-opacity').val(100);
                container.find('.walletup-qrcode-round-mode').val('margin');
                
                container.find('.walletup-qrcode-logo-input').val('');
                container.find('.walletup-logo-preview-container').hide();
                container.find('.walletup-qrcode-logo-size').val(20);
                container.find('.walletup-qrcode-logo-position').val('center');
                container.find('.walletup-qrcode-logo-punchout').prop('checked', true);
                container.find('.walletup-qrcode-logo-aspect').prop('checked', true);
                
                container.find('.walletup-range-current-value').each(function() {
                    const input = $(this).closest('.walletup-qrcode-field').find('input[type="range"]');
                    $(this).text(input.val());
                });
                
                if ($.fn.spectrum) {
                    container.find('.walletup-qrcode-color-input').spectrum('set', defaultColor);
                    container.find('.walletup-qrcode-bg-input').spectrum('set', defaultBg);
                }
                
                if (container.data('live-preview')) {
                    walletUpQRGenerator.triggerPreview(container);
                }
            });
        },
        
        triggerPreview: function(container) {
            const formId = container.find('.walletup-qrcode-generate').data('form');
            const targetId = container.find('.walletup-qrcode-generate').data('target');
            
            if (!formId || !targetId) return;
            
            const data = container.find('#' + formId + '-data').val();
            if (!data) return;
            
            const targetElement = $('#' + targetId);
            if (!targetElement.length) return;
            
            WalletUpProQR.showLoading(targetElement);
            
            setTimeout(function() {
                WalletUpProQR.generateQRCode(formId);
            }, 100);
        },
        
        autoGenerate: function(selector) {
            const container = $(selector);
            if (!container.length) return;
            
            const dataInput = container.find('.walletup-qrcode-data-input');
            if (dataInput.length && dataInput.val()) {
                this.triggerPreview(container);
            }
        },
        
        debounce: function(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
    };
    
})(jQuery);