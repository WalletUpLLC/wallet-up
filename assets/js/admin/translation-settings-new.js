(function($) {
    'use strict';

    class TranslationSettings {
        constructor() {
            this.activeProvider = walletUpTranslations.activeProvider || 'local';
            this.providers = {
                'local': {
                    name: 'Local Translation',
                    icon: 'dashicons-admin-home',
                    description: 'Built-in dictionary with pattern matching',
                    requiresKey: false
                },
                'google': {
                    name: 'Google Translate',
                    icon: 'dashicons-google',
                    description: 'Google Cloud Translation API',
                    requiresKey: true,
                    keyField: 'wallet_up_google_translate_key'
                },
                'deepl': {
                    name: 'DeepL',
                    icon: 'dashicons-admin-site-alt3',
                    description: 'DeepL Pro Translation API',
                    requiresKey: true,
                    keyField: 'wallet_up_deepl_key'
                },
                'libre': {
                    name: 'LibreTranslate',
                    icon: 'dashicons-translation',
                    description: 'Open-source translation API',
                    requiresKey: false,
                    urlField: 'wallet_up_libre_translate_url'
                },
                'openai': {
                    name: 'OpenAI GPT-4',
                    icon: 'dashicons-lightbulb',
                    description: 'AI-powered contextual translation',
                    requiresKey: true,
                    keyField: 'wallet_up_openai_key',
                    modelField: 'wallet_up_openai_model'
                }
            };
            
            this.init();
        }

        init() {
            this.createInterface();
            this.bindEvents();
            this.loadCurrentSettings();
            this.checkProvidersStatus();
            
            setInterval(() => this.checkProvidersStatus(), 30000);
        }

        createInterface() {
            const container = $('#wallet-up-translation-settings');
            if (!container.length) return;

            let wrapper = container.find('.wallet-up-translation-wrapper');
            if (!wrapper.length) {
                container.html('<div class="wallet-up-translation-wrapper"></div>');
                wrapper = container.find('.wallet-up-translation-wrapper');
            }

            wrapper.html(`
                    <div class="translation-enterprise-ui">
                        <!-- Header -->
                        <div class="translation-header-advanced">
                            <div class="header-left">
                                <h2 class="header-title">
                                    <span class="dashicons dashicons-admin-settings"></span>
                                    Wallet Up Pro Translation Settings
                                </h2>
                            </div>
                            <div class="header-right">
                                <button id="test-connection" class="button">
                                    <span class="dashicons dashicons-admin-plugins"></span> Test Connection
                                </button>
                                <button id="save-settings" class="button button-primary">
                                    <span class="dashicons dashicons-saved"></span> Save Settings
                                </button>
                            </div>
                        </div>

                        <!-- Provider Selection -->
                        <div class="provider-selection">
                            <h3 class="section-title">Translation Provider</h3>
                            <div class="provider-grid">
                                ${Object.entries(this.providers).map(([key, provider]) => `
                                    <div class="provider-card ${this.activeProvider === key ? 'active' : ''}" data-provider="${key}">
                                        <div class="provider-header">
                                            <span class="dashicons ${provider.icon}"></span>
                                            <h4>${provider.name}</h4>
                                        </div>
                                        <p class="provider-description">${provider.description}</p>
                                        ${provider.requiresKey ? '<span class="requires-key">Requires API Key</span>' : '<span class="free-service">Free Service</span>'}
                                    </div>
                                `).join('')}
                            </div>
                        </div>

                        <!-- Provider Settings -->
                        <div class="provider-settings">
                            <!-- Google Translate Settings -->
                            <div class="provider-panel" id="google-settings" style="display: none;">
                                <h3 class="section-title">Google Translate Configuration</h3>
                                <div class="settings-grid">
                                    <div class="setting-row">
                                        <label>API Key</label>
                                        <div class="input-group">
                                            <input type="password" id="google-api-key" class="regular-text" placeholder="Enter your Google Cloud API key">
                                            <button class="button toggle-visibility" data-target="google-api-key">
                                                <span class="dashicons dashicons-visibility"></span>
                                            </button>
                                        </div>
                                        <p class="description">Get your API key from <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a></p>
                                    </div>
                                </div>
                            </div>

                            <!-- DeepL Settings -->
                            <div class="provider-panel" id="deepl-settings" style="display: none;">
                                <h3 class="section-title">DeepL Configuration</h3>
                                <div class="settings-grid">
                                    <div class="setting-row">
                                        <label>API Key</label>
                                        <div class="input-group">
                                            <input type="password" id="deepl-api-key" class="regular-text api-key-field" placeholder="Enter your DeepL API key">
                                            <button class="button toggle-visibility" data-target="deepl-api-key">
                                                <span class="dashicons dashicons-visibility"></span>
                                            </button>
                                        </div>
                                        <p class="help-text">Get your API key from <a href="https://www.deepl.com/pro-api" target="_blank">DeepL Pro</a></p>
                                    </div>
                                </div>
                            </div>

                            <!-- LibreTranslate Settings -->
                            <div class="provider-panel" id="libre-settings" style="display: none;">
                                <h3 class="section-title">LibreTranslate Configuration</h3>
                                <div class="settings-grid">
                                    <div class="setting-row">
                                        <label>API URL</label>
                                        <input type="url" id="libre-url" class="regular-text" placeholder="https://libretranslate.com/translate">
                                        <p class="help-text">Leave empty to use the public API, or enter your self-hosted instance URL</p>
                                    </div>
                                    <div class="setting-row">
                                        <label>API Key (Optional)</label>
                                        <div class="input-group">
                                            <input type="password" id="libre-api-key" class="regular-text" placeholder="Enter API key if required">
                                            <button class="button toggle-visibility" data-target="libre-api-key">
                                                <span class="dashicons dashicons-visibility"></span>
                                            </button>
                                        </div>
                                        <p class="help-text">Some LibreTranslate instances require an API key for access</p>
                                    </div>
                                </div>
                            </div>

                            <!-- OpenAI Settings -->
                            <div class="provider-panel" id="openai-settings" style="display: none;">
                                <h3 class="section-title">OpenAI GPT-4 Configuration</h3>
                                <div class="settings-grid">
                                    <div class="setting-row">
                                        <label>API Key</label>
                                        <div class="input-group">
                                            <input type="password" id="openai-api-key" class="regular-text" placeholder="Enter your OpenAI API key">
                                            <button class="button toggle-visibility" data-target="openai-api-key">
                                                <span class="dashicons dashicons-visibility"></span>
                                            </button>
                                        </div>
                                        <p class="description">Get your API key from <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platform</a></p>
                                    </div>
                                    <div class="setting-row">
                                        <label>Model</label>
                                        <select id="openai-model" class="regular-text">
                                            <option value="gpt-4-turbo-preview">GPT-4 Turbo (Recommended)</option>
                                            <option value="gpt-4">GPT-4</option>
                                            <option value="gpt-3.5-turbo">GPT-3.5 Turbo (Faster)</option>
                                        </select>
                                        <p class="description">GPT-4 provides better contextual translations but costs more</p>
                                    </div>
                                    <div class="setting-row">
                                        <label>Context Instructions</label>
                                        <textarea id="openai-context" class="large-text" rows="4" placeholder="Additional instructions for the AI translator...">You are translating a WordPress donation plugin. Maintain formal tone for payment/legal text and friendly tone for user-facing messages. Preserve all placeholders and HTML tags.</textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Local Settings -->
                            <div class="provider-panel" id="local-settings" style="display: none;">
                                <h3 class="section-title">Local Translation Configuration</h3>
                                <div class="settings-grid">
                                    <div class="stat-card">
                                        <div class="stat-value">${walletUpTranslations.localDictionarySize || 0}</div>
                                        <div class="stat-label">Dictionary Entries</div>
                                    </div>
                                    <div class="stat-card">
                                        <div class="stat-value">${walletUpTranslations.localPatterns || 0}</div>
                                        <div class="stat-label">Pattern Rules</div>
                                    </div>
                                    <div class="stat-card">
                                        <div class="stat-value">${walletUpTranslations.localLanguages || 0}</div>
                                        <div class="stat-label">Languages</div>
                                    </div>
                                </div>
                                <p class="description">Local translation uses built-in dictionaries and pattern matching. No API calls required.</p>
                            </div>
                        </div>

                        <!-- Advanced Settings -->
                        <div class="advanced-settings">
                            <h3 class="section-title">Advanced Options</h3>
                            <div class="settings-grid">
                                <div class="setting-row">
                                    <label class="inline-label">
                                        <input type="checkbox" id="enable-cache" checked>
                                        <span>Enable Translation Cache</span>
                                    </label>
                                    <p class="help-text">Cache translations to reduce API calls and improve performance</p>
                                    <div class="button-group">
                                        <button type="button" id="clear-cache" class="button button-secondary">
                                            <span class="dashicons dashicons-trash"></span> Clear Cache
                                        </button>
                                        <button type="button" id="clean-corrupted" class="button button-secondary">
                                            <span class="dashicons dashicons-admin-tools"></span> Clean Corrupted Translations
                                        </button>
                                    </div>
                                </div>
                                <div class="setting-row">
                                    <label class="inline-label">
                                        <input type="checkbox" id="enable-auto-translate" checked>
                                        <span>Enable Auto-Translation Suggestions</span>
                                    </label>
                                    <p class="help-text">Show translation suggestions while typing</p>
                                </div>
                                <div class="setting-row">
                                    <label>Batch Size</label>
                                    <input type="number" id="batch-size" min="1" max="100" value="50" class="small-text">
                                    <p class="help-text">Number of strings to translate in a single API request</p>
                                </div>
                                <div class="setting-row">
                                    <label>Request Timeout</label>
                                    <div class="input-with-unit">
                                        <input type="number" id="request-timeout" min="5" max="60" value="10" class="small-text">
                                        <span class="unit">seconds</span>
                                    </div>
                                    <p class="help-text">Maximum time to wait for translation API response</p>
                                </div>
                                <div class="setting-row">
                                    <label>Force Plugin Language</label>
                                    <select id="force-plugin-language" class="regular-text">
                                        <option value="">Use WordPress Site Language</option>
                                        <option value="en_US">English</option>
                                        <option value="es_ES">Spanish</option>
                                        <option value="fr_FR">French</option>
                                        <option value="de_DE">German</option>
                                        <option value="it_IT">Italian</option>
                                        <option value="pt_BR">Portuguese (Brazil)</option>
                                        <option value="zh_CN">Chinese (Simplified)</option>
                                        <option value="ja">Japanese</option>
                                        <option value="ko_KR">Korean</option>
                                        <option value="ar">Arabic</option>
                                        <option value="ru">Russian</option>
                                    </select>
                                    <p class="help-text">Force the plugin to always use this language, regardless of WordPress locale settings</p>
                                </div>
                                <div class="setting-row">
                                    <label>
                                        <input type="checkbox" id="enable-fuzzy-matching" class="regular-checkbox">
                                        Enable Fuzzy Translation Matching
                                    </label>
                                    <p class="help-text">
                                        <strong>New Feature:</strong> Intelligently matches translations between original JSX and built/minified JavaScript files. 
                                        Handles differences in quotes, whitespace, and formatting. Recommended when importing translations from development to production sites.
                                        <br><span style="color: #f39c12;">⚠️ Experimental: Enable only if experiencing translation matching issues.</span>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Test Translation -->
                        <div class="test-translation">
                            <h3 class="section-title">Test Translation</h3>
                            <div class="test-grid">
                                <div class="test-input">
                                    <label>Test Text</label>
                                    <input type="text" id="test-text" class="regular-text" value="Welcome to our donation page" placeholder="Enter text to translate">
                                </div>
                                <div class="test-language">
                                    <label>Target Language</label>
                                    <select id="test-language" class="regular-text">
                                        <option value="es_ES">Spanish</option>
                                        <option value="fr_FR">French</option>
                                        <option value="de_DE">German</option>
                                        <option value="it_IT">Italian</option>
                                        <option value="pt_BR">Portuguese (Brazil)</option>
                                        <option value="zh_CN">Chinese (Simplified)</option>
                                        <option value="ja">Japanese</option>
                                    </select>
                                </div>
                                <div class="test-button">
                                    <button id="run-test" class="button button-secondary">
                                        <span class="dashicons dashicons-translation"></span> Test Translation
                                    </button>
                                </div>
                            </div>
                            <div id="test-result" class="test-result" style="display: none;">
                                <div class="result-grid">
                                    <div class="result-item">
                                        <strong>Original:</strong>
                                        <p id="result-original"></p>
                                    </div>
                                    <div class="result-item">
                                        <strong>Translation:</strong>
                                        <p id="result-translation"></p>
                                    </div>
                                    <div class="result-meta">
                                        <span id="result-provider"></span> | 
                                        <span id="result-time"></span>ms | 
                                        <span id="result-cached"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Footer with Save Button -->
                        <div class="settings-footer">
                            <div class="footer-actions">
                                <button id="save-settings-bottom" class="button button-primary button-large">
                                    <span class="dashicons dashicons-saved"></span> Save Settings
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `);

            $('body').append(`
                <div id="settings-loading" class="loading-overlay" style="display: none;">
                    <div class="loading-content">
                        <div class="spinner"></div>
                        <p class="loading-text">Processing...</p>
                    </div>
                </div>
            `);
        }

        bindEvents() {
            $(document).on('click', '.provider-card', (e) => {
                const provider = $(e.currentTarget).data('provider');
                this.selectProvider(provider);
            });

            $(document).on('click', '.toggle-visibility', (e) => {
                const target = $(e.currentTarget).data('target');
                const input = $(`#${target}`);
                const icon = $(e.currentTarget).find('.dashicons');
                
                if (input.attr('type') === 'password') {
                    input.attr('type', 'text');
                    icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
                } else {
                    input.attr('type', 'password');
                    icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
                }
            });

            $(document).on('click', '#save-settings, #save-settings-bottom', () => this.saveSettings());

            $(document).on('click', '#test-connection', () => this.testConnection());

            $(document).on('click', '#run-test', () => this.testTranslation());

            $(document).on('click', '#clear-cache', () => this.clearCache());

            $(document).on('click', '#clean-corrupted', () => this.cleanCorruptedTranslations());
        }
        
        clearCache() {
            if (!confirm('Are you sure you want to clear the translation cache?')) {
                return;
            }
            
            this.showLoading(true, 'Clearing cache...');
            
            $.ajax({
                url: walletUpTranslations.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_clear_translation_cache',
                    nonce: walletUpTranslations.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice('Translation cache cleared successfully', 'success');
                    } else {
                        this.showNotice(response.data.message || 'Failed to clear cache', 'error');
                    }
                },
                error: () => {
                    this.showNotice('Failed to clear cache', 'error');
                },
                complete: () => {
                    this.showLoading(false);
                }
            });
        }
        
        cleanCorruptedTranslations() {
            if (!confirm('This will remove translations that contain dynamic code patterns (like ${variable}) which can break the site. Continue?')) {
                return;
            }
            
            this.showLoading(true, 'Initializing cleanup...');
            
            $.ajax({
                url: walletUpTranslations.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_clean_corrupted_translations',
                    nonce: walletUpTranslations.nonce
                },
                success: (response) => {
                    if (response.success && response.data.action === 'init') {
                        this.startBatchCleaning(response.data.languages);
                    } else {
                        this.showNotice('Failed to initialize cleanup', 'error');
                        this.showLoading(false);
                    }
                },
                error: (xhr, status, error) => {
                    console.error('AJAX error:', status, error);
                    this.showNotice('Failed to initialize cleanup: ' + error, 'error');
                    this.showLoading(false);
                }
            });
        }
        
        startBatchCleaning(languages) {
            const languagesToClean = languages.filter(lang => lang.needs_cleaning > 0);
            
            if (languagesToClean.length === 0) {
                this.showNotice('No corrupted translations found!', 'success');
                this.showLoading(false);
                return;
            }
            
            this.createProgressDisplay(languagesToClean);

            this.cleanNextLanguage(languagesToClean, 0);
        }
        
        createProgressDisplay(languages) {
            const progressHtml = `
                <div id="cleaning-progress" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); 
                     background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); 
                     z-index: 100002; min-width: 400px;">
                    <h3 style="margin-top: 0;">Cleaning Corrupted Translations</h3>
                    <div id="language-progress"></div>
                    <div style="margin-top: 20px;">
                        <div style="background: #f0f0f0; height: 20px; border-radius: 10px; overflow: hidden;">
                            <div id="overall-progress-bar" style="background: #674FBF; height: 100%; width: 0%; 
                                 transition: width 0.3s ease;"></div>
                        </div>
                        <p id="progress-text" style="text-align: center; margin-top: 10px;">Starting...</p>
                    </div>
                </div>
            `;
            
            $('body').append(progressHtml);
            
            const langProgressHtml = languages.map(lang => `
                <div class="lang-progress" data-lang="${lang.language}">
                    <span>${lang.language}: </span>
                    <span class="status">Pending</span>
                    <span class="stats" style="float: right; color: #666;"></span>
                </div>
            `).join('');
            
            $('#language-progress').html(langProgressHtml);
        }
        
        cleanNextLanguage(languages, index) {
            if (index >= languages.length) {
                $('#cleaning-progress').remove();
                this.showLoading(false);
                this.showNotice('All corrupted translations cleaned successfully!', 'success', 5000);
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
                return;
            }
            
            const currentLang = languages[index];
            const totalLanguages = languages.length;
            
            $(`.lang-progress[data-lang="${currentLang.language}"] .status`).text('Processing...');

            let totalRemoved = 0;
            this.processBatch(currentLang.language, 0, totalRemoved, (removed) => {
                $(`.lang-progress[data-lang="${currentLang.language}"] .status`).text('✓ Completed');
                $(`.lang-progress[data-lang="${currentLang.language}"] .stats`).text(`(${removed} removed)`);

                const overallProgress = ((index + 1) / totalLanguages) * 100;
                $('#overall-progress-bar').css('width', overallProgress + '%');
                $('#progress-text').text(`Completed ${index + 1} of ${totalLanguages} languages`);

                this.cleanNextLanguage(languages, index + 1);
            });
        }
        
        processBatch(language, offset, totalRemoved, onComplete) {
            $.ajax({
                url: walletUpTranslations.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_clean_corrupted_translations',
                    nonce: walletUpTranslations.nonce,
                    language: language,
                    offset: offset
                },
                success: (response) => {
                    if (response.success) {
                        const data = response.data;
                        
                        totalRemoved += data.removed;

                        const langProgress = `${data.offset}/${data.total} (${data.progress}%)`;
                        $(`.lang-progress[data-lang="${language}"] .stats`).text(langProgress);

                        if (data.completed) {
                            onComplete(totalRemoved);
                        } else {
                            setTimeout(() => {
                                this.processBatch(language, data.offset, totalRemoved, onComplete);
                            }, 100);
                        }
                    } else {
                        console.error('Batch processing failed:', response);
                        this.showNotice(`Failed to clean ${language}`, 'error');
                        onComplete(totalRemoved);
                    }
                },
                error: (xhr, status, error) => {
                    console.error('AJAX error:', status, error);
                    this.showNotice(`Error cleaning ${language}: ${error}`, 'error');
                    onComplete(totalRemoved);
                }
            });
        }

        loadCurrentSettings() {
            $.ajax({
                url: walletUpTranslations.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_get_translation_settings',
                    nonce: walletUpTranslations.nonce
                },
                success: (response) => {
                    if (response.success) {
                        const settings = response.data;
                        
                        const provider = settings.provider || 'local';
                        this.selectProvider(provider);

                        if (provider === 'local') {
                            this.refreshLocalStats();
                        }

                        if (settings.google_key) $('#google-api-key').val(settings.google_key);
                        if (settings.deepl_key) $('#deepl-api-key').val(settings.deepl_key);
                        if (settings.openai_key) $('#openai-api-key').val(settings.openai_key);
                        if (settings.openai_model) $('#openai-model').val(settings.openai_model);
                        if (settings.openai_context) $('#openai-context').val(settings.openai_context);
                        if (settings.libre_url) $('#libre-url').val(settings.libre_url);
                        if (settings.libre_key) $('#libre-api-key').val(settings.libre_key);

                        $('#enable-cache').prop('checked', settings.cache_enabled !== false);
                        $('#enable-auto-translate').prop('checked', settings.auto_translate !== false);
                        $('#batch-size').val(settings.batch_size || 50);
                        $('#request-timeout').val(settings.request_timeout || 10);
                        $('#force-plugin-language').val(settings.force_plugin_language || '');
                        
                        const fuzzyMatchingEnabled = settings.enable_fuzzy_matching === true ||
                                                     settings.enable_fuzzy_matching === '1' || 
                                                     settings.enable_fuzzy_matching === 1;
                        $('#enable-fuzzy-matching').prop('checked', fuzzyMatchingEnabled);
                    }
                }
            });
        }

        selectProvider(provider) {
            this.activeProvider = provider;

            $('.provider-card').removeClass('active');
            $(`.provider-card[data-provider="${provider}"]`).addClass('active');

            $('.provider-panel').fadeOut(200, () => {
                $(`#${provider}-settings`).fadeIn(300);

                if (provider === 'local') {
                    this.refreshLocalStats();
                }
            });

            $('#save-settings').removeClass('success');
        }
        
        refreshLocalStats() {
            $.ajax({
                url: walletUpTranslations.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_get_local_stats',
                    nonce: walletUpTranslations.nonce
                },
                success: (response) => {
                    if (response.success) {
                        const stats = response.data;
                        $('#local-settings .stat-card:nth-child(1) .stat-value').text(stats.totalEntries);
                        $('#local-settings .stat-card:nth-child(2) .stat-value').text(stats.patternRules);
                        $('#local-settings .stat-card:nth-child(3) .stat-value').text(stats.languages);
                        
                        walletUpTranslations.localDictionarySize = stats.totalEntries;
                        walletUpTranslations.localPatterns = stats.patternRules;
                        walletUpTranslations.localLanguages = stats.languages;
                    }
                }
            });
        }
        
        checkProvidersStatus() {
            $.ajax({
                url: walletUpTranslations.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_check_providers_status',
                    nonce: walletUpTranslations.nonce
                },
                success: (response) => {
                    if (response.success) {
                        const statuses = response.data;
                        
                        Object.entries(statuses).forEach(([provider, status]) => {
                            const $card = $(`.provider-card[data-provider="${provider}"]`);
                            if ($card.length) {
                                const $statusSpan = $card.find('.requires-key, .free-service, .ready-to-translate');
                                
                                if (status.ready) {
                                    if ($statusSpan.hasClass('ready-to-translate')) {
                                        return;
                                    }

                                    $statusSpan
                                        .removeClass('requires-key free-service')
                                        .addClass('ready-to-translate')
                                        .html('<span class="dashicons dashicons-yes-alt"></span> Ready To Translate');
                                } else if (status.configured) {
                                    $statusSpan
                                        .removeClass('free-service ready-to-translate')
                                        .addClass('requires-key')
                                        .text(this.providers[provider].requiresKey ? 'Invalid API Key' : 'Configuration Error');
                                } else {
                                    if (this.providers[provider].requiresKey) {
                                        $statusSpan
                                            .removeClass('free-service ready-to-translate')
                                            .addClass('requires-key')
                                            .text('Requires API Key');
                                    } else {
                                        $statusSpan
                                            .removeClass('requires-key ready-to-translate')
                                            .addClass('free-service')
                                            .text('Free Service');
                                    }
                                }
                            }
                        });
                    }
                }
            });
        }

        saveSettings() {
            this.showLoading(true, 'Saving settings...');
            
            const data = {
                action: 'wallet_up_save_translation_settings',
                nonce: walletUpTranslations.nonce,
                provider: this.activeProvider,
                google_key: $('#google-api-key').val(),
                deepl_key: $('#deepl-api-key').val(),
                openai_key: $('#openai-api-key').val(),
                openai_model: $('#openai-model').val(),
                openai_context: $('#openai-context').val(),
                libre_url: $('#libre-url').val(),
                libre_key: $('#libre-api-key').val(),
                cache_enabled: $('#enable-cache').is(':checked'),
                auto_translate: $('#enable-auto-translate').is(':checked'),
                batch_size: $('#batch-size').val(),
                request_timeout: $('#request-timeout').val(),
                force_plugin_language: $('#force-plugin-language').val(),
                enable_fuzzy_matching: $('#enable-fuzzy-matching').is(':checked')
            };
            
            $.ajax({
                url: walletUpTranslations.ajaxUrl,
                method: 'POST',
                data: data,
                success: (response) => {
                    if (response.success) {
                        this.showNotice('Settings saved successfully', 'success');
                        const $saveBtn = $('#save-settings');
                        const originalHtml = $saveBtn.html();
                        $saveBtn.addClass('success').html('<span class="dashicons dashicons-yes"></span> Saved!');
                        setTimeout(() => {
                            $saveBtn.removeClass('success').html(originalHtml);
                        }, 2000);
                    } else {
                        this.showNotice(response.data.message || 'Failed to save settings', 'error');
                    }
                },
                error: () => {
                    this.showNotice('Failed to save settings', 'error');
                },
                complete: () => {
                    this.showLoading(false);
                }
            });
        }

        testConnection() {
            this.showLoading(true, 'Testing connection...');
            
            $.ajax({
                url: walletUpTranslations.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_test_translation_provider',
                    nonce: walletUpTranslations.nonce,
                    provider: this.activeProvider
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice(`${this.providers[this.activeProvider].name} is working correctly!`, 'success');
                    } else {
                        this.showNotice(response.data.message || 'Connection test failed', 'error');
                    }
                },
                error: () => {
                    this.showNotice('Connection test failed', 'error');
                },
                complete: () => {
                    this.showLoading(false);
                }
            });
        }

        testTranslation() {
            const text = $('#test-text').val();
            const language = $('#test-language').val();
            
            if (!text) {
                this.showNotice('Please enter text to translate', 'error');
                return;
            }
            
            this.showLoading(true, 'Translating...');
            const startTime = Date.now();
            
            $.ajax({
                url: walletUpTranslations.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_test_translation',
                    nonce: walletUpTranslations.nonce,
                    text: text,
                    language: language,
                    provider: this.activeProvider
                },
                success: (response) => {
                    const endTime = Date.now();
                    const duration = endTime - startTime;
                    
                    if (response.success) {
                        $('#result-original').text(text);
                        $('#result-translation').text(response.data.translation);
                        $('#result-provider').html(`<span class="dashicons ${this.providers[this.activeProvider].icon}"></span> ${this.providers[this.activeProvider].name}`);
                        $('#result-time').text(duration);
                        $('#result-cached').html(response.data.cached ? '<span style="color: #10b981;">Cached</span>' : '<span style="color: #3b82f6;">Fresh</span>');
                        
                        $('#test-result').hide().slideDown(300);

                        $('#result-translation').parent().css('border-left', '4px solid #10b981');
                    } else {
                        const errorMsg = response.data.message || 'Translation failed';
                        this.showNotice(errorMsg, 'error', 10000); // Show error for 10 seconds

                        $('#result-original').text(text);
                        $('#result-translation').html(`<span style="color: #dc2626;">${errorMsg}</span>`);
                        $('#result-provider').html(`<span class="dashicons ${this.providers[this.activeProvider].icon}"></span> ${this.providers[this.activeProvider].name}`);
                        $('#result-time').text(response.data.duration || 0);
                        $('#result-cached').html('<span style="color: #dc2626;">Error</span>');
                        
                        $('#test-result').hide().slideDown(300);
                        $('#result-translation').parent().css('border-left', '4px solid #dc2626');
                    }
                },
                error: () => {
                    this.showNotice('Translation test failed', 'error');
                },
                complete: () => {
                    this.showLoading(false);
                }
            });
        }

        showLoading(show, text = 'Processing...') {
            if (show) {
                $('#settings-loading').show().find('.loading-text').text(text);
            } else {
                $('#settings-loading').hide();
            }
        }

        showNotice(message, type = 'info', duration = 3000) {
            const $notice = $(`
                <div class="notice notice-${type} is-dismissible" style="position: fixed; top: 50px; right: 20px; z-index: 100001; max-width: 400px;">
                    <p>${message}</p>
                </div>
            `);
            
            $('body').append($notice);
            
            setTimeout(() => {
                $notice.fadeOut(() => $notice.remove());
            }, duration);
        }
    }

    $(document).ready(() => {
        if ($('#wallet-up-translation-settings').length) {
            window.translationSettings = new TranslationSettings();
        }
    });

})(jQuery);