<?php
namespace WP_Cognito_Auth;

class API {
    const LOG_OPTION = 'wp_cognito_sync_logs';
    const MAX_LOGS = 100;

    public function log_message($message, $level = 'info') {
        $logs = get_option(self::LOG_OPTION, []);
        
        array_unshift($logs, [
            'timestamp' => current_time('mysql'),
            'message' => $message,
            'level' => $level
        ]);
        
        $logs = array_slice($logs, 0, self::MAX_LOGS);
        update_option(self::LOG_OPTION, $logs);
    }

    private function send_to_lambda($action, $data) {
        $this->log_message("Sending request to Lambda: {$action} - " . json_encode($data));

        $response = $this->send_api_request([
            'action' => $action,
            ...$data
        ]);

        if ($response && isset($response['result']['User']['Username'])) {
            return $response['result']['User']['Username'];
        }

        return $response;
    }

    private function send_api_request($data) {
        $api_url = rtrim(get_option('wp_cognito_sync_api_url'), '/') . '/sync';
        $api_key = get_option('wp_cognito_sync_api_key');

        if (empty($api_url) || empty($api_key)) {
            $this->log_message('API configuration missing', 'error');
            return false;
        }

        $response = wp_remote_post($api_url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key
            ],
            'body' => json_encode($data),
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            $this->log_message('API request error: ' . $response->get_error_message(), 'error');
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);

        if ($http_code !== 200) {
            $this->log_message("API error: Status {$http_code} - {$body}", 'error');
            return false;
        }

        try {
            $decoded_response = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log_message('Invalid JSON response: ' . json_last_error_msg(), 'error');
                return false;
            }
            return $decoded_response;
        } catch (\Exception $e) {
            $this->log_message('Failed to decode response: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    public function create_user($user_data) {
        $this->log_message("Creating user in Cognito: {$user_data['email']}");
        
        $result = $this->send_to_lambda('create', ['user' => $user_data]);
        
        if ($result && isset($user_data['wp_user_id'])) {
            $cognito_user_id = is_array($result) && isset($result['result']['User']['Username']) 
                ? $result['result']['User']['Username'] 
                : $user_data['email'];
            update_user_meta($user_data['wp_user_id'], 'cognito_user_id', $cognito_user_id);
            $this->log_message("Updated Cognito User ID: {$cognito_user_id} for WordPress user: {$user_data['wp_user_id']}");
        }

        return $result;
    }

    public function update_user($user_data) {
        $this->log_message("Updating user in Cognito: {$user_data['email']}");
        return $this->send_to_lambda('update', ['user' => $user_data]);
    }

    public function delete_user($user_data) {
        $this->log_message("Deleting user from Cognito: {$user_data['email']}");
        return $this->send_to_lambda('delete', ['user' => $user_data]);
    }

    public function create_group($group_name, $description = '') {
        $this->log_message("Creating group in Cognito: WP_{$group_name}");
        
        return $this->send_api_request([
            'action' => 'create_group',
            'group' => [
                'name' => "WP_{$group_name}",
                'description' => $description ?: "WordPress role: {$group_name}"
            ]
        ]);
    }

    public function update_group_membership($cognito_user_id, $group_name, $operation = 'add') {
        $this->log_message("Syncing group membership for user {$cognito_user_id} in group WP_{$group_name}: {$operation}");
        
        return $this->send_api_request([
            'action' => 'update_group_membership',
            'group' => [
                'name' => "WP_{$group_name}",
                'user_id' => $cognito_user_id,
                'operation' => $operation
            ]
        ]);
    }

    public function get_logs($limit = 50) {
        return array_slice(get_option(self::LOG_OPTION, []), 0, $limit);
    }

    public function clear_logs() {
        update_option(self::LOG_OPTION, []);
    }

    public function test_connection() {
        $api_url = rtrim(get_option('wp_cognito_sync_api_url'), '/') . '/test';
        $api_key = get_option('wp_cognito_sync_api_key');

        if (empty($api_url) || empty($api_key)) {
            return ['success' => false, 'message' => __('API URL and Key are required', 'wp-cognito-auth')];
        }

        // Log the request details for debugging
        $this->log_message("Testing connection to: {$api_url}");
        $this->log_message("Using API key: " . substr($api_key, 0, 8) . "...");

        // Use GET request to the /test endpoint which returns a simple 200 response
        $response = wp_remote_get($api_url, [
            'headers' => [
                'x-api-key' => $api_key
            ],
            'timeout' => 15
        ]);

        // Log the raw response for debugging
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_message("WordPress HTTP Error: {$error_message}", 'error');
            return ['success' => false, 'message' => $error_message];
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $this->log_message("Response HTTP Code: {$http_code}");
        $this->log_message("Response Body: {$body}");
        
        if ($http_code === 200) {
            $decoded = json_decode($body, true);
            $message = isset($decoded['message']) ? $decoded['message'] : __('Connection successful!', 'wp-cognito-auth');
            $this->log_message("Connection test successful: {$message}");
            return ['success' => true, 'message' => $message];
        } else {
            $error_msg = sprintf(__('Connection failed. HTTP %d: %s', 'wp-cognito-auth'), $http_code, $body);
            $this->log_message("Connection test failed: {$error_msg}", 'error');
            return ['success' => false, 'message' => $error_msg];
        }
    }

    public function get_config_debug_info() {
        $api_url = get_option('wp_cognito_sync_api_url');
        $api_key = get_option('wp_cognito_sync_api_key');

        return [
            'base_url' => $api_url,
            'test_url' => rtrim($api_url, '/') . '/test',
            'sync_url' => rtrim($api_url, '/') . '/sync',
            'api_key_set' => !empty($api_key),
            'api_key_length' => strlen($api_key),
            'api_key_prefix' => !empty($api_key) ? substr($api_key, 0, 8) . '...' : 'Not set'
        ];
    }

    public function bulk_sync_users($limit = 20, $offset = 0) {
        $users = get_users([
            'number' => $limit,
            'offset' => $offset,
            'fields' => 'all'
        ]);

        $stats = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($users as $user) {
            $stats['processed']++;
            
            try {
                $cognito_id = get_user_meta($user->ID, 'cognito_user_id', true);
                $user_data = $this->prepare_user_data($user->ID, $user);
                
                if (empty($cognito_id)) {
                    $result = $this->create_user($user_data);
                    if ($result) {
                        $stats['created']++;
                    } else {
                        $stats['failed']++;
                    }
                } else {
                    $user_data['cognito_user_id'] = $cognito_id;
                    $result = $this->update_user($user_data);
                    if ($result) {
                        $stats['updated']++;
                    } else {
                        $stats['failed']++;
                    }
                }
            } catch (\Exception $e) {
                $stats['failed']++;
                $stats['errors'][] = sprintf(
                    'Error processing user %s (%d): %s',
                    $user->user_email,
                    $user->ID,
                    $e->getMessage()
                );
            }
        }

        return $stats;
    }

    private function prepare_user_data($user_id, $user) {
        $first_name = get_user_meta($user_id, 'first_name', true);
        $last_name = get_user_meta($user_id, 'last_name', true);

        $full_name = trim($first_name . ' ' . $last_name);
        if (empty($full_name)) {
            $full_name = $user->display_name ?: $user->user_login;
        }

        return [
            'wp_user_id' => $user_id,
            'email' => $user->user_email,
            'username' => $user->user_login,
            'firstName' => $first_name,
            'lastName' => $last_name,
            'name' => $full_name,
            'wp_memberrank' => get_user_meta($user_id, 'wpuef_cid_c6', true),
            'wp_membercategory' => get_user_meta($user_id, 'wpuef_cid_c10', true)
        ];
    }

    public function get_user_count() {
        return count(get_users(['fields' => 'ID']));
    }

    public function get_sync_statistics() {
        $total_users = $this->get_user_count();
        $users_with_cognito = count(get_users([
            'meta_key' => 'cognito_user_id',
            'meta_compare' => 'EXISTS',
            'fields' => 'ID'
        ]));

        return [
            'total_users' => $total_users,
            'synced_users' => $users_with_cognito,
            'unsynced_users' => $total_users - $users_with_cognito,
            'sync_percentage' => $total_users > 0 ? round(($users_with_cognito / $total_users) * 100, 2) : 0
        ];
    }
}
