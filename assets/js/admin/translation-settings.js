
(function($) {
    'use strict';

    $(document).ready(function() {
        
        const TranslationSettings = {
            
            init: function() {
                this.bindEvents();
                this.updateProviderSettings();
            },
            
            bindEvents: function() {
                $('#wallet_up_translation_provider').on('change', this.updateProviderSettings.bind(this));
                
                $('.toggle-password').on('click', this.togglePasswordVisibility);
                
                $('#test-translation').on('click', this.testTranslation);
                
                $('#clear-translation-cache').on('click', this.clearCache);
            },
            
            updateProviderSettings: function() {
                const selectedProvider = $('#wallet_up_translation_provider').val();
                
                $('.translation-provider-settings').removeClass('active').hide();
                
                $('.translation-provider-settings[data-provider="' + selectedProvider + '"]')
                    .addClass('active')
                    .fadeIn(300);
                    
                this.updateHelpText(selectedProvider);
            },
            
            togglePasswordVisibility: function(e) {
                e.preventDefault();
                
                const $button = $(this);
                const targetId = $button.data('target');
                const $input = $('#' + targetId);
                const $icon = $button.find('.dashicons');
                
                if ($input.attr('type') === 'password') {
                    $input.attr('type', 'text');
                    $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
                    $button.attr('aria-label', walletUpTranslationSettings.i18n.hidePassword);
                } else {
                    $input.attr('type', 'password');
                    $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
                    $button.attr('aria-label', walletUpTranslationSettings.i18n.showPassword);
                }
            },
            
            testTranslation: function(e) {
                e.preventDefault();
                
                const $button = $(this);
                const $resultRow = $('#test-result');
                const $result = $('#translation-result');
                const text = $('#test-text').val().trim();
                const language = $('#test-language').val();
                
                if (!text) {
                    alert(walletUpTranslationSettings.i18n.enterText);
                    $('#test-text').focus();
                    return;
                }
                
                $button.prop('disabled', true)
                       .text(walletUpTranslationSettings.i18n.testing);
                $resultRow.hide();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wallet_up_test_translation',
                        nonce: walletUpTranslationSettings.nonce,
                        text: text,
                        language: language
                    },
                    success: function(response) {
                        if (response.success) {
                            $result.removeClass('error').addClass('success');
                            
                            let html = '<strong>' + walletUpTranslationSettings.i18n.originalText + ':</strong> ' + 
                                      response.data.source_text + '<br>';
                            html += '<strong>' + walletUpTranslationSettings.i18n.translation + ':</strong> ' + 
                                    response.data.translation + '<br>';
                            html += '<strong>' + walletUpTranslationSettings.i18n.provider + ':</strong> ' + 
                                    response.data.provider + '<br>';
                            html += '<strong>' + walletUpTranslationSettings.i18n.targetLanguage + ':</strong> ' + 
                                    response.data.target_language;
                            
                            $result.html(html);
                        } else {
                            $result.removeClass('success').addClass('error');
                            $result.text(walletUpTranslationSettings.i18n.error + ': ' + 
                                       (response.data.message || walletUpTranslationSettings.i18n.unknownError));
                        }
                        $resultRow.fadeIn(300);
                    },
                    error: function(xhr, status, error) {
                        $result.removeClass('success').addClass('error');
                        $result.text(walletUpTranslationSettings.i18n.ajaxError);
                        $resultRow.fadeIn(300);
                        
                        console.error('Translation test error:', error);
                    },
                    complete: function() {
                        $button.prop('disabled', false)
                               .text(walletUpTranslationSettings.i18n.testTranslation);
                    }
                });
            },
            
            updateHelpText: function(provider) {
                const helpTexts = {
                    'local': walletUpTranslationSettings.i18n.localHelp,
                    'google': walletUpTranslationSettings.i18n.googleHelp,
                    'deepl': walletUpTranslationSettings.i18n.deeplHelp,
                    'libre': walletUpTranslationSettings.i18n.libreHelp
                };
                
                const $helpText = $('#provider-help-text');
                if ($helpText.length && helpTexts[provider]) {
                    $helpText.html(helpTexts[provider]).fadeIn(300);
                }
            },
            
            clearCache: function(e) {
                e.preventDefault();
                
                if (!confirm(walletUpTranslationSettings.i18n.confirmClearCache)) {
                    return;
                }
                
                const $button = $(this);
                $button.prop('disabled', true);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wallet_up_clear_translation_cache',
                        nonce: walletUpTranslationSettings.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(walletUpTranslationSettings.i18n.cacheCleared);
                        } else {
                            alert(walletUpTranslationSettings.i18n.cacheClearError);
                        }
                    },
                    error: function() {
                        alert(walletUpTranslationSettings.i18n.ajaxError);
                    },
                    complete: function() {
                        $button.prop('disabled', false);
                    }
                });
            }
        };
        
        TranslationSettings.init();
        
        $('form').on('submit', function() {
            const provider = $('#wallet_up_translation_provider').val();
            
            if (provider === 'google') {
                const apiKey = $('#wallet_up_google_translate_key').val().trim();
                if (!apiKey) {
                    alert(walletUpTranslationSettings.i18n.googleKeyRequired);
                    $('#wallet_up_google_translate_key').focus();
                    return false;
                }
            } else if (provider === 'deepl') {
                const apiKey = $('#wallet_up_deepl_key').val().trim();
                if (!apiKey) {
                    alert(walletUpTranslationSettings.i18n.deeplKeyRequired);
                    $('#wallet_up_deepl_key').focus();
                    return false;
                }
            }
            
            return true;
        });
        
    });
    
})(jQuery);