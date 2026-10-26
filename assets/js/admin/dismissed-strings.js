(function($) {
    'use strict';

    class DismissedStringsManager {
        constructor() {
            this.init();
        }
        
        init() {
            this.bindEvents();
        }
        
        bindEvents() {
            $(document).on('click', '.clear-language-dismissals', (e) => {
                e.preventDefault();
                const button = $(e.currentTarget);
                const language = button.data('language');
                const languageName = button.closest('.setting-row').find('label').text().trim();
                
                this.confirmAndClearLanguage(language, languageName, button);
            });
            
            $(document).on('click', '.remove-pattern', (e) => {
                e.preventDefault();
                const button = $(e.currentTarget);
                const pattern = button.data('pattern');
                
                this.confirmAndRemovePattern(pattern, button);
            });
            
            $(document).on('click', '#clear-all-dismissed', (e) => {
                e.preventDefault();
                this.confirmAndClearAll();
            });
            
            $(document).on('click', '#export-dismissals', (e) => {
                e.preventDefault();
                this.exportDismissals();
            });
            
            $(document).on('click', '#import-dismissals', (e) => {
                e.preventDefault();
                $('#dismissal-import-file').click();
            });
            
            $(document).on('change', '#dismissal-import-file', (e) => {
                const file = e.target.files[0];
                if (file) {
                    this.importDismissals(file);
                }
            });
        }
        
        confirmAndClearLanguage(language, languageName, button) {
            if (!confirm(`Are you sure you want to clear all dismissed strings for ${languageName}?\n\nThis will restore all dismissed strings for this language in the translation interface.`)) {
                return;
            }
            
            this.setButtonLoading(button, true);
            
            $.ajax({
                url: ajaxurl || walletUpDismissed.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_clear_language_dismissals',
                    nonce: walletUpDismissed?.nonce || wp.ajax.settings.nonce,
                    language: language
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice(`Successfully cleared dismissals for ${languageName}`, 'success');
                        button.closest('.setting-row').fadeOut(400, function() {
                            $(this).remove();
                            if ($('.clear-language-dismissals').length === 0) {
                                $('.provider-settings').first().append(
                                    '<p class="description">No language-specific dismissals found.</p>'
                                );
                            }
                        });
                        this.updateHeaderStats();
                    } else {
                        this.showNotice(response.data?.message || 'Failed to clear dismissals', 'error');
                    }
                },
                error: () => {
                    this.showNotice('Network error. Please try again.', 'error');
                },
                complete: () => {
                    this.setButtonLoading(button, false);
                }
            });
        }
        
        confirmAndRemovePattern(pattern, button) {
            if (!confirm(`Are you sure you want to remove this dismissal pattern?\n\n${pattern}\n\nStrings matching this pattern will appear in the translation interface again.`)) {
                return;
            }
            
            this.setButtonLoading(button, true);
            
            $.ajax({
                url: ajaxurl || walletUpDismissed.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_remove_dismissal_pattern',
                    nonce: walletUpDismissed?.nonce || wp.ajax.settings.nonce,
                    pattern: pattern
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice('Pattern removed successfully', 'success');
                        button.closest('.setting-row').fadeOut(400, function() {
                            $(this).remove();
                            if ($('.remove-pattern').length === 0) {
                                $('.advanced-settings .settings-grid').html(
                                    '<p class="description">No global patterns defined.</p>'
                                );
                            }
                        });
                        this.updateHeaderStats();
                    } else {
                        this.showNotice(response.data?.message || 'Failed to remove pattern', 'error');
                    }
                },
                error: () => {
                    this.showNotice('Network error. Please try again.', 'error');
                },
                complete: () => {
                    this.setButtonLoading(button, false);
                }
            });
        }
        
        confirmAndClearAll() {
            if (!confirm('Are you sure you want to clear ALL dismissed strings?\n\nThis will:\n• Clear all language-specific dismissals\n• Remove all global patterns\n• Restore all strings in the translation interface\n\nThis action cannot be undone!')) {
                return;
            }
            
            const button = $('#clear-all-dismissed');
            this.setButtonLoading(button, true);
            
            $.ajax({
                url: ajaxurl || walletUpDismissed.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_clear_all_dismissals',
                    nonce: walletUpDismissed?.nonce || wp.ajax.settings.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice('All dismissals cleared successfully', 'success');
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        this.showNotice(response.data?.message || 'Failed to clear all dismissals', 'error');
                    }
                },
                error: () => {
                    this.showNotice('Network error. Please try again.', 'error');
                },
                complete: () => {
                    this.setButtonLoading(button, false);
                }
            });
        }
        
        exportDismissals() {
            const button = $('#export-dismissals');
            this.setButtonLoading(button, true);
            
            $.ajax({
                url: ajaxurl || walletUpDismissed.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_export_dismissals',
                    nonce: walletUpDismissed?.nonce || wp.ajax.settings.nonce
                },
                success: (response) => {
                    if (response.success) {
                        const data = JSON.stringify(response.data, null, 2);
                        const blob = new Blob([data], { type: 'application/json' });
                        const url = URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = `wallet-up-dismissals-${Date.now()}.json`;
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);
                        
                        this.showNotice('Dismissals exported successfully', 'success');
                    } else {
                        this.showNotice(response.data?.message || 'Failed to export dismissals', 'error');
                    }
                },
                error: () => {
                    this.showNotice('Network error. Please try again.', 'error');
                },
                complete: () => {
                    this.setButtonLoading(button, false);
                }
            });
        }
        
        importDismissals(file) {
            if (!file.type.match('application/json')) {
                this.showNotice('Please select a valid JSON file', 'error');
                return;
            }
            
            const reader = new FileReader();
            reader.onload = (e) => {
                try {
                    const data = JSON.parse(e.target.result);
                    this.processImport(data);
                } catch (error) {
                    this.showNotice('Invalid JSON file format', 'error');
                }
            };
            reader.readAsText(file);
        }
        
        processImport(data) {
            if (!confirm('This will merge the imported dismissals with existing ones. Continue?')) {
                return;
            }
            
            const button = $('#import-dismissals');
            this.setButtonLoading(button, true);
            
            $.ajax({
                url: ajaxurl || walletUpDismissed.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_import_dismissals',
                    nonce: walletUpDismissed?.nonce || wp.ajax.settings.nonce,
                    data: JSON.stringify(data)
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice('Dismissals imported successfully', 'success');
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        this.showNotice(response.data?.message || 'Failed to import dismissals', 'error');
                    }
                },
                error: () => {
                    this.showNotice('Network error. Please try again.', 'error');
                },
                complete: () => {
                    this.setButtonLoading(button, false);
                    $('#dismissal-import-file').val('');
                }
            });
        }
        
        updateHeaderStats() {
            $.ajax({
                url: ajaxurl || walletUpDismissed.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_get_dismissal_stats',
                    nonce: walletUpDismissed?.nonce || wp.ajax.settings.nonce
                },
                success: (response) => {
                    if (response.success && response.data) {
                        $('.stat-badge').each(function() {
                            const text = $(this).text();
                            if (text.includes('Total')) {
                                $(this).html(`${response.data.total_dismissed} Total`);
                            } else if (text.includes('Auto-Detected')) {
                                $(this).html(`${response.data.recommended || 0} Auto-Detected`);
                            } else if (text.includes('Manual')) {
                                $(this).html(`${response.data.manual || 0} Manual`);
                            }
                        });
                    }
                }
            });
        }
        
        setButtonLoading(button, loading) {
            if (loading) {
                button.prop('disabled', true);
                const originalText = button.html();
                button.data('original-text', originalText);
                button.html('<span class="dashicons dashicons-update spin"></span> Processing...');
            } else {
                button.prop('disabled', false);
                const originalText = button.data('original-text');
                if (originalText) {
                    button.html(originalText);
                }
            }
        }
        
        showNotice(message, type = 'info') {
            $('.dismissal-notice').remove();
            
            const noticeClass = type === 'success' ? 'notice-success' : type === 'error' ? 'notice-error' : 'notice-info';
            const icon = type === 'success' ? 'yes' : type === 'error' ? 'warning' : 'info';
            
            const notice = $(`
                <div class="notice ${noticeClass} dismissal-notice is-dismissible" style="margin: 20px 0;">
                    <p>
                        <span class="dashicons dashicons-${icon}"></span>
                        ${message}
                    </p>
                </div>
            `);
            
            $('.wallet-up-translation-wrapper').prepend(notice);
            
            setTimeout(() => {
                notice.fadeOut(400, function() {
                    $(this).remove();
                });
            }, 5000);
        }
    }
    
    $(document).ready(() => {
        if ($('#wallet-up-dismissed-strings').length) {
            window.dismissedStringsManager = new DismissedStringsManager();
        }
    });
    
    const style = document.createElement('style');
    style.textContent = `
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .dashicons.spin {
            animation: spin 1s linear infinite;
            display: inline-block;
        }
    `;
    document.head.appendChild(style);
    
})(jQuery);