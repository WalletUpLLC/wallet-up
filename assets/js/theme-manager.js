(function($) {
    $(document).ready(function() {
        const container = document.getElementById('wallet-up-react-admin');
        if (container) {
            const ThemeManager = {
                initialize: function() {
                    const [themeEnabled, setThemeEnabled] = React.useState(false);
                    const [loading, setLoading] = React.useState(true);
                    const [error, setError] = React.useState(null);

                    const fetchThemeState = async () => {
                        try {
                            const response = await fetch(window.walletUpData.ajaxUrl, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: new URLSearchParams({
                                    action: 'wallet_up_get_theme_state',
                                    nonce: window.walletUpData.nonce
                                })
                            });
                            const data = await response.json();
                            if (data.success) {
                                setThemeEnabled(data.enabled);
                            } else {
                                throw new Error('Failed to fetch theme state');
                            }
                        } catch (error) {
                            console.error('Error fetching theme state:', error);
                            setError('Error fetching theme state');
                        } finally {
                            setLoading(false);
                        }
                    };

                    const toggleTheme = async (enabled) => {
                        try {
                            const response = await fetch(window.walletUpData.ajaxUrl, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: new URLSearchParams({
                                    action: 'wallet_up_toggle_admin_theme',
                                    nonce: window.walletUpData.nonce,
                                    enabled: enabled ? 'yes' : 'no'
                                })
                            });
                            const data = await response.json();
                            if (data.success) {
                                setThemeEnabled(enabled);
                                await fetchThemeState();
                            } else {
                                throw new Error('Failed to toggle theme');
                            }
                        } catch (error) {
                            console.error('Error toggling theme:', error);
                            setError('Error toggling theme');
                        }
                    };

                    React.useEffect(() => {
                        fetchThemeState();
                    }, []);

                    if (loading) return React.createElement('div', null, 'Loading...');
                    if (error) return React.createElement('div', null, 'Error: ', error);

                    return React.createElement(
                        'div',
                        { className: 'flex items-center justify-between' },
                        React.createElement('label', { htmlFor: 'theme-toggle' }, 'Enable Custom Admin Theme'),
                        React.createElement('button', {
                            id: 'theme-toggle',
                            onClick: () => toggleTheme(!themeEnabled),
                            style: {backgroundColor: themeEnabled ? 'green' : 'grey'}
                        }, themeEnabled ? 'ON' : 'OFF')
                    );
                }
            };

            const reactVersion = React.version ? parseInt(React.version.split('.')[0], 10) : 0;

            if (reactVersion >= 18 && window.ReactDOM.createRoot) {
                const root = window.ReactDOM.createRoot(container);
                root.render(React.createElement(ThemeManager.initialize));
            } else {
                window.ReactDOM.render(React.createElement(ThemeManager.initialize), container);
            }
        }
    });
})(jQuery);