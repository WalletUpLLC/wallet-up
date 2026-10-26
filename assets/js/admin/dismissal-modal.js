(function($) {
    'use strict';

    class DismissalModal {
        constructor() {
            this.modal = null;
            this.overlay = null;
            this.selectedStrings = [];
            this.currentLanguage = '';
            this.dismissalType = 'language';
            this.isProcessing = false;
            this.onDismissCallback = null;

            this.init();
        }

        init() {
            this.createModalStructure();

            this.bindEvents();
        }

        createModalStructure() {
            if ($('#dismissal-modal').length > 0) {
                this.modal = $('#dismissal-modal');
                this.overlay = $('#dismissal-modal-overlay');
                return;
            }

            this.overlay = $('<div/>', {
                id: 'dismissal-modal-overlay',
                class: 'dismissal-modal-overlay'
            }).appendTo('body');

            const modalHTML = `
                <div id="dismissal-modal" class="dismissal-modal">
                    <!-- Loading State -->
                    <div class="dismissal-modal-loading" style="display: none;">
                        <div class="dismissal-loading-spinner"></div>
                    </div>
                    
                    <!-- Modal Header -->
                    <div class="dismissal-modal-header">
                        <h3 class="dismissal-modal-title">
                            <span class="dashicons dashicons-dismiss"></span>
                            Dismiss Translation Strings
                        </h3>
                        <button class="dismissal-modal-close" aria-label="Close modal">
                            <span class="dashicons dashicons-no"></span>
                        </button>
                    </div>
                    
                    <!-- Modal Body -->
                    <div class="dismissal-modal-body">
                        <!-- Strings Preview -->
                        <div class="dismissal-preview">
                            <h4 class="dismissal-preview-title">Strings to Dismiss (<span id="dismissal-count">0</span>)</h4>
                            <div class="dismissal-string-list" id="dismissal-string-list">
                                <!-- Strings will be populated here -->
                            </div>
                        </div>
                        
                        <!-- Dismissal Options -->
                        <div class="dismissal-options">
                            <div class="dismissal-option" data-type="language">
                                <div class="dismissal-option-header">
                                    <input type="radio" name="dismissal-type" value="language" id="dismiss-language" checked>
                                    <div class="dismissal-option-content">
                                        <label for="dismiss-language" class="dismissal-option-title">
                                            <span class="dashicons dashicons-translation"></span>
                                            Dismiss for Language Only
                                            <span class="language-badge" id="current-language-badge">ES</span>
                                        </label>
                                        <p class="dismissal-option-description">
                                            These strings will be hidden only for the current language. 
                                            They will still appear for other languages.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="dismissal-option" data-type="global">
                                <div class="dismissal-option-header">
                                    <input type="radio" name="dismissal-type" value="global" id="dismiss-global">
                                    <div class="dismissal-option-content">
                                        <label for="dismiss-global" class="dismissal-option-title">
                                            <span class="dashicons dashicons-admin-site-alt3"></span>
                                            Dismiss Globally
                                        </label>
                                        <p class="dismissal-option-description">
                                            These strings will be hidden for ALL languages. 
                                            Use this for technical strings that should never be translated.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Warning Message -->
                        <div class="dismissal-warning" id="dismissal-warning" style="display: none;">
                            <span class="dashicons dashicons-warning"></span>
                            <p class="dismissal-warning-text">
                                <strong>Caution:</strong> Global dismissal will hide these strings for all languages. 
                                This action can be reversed from the Dismissed Strings management page.
                            </p>
                        </div>
                    </div>
                    
                    <!-- Modal Footer -->
                    <div class="dismissal-modal-footer">
                        <div class="dismissal-info">
                            <span id="dismissal-status-text">Ready to dismiss</span>
                        </div>
                        <div class="dismissal-modal-actions">
                            <button class="dismissal-btn dismissal-btn-cancel" id="dismissal-cancel">
                                <span class="dashicons dashicons-no-alt"></span>
                                Cancel
                            </button>
                            <button class="dismissal-btn dismissal-btn-dismiss-language" id="dismissal-confirm-language">
                                <span class="dashicons dashicons-translation"></span>
                                Dismiss for <span class="language-name">Language</span>
                            </button>
                            <button class="dismissal-btn dismissal-btn-dismiss-global" id="dismissal-confirm-global" style="display: none;">
                                <span class="dashicons dashicons-admin-site-alt3"></span>
                                Dismiss Globally
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            this.modal = $(modalHTML).appendTo('body');
        }
        
        bindEvents() {
            const self = this;

            this.overlay.on('click', () => this.close());
            this.modal.on('click', '.dismissal-modal-close', () => this.close());
            this.modal.on('click', '#dismissal-cancel', () => this.close());

            this.modal.on('click', (e) => {
                e.stopPropagation();
            });

            this.modal.on('change', 'input[name="dismissal-type"]', function() {
                const type = $(this).val();
                self.dismissalType = type;
                self.updateModalUI(type);
            });

            this.modal.on('click', '.dismissal-option', function() {
                const type = $(this).data('type');
                $(`#dismiss-${type}`).prop('checked', true).trigger('change');
            });

            this.modal.on('click', '#dismissal-confirm-language', () => {
                this.processDismissal('language');
            });

            this.modal.on('click', '#dismissal-confirm-global', () => {
                this.processDismissal('global');
            });

            $(document).on('keydown.dismissalModal', (e) => {
                if (e.key === 'Escape' && this.isOpen()) {
                    this.close();
                }
            });
        }

        open(strings, language, callback) {
            if (this.isProcessing) return;

            this.selectedStrings = strings || [];
            this.currentLanguage = language || 'en_US';
            this.onDismissCallback = callback;

            this.dismissalType = 'language';
            $('#dismiss-language').prop('checked', true);

            this.updateStringList();
            this.updateLanguageBadge();
            this.updateModalUI('language');

            this.overlay.addClass('active');
            this.modal.addClass('active');

            setTimeout(() => {
                $('#dismiss-language').focus();
            }, 100);
        }

        close() {
            if (this.isProcessing) return;

            this.overlay.removeClass('active');
            this.modal.removeClass('active');

            this.selectedStrings = [];
            this.currentLanguage = '';
            this.onDismissCallback = null;

            $(document).off('keydown.dismissalModal');
        }

        isOpen() {
            return this.modal && this.modal.hasClass('active');
        }

        updateStringList() {
            const listContainer = $('#dismissal-string-list');
            const countSpan = $('#dismissal-count');
            
            listContainer.empty();
            countSpan.text(this.selectedStrings.length);
            
            this.selectedStrings.forEach(string => {
                const isRecommended = string.shouldRecommendDismissal || false;
                const reason = string.dismissalReason || '';
                
                const item = $('<div/>', {
                    class: 'dismissal-string-item',
                    title: string.original
                }).html(`
                    <span>${this.escapeHtml(this.truncateString(string.original, 80))}</span>
                    ${isRecommended ? `<span class="recommendation-badge" title="${reason}">Recommended</span>` : ''}
                `);
                
                listContainer.append(item);
            });
        }

        updateLanguageBadge() {
            const badge = $('#current-language-badge');
            const langName = $('.language-name');

            const langCode = this.currentLanguage.split('_')[0].toUpperCase();
            badge.text(langCode);

            const languageNames = {
                'es_ES': 'Spanish',
                'fr_FR': 'French',
                'de_DE': 'German',
                'it_IT': 'Italian',
                'pt_BR': 'Portuguese',
                'zh_CN': 'Chinese',
                'ja': 'Japanese',
                'ko_KR': 'Korean',
                'ar': 'Arabic',
                'ru': 'Russian',
                'en_US': 'English'
            };
            
            const fullName = languageNames[this.currentLanguage] || this.currentLanguage;
            langName.text(fullName);
        }

        updateModalUI(type) {
            const warning = $('#dismissal-warning');
            const languageBtn = $('#dismissal-confirm-language');
            const globalBtn = $('#dismissal-confirm-global');
            const options = $('.dismissal-option');

            options.removeClass('selected');
            $(`.dismissal-option[data-type="${type}"]`).addClass('selected');

            if (type === 'global') {
                warning.slideDown(200);
                languageBtn.hide();
                globalBtn.show();
            } else {
                warning.slideUp(200);
                languageBtn.show();
                globalBtn.hide();
            }
        }

        processDismissal(type) {
            if (this.isProcessing || this.selectedStrings.length === 0) return;

            this.isProcessing = true;
            this.showLoading(true);

            const strings = this.selectedStrings.map(s => s.original);
            const isGlobal = (type === 'global');

            $.ajax({
                url: walletUpTranslations.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_dismiss_strings',
                    nonce: walletUpTranslations.nonce,
                    strings: strings,
                    language: this.currentLanguage,
                    apply_globally: isGlobal
                },
                success: (response) => {
                    if (response.success) {
                        this.handleSuccess(response.data, type);
                    } else {
                        this.handleError(response.data?.message || 'Failed to dismiss strings');
                    }
                },
                error: (xhr) => {
                    this.handleError('Network error. Please try again.');
                },
                complete: () => {
                    this.isProcessing = false;
                    this.showLoading(false);
                }
            });
        }

        handleSuccess(data, type) {
            const message = `Successfully dismissed ${this.selectedStrings.length} string(s) ${type === 'global' ? 'globally' : 'for ' + this.currentLanguage}`;
            this.showNotice(message, 'success');

            if (this.onDismissCallback && typeof this.onDismissCallback === 'function') {
                this.onDismissCallback(this.selectedStrings, type);
            }

            setTimeout(() => {
                this.close();
            }, 500);
        }

        handleError(message) {
            this.showNotice(message, 'error');
            $('#dismissal-status-text').text('Error occurred').css('color', '#dc2626');
        }

        showLoading(show) {
            const loadingDiv = this.modal.find('.dismissal-modal-loading');
            const buttons = this.modal.find('.dismissal-btn');

            if (show) {
                loadingDiv.show();
                buttons.prop('disabled', true);
                $('#dismissal-status-text').text('Processing...');
            } else {
                loadingDiv.hide();
                buttons.prop('disabled', false);
                $('#dismissal-status-text').text('Ready to dismiss');
            }
        }

        showNotice(message, type = 'info') {
            let notice = $('#dismissal-notice');
            if (notice.length === 0) {
                notice = $('<div/>', {
                    id: 'dismissal-notice',
                    style: 'position: fixed; top: 20px; right: 20px; z-index: 1000000; max-width: 400px;'
                }).appendTo('body');
            }

            const bgColor = type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6';
            notice.html(`
                <div style="
                    background: ${bgColor};
                    color: white;
                    padding: 16px 20px;
                    border-radius: 8px;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    animation: slideInRight 0.3s ease;
                ">
                    <span class="dashicons dashicons-${type === 'success' ? 'yes' : type === 'error' ? 'warning' : 'info'}"></span>
                    <span>${message}</span>
                </div>
            `).show();

            setTimeout(() => {
                notice.fadeOut(300);
            }, 3000);
        }

        escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }

        truncateString(str, maxLength) {
            if (str.length <= maxLength) return str;
            return str.substring(0, maxLength - 3) + '...';
        }
    }

    window.DismissalModal = new DismissalModal();
    
})(jQuery);