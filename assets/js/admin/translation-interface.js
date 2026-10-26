(function($) {
    'use strict';

    class EnterpriseTranslationManager {
        constructor() {
            this.strings = [];
            this.filteredStrings = [];
            this.selectedLanguage = localStorage.getItem('walletup_selected_translation_language') ||
                                  walletUpTranslations.currentLanguage || 'es_ES';
            this.searchTerm = '';
            this.filterType = 'all';
            this.contextFilter = 'all';
            this.isLoading = false;
            this.isSaving = false;
            this.translationMode = walletUpTranslations.activeProvider ||
                                 localStorage.getItem('walletup_translation_mode') ||
                                 'manual';
            this.modifiedStrings = new Set();
            this.selectedStrings = new Set();
            this.showDismissed = false;
            
            this.init();
        }

        init() {
            this.createInterface();
            this.bindEvents();

            $('#translation-mode').val(this.translationMode);

            if (this.selectedLanguage === 'en_US' || this.selectedLanguage === 'en') {
                $('#translation-mode').val('manual').prop('disabled', true);
                this.translationMode = 'manual';
            }

            this.loadTranslations();
        }

        createInterface() {
            const container = $('#wallet-up-translation-app');
            if (!container.length) {
                console.error('WalletUp: Translation container not found');
                return;
            }

            container.empty().removeClass('loading').html(`
                <div class="wallet-up-translation-wrapper">
                    <div class="translation-enterprise-ui">
                    <!-- Header -->
                    <div class="translation-header-advanced">
                        <div class="header-left">
                            <h2 class="header-title">
                                <span class="dashicons dashicons-translation"></span>
                                Wallet Up Pro Translation Manager
                            </h2>
                            <div class="translation-mode-selector">
                                <label>Target:</label>
                                <select id="language-selector" class="language-selector">
                                ${this.getLanguageOptions()}
                                 </select>
                            </div>
                        </div>
                        <div class="header-right">
                            <div class="translation-mode-selector">
                                <label>Mode:</label>
                                <select id="translation-mode">
                                    <option value="manual">Manual</option>
                                    <option value="local">Local Dictionary</option>
                                    <option value="google">Google Translate</option>
                                    <option value="deepl">DeepL</option>
                                    <option value="libre">LibreTranslate</option>
                                    <option value="openai">OpenAI GPT-4</option>
                                </select>
                            </div>
                            <button id="scan-strings" class="button">
                                <span class="dashicons dashicons-search"></span> Scan
                            </button>
                            <button id="translate-code-file" class="button" title="Translate PHP/JS/CSS files with AI">
                                <span class="dashicons dashicons-media-code"></span> Code File
                            </button>
                            <button id="export-translations" class="button">
                                <span class="dashicons dashicons-download"></span> Export
                            </button>
                            <button id="import-translations" class="button">
                                <span class="dashicons dashicons-upload"></span> Import
                            </button>
                            <button id="save-all" class="button button-primary">
                                <span class="dashicons dashicons-saved"></span> Save All
                            </button>
                            <button id="reset-all" class="button button-link-delete" title="Reset all translations for this language">
                                <span class="dashicons dashicons-trash"></span> Reset All
                            </button>
                        </div>
                    </div>

                    <!-- Statistics -->
                    <div class="translation-stats-grid">
                        <div class="stat-card clickable" data-filter="all" title="Click to show all strings">
                            <div class="stat-value" id="stat-total">0</div>
                            <div class="stat-label">Total Strings</div>
                        </div>
                        <div class="stat-card translated clickable" data-filter="translated" title="Click to show translated strings">
                            <div class="stat-value" id="stat-translated">0</div>
                            <div class="stat-label">Translated</div>
                        </div>
                        <div class="stat-card untranslated clickable" data-filter="untranslated" title="Click to show untranslated strings">
                            <div class="stat-value" id="stat-untranslated">0</div>
                            <div class="stat-label">Untranslated</div>
                        </div>
                        <div class="stat-card modified clickable" data-filter="modified" title="Click to show modified strings">
                            <div class="stat-value" id="stat-modified">0</div>
                            <div class="stat-label">Modified</div>
                        </div>
                        <div class="stat-card completion">
                            <div class="stat-value" id="stat-percentage">0%</div>
                            <div class="stat-label">Complete</div>
                            <div class="progress-bar">
                                <div class="progress-fill" id="progress-fill"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Search and Filters Container -->
                    <div class="translation-controls-wrapper">
                        <!-- Search Box - Full Width -->
                        <div class="translation-search-row">
                            <div class="walletup-search-box full-width">
                                <input type="text" id="search-strings" placeholder="Search strings, translations, or files..." style="margin-bottom: 20px;" />
                            </div>
                        </div>

                        <!-- Filters - Second Row -->
                        <div class="translation-filters-row">
                            <div class="filter-controls">
                                <select id="filter-status" class="filter-select">
                                    <option value="all">All Strings</option>
                                    <option value="translated">Translated</option>
                                    <option value="untranslated">Untranslated</option>
                                    <option value="modified">Modified</option>
                                    <option value="reviewed">Reviewed</option>
                                    <option value="recommended_dismiss">Recommended for Dismissal</option>
                                </select>
                                <select id="filter-context" class="filter-select">
                                    <option value="all">All Contexts</option>
                                    <option value="general">General</option>
                                    <option value="admin">Admin</option>
                                    <option value="payment">Payment</option>
                                    <option value="donation">Donation</option>
                                    <option value="error">Error</option>
                                    <option value="form">Form</option>
                                    <option value="button">Button</option>
                                    <option value="navigation">Navigation</option>
                                </select>
                                <label class="show-dismissed-label" style="display: flex; align-items: center; gap: 5px;">
                                    <input type="checkbox" id="show-dismissed" />
                                    Show Dismissed
                                </label>
                                <button id="bulk-translate" class="button button-secondary" style="display: none;">
                                    <span class="dashicons dashicons-admin-generic"></span>
                                    Auto-Translate Selected (<span id="selected-count">0</span>)
                                </button>
                                <button id="reset-translations" class="button button-secondary" style="display: none;">
                                    <span class="dashicons dashicons-trash"></span>
                                    Reset Selected (<span id="reset-count">0</span>)
                                </button>
                                <button id="dismiss-selected" class="button button-secondary" style="display: none;">
                                    <span class="dashicons dashicons-hidden"></span>
                                    Dismiss Selected (<span id="dismiss-count">0</span>)
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Translation List -->
                    <div class="translation-list-container" style="margin-top: 20px;">
                        <div class="translation-list-header">
                            <div class="column-check">
                                <input type="checkbox" id="select-all" />
                            </div>
                            <div class="column-status">Status</div>
                            <div class="column-original">Original Text</div>
                            <div class="column-translation">Translation</div>
                            <div class="column-context">Context</div>
                            <div class="column-actions">Actions</div>
                        </div>
                        <div id="translation-list" class="translation-list">
                            <!-- Strings will be loaded here -->
                        </div>
                    </div>

                    <!-- Loading Overlay -->
                    <div id="loading-overlay" class="loading-overlay" style="display: none;">
                        <div class="spinner"></div>
                        <div class="loading-text">Processing...</div>
                    </div>
                </div>

                <!-- Import Dialog -->
                <div id="import-dialog" class="translation-dialog" style="display: none;">
                    <div class="dialog-content">
                        <h3>Import Translations</h3>
                        <p>Select a translation file (.wup or .json):</p>
                        <input type="file" id="import-file" accept=".wup,.json" />
                        <div class="dialog-buttons">
                            <button class="button" onclick="enterpriseTranslation.closeImportDialog()">Cancel</button>
                            <button class="button button-primary" onclick="enterpriseTranslation.processImport()">Import</button>
                        </div>
                        </div>
                    </div>
                    
                <!-- Scan Options Dialog -->
                <div id="scan-dialog" class="translation-dialog" style="display: none;">
                    <div class="dialog-content">
                        <h3>Scan for Translatable Strings</h3>
                        <p>Configure scanning options:</p>
                        <div style="margin: 20px 0;">
                            <label style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
                                <input type="checkbox" id="scan-include-minified" />
                                <span>Include minified React/Vite dist files</span>
                            </label>
                            <p class="description" style="margin-left: 30px; color: #666; font-size: 13px;">
                                This will scan heavily minified JavaScript files in dist/build folders. 
                                May find additional UI strings but could also include false positives.
                            </p>
                        </div>
                        <div class="dialog-buttons">
                            <button class="button" onclick="enterpriseTranslation.closeScanDialog()">Cancel</button>
                            <button class="button button-primary" onclick="enterpriseTranslation.performScan()">Start Scan</button>
                        </div>
                    </div>
                </div>
                
                <!-- Code File Translation Dialog -->
                <div id="code-file-dialog" class="translation-dialog" style="display: none;">
                    <div class="dialog-content" style="max-width: 600px;">
                        <h3>Translate Code File</h3>
                        <p>Select a PHP, JavaScript, or CSS file to translate while preserving code structure:</p>
                        
                        <div style="margin: 20px 0;">
                            <label style="display: block; margin-bottom: 10px;">
                                <strong>File Path:</strong>
                            </label>
                            <input type="text" id="code-file-path" placeholder="walup-react/dist/assets/minified.js" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" />
                            <p class="description" style="margin-top: 5px; color: #666; font-size: 13px;">
                                Enter the path to the file you want to translate. Supports PHP, JS, JSX, CSS files.<br>
                                Example: <code>walup-react/dist/assets/minified.js</code> or an absolute path <code>/path/to/wp-content/plugins/wallet-up-pro/walup-react/dist/assets/minified.js</code>
                            </p>
                        </div>
                        
                        <div style="margin: 20px 0;">
                            <label style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" id="code-preserve-variables" checked />
                                <span>Preserve variables and function names</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 10px; margin-top: 10px;">
                                <input type="checkbox" id="code-handle-minified" />
                                <span>Handle minified files (may be slower)</span>
                            </label>
                        </div>
                        
                        <div class="dialog-buttons">
                            <button class="button" onclick="enterpriseTranslation.closeCodeFileDialog()">Cancel</button>
                            <button class="button button-primary" onclick="enterpriseTranslation.translateCodeFile()">Translate</button>
                        </div>
                    </div>
                </div>
                </div>
            `);

            this.addStyles();
        }

        addStyles() {
            if ($('#enterprise-translation-styles').length) return;

            $('head').append(`
                <style id="enterprise-translation-styles">
                    .wallet-up-translation-wrapper {
                        clear: both;
                        padding: 0;
                        margin: 0;
                        position: relative;
                        min-height: 100vh;
                    }
                    
                    .translation-enterprise-ui {
                        margin: 0;
                        padding: 20px;
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                        max-width: 1400px;
                        margin-left: auto;
                        margin-right: auto;
                    }

                    .translation-header-advanced {
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        padding: 20px;
                        background: #fff;
                        border: 1px solid #e0e0e0;
                        border-radius: 8px;
                        margin-bottom: 20px;
                        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
                        position: relative;
                        overflow: visible;
                        min-height: 80px;
                        flex-wrap: wrap;
                        gap: 20px;
                    }

                    .header-left {
                        display: flex;
                        align-items: center;
                        gap: 20px;
                        flex: 1 1 auto;
                        min-width: 0;
                    }

                    .header-title {
                        display: flex;
                        align-items: center;
                        gap: 10px;
                        margin: 0;
                        font-size: 24px;
                        font-weight: 600;
                        color: #23282d;
                        white-space: nowrap;
                        overflow: hidden;
                        text-overflow: ellipsis;
                        flex: 1 1 auto;
                    }

                    .header-title .dashicons {
                        font-size: 28px;
                        color: #674FBF;
                        flex-shrink: 0;
                    }

                    .header-right {
                        display: flex;
                        align-items: center;
                        gap: 15px;
                        flex-wrap: wrap;
                        justify-content: flex-end;
                        flex: 0 1 auto;
                    }

                    .translation-mode-selector {
                        display: flex;
                        align-items: center;
                        gap: 8px;
                        white-space: nowrap;
                        flex: 0 1 auto;
                    }
                    
                    .translation-mode-selector label {
                        font-weight: 600;
                        color: #555;
                    }
                    
                    .translation-mode-selector select {
                        min-width: 120px;
                        padding: 6px 10px;
                        border: 2px solid #e0e0e0;
                        border-radius: 6px;
                        font-size: 14px;
                    }

                    .language-selector {
                        padding: 8px 12px;
                        border: 2px solid #e0e0e0;
                        border-radius: 6px;
                        font-size: 14px;
                        min-width: 200px;
                        max-width: 300px;
                        flex: 0 1 auto;
                    }
                    
                    .header-right button {
                        white-space: nowrap;
                        display: flex;
                        align-items: center;
                        gap: 5px;
                        flex: 0 0 auto;
                    }

                    .translation-stats-grid {
                        display: grid;
                        grid-template-columns: repeat(5, 1fr);
                        gap: 20px;
                        margin-bottom: 20px;
                    }

                    .stat-card {
                        background: #fff;
                        border: 1px solid #e0e0e0;
                        border-radius: 8px;
                        padding: 20px;
                        text-align: center;
                        transition: all 0.3s ease;
                        position: relative;
                        overflow: hidden;
                    }
                    
                    .stat-card.clickable {
                        cursor: pointer;
                        user-select: none;
                    }
                    
                    .stat-card.clickable:hover {
                        transform: translateY(-2px);
                        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                        border-color: #674FBF;
                    }
                    
                    .stat-card.active {
                        border-color: #674FBF;
                        box-shadow: 0 4px 12px rgba(103, 79, 191, 0.2);
                        transform: translateY(-2px);
                    }
                    
                    .stat-card.active::after {
                        content: '✓';
                        position: absolute;
                        top: 10px;
                        right: 10px;
                        background: #674FBF;
                        color: white;
                        width: 20px;
                        height: 20px;
                        border-radius: 50%;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-size: 12px;
                    }

                    .stat-card:hover {
                        transform: translateY(-2px);
                        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                    }

                    .stat-card::before {
                        content: '';
                        position: absolute;
                        top: 0;
                        left: 0;
                        right: 0;
                        height: 4px;
                        background: #674FBF;
                    }

                    .stat-card.translated::before { background: #4caf50; }
                    .stat-card.untranslated::before { background: #ff9800; }
                    .stat-card.modified::before { background: #2196f3; }
                    .stat-card.completion::before { background: #9c27b0; }

                    .stat-value {
                        font-size: 32px;
                        font-weight: 700;
                        color: #23282d;
                        margin-bottom: 5px;
                    }

                    .stat-label {
                        font-size: 14px;
                        color: #666;
                        text-transform: uppercase;
                        letter-spacing: 0.5px;
                    }

                    .progress-bar {
                        margin-top: 10px;
                        height: 6px;
                        background: #e0e0e0;
                        border-radius: 3px;
                        overflow: hidden;
                    }

                    .progress-fill {
                        height: 100%;
                        background: linear-gradient(90deg, #674FBF 0%, #9c27b0 100%);
                        border-radius: 3px;
                        transition: width 0.5s ease;
                    }

                    .translation-controls {
                        display: flex;
                        gap: 20px;
                        margin-bottom: 20px;
                        padding: 20px;
                        background: #fff;
                        border: 1px solid #e0e0e0;
                        border-radius: 8px;
                    }

                    .search-box {
                        flex: 1;
                        position: relative;
                    }

                    .search-box .dashicons {
                        position: absolute;
                        right: 12px;
                        left: auto;
                        top: 50%;
                        transform: translateY(-50%);
                        color: #666;
                        width: 20px;
                        height: 20px;
                        line-height: 20px;
                        text-align: center;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                    }
                    
                    .search-box .dashicons:before {
                        line-height: 1;
                        display: block;
                    }

                    .search-box input {
                        width: 100%;
                        padding: 10px 40px 10px 10px;
                        border: 2px solid #e0e0e0;
                        border-radius: 6px;
                        font-size: 14px;
                    }
                    
                    .walletup-search-box {
                        position: relative;
                        width: 100%;
                    }
                    
                    .walletup-search-box input {
                        width: 100%;
                        padding: 10px 15px;
                        border: 2px solid #e0e0e0;
                        border-radius: 6px;
                        font-size: 14px;
                    }

                    .filter-controls {
                        display: flex;
                        gap: 10px;
                        align-items: center;
                    }

                    .filter-select {
                        padding: 10px 15px;
                        border: 2px solid #e0e0e0;
                        border-radius: 6px;
                        font-size: 14px;
                    }

                    .translation-list-container {
                        background: #fff;
                        border: 1px solid #e0e0e0;
                        border-radius: 8px;
                        overflow: hidden;
                    }

                    .translation-list-header {
                        display: grid;
                        grid-template-columns: 40px 80px 0.8fr 1.3fr 160px 100px;
                        padding: 15px 20px;
                        background: #f5f5f5;
                        border-bottom: 2px solid #e0e0e0;
                        font-weight: 600;
                        font-size: 14px;
                        color: #555;
                    }

                    .translation-list {
                        max-height: 600px;
                        overflow-y: auto;
                    }

                    .translation-item {
                        display: grid;
                        grid-template-columns: 40px 80px 0.8fr 1.3fr 160px 100px;
                        padding: 15px 20px;
                        border-bottom: 1px solid #f0f0f0;
                        align-items: center;
                        transition: all 0.2s ease;
                    }

                    .translation-item:hover {
                        background: #f9f9f9;
                    }
                    
                    @media (max-width: 1400px) {
                        .translation-list-header,
                        .translation-item {
                            grid-template-columns: 40px 70px 0.7fr 1.3fr 140px 90px;
                            font-size: 13px;
                        }
                    }
                    
                    @media (max-width: 1200px) {
                        .translation-list-header,
                        .translation-item {
                            grid-template-columns: 40px 60px 0.6fr 1.4fr 120px 80px;
                            padding: 12px 15px;
                        }
                        
                        .translation-list-container {
                            margin: 15px;
                        }
                    }
                    
                    @media (max-width: 992px) {
                        .translation-list-header,
                        .translation-item {
                            grid-template-columns: 40px 60px 1fr 1.5fr 100px;
                        }
                        
                        .column-actions,
                        .translation-item > div:nth-child(6) {
                            display: none;
                        }
                        
                        .translation-item input[type="text"] {
                            font-size: 14px;
                        }
                    }
                    
                    @media (max-width: 768px) {
                        .translation-list-container {
                            margin: 10px;
                        }
                        
                        .translation-list-header {
                            display: flex !important;
                            justify-content: flex-start;
                            align-items: center;
                            padding: 12px 15px;
                            background: #f5f5f5;
                            border-bottom: 2px solid #e0e0e0;
                            grid-template-columns: none !important;
                        }
                        
                        .translation-list-header > div:not(.column-check) {
                            display: none !important;
                        }
                        
                        .translation-list-header .column-check {
                            display: flex !important;
                            align-items: center;
                            gap: 10px;
                            width: auto !important;
                            position: static !important;
                        }
                        
                        .translation-list-header .column-check:after {
                            content: "Select All";
                            font-weight: 600;
                            font-size: 14px;
                        }
                        
                        .translation-item {
                            display: flex !important;
                            flex-direction: column;
                            padding: 15px;
                            padding-top: 45px; /* Space for checkbox and status */
                            gap: 10px;
                            border-bottom: 2px solid #e0e0e0;
                            position: relative;
                            grid-template-columns: none !important;
                        }
                        
                        .translation-item > div:not(.column-check):not(.column-status) {
                            width: 100% !important;
                            position: static !important;
                        }
                        
                        .translation-item .column-check {
                            position: absolute !important;
                            top: 15px;
                            left: 15px;
                            display: block !important;
                            width: auto !important;
                            min-width: 20px !important;
                            z-index: 2;
                        }
                        
                        .translation-item .column-status {
                            position: absolute !important;
                            top: 15px;
                            right: 15px;
                            left: auto !important;
                            width: auto !important;
                            display: block !important;
                            z-index: 2;
                        }
                        
                        .translation-item .column-original {
                            margin-top: 0;
                            width: 100% !important;
                        }
                        
                        .translation-item .column-original:before {
                            content: "Original: ";
                            font-weight: 600;
                            color: #666;
                        }
                        
                        .translation-item .column-translation:before {
                            content: "Translation: ";
                            font-weight: 600;
                            color: #666;
                        }
                        
                        .translation-item .column-context:before {
                            content: "Context: ";
                            font-weight: 600;
                            color: #666;
                        }
                        
                        .translation-item input[type="text"] {
                            width: 100%;
                            padding: 10px;
                            font-size: 16px; /* Prevent zoom on iOS */
                        }
                        
                        .translation-item input[type="checkbox"],
                        .translation-list-header input[type="checkbox"] {
                            width: 20px !important;
                            height: 20px !important;
                            min-width: 20px !important;
                            min-height: 20px !important;
                            cursor: pointer;
                            display: inline-block !important;
                            position: relative !important;
                            margin: 0 !important;
                            flex-shrink: 0 !important;
                        }
                        
                        .column-check {
                            overflow: visible !important;
                        }
                    }
                    
                    @media (max-width: 480px) {
                        .translation-list-container {
                            margin: 5px;
                            border-radius: 4px;
                        }
                        
                        .translation-item {
                            padding: 12px;
                            font-size: 14px;
                        }
                        
                        .translation-list {
                            max-height: 500px; /* Smaller on mobile */
                        }
                        
                        .translation-item input[type="text"] {
                            padding: 8px;
                            border-width: 1px;
                        }
                    }
                    
                    @media (max-width: 375px) {
                        .translation-item {
                            padding: 10px;
                        }
                        
                        .translation-item input[type="text"] {
                            font-size: 15px;
                        }
                    }

                    .translation-item.modified {
                        background: #e3f2fd;
                    }

                    .translation-item.untranslated {
                        background: #fff8e1;
                    }

                    .status-indicator {
                        width: 24px;
                        height: 24px;
                        border-radius: 50%;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                    }

                    .status-indicator.translated {
                        background: #4caf50;
                        color: white;
                    }

                    .status-indicator.untranslated {
                        background: #ff9800;
                        color: white;
                    }

                    .original-text {
                        font-weight: 500;
                        color: #23282d;
                        padding-right: 20px;
                    }

                    .translation-input-wrapper {
                        position: relative;
                    }

                    .translation-input {
                        width: 100%;
                        padding: 8px 35px 8px 12px;
                        border: 2px solid #e0e0e0;
                        border-radius: 6px;
                        font-size: 14px;
                        transition: all 0.2s ease;
                    }

                    .translation-input:focus {
                        border-color: #674FBF;
                        outline: none;
                        box-shadow: 0 0 0 3px rgba(103, 79, 191, 0.1);
                    }

                    .auto-translate-btn {
                        position: absolute;
                        right: 8px;
                        top: 50%;
                        transform: translateY(-50%);
                        background: none;
                        border: none;
                        color: #674FBF;
                        cursor: pointer;
                        padding: 4px;
                    }

                    .auto-translate-btn:hover {
                        color: #8e7cc3;
                    }

                    .context-badge {
                        display: inline-block;
                        padding: 4px 12px;
                        border-radius: 16px;
                        font-size: 12px;
                        font-weight: 500;
                        text-transform: capitalize;
                    }

                    .context-badge.general { background: #e8f5e9; color: #2e7d32; }
                    .context-badge.admin { background: #f3e5f5; color: #6a1b9a; }
                    .context-badge.payment { background: #e3f2fd; color: #1565c0; }
                    .context-badge.donation { background: #fce4ec; color: #c2185b; }
                    .context-badge.error { background: #ffebee; color: #c62828; }
                    .context-badge.form { background: #e8eaf6; color: #3f51b5; }
                    .context-badge.button { background: #fff9c4; color: #f57f17; }
                    .context-badge.navigation { background: #e0f2f1; color: #00897b; }

                    .action-buttons {
                        display: flex;
                        gap: 8px;
                    }

                    .action-btn {
                        background: none;
                        border: none;
                        color: #666;
                        cursor: pointer;
                        padding: 4px;
                        border-radius: 4px;
                        transition: all 0.2s ease;
                    }

                    .action-btn:hover {
                        background: #f0f0f0;
                        color: #674FBF;
                    }

                    .loading-overlay {
                        position: fixed;
                        top: 0;
                        left: 0;
                        right: 0;
                        bottom: 0;
                        background: rgba(0, 0, 0, 0.6);
                        display: flex;
                        flex-direction: column;
                        align-items: center;
                        justify-content: center;
                        z-index: 100000;
                    }

                    .spinner {
                        width: 50px;
                        height: 50px;
                        border: 4px solid #f3f3f3;
                        border-top: 4px solid #674FBF;
                        border-radius: 50%;
                        animation: spin 1s linear infinite;
                    }

                    @keyframes spin {
                        0% { transform: rotate(0deg); }
                        100% { transform: rotate(360deg); }
                    }

                    .loading-text {
                        color: white;
                        margin-top: 20px;
                        font-size: 16px;
                    }

                    .translation-dialog {
                        position: fixed;
                        top: 0;
                        left: 0;
                        right: 0;
                        bottom: 0;
                        background: rgba(0, 0, 0, 0.6);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        z-index: 100000;
                    }

                    .dialog-content {
                        background: white;
                        padding: 30px;
                        border-radius: 8px;
                        max-width: 500px;
                        width: 90%;
                        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
                    }

                    .dialog-content h3 {
                        margin-top: 0;
                        margin-bottom: 20px;
                        font-size: 20px;
                        color: #23282d;
                    }
                    
                    .dialog-content p {
                        margin: 10px 0;
                        line-height: 1.6;
                    }
                    
                    .dialog-content strong {
                        color: #555;
                    }
                    
                    .dialog-close-x:hover {
                        color: #000;
                        background: #f0f0f0;
                        border-radius: 4px;
                    }

                    .dialog-buttons {
                        display: flex;
                        gap: 10px;
                        justify-content: flex-end;
                        margin-top: 20px;
                        padding-top: 20px;
                        border-top: 1px solid #eee;
                    }

                    @media (max-width: 1200px) {
                        .translation-stats-grid {
                            grid-template-columns: repeat(3, 1fr);
                        }
                        
                        .header-right {
                            flex-wrap: wrap;
                            justify-content: flex-end;
                        }
                    }

                    @media (max-width: 900px) {
                        .translation-header-advanced {
                            flex-direction: column;
                            gap: 20px;
                            padding: 20px 15px;
                            min-height: auto;
                            align-items: stretch;
                        }

                        .header-left {
                            width: 100%;
                            flex-direction: column;
                            gap: 15px;
                            align-items: stretch;
                            text-align: center;
                            flex: 1 1 100%;
                        }
                        
                        .header-title {
                            font-size: 20px;
                            justify-content: center;
                            flex-wrap: wrap;
                            white-space: normal;
                            text-align: center;
                        }
                        
                        .header-title .dashicons {
                            width: 100%;
                            margin-bottom: 5px;
                            font-size: 32px;
                        }
                        
                        .language-selector {
                            width: 100%;
                            max-width: none;
                            text-align: center;
                            min-width: 0;
                        }

                        .header-right {
                            width: 100%;
                            gap: 10px;
                            justify-content: center;
                            flex: 1 1 100%;
                        }
                        
                        .translation-mode-selector {
                            width: 100%;
                            justify-content: center;
                            padding: 10px;
                            background: #f9f9f9;
                            border-radius: 6px;
                            margin-bottom: 5px;
                            flex: 1 1 100%;
                        }
                        
                        .translation-mode-selector select {
                            flex: 0 1 auto;
                            margin-left: 10px;
                            max-width: 200px;
                        }
                        
                        .header-right button {
                            flex: 1 1 calc(50% - 5px);
                            min-width: 0;
                            max-width: 150px;
                            justify-content: center;
                            padding: 8px 12px;
                        }
                        
                        .header-right button.button-primary {
                            flex: 1 1 100%;
                            max-width: none;
                            margin-top: 5px;
                            font-size: 16px;
                            padding: 10px 20px;
                        }
                    }

                    @media (max-width: 768px) {
                        .translation-stats-grid {
                            grid-template-columns: repeat(2, 1fr);
                            gap: 15px;
                        }
                        
                        .stat-card {
                            padding: 15px;
                        }
                        
                        .stat-value {
                            font-size: 24px;
                        }

                        .translation-controls {
                            flex-direction: column;
                            padding: 15px;
                        }
                        
                        .search-box {
                            width: 100%;
                            margin-bottom: 15px;
                        }
                        
                        .filter-controls {
                            width: 100%;
                            flex-wrap: wrap;
                            gap: 10px;
                        }
                        
                        .filter-select {
                            flex: 1;
                            min-width: 140px;
                        }
                        
                        #bulk-translate {
                            width: 100%;
                            margin-top: 10px;
                        }

                        .translation-list-header {
                            display: none;
                        }

                        .translation-item {
                            grid-template-columns: 1fr;
                            padding: 15px;
                            position: relative;
                        }
                        
                        .column-check {
                            position: absolute;
                            top: 15px;
                            right: 15px;
                        }
                        
                        .column-status {
                            position: absolute;
                            top: 15px;
                            left: 15px;
                        }
                        
                        .column-original {
                            margin-bottom: 10px;
                            padding-left: 40px;
                            padding-right: 40px;
                        }
                        
                        .column-translation {
                            margin-bottom: 10px;
                        }
                        
                        .column-context {
                            margin-bottom: 10px;
                        }
                        
                        .column-actions {
                            position: absolute;
                            bottom: 15px;
                            right: 15px;
                        }
                        
                        .string-meta {
                            margin-top: 5px;
                        }
                        
                        .string-meta small {
                            display: block;
                            font-size: 11px;
                            color: #666;
                        }
                    }
                    
                    @media (max-width: 480px) {
                        .translation-header-advanced {
                            padding: 15px 10px;
                            gap: 15px;
                        }
                        
                        .header-title {
                            font-size: 18px;
                        }
                        
                        .header-title .dashicons {
                            font-size: 24px;
                        }
                        
                        .translation-stats-grid {
                            grid-template-columns: 1fr;
                            gap: 10px;
                        }
                        
                        .header-right {
                            flex-direction: column;
                            align-items: stretch;
                        }
                        
                        .header-right button {
                            min-width: 0;
                            font-size: 13px;
                            flex: 1 1 auto;
                            max-width: none;
                            width: 100%;
                        }
                        
                        .header-right button .dashicons {
                            font-size: 16px;
                        }
                        
                        .translation-mode-selector {
                            flex-direction: column;
                            align-items: stretch;
                            gap: 5px;
                        }
                        
                        .translation-mode-selector label {
                            text-align: center;
                            margin-bottom: 5px;
                        }
                        
                        .translation-mode-selector select {
                            width: 100%;
                            max-width: none;
                            margin-left: 0;
                        }
                        
                        .stat-card {
                            padding: 12px;
                        }
                        
                        .stat-value {
                            font-size: 20px;
                        }
                        
                        .stat-label {
                            font-size: 12px;
                        }
                    }

                    .progress-bar-large {
                        box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1);
                    }
                    
                    .progress-fill-large {
                        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
                        position: relative;
                        overflow: hidden;
                    }
                    
                    .progress-fill-large::before {
                        content: '';
                        position: absolute;
                        top: 0;
                        left: -100%;
                        width: 100%;
                        height: 100%;
                        background: linear-gradient(90deg, 
                            transparent 0%, 
                            rgba(255, 255, 255, 0.2) 50%, 
                            transparent 100%
                        );
                        animation: shimmer 2s infinite;
                    }
                    
                    @keyframes shimmer {
                        0% { left: -100%; }
                        100% { left: 100%; }
                    }
                    
                    .progress-container h3 {
                        margin-bottom: 20px;
                        font-size: 22px;
                        text-align: center;
                    }
                    
                    .progress-text {
                        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
                    }
                    
                    @keyframes dashicons-spin {
                        0% { transform: rotate(0deg); }
                        100% { transform: rotate(360deg); }
                    }
                    
                    .dashicons-spin {
                        animation: dashicons-spin 1s linear infinite;
                    }
                    
                    .translation-success {
                        animation: successFlash 0.5s ease-in-out;
                    }
                    
                    @keyframes successFlash {
                        0% { background-color: transparent; }
                        50% { background-color: #c8e6c9; border-color: #4caf50; }
                        100% { background-color: transparent; }
                    }
                    
                    @media (max-width: 782px) {
                        .wallet-up-translation-wrapper {
                            margin-top: 46px;
                        }
                        
                        .translation-enterprise-ui {
                            padding: 15px;
                        }
                    }
                    
                    @media (max-width: 600px) {
                        #wpadminbar {
                            position: fixed !important;
                        }
                        
                        .wallet-up-translation-wrapper {
                            min-height: calc(100vh - 46px);
                        }
                        
                        .translation-enterprise-ui {
                            padding: 10px;
                        }
                    }
                    
                    .wallet-up-translation-wrapper::before,
                    .wallet-up-translation-wrapper::after {
                        content: "";
                        display: table;
                        clear: both;
                    }
                    
                    .translation-enterprise-ui .button,
                    .translation-enterprise-ui button {
                        display: inline-flex !important;
                        align-items: center !important;
                        justify-content: center !important;
                        gap: 6px !important;
                        min-height: 36px;
                    }
                    
                    .translation-enterprise-ui .button .dashicons,
                    .translation-enterprise-ui button .dashicons {
                        display: inline-block !important;
                        width: 16px !important;
                        height: 16px !important;
                        font-size: 16px !important;
                        line-height: 16px !important;
                        vertical-align: middle !important;
                        margin: 0 !important;
                        margin-top: -2px !important;
                    }
                    
                    .auto-translate-btn,
                    .save-translation-btn,
                    .reset-translation-btn {
                        display: inline-flex !important;
                        align-items: center !important;
                        justify-content: center !important;
                        padding: 6px !important;
                    }
                    
                    .auto-translate-btn .dashicons,
                    .save-translation-btn .dashicons,
                    .reset-translation-btn .dashicons {
                        margin: 0 !important;
                        width: 18px !important;
                        height: 18px !important;
                        font-size: 18px !important;
                        line-height: 18px !important;
                    }
                    
                    #bulk-translate,
                    #reset-translations {
                        display: inline-flex !important;
                        align-items: center !important;
                        gap: 6px !important;
                    }
                    
                    #bulk-translate .dashicons,
                    #reset-translations .dashicons {
                        margin: 0 !important;
                        margin-top: -2px !important;
                    }
                    
                    .translation-item.dismissed {
                        opacity: 0.6;
                        background-color: #f8f8f8;
                    }
                    
                    .translation-item.recommend-dismiss {
                        background-color: #fff9e6;
                    }
                    
                    .dismiss-recommendation {
                        color: #856404;
                        background-color: #fff3cd;
                        padding: 2px 8px;
                        border-radius: 3px;
                        display: inline-block;
                        margin-top: 4px;
                    }
                    
                    .dismissed-label {
                        color: #721c24;
                        background-color: #f8d7da;
                        padding: 2px 8px;
                        border-radius: 3px;
                        display: inline-block;
                        margin-top: 4px;
                    }
                    
                    .show-dismissed-label {
                        font-weight: normal;
                        margin-left: 10px;
                    }
                    
                    .dismiss-btn .dashicons-hidden,
                    .restore-btn .dashicons-visibility {
                        color: #666;
                    }
                    
                    .dismiss-btn:hover .dashicons-hidden {
                        color: #dc3545;
                    }
                    
                    .restore-btn:hover .dashicons-visibility {
                        color: #28a745;
                    }
                    
                    #dismiss-selected {
                        background-color: #f0f0f0;
                        border-color: #999;
                    }
                    
                    #dismiss-selected:hover {
                        background-color: #e0e0e0;
                        border-color: #666;
                    }
                </style>
            `);
        }

        getLanguageName(code) {
            const languages = walletUpTranslations.availableLanguages || {
                'en_US': 'English',
                'es_ES': 'Spanish',
                'es_MX': 'Spanish (Mexico)',
                'fr_FR': 'French',
                'de_DE': 'German',
                'it_IT': 'Italian',
                'pt_BR': 'Portuguese (Brazil)',
                'zh_CN': 'Chinese (Simplified)',
                'ja': 'Japanese',
                'ko_KR': 'Korean',
                'ar': 'Arabic',
                'ru': 'Russian'
            };
            return languages[code] || code;
        }

        getLanguageOptions() {
            const languages = walletUpTranslations.availableLanguages || {
                'en_US': 'English',
                'es_ES': 'Spanish',
                'es_MX': 'Spanish (Mexico)',
                'fr_FR': 'French',
                'de_DE': 'German',
                'it_IT': 'Italian',
                'pt_BR': 'Portuguese (Brazil)',
                'zh_CN': 'Chinese (Simplified)',
                'ja': 'Japanese',
                'ko_KR': 'Korean',
                'ar': 'Arabic',
                'ru': 'Russian'
            };

            const currentSiteLocale = walletUpTranslations.currentLanguage;
            if (currentSiteLocale && !languages[currentSiteLocale] && currentSiteLocale !== 'en' && currentSiteLocale !== 'en_US') {
                const localeMap = {
                    'fr': 'fr_FR',
                    'es': 'es_ES',
                    'de': 'de_DE',
                    'it': 'it_IT',
                    'pt': 'pt_BR'
                };
                
                const shortLocale = currentSiteLocale.split('_')[0];
                if (localeMap[shortLocale] && languages[localeMap[shortLocale]]) {
                    if (!this.selectedLanguage || this.selectedLanguage === currentSiteLocale) {
                        this.selectedLanguage = localeMap[shortLocale];
                    }
                }
            }

            return Object.entries(languages).map(([code, name]) => 
                `<option value="${code}" ${code === this.selectedLanguage ? 'selected' : ''}>${name}</option>`
            ).join('');
        }

        bindEvents() {
            $(document).on('change', '#language-selector', (e) => {
                this.selectedLanguage = e.target.value;
                localStorage.setItem('walletup_selected_translation_language', this.selectedLanguage);

                if (this.selectedLanguage === 'en_US' || this.selectedLanguage === 'en') {
                    this.showNotice('Please select a target language other than English for translation', 'warning');
                    $('#translation-mode').val('manual').prop('disabled', true);
                    this.translationMode = 'manual';
                    localStorage.setItem('walletup_translation_mode', 'manual');
                } else {
                    $('#translation-mode').prop('disabled', false);
                    if (this.translationMode === 'manual') {
                        const savedMode = localStorage.getItem('walletup_translation_mode_preference') || 'auto';
                        this.translationMode = savedMode;
                        $('#translation-mode').val(savedMode);
                    }
                }
                
                this.loadTranslations();
                this.updateUIForMode();
                this.updateSelectedCount();
            });

            $(document).on('change', '#translation-mode', (e) => {
                this.translationMode = e.target.value;
                localStorage.setItem('walletup_translation_mode', this.translationMode);
                localStorage.setItem('walletup_translation_mode_preference', this.translationMode);

                $.ajax({
                    url: walletUpTranslations.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'wallet_up_save_translation_settings',
                        nonce: walletUpTranslations.nonce,
                        provider: this.translationMode
                    },
                    success: (response) => {
                        if (response.success) {
                        }
                    }
                });
                
                this.updateUIForMode();
            });

            $(document).on('input', '#search-strings', debounce((e) => {
                this.searchTerm = e.target.value.toLowerCase();
                this.filterStrings();
            }, 300));

            $(document).on('change', '#filter-status, #filter-context', () => {
                this.filterType = $('#filter-status').val();
                this.contextFilter = $('#filter-context').val();

                if (this.filterType === 'all') {
                    $('.stat-card').removeClass('active');
                    $('.stat-card[data-filter="all"]').addClass('active');
                } else if (['translated', 'untranslated', 'modified'].includes(this.filterType)) {
                    $('.stat-card').removeClass('active');
                    $(`.stat-card[data-filter="${this.filterType}"]`).addClass('active');
                } else {
                    $('.stat-card').removeClass('active');
                }
                
                this.filterStrings();
            });

            $(document).on('change', '#select-all', (e) => {
                const checked = e.target.checked;
                $('.string-checkbox').prop('checked', checked);
                this.updateSelectedCount();
            });

            $(document).on('change', '.string-checkbox', () => {
                this.updateSelectedCount();
            });

            $(document).on('input', '.translation-input', (e) => {
                const $input = $(e.target);
                const stringId = $input.data('id');
                const value = $input.val();
                
                this.updateTranslation(stringId, value);
            });

            $(document).on('click', '.auto-translate-btn', (e) => {
                const stringId = $(e.target).closest('.translation-item').data('id');
                this.autoTranslateSingle(stringId);
            });

            $(document).on('click', '#scan-strings', () => this.showScanDialog());
            $(document).on('click', '#translate-code-file', () => this.showCodeFileDialog());
            $(document).on('click', '#save-all', () => this.saveAllTranslations());
            $(document).on('click', '#reset-all', () => this.resetAllTranslations());
            $(document).on('click', '#reset-translations', () => this.resetSelectedTranslations());
            $(document).on('click', '#export-translations', () => this.exportTranslations());
            $(document).on('click', '#import-translations', () => this.showImportDialog());
            $(document).on('click', '#bulk-translate', () => this.bulkTranslate());

            $(document).on('change', '#import-file', (e) => {
                this.importFile = e.target.files[0];
            });

            $(document).on('change', '#show-dismissed', (e) => {
                this.showDismissed = e.target.checked;
                this.loadTranslations(true, this.showDismissed);
            });

            $(document).on('click', '#dismiss-selected', () => {
                this.dismissSelected();
            });

            $(document).on('click', '.dismiss-single-btn', (e) => {
                e.preventDefault();
                const stringId = $(e.currentTarget).data('id');
                const stringObj = this.strings.find(s => s.id === stringId);
                
                if (stringObj && window.DismissalModal) {
                    window.DismissalModal.open([stringObj], this.selectedLanguage, (dismissedStrings, type) => {
                        this.handleDismissalSuccess(dismissedStrings, type === 'global');
                    });
                } else {
                    console.error('DismissalModal not found or string not found');
                }
            });
            
            $(document).on('click', '.undismiss-single-btn', (e) => {
                e.preventDefault();
                const stringId = $(e.currentTarget).data('id');
                const stringObj = this.strings.find(s => s.id === stringId);
                
                if (stringObj) {
                    this.undismissString(stringId);
                }
            });
            
            $(document).on('click', '.stat-card.clickable', (e) => {
                e.preventDefault();
                const filterType = $(e.currentTarget).data('filter');

                $('#filter-status').val(filterType);

                this.filterType = filterType;

                $('#search-strings').val('');
                this.searchTerm = '';

                this.filterStrings();

                $('.stat-card').removeClass('active');
                $(e.currentTarget).addClass('active');

                $('html, body').animate({
                    scrollTop: $('.translation-controls').offset().top - 50
                }, 300);
            });
        }

        loadTranslations(preserveFilters = true, includeDismissed) {
            this.showLoading(true);

            const currentSearchTerm = preserveFilters ? this.searchTerm : '';
            const currentFilterType = preserveFilters ? this.filterType : 'all';
            const currentContextFilter = preserveFilters ? this.contextFilter : 'all';

            if (!walletUpTranslations || !walletUpTranslations.ajaxUrl || !walletUpTranslations.nonce) {
                console.error('WalletUp: Missing required AJAX configuration');
                this.showNotice('Configuration error. Please refresh the page.', 'error');
                this.showLoading(false);
                return;
            }
            
            const ajaxData = {
                action: 'wallet_up_load_all_translations',
                nonce: walletUpTranslations.nonce,
                language: this.selectedLanguage
            };

            if (includeDismissed !== undefined) {
                ajaxData.include_dismissed = includeDismissed ? 1 : 0;
            }
            
            $.ajax({
                url: walletUpTranslations.ajaxUrl,
                method: 'POST',
                data: ajaxData,
                success: (response) => {
                    if (response.success) {
                        this.strings = response.data.strings || [];

                        if (response.data.hasTranslationFile === false) {
                            this.showNotice(response.data.message || `Translation file for ${this.getLanguageName(this.selectedLanguage)} not found. Only Spanish and French translations are currently available.`, 'info');
                        }

                        if (preserveFilters) {
                            this.searchTerm = currentSearchTerm;
                            this.filterType = currentFilterType;
                            this.contextFilter = currentContextFilter;

                            $('#search-strings').val(currentSearchTerm);
                            $('#filter-status').val(currentFilterType);
                            $('#filter-context').val(currentContextFilter);
                        }

                        this.filterStrings();
                        this.updateStats();
                        this.updateUIForMode();
                        this.updateSelectedCount();

                        $('.stat-card').removeClass('active');
                        if (this.filterType === 'all') {
                            $('.stat-card[data-filter="all"]').addClass('active');
                        } else if (['translated', 'untranslated', 'modified'].includes(this.filterType)) {
                            $(`.stat-card[data-filter="${this.filterType}"]`).addClass('active');
                        }
                    }
                },
                error: (xhr) => {
                    console.error('Translation loading error:', xhr);
                    this.showNotice('Error loading translations. Please refresh and try again.', 'error');
                },
                complete: () => {
                    this.showLoading(false);
                    setTimeout(() => this.resetAllAutoTranslateButtons(), 200);
                }
            });
        }

        filterStrings() {
            this.filteredStrings = this.strings.filter(string => {
                if (!this.showDismissed && string.isDismissed) {
                    return false;
                }

                if (this.searchTerm) {
                    const searchMatch = 
                        string.original.toLowerCase().includes(this.searchTerm) ||
                        (string.translation && string.translation.toLowerCase().includes(this.searchTerm)) ||
                        this.getContextLabel(string.context).toLowerCase().includes(this.searchTerm);
                    
                    if (!searchMatch) return false;
                }

                switch (this.filterType) {
                    case 'translated':
                        if (!string.translation || string.translation.trim() === '') return false;
                        break;
                    case 'untranslated':
                        if (string.translation && string.translation.trim() !== '') return false;
                        break;
                    case 'modified':
                        if (!this.modifiedStrings.has(string.id)) return false;
                        break;
                    case 'reviewed':
                        if (!string.reviewed) return false;
                        break;
                    case 'recommended_dismiss':
                        if (!string.shouldRecommendDismissal) return false;
                        break;
                }

                if (this.contextFilter !== 'all' && string.context !== this.contextFilter) {
                    return false;
                }

                return true;
            });

            this.renderStrings();
            this.updateStats();

            setTimeout(() => this.resetAllAutoTranslateButtons(), 100);
        }

        renderStrings() {
            const $list = $('#translation-list');
            $list.empty();

            if (this.filteredStrings.length === 0) {
                $list.html('<div class="no-results">No strings found matching your criteria.</div>');
                return;
            }

            this.filteredStrings.forEach(string => {
                const hasTranslation = string.translation && string.translation.trim() !== '';
                const isModified = this.modifiedStrings.has(string.id);
                const isDismissed = string.isDismissed || false;
                const shouldRecommendDismissal = string.shouldRecommendDismissal || false;
                const dismissalReason = string.dismissalReason || '';
                
                const item = $(`
                    <div class="translation-item ${hasTranslation ? '' : 'untranslated'} ${isModified ? 'modified' : ''} ${isDismissed ? 'dismissed' : ''} ${shouldRecommendDismissal ? 'recommend-dismiss' : ''}" data-id="${string.id}">
                        <div class="column-check">
                            <input type="checkbox" class="string-checkbox" value="${string.id}" />
                        </div>
                        <div class="column-status">
                            <div class="status-indicator ${hasTranslation ? 'translated' : 'untranslated'}">
                                <span class="dashicons dashicons-${hasTranslation ? 'yes' : 'warning'}"></span>
                            </div>
                        </div>
                        <div class="column-original">
                            <div class="original-text">${this.escapeHtml(string.original)}</div>
                            ${string.occurrences > 1 ? `<div class="string-meta"><small>(Used ${string.occurrences} times)</small></div>` : ''}
                            ${shouldRecommendDismissal && !isDismissed ? `<div class="string-meta dismiss-recommendation"><small>⚠️ ${dismissalReason} - Recommended for dismissal</small></div>` : ''}
                            ${isDismissed ? `<div class="string-meta dismissed-label"><small>🚫 Dismissed</small></div>` : ''}
                        </div>
                        <div class="column-translation">
                            <div class="translation-input-wrapper">
                                <input type="text" 
                                       class="translation-input" 
                                       value="${this.escapeHtml(string.translation || '')}"
                                       data-id="${string.id}"
                                       placeholder="Enter translation..."
                                       ${isDismissed ? 'disabled' : ''}>
                                ${this.translationMode !== 'manual' && !hasTranslation && !isDismissed && this.selectedLanguage !== 'en_US' && this.selectedLanguage !== 'en' ? 
                                    '<button class="auto-translate-btn" title="Auto-translate"><span class="dashicons dashicons-admin-generic"></span></button>' : 
                                    ''}
                            </div>
                        </div>
                        <div class="column-context">
                            <span class="context-badge ${string.context}">${this.getContextLabel(string.context)}</span>
                        </div>
                        <div class="column-actions">
                            <div class="action-buttons">
                                <button class="action-btn" title="View details" onclick="enterpriseTranslation.showDetails('${string.id}')">
                                    <span class="dashicons dashicons-info"></span>
                                </button>
                                ${hasTranslation && !isDismissed ? `
                                    <button class="action-btn" title="Clear translation" onclick="enterpriseTranslation.clearTranslation('${string.id}')">
                                        <span class="dashicons dashicons-no"></span>
                                    </button>
                                ` : ''}
                                ${!isDismissed ? `
                                    <button class="action-btn dismiss-single-btn" title="Dismiss this string" data-id="${string.id}">
                                        <span class="dashicons dashicons-dismiss"></span>
                                    </button>
                                ` : `
                                    <button class="action-btn undismiss-single-btn" title="Restore this string" data-id="${string.id}">
                                        <span class="dashicons dashicons-undo"></span>
                                    </button>
                                `}
                            </div>
                        </div>
                    </div>
                `);

                $list.append(item);
            });
        }

        updateTranslation(stringId, value) {
            const string = this.strings.find(s => s.id === stringId);
            if (string) {
                string.translation = value;
                this.modifiedStrings.add(stringId);

                const $item = $(`.translation-item[data-id="${stringId}"]`);
                $item.addClass('modified');
                
                if (value.trim() !== '') {
                    $item.removeClass('untranslated');
                    $item.find('.status-indicator')
                        .removeClass('untranslated')
                        .addClass('translated')
                        .html('<span class="dashicons dashicons-yes"></span>');
                } else {
                    $item.addClass('untranslated');
                    $item.find('.status-indicator')
                        .removeClass('translated')
                        .addClass('untranslated')
                        .html('<span class="dashicons dashicons-warning"></span>');
                }
                
                this.updateStats();
            }
        }

        updateStats(stats = null) {
            if (!stats) {
                const total = this.filteredStrings.length;
                const translated = this.filteredStrings.filter(s => s.translation && s.translation.trim() !== '').length;
                const untranslated = total - translated;
                const modified = this.filteredStrings.filter(s => this.modifiedStrings.has(s.id)).length;
                const percentage = total > 0 ? Math.round((translated / total) * 100) : 0;
                
                stats = { total, translated, untranslated, modified, percentage };
            }
            
            $('#stat-total').text(stats.total);
            $('#stat-translated').text(stats.translated);
            $('#stat-untranslated').text(stats.untranslated);
            $('#stat-modified').text(stats.modified || this.modifiedStrings.size);
            $('#stat-percentage').text(stats.percentage + '%');
            $('#progress-fill').css('width', stats.percentage + '%');
        }

        updateSelectedCount() {
            const selected = $('.string-checkbox:checked').length;
            $('#selected-count').text(selected);
            $('#reset-count').text(selected);
            $('#dismiss-count').text(selected);
            $('#bulk-translate').toggle(selected > 0 && this.translationMode !== 'manual');
            $('#reset-translations').toggle(selected > 0);
            $('#dismiss-selected').toggle(selected > 0);
        }

        autoTranslateSingle(stringId) {
            const string = this.strings.find(s => s.id === stringId);
            if (!string) return;

            if (this.selectedLanguage === 'en_US' || this.selectedLanguage === 'en') {
                this.showNotice('Cannot translate to English. Please select a different target language.', 'error');
                return;
            }

            const $btn = $(`.translation-item[data-id="${stringId}"] .auto-translate-btn`);
            const $input = $(`.translation-input[data-id="${stringId}"]`);
            const originalBtnHtml = $btn.html();

            if ($btn.prop('disabled')) {
                return;
            }
            
            $btn.html('<span class="dashicons dashicons-update dashicons-spin"></span>')
                .prop('disabled', true);
            $input.css('opacity', '0.6');

            const requestId = 'auto_translate_' + Date.now();
            $btn.data('request-id', requestId);
            
            $.ajax({
                url: walletUpTranslations.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_auto_translate',
                    nonce: walletUpTranslations.nonce,
                    language: this.selectedLanguage,
                    strings: JSON.stringify([{
                        id: string.id,
                        text: string.original,
                        context: string.context
                    }]),
                    mode: this.translationMode
                },
                success: (response) => {
                    if ($btn.data('request-id') !== requestId) return;

                    if (response.success && response.data.translations.length > 0) {
                        const translation = response.data.translations[0];
                        this.updateTranslation(stringId, translation.translation);
                        $input.val(translation.translation)
                              .css('opacity', '1')
                              .addClass('translation-success');

                        setTimeout(() => {
                            $input.removeClass('translation-success');
                        }, 1000);

                        if (translation.suggestions && translation.suggestions.length > 0) {
                            this.showSuggestions(stringId, translation.suggestions);
                        }
                    } else {
                        const errorMsg = response.data?.message || 'No translation returned';
                        this.showNotice(errorMsg, 'error');
                    }
                },
                error: (xhr) => {
                    if ($btn.data('request-id') !== requestId) return;

                    const errorMsg = xhr.responseJSON?.data?.message || 'Translation failed';
                    this.showNotice(errorMsg, 'error');
                },
                complete: () => {
                    if ($btn.data('request-id') === requestId) {
                        $btn.html(originalBtnHtml).prop('disabled', false);
                        $input.css('opacity', '1');
                        $btn.removeData('request-id');
                    }
                }
            });
        }

        bulkTranslate() {
            const selectedIds = $('.string-checkbox:checked').map(function() {
                return $(this).val();
            }).get();
            
            if (selectedIds.length === 0) return;
            
            const stringsToTranslate = this.strings
                .filter(s => selectedIds.includes(s.id))
                .map(s => ({
                    id: s.id,
                    text: s.original,
                    context: s.context
                }));
            
            if (stringsToTranslate.length === 0) {
                this.showNotice('No strings selected', 'info');
                return;
            }
            
            this.showProgressDialog(stringsToTranslate.length);

            const batchSize = 10;
            const batches = [];
            for (let i = 0; i < stringsToTranslate.length; i += batchSize) {
                batches.push(stringsToTranslate.slice(i, i + batchSize));
            }
            
            let processedCount = 0;
            let successCount = 0;
            let failedCount = 0;
            
            const processBatch = (batchIndex) => {
                if (this.bulkTranslationCancelled) {
                    this.showNotice(`Translation cancelled: ${successCount} completed, ${failedCount} failed`, 'info');

                    this.resetAllAutoTranslateButtons();

                    return;
                }

                if (batchIndex >= batches.length) {
                    this.hideProgressDialog();
                    this.showNotice(`Translation complete: ${successCount} successful, ${failedCount} failed`,
                                   failedCount > 0 ? 'warning' : 'success', 5000);

                    this.resetAllAutoTranslateButtons();

                    return;
                }
                
                const currentBatch = batches[batchIndex];
                
                $.ajax({
                    url: walletUpTranslations.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'wallet_up_auto_translate',
                        nonce: walletUpTranslations.nonce,
                        language: this.selectedLanguage,
                        strings: JSON.stringify(currentBatch),
                        mode: this.translationMode
                    },
                    success: (response) => {
                        if (response.success) {
                            response.data.translations.forEach(translation => {
                                this.updateTranslation(translation.id, translation.translation);
                                $(`.translation-input[data-id="${translation.id}"]`).val(translation.translation);
                                successCount++;
                                processedCount++;
                                this.updateProgressDialog(processedCount, stringsToTranslate.length);
                                $('#progress-success').text(`${successCount} successful`);
                            });

                            const expectedCount = currentBatch.length;
                            const actualCount = response.data.translations.length;
                            if (actualCount < expectedCount) {
                                failedCount += (expectedCount - actualCount);
                                processedCount += (expectedCount - actualCount);
                                this.updateProgressDialog(processedCount, stringsToTranslate.length);
                                $('#progress-failed').text(`${failedCount} failed`);
                            }
                        } else {
                            failedCount += currentBatch.length;
                            processedCount += currentBatch.length;
                            this.updateProgressDialog(processedCount, stringsToTranslate.length);
                            $('#progress-failed').text(`${failedCount} failed`);
                        }
                    },
                    error: () => {
                        failedCount += currentBatch.length;
                        processedCount += currentBatch.length;
                        this.updateProgressDialog(processedCount, stringsToTranslate.length);
                        $('#progress-failed').text(`${failedCount} failed`);
                    },
                    complete: () => {
                        if (!this.bulkTranslationCancelled) {
                            setTimeout(() => processBatch(batchIndex + 1), 100);
                        }
                    }
                });
            };

            processBatch(0);
        }

        saveAllTranslations() {
            if (this.modifiedStrings.size === 0) {
                this.showNotice('No changes to save', 'info');
                return;
            }
            
            this.showLoading(true, 'Saving translations...');
            
            const translations = {};
            this.strings.forEach(string => {
                if (string.translation && string.translation.trim() !== '') {
                    translations[string.original] = string.translation;
                }
            });
            
            $.ajax({
                url: walletUpTranslations.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_save_translations',
                    nonce: walletUpTranslations.nonce,
                    language: this.selectedLanguage,
                    translations: JSON.stringify(translations)
                },
                success: (response) => {
                    if (response.success) {
                        this.modifiedStrings.clear();
                        $('.translation-item').removeClass('modified');
                        this.showNotice(`Saved ${Object.keys(translations).length} translations`, 'success');
                        this.updateStats();
                    } else {
                        this.showNotice(response.data.message || 'Save failed', 'error');
                    }
                },
                error: () => {
                    this.showNotice('Error saving translations', 'error');
                },
                complete: () => {
                    this.showLoading(false);
                }
            });
        }

        showScanDialog() {
            $('#scan-dialog').show();
        }
        
        closeScanDialog() {
            $('#scan-dialog').hide();
        }
        
        performScan() {
            const includeMinified = $('#scan-include-minified').is(':checked');
            this.closeScanDialog();
            this.scanForStrings(includeMinified);
        }
        
        scanForStrings(includeMinified = false) {
            this.showLoading(true, includeMinified ? 
                'Scanning for new strings (including minified files)...' : 
                'Scanning for new strings...');
            
            $.ajax({
                url: walletUpTranslations.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_scan_translations',
                    nonce: walletUpTranslations.nonce,
                    language: this.selectedLanguage,
                    include_minified: includeMinified ? 1 : 0
                },
                success: (response) => {
                    if (response.success) {
                        if (response.data.newCount > 0) {
                            const existingIds = new Set(this.strings.map(s => s.id));
                            const newStrings = response.data.newStrings.filter(s => !existingIds.has(s.id));
                            
                            this.strings = [...this.strings, ...newStrings];
                            this.filterStrings();
                            
                            let message = `Found ${newStrings.length} new strings`;
                            if (includeMinified && response.data.byContext) {
                                const minifiedCount = newStrings.filter(s => s.source === 'minified_react').length;
                                if (minifiedCount > 0) {
                                    message += ` (${minifiedCount} from minified files)`;
                                }
                            }
                            
                            this.showNotice(message, 'success');
                        } else {
                            this.showNotice('No new strings found', 'info');
                        }
                    }
                },
                error: () => {
                    this.showNotice('Scan failed', 'error');
                },
                complete: () => {
                    this.showLoading(false);
                }
            });
        }

        exportTranslations() {
            this.showExportFormatDialog();
        }

        showExportFormatDialog() {
            $('#export-format-dialog').remove();
            
            const dialog = $(`
                <div id="export-format-dialog" class="translation-dialog">
                    <div class="dialog-content">
                        <h2 style="margin-top: 0; color: #23282d; font-size: 20px;">
                            <span class="dashicons dashicons-download" style="color: #674FBF; margin-right: 8px;"></span>
                            Export Translations
                        </h2>
                        <p style="color: #50575e; margin: 15px 0;">
                            Choose export format for <strong style="color: #674FBF">${this.getLanguageName(this.selectedLanguage)}</strong> translations:
                        </p>
                        <div class="export-format-options" style="margin: 25px 0;">
                            <label class="export-format-option" style="display: block; margin: 12px 0; padding: 18px; border: 2px solid #e0e0e0; border-radius: 6px; cursor: pointer; transition: all 0.3s ease; background: #fafafa;">
                                <input type="radio" name="export-format" value="wup" checked style="margin-right: 12px; margin-top: 2px;">
                                <div style="display: inline-block; vertical-align: top;">
                                    <strong style="color: #23282d; font-size: 15px;">
                                        <span class="dashicons dashicons-lock" style="font-size: 16px; color: #674FBF; margin-right: 5px;"></span>
                                        .wup (Encrypted)
                                    </strong>
                                    <div style="margin-left: 28px; margin-top: 5px; color: #666; font-size: 13px; line-height: 1.5;">
                                        Site-specific encrypted format. Maximum security but can only be imported on this site.
                                    </div>
                                </div>
                            </label>
                            <label class="export-format-option" style="display: block; margin: 12px 0; padding: 18px; border: 2px solid #e0e0e0; border-radius: 6px; cursor: pointer; transition: all 0.3s ease; background: #fafafa;">
                                <input type="radio" name="export-format" value="json" style="margin-right: 12px; margin-top: 2px;">
                                <div style="display: inline-block; vertical-align: top;">
                                    <strong style="color: #23282d; font-size: 15px;">
                                        <span class="dashicons dashicons-admin-site-alt3" style="font-size: 16px; color: #4caf50; margin-right: 5px;"></span>
                                        .json (Portable)
                                    </strong>
                                    <div style="margin-left: 28px; margin-top: 5px; color: #666; font-size: 13px; line-height: 1.5;">
                                        Unencrypted JSON format. Can be imported on any site. Ideal for cross-site transfers.
                                    </div>
                                </div>
                            </label>
                        </div>
                        <div class="dialog-buttons" style="margin-top: 25px; text-align: right; padding-top: 20px; border-top: 1px solid #e0e0e0;">
                            <button class="button" onclick="enterpriseTranslation.closeExportDialog()" style="margin-right: 10px;">Cancel</button>
                            <button class="button button-primary" onclick="enterpriseTranslation.performExport()" style="background: linear-gradient(135deg, #674FBF 0%, #764ba2 100%); border: none; color: white; font-weight: 600; padding: 8px 20px;">
                                <span class="dashicons dashicons-download" style="margin-right: 5px; vertical-align: text-bottom;"></span>
                                Export
                            </button>
                        </div>
                    </div>
                </div>
            `);
            
            $('body').append(dialog);

            $(document).on('keydown.export-dialog', (e) => {
                if (e.keyCode === 27) {
                    this.closeExportDialog();
                    $(document).off('keydown.export-dialog');
                }
            });

            $('#export-format-dialog').on('click', (e) => {
                if ($(e.target).is('#export-format-dialog')) {
                    this.closeExportDialog();
                }
            });

            $('.export-format-option').hover(
                function() { 
                    $(this).css({
                        'border-color': '#674FBF',
                        'background': '#f7f5ff',
                        'transform': 'translateY(-2px)',
                        'box-shadow': '0 4px 12px rgba(103, 79, 191, 0.15)'
                    });
                },
                function() { 
                    if (!$(this).find('input').is(':checked')) {
                        $(this).css({
                            'border-color': '#e0e0e0',
                            'background': '#fafafa',
                            'transform': 'translateY(0)',
                            'box-shadow': 'none'
                        });
                    }
                }
            );

            $('input[name="export-format"]').change(function() {
                $('.export-format-option').css({
                    'border-color': '#e0e0e0',
                    'background': '#fafafa',
                    'transform': 'translateY(0)',
                    'box-shadow': 'none'
                });
                $(this).closest('.export-format-option').css({
                    'border-color': '#674FBF',
                    'background': '#f7f5ff',
                    'transform': 'translateY(-2px)',
                    'box-shadow': '0 4px 12px rgba(103, 79, 191, 0.15)'
                });
            });

            $('input[name="export-format"]:checked').closest('.export-format-option').css({
                'border-color': '#674FBF',
                'background': '#f7f5ff',
                'transform': 'translateY(-2px)',
                'box-shadow': '0 4px 12px rgba(103, 79, 191, 0.15)'
            });
        }
        
        closeExportDialog() {
            $('#export-format-dialog').remove();
            $(document).off('keydown.export-dialog');
        }
        
        performExport() {
            const format = $('input[name="export-format"]:checked').val();
            this.closeExportDialog();
            
            if (format === 'json') {
                this.exportAsJson();
            } else {
                this.exportAsWup();
            }
        }
        
        exportAsWup() {
            this.showLoading(true);
            
            $.ajax({
                url: walletUpTranslations.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_export_wup',
                    nonce: walletUpTranslations.nonce,
                    language: this.selectedLanguage
                },
                success: (response) => {
                    if (response.success) {
                        if (!response.data || !response.data.content) {
                            console.error('No content in response:', response);
                            this.showNotice('Export failed: No content received', 'error');
                            return;
                        }
                        
                        try {
                            const blob = new Blob([atob(response.data.content)], {
                                type: 'application/x-walletup-translation' 
                            });
                            const url = URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            
                            a.href = url;
                            a.download = `wallet-up-${this.selectedLanguage}-${new Date().toISOString().split('T')[0]}.wup`;
                            document.body.appendChild(a); // Add to DOM before clicking
                            a.click();
                            document.body.removeChild(a); // Remove after clicking
                            
                            URL.revokeObjectURL(url);
                            this.showNotice(`Exported ${response.data.count || 0} translations as encrypted .wup file (site-specific)`, 'success');
                        } catch (err) {
                            console.error('Export download error:', err);
                            this.showNotice('Export failed: ' + err.message, 'error');
                        }
                    } else {
                        this.showNotice(response.data?.message || 'Export failed', 'error');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Export AJAX error:', status, error, xhr.responseText);
                    this.showNotice('Export failed: ' + error, 'error');
                },
                complete: () => {
                    this.showLoading(false);
                }
            });
        }
        
        exportAsJson() {
            this.showLoading(true);

            const translations = {};
            this.strings.forEach(string => {
                if (string.translation) {
                    translations[string.original] = string.translation;
                }
            });
            
            if (Object.keys(translations).length === 0) {
                this.showNotice('No translations to export', 'warning');
                this.showLoading(false);
                return;
            }
            
            try {
                const jsonContent = JSON.stringify(translations, null, 2);
                const blob = new Blob([jsonContent], { type: 'application/json' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                
                a.href = url;
                a.download = `wallet-up-${this.selectedLanguage}-${new Date().toISOString().split('T')[0]}.json`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                
                URL.revokeObjectURL(url);
                this.showNotice(`Exported ${Object.keys(translations).length} translations as JSON (portable format)`, 'success');
            } catch (err) {
                console.error('JSON export error:', err);
                this.showNotice('Export failed: ' + err.message, 'error');
            }
            
            this.showLoading(false);
        }

        showImportDialog() {
            $('#import-dialog').show();
        }

        closeImportDialog() {
            $('#import-dialog').hide();
            $('#import-file').val('');
            this.importFile = null;
        }

        showCodeFileDialog() {
            $('#code-file-dialog').show();
        }
        
        closeCodeFileDialog() {
            $('#code-file-dialog').hide();
            $('#code-file-path').val('');
        }
        
        translateCodeFile() {
            const filePath = $('#code-file-path').val().trim();
            
            if (!filePath) {
                this.showNotice('Please enter a file path', 'error');
                return;
            }
            
            const preserveVariables = $('#code-preserve-variables').prop('checked');
            const handleMinified = $('#code-handle-minified').prop('checked');
            const fileName = filePath.split('/').pop();

            this.closeCodeFileDialog();

            this.showProgressDialog(1);
            $('#progress-dialog h3').html(`Translating Code File<br><small style="font-size: 14px; color: #666;">${this.escapeHtml(fileName)}</small>`);
            $('#progress-success, #progress-failed').parent().hide();
            $('#progress-current').parent().html(`<div style="color: #666; font-size: 14px;" id="progress-status">Analyzing file structure...</div>`);

            $('#progress-eta-text').text('Processing file...');

            const startTime = Date.now();

            let progressInterval = null;
            let currentProgress = 10;

            progressInterval = setInterval(() => {
                if (currentProgress < 90) {
                    currentProgress += Math.random() * 15;
                    currentProgress = Math.min(currentProgress, 90);
                    
                    const status = currentProgress < 30 ? 'Analyzing file structure...' :
                                  currentProgress < 60 ? 'Extracting translatable strings...' :
                                  currentProgress < 80 ? 'Translating content...' :
                                  'Finalizing translation...';

                    const percentage = Math.round(currentProgress);
                    $('.progress-fill-large').css('width', percentage + '%');
                    $('.progress-text').text(percentage + '%');
                    $('#progress-status').text(status);

                    const elapsed = Date.now() - startTime;
                    const estimatedTotal = elapsed / (currentProgress / 100);
                    const remaining = estimatedTotal - elapsed;
                    
                    if (remaining > 0) {
                        const seconds = Math.ceil(remaining / 1000);
                        const etaText = seconds < 60 
                            ? `${seconds} seconds remaining`
                            : `${Math.ceil(seconds / 60)} minutes remaining`;
                        $('#progress-eta-text').text(etaText);
                    } else {
                        $('#progress-eta-text').text('Almost done...');
                    }
                }
            }, 800);
            
            $.ajax({
                url: walletUpTranslations.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_translate_code_file',
                    nonce: walletUpTranslations.nonce,
                    file_path: filePath,
                    target_language: this.selectedLanguage,
                    preserve_variables: preserveVariables,
                    handle_minified: handleMinified
                },
                success: (response) => {
                    if (progressInterval) {
                        clearInterval(progressInterval);
                    }

                    if (response.success) {
                        $('.progress-fill-large').css('width', '100%');
                        $('.progress-text').text('100%');
                        $('#progress-status').text('Translation complete!');

                        let statsMessage = '';
                        if (response.data.stats) {
                            const stats = response.data.stats;
                            statsMessage = `<br><small>Translated ${stats.translated_count || 0} strings, preserved ${stats.preserved_count || 0} code elements</small>`;
                        }

                        setTimeout(() => {
                            this.hideProgressDialog();

                            this.showTranslationResultDialog(
                                fileName,
                                response.data.translated_content,
                                response.data.stats,
                                response.data.warnings
                            );
                        }, 1000);
                    } else {
                        this.hideProgressDialog();
                        const errorMsg = response.data?.message || response.data || 'Failed to translate file';
                        this.showNotice(errorMsg, 'error', 5000);
                    }
                },
                error: (xhr) => {
                    if (progressInterval) {
                        clearInterval(progressInterval);
                    }
                    
                    this.hideProgressDialog();
                    let errorMsg = 'Error translating file';
                    try {
                        const response = JSON.parse(xhr.responseText);
                        errorMsg = response.data?.message || response.data || xhr.statusText;
                    } catch (e) {
                        errorMsg = xhr.statusText || 'Unknown error occurred';
                    }
                    this.showNotice('Error: ' + errorMsg, 'error', 5000);
                }
            });
        }
        
        showTranslationResultDialog(fileName, translatedContent, stats, warnings) {
            $('#translation-result-dialog').remove();

            let statsHtml = '';
            if (stats) {
                statsHtml = `
                    <div style="background: #f0f8ff; padding: 15px; border-radius: 5px; margin: 15px 0;">
                        <h4 style="margin: 0 0 10px 0; color: #0073aa;">Translation Statistics</h4>
                        <ul style="margin: 0; padding-left: 20px;">
                            ${stats.translated_count ? `<li>Strings translated: <strong>${stats.translated_count}</strong></li>` : ''}
                            ${stats.preserved_count ? `<li>Code elements preserved: <strong>${stats.preserved_count}</strong></li>` : ''}
                            ${stats.file_size ? `<li>File size: <strong>${Math.round(stats.file_size / 1024)} KB</strong></li>` : ''}
                        </ul>
                    </div>
                `;
            }

            let warningsHtml = '';
            if (warnings && warnings.length > 0) {
                warningsHtml = `
                    <div style="background: #fff8e1; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid #ffb300;">
                        <h4 style="margin: 0 0 10px 0; color: #ff6f00;">Warnings</h4>
                        <ul style="margin: 0; padding-left: 20px;">
                            ${warnings.map(w => `<li>${this.escapeHtml(w)}</li>`).join('')}
                        </ul>
                    </div>
                `;
            }
            
            const nameParts = fileName.split('.');
            const extension = nameParts.pop();
            const baseName = nameParts.join('.');
            const newFileName = `${baseName}_${this.selectedLanguage}.${extension}`;
            
            const $dialog = $(`
                <div id="translation-result-dialog" class="translation-dialog wallet-up-translation-wrapper" style="z-index: 100002;">
                    <div class="dialog-content" style="max-width: 700px; max-height: 80vh; overflow-y: auto;">
                        <h3 style="color: #4caf50;">
                            <span class="dashicons dashicons-yes-alt" style="color: #4caf50; margin-right: 5px;"></span>
                            Translation Successful!
                        </h3>
                        
                        <p><strong>Original File:</strong> ${this.escapeHtml(fileName)}</p>
                        <p><strong>Translated File:</strong> ${this.escapeHtml(newFileName)}</p>
                        
                        ${statsHtml}
                        ${warningsHtml}
                        
                        <div style="margin: 20px 0;">
                            <label style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" id="preview-translation" />
                                <span>Preview translated content (first 500 characters)</span>
                            </label>
                            <div id="translation-preview" style="display: none; margin-top: 10px; padding: 10px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; font-family: monospace; font-size: 12px; max-height: 200px; overflow-y: auto; white-space: pre-wrap; word-wrap: break-word;">
                                ${this.escapeHtml(translatedContent.substring(0, 500))}${translatedContent.length > 500 ? '...' : ''}
                            </div>
                        </div>
                        
                        <div class="dialog-buttons" style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">
                            <button class="button button-secondary" id="cancel-translation-result">
                                <span class="dashicons dashicons-no-alt"></span>
                                Cancel
                            </button>
                            <button class="button button-primary" id="save-translated-file">
                                <span class="dashicons dashicons-download"></span>
                                Download Translated File
                            </button>
                        </div>
                    </div>
                </div>
            `);
            
            $('body').append($dialog);
            $dialog.fadeIn(300);
            
            $('#preview-translation').on('change', function() {
                $('#translation-preview').toggle(this.checked);
            });

            $('#cancel-translation-result').on('click', () => {
                $('#translation-result-dialog').fadeOut(300, function() { 
                    $(this).remove(); 
                });
            });

            $('#save-translated-file').on('click', () => {
                const blob = new Blob([translatedContent], { type: 'text/plain' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                
                a.href = url;
                a.download = newFileName;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);

                $('#translation-result-dialog').fadeOut(300, function() {
                    $(this).remove();
                });

                this.showNotice(`Successfully downloaded ${newFileName}`, 'success', 4000);
            });
        }
        
        processImport() {
            if (!this.importFile) {
                this.showNotice('Please select a file', 'error');
                return;
            }
            
            const fileExtension = this.importFile.name.split('.').pop().toLowerCase();
            
            if (fileExtension === 'wup') {
                const formData = new FormData();
                formData.append('action', 'wallet_up_import_wup');
                formData.append('nonce', walletUpTranslations.nonce);
                formData.append('language', this.selectedLanguage);
                formData.append('wup_file', this.importFile);
                
                this.showLoading(true);
                
                $.ajax({
                    url: walletUpTranslations.ajaxUrl,
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: (response) => {
                        if (response.success) {
                            this.loadTranslations();
                            this.closeImportDialog();
                            this.showNotice(`Imported ${response.data.count} translations from encrypted .wup file`, 'success');
                        } else {
                            this.showNotice(response.data.message || 'Import failed', 'error');
                        }
                    },
                    error: () => {
                        this.showNotice('Import failed', 'error');
                    },
                    complete: () => {
                        this.showLoading(false);
                    }
                });
            } else if (fileExtension === 'json') {
                const reader = new FileReader();
                reader.onload = (e) => {
                    try {
                        const imported = JSON.parse(e.target.result);

                        let updated = 0;
                        this.strings.forEach(string => {
                            if (imported[string.original]) {
                                string.translation = imported[string.original];
                                this.modifiedStrings.add(string.id);
                                updated++;
                            }
                        });
                        
                        this.filterStrings();
                        this.closeImportDialog();
                        this.showNotice(`Imported ${updated} translations from JSON file`, 'success');
                        
                    } catch (error) {
                        this.showNotice('Invalid JSON file format', 'error');
                    }
                };
                reader.readAsText(this.importFile);
            } else {
                this.showNotice('Invalid file type. Only .wup and .json files are supported.', 'error');
            }
        }

        showDetails(stringId) {
            const string = this.strings.find(s => s.id === stringId);
            if (!string) return;

            $('#details-dialog').remove();

            const $dialog = $(`
                <div class="translation-dialog" id="details-dialog">
                    <div class="dialog-content" style="position: relative;">
                        <button class="dialog-close-x" id="close-x-btn" style="position: absolute; top: 10px; right: 10px; background: none; border: none; font-size: 24px; cursor: pointer; color: #666; padding: 5px; line-height: 1;" title="Close">&times;</button>
                        <h3>Translation Details</h3>
                        <div style="margin: 20px 0;">
                            <p><strong>Original Text:</strong><br>${this.escapeHtml(string.original)}</p>
                            <p><strong>Current Translation:</strong><br>${this.escapeHtml(string.translation || 'Not translated yet')}</p>
                            <p><strong>Context:</strong> ${this.getContextLabel(string.context)}</p>
                            <p><strong>Usage Count:</strong> ${string.occurrences} ${string.occurrences === 1 ? 'time' : 'times'}</p>
                            <p><strong>Priority:</strong> ${string.priority === 3 ? 'High' : string.priority === 2 ? 'Medium' : 'Low'}</p>
                            
                            <details style="margin-top: 20px;">
                                <summary style="cursor: pointer; font-weight: bold;">Developer Info</summary>
                                <div style="margin-top: 10px; padding: 10px; background: #f5f5f5; border-radius: 4px;">
                                    <p><strong>File:</strong> ${string.file}</p>
                                    <p><strong>Line:</strong> ${string.line}</p>
                                    <p><strong>Function:</strong> ${string.function || 'N/A'}</p>
                                </div>
                            </details>
                        </div>
                        <div class="dialog-buttons">
                            <button class="button button-primary" id="close-details-btn">Close</button>
                        </div>
                    </div>
                </div>
            `);
            
            $('body').append($dialog);
            $dialog.show();

            $('#close-details-btn, #close-x-btn').on('click', function() {
                $('#details-dialog').remove();
                $(document).off('keydown.details-dialog');
            });

            $dialog.on('click', function(e) {
                if (e.target === this) {
                    $(this).remove();
                }
            });

            $(document).on('keydown.details-dialog', function(e) {
                if (e.keyCode === 27) {
                    $('#details-dialog').remove();
                    $(document).off('keydown.details-dialog');
                }
            });
        }

        clearTranslation(stringId) {
            this.updateTranslation(stringId, '');
            $(`.translation-input[data-id="${stringId}"]`).val('');
        }

        updateUIForMode() {
            const showAutoButtons = this.translationMode !== 'manual';
            $('.auto-translate-btn').toggle(showAutoButtons);
            $('#bulk-translate').toggle(showAutoButtons && $('.string-checkbox:checked').length > 0);
        }
        
        resetAllAutoTranslateButtons() {
            $('.auto-translate-btn').each(function() {
                const $btn = $(this);
                if ($btn.find('.dashicons-spin').length > 0 || $btn.prop('disabled')) {
                    $btn.html('<span class="dashicons dashicons-admin-generic"></span>')
                        .prop('disabled', false)
                        .removeData('request-id');
                }
            });
            $('.translation-input').css('opacity', '1');
        }

        showLoading(show, text = 'Processing...') {
            if (show) {
                $('#loading-overlay').show().find('.loading-text').text(text);
            } else {
                $('#loading-overlay').hide();
            }
        }
        
        showProgressDialog(totalItems) {
            $('#progress-dialog').remove();
            
            const $dialog = $(`
                <div id="progress-dialog" class="translation-dialog wallet-up-translation-wrapper" style="z-index: 100002;">
                    <div class="dialog-content" style="max-width: 600px;">
                        <h3>Translating Strings</h3>
                        <div class="progress-container" style="margin: 20px 0;">
                            <div class="progress-bar-large" style="height: 30px; background: #f0f0f0; border-radius: 15px; overflow: hidden; position: relative;">
                                <div class="progress-fill-large" style="height: 100%; background: linear-gradient(90deg, #674FBF 0%, #9c27b0 100%); width: 0%; transition: width 0.3s ease; position: relative;">
                                    <div class="progress-text" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; font-weight: bold; font-size: 14px; white-space: nowrap;">0%</div>
                                </div>
                            </div>
                            <div class="progress-details" style="margin-top: 15px; text-align: center;">
                                <div class="progress-numbers" style="font-size: 18px; font-weight: 600; color: #23282d;">
                                    <span id="progress-current">0</span> / <span id="progress-total">${totalItems}</span>
                                </div>
                                <div class="progress-status" style="margin-top: 10px; color: #666;">
                                    <span id="progress-success" style="color: #4caf50;">0 successful</span> • 
                                    <span id="progress-failed" style="color: #f44336;">0 failed</span>
                                </div>
                                <div class="progress-eta" style="margin-top: 10px; font-size: 14px; color: #999;">
                                    <span id="progress-eta-text">Calculating time remaining...</span>
                                </div>
                            </div>
                        </div>
                        <div class="dialog-buttons" style="display: flex; justify-content: center; margin-top: 30px;">
                            <button class="button button-secondary" id="cancel-bulk-translation">
                                <span class="dashicons dashicons-no-alt"></span>
                                Cancel Translation
                            </button>
                        </div>
                    </div>
                </div>
            `);
            
            $('body').append($dialog);
            $dialog.show();

            $('#cancel-bulk-translation').on('click', () => {
                this.cancelBulkTranslation();
            });

            this.bulkTranslationStartTime = Date.now();
            this.bulkTranslationCancelled = false;
        }
        
        updateProgressDialog(current, total) {
            if (!$('#progress-dialog').length) return;
            
            const percentage = Math.round((current / total) * 100);
            const $progressFill = $('.progress-fill-large');
            const $progressText = $('.progress-text');

            $progressFill.css('width', percentage + '%');
            $progressText.text(percentage + '%');

            $('#progress-current').text(current);

            const elapsed = Date.now() - this.bulkTranslationStartTime;
            const itemsPerMs = current / elapsed;
            const remaining = total - current;
            const eta = remaining / itemsPerMs;
            
            if (current > 0) {
                const etaSeconds = Math.ceil(eta / 1000);
                const etaText = etaSeconds < 60 
                    ? `${etaSeconds} seconds remaining`
                    : `${Math.ceil(etaSeconds / 60)} minutes remaining`;
                $('#progress-eta-text').text(etaText);
            }
        }
        
        hideProgressDialog() {
            $('#progress-dialog').fadeOut(300, function() {
                $(this).remove();
            });
        }
        
        cancelBulkTranslation() {
            this.bulkTranslationCancelled = true;
            this.hideProgressDialog();
            this.showNotice('Bulk translation cancelled', 'info');
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

        escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            
            return text ? text.replace(/[&<>"']/g, m => map[m]) : '';
        }
        
        getContextLabel(context) {
            const contextLabels = {
                'general': 'General',
                'admin': 'Admin Panel',
                'payment': 'Payment Flow',
                'donation': 'Donation Form',
                'error': 'Error Message',
                'form': 'Form Field',
                'button': 'Button Label',
                'navigation': 'Navigation',
                'email': 'Email Template',
                'success': 'Success Message',
                'notification': 'Notification'
            };
            
            return contextLabels[context] || context.charAt(0).toUpperCase() + context.slice(1);
        }
        
        resetAllTranslations() {
            if (!confirm('Are you sure you want to reset ALL translations for ' + this.selectedLanguage + '? This will delete all translations and cannot be undone.')) {
                return;
            }
            
            this.showLoading(true);
            
            $.ajax({
                url: walletUpTranslations.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_reset_translations',
                    nonce: walletUpTranslations.nonce,
                    language: this.selectedLanguage,
                    type: 'all'
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice('All translations have been reset successfully', 'success');
                        this.loadTranslations(false);
                    } else {
                        this.showNotice(response.data || 'Failed to reset translations', 'error');
                    }
                },
                error: () => {
                    this.showNotice('Error resetting translations', 'error');
                },
                complete: () => {
                    this.showLoading(false);
                }
            });
        }
        
        resetSelectedTranslations() {
            const selected = $('.string-checkbox:checked').map(function() {
                return $(this).data('id');
            }).get();
            
            if (selected.length === 0) {
                this.showNotice('Please select translations to reset', 'warning');
                return;
            }
            
            if (!confirm('Are you sure you want to reset ' + selected.length + ' selected translation(s)? This cannot be undone.')) {
                return;
            }
            
            this.showLoading(true);
            
            $.ajax({
                url: walletUpTranslations.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_reset_translations',
                    nonce: walletUpTranslations.nonce,
                    language: this.selectedLanguage,
                    type: 'selected',
                    strings: selected
                },
                success: (response) => {
                    if (response.success) {
                        selected.forEach(id => {
                            const $input = $(`.translation-input[data-id="${id}"]`);
                            $input.val('').removeClass('has-translation modified');
                            
                            const string = this.strings.find(s => s.id === id);
                            if (string) {
                                string.translation = '';
                                string.status = 'untranslated';
                            }
                        });
                        
                        $('.string-checkbox').prop('checked', false);
                        $('#select-all').prop('checked', false);
                        
                        this.updateStats();
                        this.updateSelectedCount();
                        this.showNotice(selected.length + ' translation(s) reset successfully', 'success');
                    } else {
                        this.showNotice(response.data || 'Failed to reset translations', 'error');
                    }
                },
                error: () => {
                    this.showNotice('Error resetting translations', 'error');
                },
                complete: () => {
                    this.showLoading(false);
                }
            });
        }
        
        dismissString(stringId) {
            const string = this.strings.find(s => s.id === stringId);
            if (!string) return;
            
            const applyGlobally = confirm(`Dismiss "${string.original}"?\n\nClick OK to dismiss for ${this.getLanguageName(this.selectedLanguage)} only.\nClick Cancel to review options.`);
            
            if (applyGlobally === false) {
                const globalOption = confirm(`Would you like to dismiss this string globally for all languages?\n\nClick OK for global dismissal.\nClick Cancel for current language only.`);
                this.performDismissal([string.original], globalOption);
            } else {
                this.performDismissal([string.original], false);
            }
        }
        
        restoreString(stringId) {
            const string = this.strings.find(s => s.id === stringId);
            if (!string) return;
            
            this.showLoading(true);
            
            $.ajax({
                url: walletUpTranslations.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_undismiss_string',
                    nonce: walletUpTranslations.nonce,
                    string: string.original,
                    language: this.selectedLanguage
                },
                success: (response) => {
                    if (response.success) {
                        string.isDismissed = false;
                        this.filterStrings();
                        this.showNotice('String restored successfully', 'success');
                    } else {
                        this.showNotice(response.data.message || 'Failed to restore string', 'error');
                    }
                },
                error: () => {
                    this.showNotice('Error restoring string', 'error');
                },
                complete: () => {
                    this.showLoading(false);
                }
            });
        }
        
        undismissString(stringId) {
            this.restoreString(stringId);
        }
        
        dismissSelected() {
            const selected = $('.string-checkbox:checked').map(function() {
                return $(this).val();
            }).get();
            
            if (selected.length === 0) {
                this.showNotice('Please select strings to dismiss', 'warning');
                return;
            }
            
            const selectedStrings = selected.map(id => this.strings.find(s => s.id === id)).filter(s => s);

            if (typeof window.DismissalModal === 'undefined') {
                const strings = selectedStrings.map(s => s.original);
                const globalOption = confirm(`Dismiss ${selected.length} selected strings?\n\nClick OK to dismiss globally (all languages).\nClick Cancel to dismiss for ${this.getLanguageName(this.selectedLanguage)} only.`);
                this.performDismissal(strings, globalOption);
                return;
            }
            
            window.DismissalModal.open(
                selectedStrings,
                this.selectedLanguage,
                (dismissedStrings, type) => {
                    this.handleDismissalSuccess(dismissedStrings, type === 'global');
                }
            );
        }
        
        handleDismissalSuccess(dismissedStrings, isGlobal) {
            dismissedStrings.forEach(stringObj => {
                const string = this.strings.find(s => s.id === stringObj.id);
                if (string) {
                    string.isDismissed = true;
                }
            });

            $('.string-checkbox').prop('checked', false);
            $('#select-all').prop('checked', false);
            this.selectedStrings.clear();

            this.filterStrings();
            this.updateStats();
            this.updateSelectedCount();

            const message = `Successfully dismissed ${dismissedStrings.length} string(s) ${isGlobal ? 'globally' : 'for ' + this.getLanguageName(this.selectedLanguage)}`;
            this.showNotice(message, 'success');
        }
        
        performDismissal(strings, applyGlobally) {
            this.showLoading(true);
            
            $.ajax({
                url: walletUpTranslations.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wallet_up_dismiss_strings',
                    nonce: walletUpTranslations.nonce,
                    strings: strings,
                    language: this.selectedLanguage,
                    apply_globally: applyGlobally
                },
                success: (response) => {
                    if (response.success) {
                        strings.forEach(original => {
                            const string = this.strings.find(s => s.original === original);
                            if (string) {
                                string.isDismissed = true;
                            }
                        });

                        $('.string-checkbox').prop('checked', false);
                        $('#select-all').prop('checked', false);
                        
                        this.filterStrings();
                        this.updateSelectedCount();
                        this.showNotice(response.data.message || 'Strings dismissed successfully', 'success');
                    } else {
                        this.showNotice(response.data.message || 'Failed to dismiss strings', 'error');
                    }
                },
                error: () => {
                    this.showNotice('Error dismissing strings', 'error');
                },
                complete: () => {
                    this.showLoading(false);
                }
            });
        }
    }

    function debounce(func, wait) {
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

    $(document).ready(() => {
        if ($('#wallet-up-translation-app').length) {
            window.enterpriseTranslation = new EnterpriseTranslationManager();
        }
    });

})(jQuery);