/**
 * WordPress Cognito Auth Admin JavaScript
 *
 * @package WP_Cognito_Auth
 */

(function($) {
    'use strict';

    // Initialize when document is ready
    $(document).ready(function() {
        initCognitoAdmin();
    });

    function initCognitoAdmin() {
        // Test connection functionality
        $('#test-cognito-connection').on('click', testCognitoConnection);

        // Test sync connection
        $('#test-sync-connection').on('click', testSyncConnection);

        // Bulk sync functionality
        $('.cognito-bulk-sync').on('click', handleBulkSync);

        // User sync functionality
        $('.cognito-user-sync').on('click', handleUserSync);

        // Clear logs functionality
        $('.cognito-clear-logs').on('click', handleClearLogs);

        // Real-time sync progress
        if ($('.sync-progress').length) {
            pollSyncProgress();
        }

        // Auto-refresh logs
        if ($('.logs-auto-refresh').is(':checked')) {
            startLogAutoRefresh();
        }

        $('.logs-auto-refresh').on('change', function() {
            if ($(this).is(':checked')) {
                startLogAutoRefresh();
            } else {
                stopLogAutoRefresh();
            }
        });

        // Feature dependency warnings
        checkFeatureDependencies();
        $('input[name^="wp_cognito_features"]').on('change', checkFeatureDependencies);

        // Dynamic form validation
        validateCognitoSettings();
        $('.cognito-setting').on('input', validateCognitoSettings);

        // Color picker functionality for login button
        initColorPicker();
    }

    /**
     * Test Cognito authentication connection
     */
    function testCognitoConnection() {
        const $button = $(this);
        const $results = $('#test-results');

        $button.prop('disabled', true).text('Testing...');
        $results.removeClass('success error').addClass('loading').text('Testing connection...').show();

        $.ajax({
            url: wpCognitoAuth.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cognito_test_connection',
                nonce: wpCognitoAuth.nonce
            },
            success: function(response) {
                if (response.success) {
                    $results.removeClass('loading error').addClass('success').text(response.data);
                } else {
                    $results.removeClass('loading success').addClass('error').text(response.data);
                }
            },
            error: function(xhr, status, error) {
                $results.removeClass('loading success').addClass('error').text('Connection failed: ' + error);
            },
            complete: function() {
                $button.prop('disabled', false).text('Test Cognito Connection');
                setTimeout(function() {
                    $results.fadeOut();
                }, 5000);
            }
        });
    }

    /**
     * Test sync API connection
     */
    function testSyncConnection() {
        const $button = $(this);
        const $results = $('#sync-test-results');

        $button.prop('disabled', true).text('Testing...');
        $results.removeClass('success error').addClass('loading').text('Testing sync connection...').show();

        $.ajax({
            url: wpCognitoAuth.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cognito_test_sync_connection',
                nonce: wpCognitoAuth.nonce
            },
            success: function(response) {
                if (response.success) {
                    $results.removeClass('loading error').addClass('success').text(response.data);
                } else {
                    $results.removeClass('loading success').addClass('error').text(response.data);
                }
            },
            error: function(xhr, status, error) {
                let errorMessage = 'Connection failed: ' + error;
                if (xhr.status === 403) {
                    errorMessage = 'WordPress AJAX access denied (403). This might be due to: security plugin restrictions, WP Engine security settings, or nonce verification failure. Try the form-based test below.';
                } else if (xhr.status === 0) {
                    errorMessage = 'Network error or request blocked. Check browser console for details.';
                }

                $results.removeClass('loading success').addClass('error').text(errorMessage);
            },
            complete: function() {
                $button.prop('disabled', false).text('Test Sync API Connection (AJAX)');
                setTimeout(function() {
                    $results.fadeOut();
                }, 5000);
            }
        });
    }

    /**
     * Handle bulk sync operations
     */
    function handleBulkSync() {
        const $button = $(this);
        const syncType = $button.data('sync-type');
        const $progress = $('.sync-progress-' + syncType);
        const $results = $('.sync-results-' + syncType);

        if (!confirm('Are you sure you want to start a bulk sync? This may take several minutes.')) {
            return;
        }

        $button.prop('disabled', true).text('Syncing...');
        $progress.show();
        $results.hide();

        startBulkSync(syncType, 0);
    }

    /**
     * Start bulk sync process
     */
    function startBulkSync(syncType, offset) {
        $.ajax({
            url: wpCognitoAuth.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cognito_bulk_sync',
                sync_type: syncType,
                offset: offset,
                nonce: wpCognitoAuth.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateSyncProgress(syncType, response.data);

                    if (response.data.continue) {
                        // Continue with next batch
                        startBulkSync(syncType, response.data.next_offset);
                    } else {
                        // Sync complete
                        completeBulkSync(syncType, response.data);
                    }
                } else {
                    showSyncError(syncType, response.data);
                }
            },
            error: function(xhr, status, error) {
                showSyncError(syncType, 'Sync failed: ' + error);
            }
        });
    }

    /**
     * Update sync progress display
     */
    function updateSyncProgress(syncType, data) {
        const $progress = $('.sync-progress-' + syncType);
        const $bar = $progress.find('.progress');
        const $text = $progress.find('.progress-text');

        if (data.percentage !== undefined) {
            $bar.css('width', data.percentage + '%');
            $text.text(`${data.processed} of ${data.total} processed (${data.percentage}%)`);
        }
    }

    /**
     * Complete bulk sync process
     */
    function completeBulkSync(syncType, data) {
        const $button = $('.cognito-bulk-sync[data-sync-type="' + syncType + '"]');
        const $progress = $('.sync-progress-' + syncType);
        const $results = $('.sync-results-' + syncType);

        $button.prop('disabled', false).text('Start ' + syncType.charAt(0).toUpperCase() + syncType.slice(1) + ' Sync');
        $progress.hide();

        // Show results
        let resultsHtml = '<h3>Sync Complete</h3>';
        resultsHtml += '<table class="widefat"><tbody>';
        resultsHtml += `<tr><th>Total Processed</th><td>${data.total || 0}</td></tr>`;
        resultsHtml += `<tr><th>Successful</th><td>${data.successful || 0}</td></tr>`;
        resultsHtml += `<tr><th>Failed</th><td>${data.failed || 0}</td></tr>`;
        resultsHtml += '</tbody></table>';

        if (data.errors && data.errors.length) {
            resultsHtml += '<h4>Errors:</h4><ul>';
            data.errors.forEach(function(error) {
                resultsHtml += '<li>' + escapeHtml(error) + '</li>';
            });
            resultsHtml += '</ul>';
        }

        $results.html(resultsHtml).show();
    }

    /**
     * Show sync error
     */
    function showSyncError(syncType, error) {
        const $button = $('.cognito-bulk-sync[data-sync-type="' + syncType + '"]');
        const $progress = $('.sync-progress-' + syncType);
        const $results = $('.sync-results-' + syncType);

        $button.prop('disabled', false).text('Start ' + syncType.charAt(0).toUpperCase() + syncType.slice(1) + ' Sync');
        $progress.hide();

        $results.html('<div class="notice notice-error"><p>Sync failed: ' + escapeHtml(error) + '</p></div>').show();
    }

    /**
     * Handle individual user sync
     */
    function handleUserSync() {
        const $button = $(this);
        const userId = $button.data('user-id');
        const $result = $('#sync-result-' + userId);

        $button.prop('disabled', true).text('Syncing...');
        $result.html('<em>Syncing user...</em>');

        $.ajax({
            url: wpCognitoAuth.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cognito_sync_user',
                user_id: userId,
                nonce: wpCognitoAuth.nonce
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<span style="color: green;">✓ ' + response.data + '</span>');
                } else {
                    $result.html('<span style="color: red;">✗ ' + response.data + '</span>');
                }
            },
            error: function(xhr, status, error) {
                $result.html('<span style="color: red;">✗ Sync failed: ' + error + '</span>');
            },
            complete: function() {
                $button.prop('disabled', false).text('Sync Now');
                setTimeout(function() {
                    $result.html('');
                }, 5000);
            }
        });
    }

    /**
     * Handle clear logs
     */
    function handleClearLogs() {
        if (!confirm('Are you sure you want to clear all logs?')) {
            return;
        }

        const $button = $(this);
        $button.prop('disabled', true).text('Clearing...');

        $.ajax({
            url: wpCognitoAuth.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cognito_clear_logs',
                nonce: wpCognitoAuth.nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Failed to clear logs: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                alert('Failed to clear logs: ' + error);
            },
            complete: function() {
                $button.prop('disabled', false).text('Clear Logs');
            }
        });
    }

    /**
     * Poll sync progress for real-time updates
     */
    function pollSyncProgress() {
        setInterval(function() {
            $.ajax({
                url: wpCognitoAuth.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cognito_get_sync_progress',
                    nonce: wpCognitoAuth.nonce
                },
                success: function(response) {
                    if (response.success && response.data.active) {
                        updateSyncProgress('current', response.data);
                    }
                }
            });
        }, 2000);
    }

    /**
     * Start auto-refreshing logs
     */
    let logRefreshInterval;

    function startLogAutoRefresh() {
        logRefreshInterval = setInterval(function() {
            refreshLogs();
        }, 5000);
    }

    /**
     * Stop auto-refreshing logs
     */
    function stopLogAutoRefresh() {
        if (logRefreshInterval) {
            clearInterval(logRefreshInterval);
        }
    }

    /**
     * Refresh logs table
     */
    function refreshLogs() {
        const $logsContainer = $('.logs-container');

        $.ajax({
            url: wpCognitoAuth.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cognito_get_logs',
                nonce: wpCognitoAuth.nonce
            },
            success: function(response) {
                if (response.success) {
                    $logsContainer.html(response.data);
                }
            }
        });
    }

    /**
     * Check feature dependencies and show warnings
     */
    function checkFeatureDependencies() {
        const authEnabled = $('input[name="wp_cognito_features[authentication]"]').is(':checked');
        const syncEnabled = $('input[name="wp_cognito_features[sync]"]').is(':checked');
        const groupSyncEnabled = $('input[name="wp_cognito_features[group_sync]"]').is(':checked');

        // Clear existing warnings
        $('.dependency-warning').remove();

        // Group sync requires sync to be enabled
        if (groupSyncEnabled && !syncEnabled) {
            $('input[name="wp_cognito_features[group_sync]"]').closest('td').append(
                '<p class="dependency-warning" style="color: #dc3232; font-style: italic;">⚠ Group sync requires User Sync to be enabled</p>'
            );
        }

        // Show info about feature combinations
        if (authEnabled && !syncEnabled) {
            $('input[name="wp_cognito_features[authentication]"]').closest('td').append(
                '<p class="dependency-warning" style="color: #856404; font-style: italic;">ℹ Consider enabling User Sync for complete integration</p>'
            );
        }
    }

    /**
     * Validate Cognito settings
     */
    function validateCognitoSettings() {
        const userPoolId = $('#wp_cognito_auth_user_pool_id').val();
        const clientId = $('#wp_cognito_auth_client_id').val();
        const region = $('#wp_cognito_auth_region').val();
        const domain = $('#wp_cognito_auth_hosted_ui_domain').val();

        // Clear existing validation
        $('.validation-error').remove();

        // Validate User Pool ID format
        if (userPoolId && !/^[a-z0-9-]+_[a-zA-Z0-9]+$/.test(userPoolId)) {
            $('#wp_cognito_auth_user_pool_id').after(
                '<p class="validation-error" style="color: #dc3232; font-size: 12px;">⚠ Invalid User Pool ID format</p>'
            );
        }

        // Validate domain format
        if (domain && !/^[a-z0-9-]+\.auth\.[a-z0-9-]+\.amazoncognito\.com$/.test(domain)) {
            $('#wp_cognito_auth_hosted_ui_domain').after(
                '<p class="validation-error" style="color: #dc3232; font-size: 12px;">⚠ Invalid hosted UI domain format</p>'
            );
        }

        // Enable/disable test button
        const canTest = userPoolId && clientId && region && domain;
        $('#test-cognito-connection').prop('disabled', !canTest);
    }

    /**
     * Utility function to escape HTML
     */
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    /**
     * Show loading overlay
     */
    function showLoading($element) {
        $element.addClass('cognito-loading').prop('disabled', true);
    }

    /**
     * Hide loading overlay
     */
    function hideLoading($element) {
        $element.removeClass('cognito-loading').prop('disabled', false);
    }

    /**
     * Show notification
     */
    function showNotification(message, type = 'info') {
        const $notification = $('<div class="cognito-notice notice-' + type + '">' + message + '</div>');
        $('.wrap h1').after($notification);

        setTimeout(function() {
            $notification.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }

    /**
     * Handle tab switching with URL hash
     */
    if (window.location.hash) {
        const tab = window.location.hash.substring(1);
        $('.nav-tab[href*="tab=' + tab + '"]').click();
    }

    $('.nav-tab').on('click', function() {
        const href = $(this).attr('href');
        const tab = href.substring(href.indexOf('tab=') + 4);
        window.location.hash = tab;
    });

    /**
     * Copy to clipboard functionality
     */
    $('.copy-to-clipboard').on('click', function() {
        const text = $(this).data('copy-text') || $(this).prev('input').val();
        navigator.clipboard.writeText(text).then(function() {
            showNotification('Copied to clipboard!', 'success');
        });
    });

    /**
     * Confirmation dialogs for destructive actions
     */
    $('.cognito-destructive').on('click', function(e) {
        const message = $(this).data('confirm') || 'Are you sure you want to perform this action?';
        if (!confirm(message)) {
            e.preventDefault();
        }
    });

    /**
     * Initialize color picker for login button customization
     */
    function initColorPicker() {
        const $colorInput = $('#wp_cognito_auth_login_button_color');
        const $colorText = $('#wp_cognito_auth_login_button_color_text');
        const $textColorInput = $('#wp_cognito_auth_login_button_text_color');
        const $textColorText = $('#wp_cognito_auth_login_button_text_color_text');
        const $textInput = $('#wp_cognito_auth_login_button_text');
        const $preview = $('#login-button-preview');

        // Handle background color picker
        if ($colorInput.length && $colorText.length) {
            // Update text field when color picker changes
            $colorInput.on('input change', function() {
                const color = $(this).val();
                $colorText.val(color);
                updateLoginButtonPreview($colorInput.val(), $textColorInput.val(), $textInput.val());
            });

            // Allow manual text input (remove readonly and add validation)
            $colorText.prop('readonly', false);
            $colorText.on('input', function() {
                let color = $(this).val();
                // Basic hex color validation
                if (/^#[0-9A-F]{6}$/i.test(color)) {
                    $colorInput.val(color);
                    updateLoginButtonPreview(color, $textColorInput.val(), $textInput.val());
                    $(this).removeClass('invalid');
                } else {
                    $(this).addClass('invalid');
                }
            });
        }

        // Handle text color picker
        if ($textColorInput.length && $textColorText.length) {
            // Update text field when color picker changes
            $textColorInput.on('input change', function() {
                const color = $(this).val();
                $textColorText.val(color);
                updateLoginButtonPreview($colorInput.val(), color, $textInput.val());
            });

            // Allow manual text input (remove readonly and add validation)
            $textColorText.prop('readonly', false);
            $textColorText.on('input', function() {
                let color = $(this).val();
                // Basic hex color validation
                if (/^#[0-9A-F]{6}$/i.test(color)) {
                    $textColorInput.val(color);
                    updateLoginButtonPreview($colorInput.val(), color, $textInput.val());
                    $(this).removeClass('invalid');
                } else {
                    $(this).addClass('invalid');
                }
            });
        }

        // Update preview when button text changes
        if ($textInput.length && $preview.length) {
            $textInput.on('input', function() {
                const text = $(this).val() || 'Login with Cognito';
                $preview.text(text);
                updateLoginButtonPreview($colorInput.val(), $textColorInput.val(), text);
            });
        }

        // Initial preview setup
        if ($preview.length) {
            // Force initial preview update with a slight delay to ensure DOM is ready
            setTimeout(function() {
                updateLoginButtonPreview(
                    $colorInput.val() || '#ff9900',
                    $textColorInput.val() || '#ffffff',
                    $textInput.val() || 'Login with Cognito'
                );
            }, 100);
        }
    }

    /**
     * Update login button preview
     */
    function updateLoginButtonPreview(bgColor, textColor, text) {
        bgColor = bgColor || '#ff9900';
        textColor = textColor || '#ffffff';
        text = text || 'Login with Cognito';

        // Update preview button directly
        const $preview = $('#login-button-preview');
        if ($preview.length) {
            $preview.text(text);

            // Calculate hover color (darker version of background)
            const hoverBgColor = darkenColor(bgColor, 20);

            // Apply styles directly to the element
            $preview.css({
                'background': bgColor,
                'background-color': bgColor,
                'background-image': 'none',
                'border': `1px solid ${bgColor}`,
                'border-color': bgColor,
                'color': textColor,
                'padding': '10px 20px',
                'border-radius': '3px',
                'text-decoration': 'none',
                'display': 'inline-block',
                'font-size': '14px',
                'font-weight': 'normal',
                'text-shadow': 'none',
                'box-shadow': 'none',
                'line-height': 'normal',
                'min-height': 'auto',
                'text-align': 'center',
                'height': 'auto',
                'width': 'auto',
                'cursor': 'pointer'
            });

            // Add hover effects
            $preview.off('mouseenter mouseleave'); // Remove existing handlers
            $preview.on('mouseenter', function() {
                $(this).css({
                    'background': hoverBgColor,
                    'background-color': hoverBgColor,
                    'border-color': hoverBgColor,
                    'color': textColor
                });
            }).on('mouseleave', function() {
                $(this).css({
                    'background': bgColor,
                    'background-color': bgColor,
                    'border-color': bgColor,
                    'color': textColor
                });
            });
        }
    }

    /**
     * Darken a hex color by a percentage
     */
    function darkenColor(color, percent) {
        // Remove # if present
        color = color.replace('#', '');

        // Parse RGB values
        const r = parseInt(color.substr(0, 2), 16);
        const g = parseInt(color.substr(2, 2), 16);
        const b = parseInt(color.substr(4, 2), 16);

        // Darken by percentage
        const factor = (100 - percent) / 100;
        const newR = Math.round(r * factor);
        const newG = Math.round(g * factor);
        const newB = Math.round(b * factor);

        // Convert back to hex
        return `#${newR.toString(16).padStart(2, '0')}${newG.toString(16).padStart(2, '0')}${newB.toString(16).padStart(2, '0')}`;
    }

})(jQuery);