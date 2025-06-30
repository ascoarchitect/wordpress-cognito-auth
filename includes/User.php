<?php
namespace WP_Cognito_Auth;

class User {
    private $sync;

    public function __construct() {
        add_action('show_user_profile', array($this, 'add_cognito_fields'));
        add_action('edit_user_profile', array($this, 'add_cognito_fields'));
        add_action('personal_options_update', array($this, 'save_cognito_fields'));
        add_action('edit_user_profile_update', array($this, 'save_cognito_fields'));
        
        // Add AJAX handlers
        add_action('wp_ajax_cognito_sync_user', array($this, 'ajax_sync_user'));
        add_action('wp_ajax_cognito_unlink_user', array($this, 'ajax_unlink_user'));
    }

    public function set_sync($sync) {
        $this->sync = $sync;
    }

    public function handle_user_login($user_login, $user) {
        try {
            $features = get_option('wp_cognito_features', []);
            
            // Only sync on login if sync feature is enabled and setting is on
            if (empty($features['sync']) || !get_option('wp_cognito_sync_on_login', false)) {
                return;
            }

            if (!$this->sync) {
                return;
            }

            $cognito_id = get_user_meta($user->ID, 'cognito_user_id', true);

            if (empty($cognito_id)) {
                $this->sync->on_user_create($user->ID);
            } else {
                $this->sync->on_user_update($user->ID);
            }
        } catch (\Exception $e) {
            // Silent handling of errors
        }
    }

    public function add_cognito_fields($user) {
        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }

        $cognito_id = get_user_meta($user->ID, 'cognito_user_id', true);
        $cognito_sync_enabled = get_user_meta($user->ID, 'cognito_sync_enabled', true);
        $last_sync = get_user_meta($user->ID, 'cognito_last_sync', true);
        
        $features = get_option('wp_cognito_features', []);
        ?>
        <h3><?php _e('Cognito Integration', 'wp-cognito-auth'); ?></h3>
        <table class="form-table cognito-user-fields">
            <tr>
                <th>
                    <label for="cognito_user_id"><?php _e('Cognito User ID', 'wp-cognito-auth'); ?></label>
                </th>
                <td>
                    <input type="text" 
                           name="cognito_user_id" 
                           id="cognito_user_id" 
                           value="<?php echo esc_attr($cognito_id); ?>" 
                           class="regular-text" 
                           readonly>
                    <p class="description">
                        <?php 
                        if (empty($cognito_id)) {
                            _e('No Cognito account linked', 'wp-cognito-auth');
                        } else {
                            _e('This user is linked to a Cognito account', 'wp-cognito-auth');
                        }
                        ?>
                    </p>
                </td>
            </tr>
            
            <?php if (!empty($features['authentication'])): ?>
            <tr>
                <th><?php _e('Authentication Status', 'wp-cognito-auth'); ?></th>
                <td>
                    <div class="cognito-user-status <?php echo !empty($cognito_id) ? 'connected' : 'disconnected'; ?>">
                        <?php if (!empty($cognito_id)): ?>
                            <span class="dashicons dashicons-yes-alt"></span>
                            <span style="color: #46b450;"><?php _e('Can login via Cognito', 'wp-cognito-auth'); ?></span>
                        <?php else: ?>
                            <span class="dashicons dashicons-warning"></span>
                            <span style="color: #dc3232;"><?php _e('WordPress login only', 'wp-cognito-auth'); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($cognito_id)): ?>
                        <p class="description">
                            <?php _e('This user can authenticate using either WordPress login or Cognito SSO.', 'wp-cognito-auth'); ?>
                        </p>
                    <?php else: ?>
                        <p class="description">
                            <?php _e('This user can only login using WordPress. Sync to Cognito to enable SSO.', 'wp-cognito-auth'); ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endif; ?>
            
            <?php if (!empty($features['sync'])): ?>
            <?php if (current_user_can('manage_options')): ?>
            <tr>
                <th>
                    <label for="cognito_sync_enabled"><?php _e('Sync to Cognito', 'wp-cognito-auth'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" 
                               name="cognito_sync_enabled" 
                               id="cognito_sync_enabled" 
                               value="1" 
                               <?php checked($cognito_sync_enabled, '1'); ?>>
                        <?php _e('Sync this user to Cognito automatically', 'wp-cognito-auth'); ?>
                    </label>
                    <p class="description">
                        <?php _e('If enabled, changes to this user will be synchronized to Cognito', 'wp-cognito-auth'); ?>
                    </p>
                </td>
            </tr>
            
            <?php if ($last_sync): ?>
            <tr>
                <th><?php _e('Last Sync', 'wp-cognito-auth'); ?></th>
                <td>
                    <span class="cognito-last-sync">
                        <?php echo wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_sync)); ?>
                    </span>
                    <p class="description">
                        <?php _e('Last time this user was synchronized with Cognito', 'wp-cognito-auth'); ?>
                    </p>
                </td>
            </tr>
            <?php endif; ?>
            
            <tr>
                <th><?php _e('Manual Actions', 'wp-cognito-auth'); ?></th>
                <td>
                    <div class="cognito-user-actions">
                        <button type="button" 
                                class="button button-secondary cognito-user-sync" 
                                data-user-id="<?php echo $user->ID; ?>">
                            <?php _e('Sync Now', 'wp-cognito-auth'); ?>
                        </button>
                        
                        <?php if (!empty($cognito_id)): ?>
                        <button type="button" 
                                class="button button-secondary cognito-user-unlink" 
                                data-user-id="<?php echo $user->ID; ?>"
                                style="margin-left: 10px;">
                            <?php _e('Unlink from Cognito', 'wp-cognito-auth'); ?>
                        </button>
                        <?php endif; ?>
                    </div>
                    
                    <p class="description">
                        <?php if (empty($cognito_id)): ?>
                            <?php _e('Create this user in Cognito and link accounts', 'wp-cognito-auth'); ?>
                        <?php else: ?>
                            <?php _e('Manually synchronize user data or unlink from Cognito', 'wp-cognito-auth'); ?>
                        <?php endif; ?>
                    </p>
                    
                    <div id="cognito-user-result-<?php echo $user->ID; ?>" class="cognito-action-result"></div>
                </td>
            </tr>
            
            <?php if (!empty($features['group_sync'])): ?>
            <tr>
                <th><?php _e('Group Membership', 'wp-cognito-auth'); ?></th>
                <td>
                    <?php 
                    $synced_groups = get_option('wp_cognito_sync_groups', []);
                    $user_roles = $user->roles;
                    ?>
                    
                    <?php if (!empty($synced_groups)): ?>
                        <div class="cognito-group-status">
                            <h4><?php _e('Synced Groups:', 'wp-cognito-auth'); ?></h4>
                            <ul>
                                <?php foreach ($synced_groups as $group): ?>
                                    <li>
                                        <strong>WP_<?php echo esc_html($group); ?></strong>
                                        <?php if (in_array($group, $user_roles)): ?>
                                            <span class="sync-status enabled"><?php _e('Member', 'wp-cognito-auth'); ?></span>
                                        <?php else: ?>
                                            <span class="sync-status disabled"><?php _e('Not Member', 'wp-cognito-auth'); ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php else: ?>
                        <p class="description">
                            <?php _e('No groups are configured for synchronization', 'wp-cognito-auth'); ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endif; ?>
            
            <?php endif; // manage_options ?>
            <?php endif; // sync feature ?>
        </table>
        
        <?php if (!empty($features['sync']) && current_user_can('manage_options')): ?>
        <script>
        jQuery(document).ready(function($) {
            // Handle user sync
            $('.cognito-user-sync').on('click', function() {
                var $button = $(this);
                var userId = $button.data('user-id');
                var $result = $('#cognito-user-result-' + userId);
                
                $button.prop('disabled', true).text('<?php esc_js(_e('Syncing...', 'wp-cognito-auth')); ?>');
                $result.html('<em><?php esc_js(_e('Syncing user...', 'wp-cognito-auth')); ?></em>');
                
                $.post(ajaxurl, {
                    action: 'cognito_sync_user',
                    user_id: userId,
                    nonce: '<?php echo wp_create_nonce('cognito_sync_user'); ?>'
                }, function(response) {
                    if (response.success) {
                        $result.html('<span style="color: #46b450;">✓ ' + response.data + '</span>');
                        // Reload page after 2 seconds to show updated info
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $result.html('<span style="color: #dc3232;">✗ ' + response.data + '</span>');
                    }
                }).fail(function() {
                    $result.html('<span style="color: #dc3232;">✗ <?php esc_js(_e('Sync request failed', 'wp-cognito-auth')); ?></span>');
                }).always(function() {
                    $button.prop('disabled', false).text('<?php esc_js(_e('Sync Now', 'wp-cognito-auth')); ?>');
                    setTimeout(function() {
                        $result.html('');
                    }, 5000);
                });
            });
            
            // Handle user unlink
            $('.cognito-user-unlink').on('click', function() {
                if (!confirm('<?php esc_js(_e('Are you sure you want to unlink this user from Cognito? This will not delete the Cognito user.', 'wp-cognito-auth')); ?>')) {
                    return;
                }
                
                var $button = $(this);
                var userId = $button.data('user-id');
                var $result = $('#cognito-user-result-' + userId);
                
                $button.prop('disabled', true).text('<?php esc_js(_e('Unlinking...', 'wp-cognito-auth')); ?>');
                $result.html('<em><?php esc_js(_e('Unlinking user...', 'wp-cognito-auth')); ?></em>');
                
                $.post(ajaxurl, {
                    action: 'cognito_unlink_user',
                    user_id: userId,
                    nonce: '<?php echo wp_create_nonce('cognito_unlink_user'); ?>'
                }, function(response) {
                    if (response.success) {
                        $result.html('<span style="color: #46b450;">✓ ' + response.data + '</span>');
                        // Reload page after 2 seconds to show updated info
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $result.html('<span style="color: #dc3232;">✗ ' + response.data + '</span>');
                    }
                }).fail(function() {
                    $result.html('<span style="color: #dc3232;">✗ <?php esc_js(_e('Unlink request failed', 'wp-cognito-auth')); ?></span>');
                }).always(function() {
                    $button.prop('disabled', false).text('<?php esc_js(_e('Unlink from Cognito', 'wp-cognito-auth')); ?>');
                    setTimeout(function() {
                        $result.html('');
                    }, 5000);
                });
            });
        });
        </script>
        <?php endif; ?>
        
        <style>
        .cognito-user-fields th {
            width: 150px;
            padding: 15px 10px 15px 0;
            vertical-align: top;
        }
        
        .cognito-user-fields td {
            padding: 15px 10px;
        }
        
        .cognito-user-status {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
        }
        
        .cognito-user-status .dashicons {
            font-size: 18px;
        }
        
        .cognito-user-actions {
            margin-bottom: 10px;
        }
        
        .cognito-action-result {
            margin-top: 10px;
            padding: 8px;
            border-radius: 4px;
            display: none;
        }
        
        .cognito-action-result:not(:empty) {
            display: block;
        }
        
        .cognito-group-status ul {
            margin: 10px 0;
            padding-left: 20px;
        }
        
        .cognito-group-status li {
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .sync-status {
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .sync-status.enabled {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .sync-status.disabled {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .cognito-last-sync {
            font-family: monospace;
            background: #f8f9fa;
            padding: 4px 8px;
            border-radius: 3px;
        }
        </style>
        <?php
    }

    public function save_cognito_fields($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }

        $features = get_option('wp_cognito_features', []);
        
        if (!empty($features['sync']) && current_user_can('manage_options')) {
            $cognito_sync_enabled = isset($_POST['cognito_sync_enabled']) ? '1' : '0';
            update_user_meta($user_id, 'cognito_sync_enabled', $cognito_sync_enabled);
        }
    }

    public function ajax_sync_user() {
        check_ajax_referer('cognito_sync_user', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'wp-cognito-auth'));
        }
        
        $user_id = intval($_POST['user_id']);
        if (!$user_id) {
            wp_send_json_error(__('Invalid user ID', 'wp-cognito-auth'));
        }
        
        if (!$this->sync) {
            wp_send_json_error(__('Sync functionality not available', 'wp-cognito-auth'));
        }
        
        $result = $this->sync->force_sync_user($user_id);
        
        if ($result['success']) {
            // Update last sync time
            update_user_meta($user_id, 'cognito_last_sync', current_time('mysql'));
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    public function ajax_unlink_user() {
        check_ajax_referer('cognito_unlink_user', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'wp-cognito-auth'));
        }
        
        $user_id = intval($_POST['user_id']);
        if (!$user_id) {
            wp_send_json_error(__('Invalid user ID', 'wp-cognito-auth'));
        }
        
        $user = get_userdata($user_id);
        if (!$user) {
            wp_send_json_error(__('User not found', 'wp-cognito-auth'));
        }
        
        // Remove Cognito user ID and sync settings
        delete_user_meta($user_id, 'cognito_user_id');
        delete_user_meta($user_id, 'cognito_last_sync');
        delete_user_meta($user_id, 'cognito_sync_enabled');
        
        wp_send_json_success(__('User unlinked from Cognito successfully', 'wp-cognito-auth'));
    }

    public function get_user_cognito_status($user_id) {
        $cognito_id = get_user_meta($user_id, 'cognito_user_id', true);
        $sync_enabled = get_user_meta($user_id, 'cognito_sync_enabled', true);
        $last_sync = get_user_meta($user_id, 'cognito_last_sync', true);
        
        return [
            'has_cognito_account' => !empty($cognito_id),
            'cognito_user_id' => $cognito_id,
            'sync_enabled' => $sync_enabled === '1',
            'last_sync' => $last_sync,
            'can_authenticate_via_cognito' => !empty($cognito_id)
        ];
    }

    public function bulk_link_users_to_cognito($user_ids) {
        if (!current_user_can('manage_options') || !$this->sync) {
            return false;
        }
        
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        foreach ($user_ids as $user_id) {
            try {
                $result = $this->sync->force_sync_user($user_id);
                if ($result['success']) {
                    $results['success']++;
                    update_user_meta($user_id, 'cognito_last_sync', current_time('mysql'));
                } else {
                    $results['failed']++;
                    $results['errors'][] = "User {$user_id}: " . $result['message'];
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = "User {$user_id}: " . $e->getMessage();
            }
        }
        
        return $results;
    }

    public function get_users_without_cognito($limit = 50) {
        return get_users([
            'number' => $limit,
            'meta_query' => [
                [
                    'key' => 'cognito_user_id',
                    'compare' => 'NOT EXISTS'
                ]
            ],
            'fields' => ['ID', 'user_email', 'user_login', 'display_name']
        ]);
    }

    public function get_users_with_cognito($limit = 50) {
        return get_users([
            'number' => $limit,
            'meta_query' => [
                [
                    'key' => 'cognito_user_id',
                    'compare' => 'EXISTS'
                ]
            ],
            'fields' => ['ID', 'user_email', 'user_login', 'display_name']
        ]);
    }
}