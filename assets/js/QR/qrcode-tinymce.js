(function() {
    tinymce.PluginManager.add('walletup_qrcode', function(editor, url) {
        editor.addButton('walletup_qrcode', {
            title: 'Insert QR Code',
            image: walletupQRcodeConfig.pluginUrl + 'assets/images/qr-icon.png',
            onclick: function() {
                editor.windowManager.open({
                    title: 'Insert QR Code Shortcode',
                    body: [
                        {
                            type: 'textbox',
                            name: 'data',
                            label: 'Data to Encode',
                            value: 'https://example.com',
                            tooltip: 'Enter URL or text to encode in QR code'
                        },
                        {
                            type: 'listbox',
                            name: 'size',
                            label: 'Size',
                            values: getSelectOptions(walletupQRcodeConfig.supportedSizes),
                            value: 'medium'
                        },
                        {
                            type: 'listbox',
                            name: 'style',
                            label: 'Style',
                            values: getSelectOptions(walletupQRcodeConfig.supportedStyles),
                            value: 'rounded'
                        },
                        {
                            type: 'colorpicker',
                            name: 'color',
                            label: 'QR Color',
                            value: '#674FBF'
                        },
                        {
                            type: 'colorpicker',
                            name: 'background',
                            label: 'Background',
                            value: '#F4F4FF'
                        },
                        {
                            type: 'listbox',
                            name: 'error_level',
                            label: 'Error Correction',
                            values: getSelectOptions(walletupQRcodeConfig.errorLevels),
                            value: 'high'
                        },
                        {
                            type: 'textbox',
                            name: 'caption',
                            label: 'Caption (optional)',
                            value: ''
                        },
                        {
                            type: 'checkbox',
                            name: 'downloadable',
                            label: 'Allow Download',
                            checked: true
                        },
                        {
                            type: 'checkbox',
                            name: 'generator',
                            label: 'Show Generator Controls',
                            checked: false
                        }
                    ],
                    onsubmit: function(e) {
                        let shortcode = '[walletup_qrcode';

                        shortcode += ' data="' + e.data.data + '"';
                        shortcode += ' size="' + e.data.size + '"';
                        shortcode += ' style="' + e.data.style + '"';
                        shortcode += ' color="' + e.data.color + '"';
                        shortcode += ' background="' + e.data.background + '"';
                        shortcode += ' error_level="' + e.data.error_level + '"';

                        if (e.data.caption) {
                            shortcode += ' caption="' + e.data.caption + '"';
                        }

                        shortcode += ' downloadable="' + (e.data.downloadable ? 'true' : 'false') + '"';
                        shortcode += ' generator="' + (e.data.generator ? 'true' : 'false') + '"';

                        shortcode += ']';

                        editor.insertContent(shortcode);
                    }
                });
            }
        });

        function getSelectOptions(obj) {
            return Object.keys(obj).map(function(key) {
                return {
                    text: obj[key].name || obj[key],
                    value: key
                };
            });
        }
    });
})();