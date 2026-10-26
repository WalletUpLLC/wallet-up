(function($) {
    'use strict';
    
    const WalletUpProQR = {
        init: function() {
            this.registerEventHandlers();
            this.initializeExistingQRs();
        },
        
        registerEventHandlers: function() {
            $(document).on('click', '.walletup-qrcode-download', function(e) {
                e.preventDefault();
                
                const container = $(this).closest('.walletup-qrcode-container');
                const id = container.attr('id');
                const img = container.find('.walletup-qrcode-image');
                
                if (img.length) {
                    WalletUpProQR.downloadQRCode(img.attr('src'), id);
                }
            });
            
            $(document).on('click', '.walletup-qrcode-generate', function(e) {
                e.preventDefault();
                
                const container = $(this).closest('.walletup-qrcode-container');
                const id = container.attr('id');
                
                WalletUpProQR.generateQRCode(id);
            });
            
            $(document).on('click', '.walletup-qrcode-copy', function(e) {
                e.preventDefault();
                
                const container = $(this).closest('.walletup-qrcode-container');
                const id = container.attr('id');
                const dataInput = container.find('#' + id + '-data');
                
                if (dataInput.length) {
                    WalletUpProQR.copyToClipboard(dataInput.val());
                    
                    const originalText = $(this).text();
                    $(this).text(walletUpQRCode.i18n.copied);
                    
                    setTimeout(() => {
                        $(this).text(originalText);
                    }, 2000);
                }
            });
            
            $(document).on('click', '.walletup-style-option', function(e) {
                const container = $(this).closest('.walletup-qrcode-container');
                const value = $(this).data('style');
                
                container.find('.walletup-qrcode-style-input').val(value);
                
                container.find('.walletup-style-option').removeClass('selected');
                $(this).addClass('selected');
            });
            
            $(document).on('click', '.walletup-toggle-advanced', function(e) {
                e.preventDefault();
                $(this).closest('.walletup-qrcode-advanced-options')
                    .find('.walletup-advanced-options-content')
                    .slideToggle(200);
                $(this).toggleClass('open');
            });
            
            $(document).on('click', '.walletup-toggle-logo', function(e) {
                e.preventDefault();
                $(this).closest('.walletup-qrcode-logo-options')
                    .find('.walletup-logo-options-content')
                    .slideToggle(200);
                $(this).toggleClass('open');
            });
            
            $(document).on('input', 'input[type="range"]', function() {
                const value = $(this).val();
                $(this).closest('.walletup-qrcode-field')
                    .find('.walletup-range-current-value')
                    .text(value);
            });
            
            $(document).on('change', '.walletup-qrcode-logo-input', function(e) {
                const file = e.target.files[0];
                const container = $(this).closest('.walletup-qrcode-container');
                
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        container.find('.walletup-logo-preview-container')
                            .show()
                            .find('.walletup-logo-preview-img')
                            .attr('src', e.target.result);
                    };
                    reader.readAsDataURL(file);
                }
            });
            
            $(document).on('change', '.walletup-qrcode-logo-punchout', function() {
                const checked = $(this).prop('checked');
                const container = $(this).closest('.walletup-qrcode-container');
                
                if (checked) {
                    container.find('.walletup-logo-preview-container')
                        .addClass('walletup-logo-punchout-preview');
                } else {
                    container.find('.walletup-logo-preview-container')
                        .removeClass('walletup-logo-punchout-preview');
                }
            });
        },
        
        initializeExistingQRs: function() {
            if ($.fn.spectrum) {
                $('.walletup-qrcode-color-input').spectrum({
                    type: 'component',
                    showInput: true,
                    showInitial: true,
                    allowEmpty: false,
                    preferredFormat: 'hex'
                });
                
                $('.walletup-qrcode-bg-input').spectrum({
                    type: 'component',
                    showInput: true,
                    showInitial: true,
                    allowEmpty: false,
                    preferredFormat: 'hex'
                });
            }
        },
        
        generateQRCode: function(containerId) {
            const container = $('#' + containerId);
            if (!container.length) return;
            const data = container.find('#' + containerId + '-data').val();
            
            if (!data) {
                this.showError(container, 'Please enter data to encode');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'walletup_live_qr_preview');
            formData.append('nonce', walletUpQRCode.nonce);
            formData.append('data', data);
            
            const size = container.find('#' + containerId + '-size').val() || 'medium';
            const color = container.find('#' + containerId + '-color').val() || '#674FBF';
            const background = container.find('#' + containerId + '-background').val() || '#F4F4FF';
            const style = container.find('#' + containerId + '-style').val() || 'rounded';
            
            formData.append('size', size);
            formData.append('color', color);
            formData.append('background', background);
            formData.append('style', style);
            
            if (container.find('#' + containerId + '-error-level').length) {
                formData.append('errorLevel', container.find('#' + containerId + '-error-level').val() || 'high');
            }
            
            if (container.find('#' + containerId + '-margin').length) {
                formData.append('margin', container.find('#' + containerId + '-margin').val() || '10');
            }
            
            if (container.find('#' + containerId + '-opacity').length) {
                formData.append('opacity', container.find('#' + containerId + '-opacity').val() || '100');
            }
            
            if (container.find('#' + containerId + '-round-mode').length) {
                formData.append('roundMode', container.find('#' + containerId + '-round-mode').val() || 'margin');
            }
            if (container.find('#' + containerId + '-round-finder').length) {
                formData.append('roundFinderPatterns', container.find('#' + containerId + '-round-finder').prop('checked'));
            }

            if (container.find('#' + containerId + '-logo-border').length) {
                formData.append('logoWhiteBorder', container.find('#' + containerId + '-logo-border').prop('checked'));
                
                if (container.find('#' + containerId + '-border-size').length) {
                    formData.append('logoBorderSize', container.find('#' + containerId + '-border-size').val() || '2');
                }
            }

            const logoInput = container.find('#' + containerId + '-logo')[0];
            if (logoInput && logoInput.files.length > 0) {
                formData.append('logo', logoInput.files[0]);
                
                if (container.find('#' + containerId + '-logo-size').length) {
                    formData.append('logoSize', container.find('#' + containerId + '-logo-size').val() || '20');
                }
                
                if (container.find('#' + containerId + '-logo-position').length) {
                    formData.append('logoPosition', container.find('#' + containerId + '-logo-position').val() || 'center');
                }
                
                if (container.find('#' + containerId + '-logo-punchout').length) {
                    formData.append('logoPunchout', container.find('#' + containerId + '-logo-punchout').prop('checked'));
                }
                
                if (container.find('#' + containerId + '-logo-aspect').length) {
                    formData.append('logoAspect', container.find('#' + containerId + '-logo-aspect').prop('checked'));
                }
            }
            
            const targetElement = container.find('#' + containerId + '-preview');
            this.showLoading(targetElement);
            
            $.ajax({
                url: walletUpQRCode.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: (response) => {
                    if (response.success && response.data.qrImage) {
                        this.updateQRPreview(targetElement, response.data.qrImage, data);
                        container.find('.walletup-qrcode-download-container').show();
                    } else {
                        this.showError(targetElement, response.data?.message || 'Failed to generate QR code');
                    }
                },
                error: (xhr) => {
                    this.showError(targetElement, 'Network error: ' + xhr.statusText);
                }
            });
        },
        
        downloadQRCode: function(src, id) {
            const link = document.createElement('a');
            link.href = src;
            
            const date = new Date().toISOString().slice(0, 10);
            link.download = 'qrcode-' + (id || 'walletup') + '-' + date + '.png';
            
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        },
        
        copyToClipboard: function(text) {
            if (!text) return;
            
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).catch(err => {
                    console.error('Could not copy text: ', err);
                });
            } else {
                const textArea = document.createElement('textarea');
                textArea.value = text;
                
                textArea.style.position = 'fixed';
                textArea.style.left = '-999999px';
                textArea.style.top = '-999999px';
                document.body.appendChild(textArea);
                
                textArea.focus();
                textArea.select();
                
                try {
                    document.execCommand('copy');
                } catch (err) {
                    console.error('Could not copy text: ', err);
                }
                
                document.body.removeChild(textArea);
            }
        },
        
        showLoading: function(element) {
            if (!element.length) return;
            
            element.html(
                '<div class="walletup-qrcode-loading">' +
                '<div class="walletup-loading"></div>' +
                walletUpQRCode.i18n.processing +
                '</div>'
            );
        },
        
        showError: function(element, message) {
            if (element.is('.walletup-qrcode-container')) {
                element.append(
                    '<div class="walletup-qrcode-error">' +
                    message +
                    '</div>'
                );
                
                setTimeout(() => {
                    element.find('.walletup-qrcode-error').fadeOut(300, function() {
                        $(this).remove();
                    });
                }, 5000);
            } else {
                element.html(
                    '<div class="walletup-qrcode-error">' +
                    '<span class="walletup-error-icon"></span>' +
                    message +
                    '</div>'
                );
            }
        },
        
        updateQRPreview: function(element, src, data) {
            const img = $('<img>', {
                'src': src,
                'alt': 'QR Code',
                'class': 'walletup-qrcode-image',
                'data-content': data
            });
            
            element.empty().append(img);
        }
    };
    
    $(document).ready(function() {
        WalletUpProQR.init();
    });
    
})(jQuery);