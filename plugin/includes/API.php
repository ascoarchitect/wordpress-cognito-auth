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
        $base_api_url = get_option('wp_cognito_sync_api_url');
        $api_key = get_option('wp_cognito_sync_api_key');

        if (empty($base_api_url) || empty($api_key)) {
            $this->log_message('API configuration missing', 'error');
            return false;
        }

        // Construct the sync URL properly
        $base_url = rtrim($base_api_url, '/');
        if (substr($base_url, -5) !== '/sync') {
            $sync_url = $base_url . '/sync';
        } else {
            $sync_url = $base_url;
        }

        $response = wp_remote_post($sync_url, [
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
        $base_api_url = get_option('wp_cognito_sync_api_url');
        $api_key = get_option('wp_cognito_sync_api_key');

        if (empty($base_api_url) || empty($api_key)) {
            return ['success' => false, 'message' => __('API URL and Key are required', 'wp-cognito-auth')];
        }

        // Construct the test URL properly
        // Remove any trailing /sync if present, then add /test
        $base_url = rtrim($base_api_url, '/');
        if (substr($base_url, -5) === '/sync') {
            $base_url = substr($base_url, 0, -5);
        }
        $test_url = $base_url . '/test';

        // Log the essential request details
        $this->log_message("Testing connection to: {$test_url}");

        // Prepare request arguments
        $request_args = [
            'headers' => [
                'x-api-key' => $api_key,
                'Content-Type' => 'application/json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
            ],
            'timeout' => 15,
            'sslverify' => true,
            'blocking' => true,
            'httpversion' => '1.1'
        ];

        // Use GET request to the /test endpoint which returns a simple 200 response
        $response = wp_remote_get($test_url, $request_args);

        // Log the raw response for debugging
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_message("WordPress HTTP Error: {$error_message}", 'error');
            return ['success' => false, 'message' => __('Connection failed: ', 'wp-cognito-auth') . $error_message];
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $this->log_message("Response HTTP Code: {$http_code}");

        if ($http_code === 200) {
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['message'])) {
                $message = $decoded['message'];
                $this->log_message("Connection test successful: {$message}");
                return ['success' => true, 'message' => $message];
            } else {
                // Even if JSON parsing fails, 200 means success
                $this->log_message("Connection test successful");
                return ['success' => true, 'message' => __('Connection successful!', 'wp-cognito-auth')];
            }
        } else {
            // Provide specific error messages for common HTTP codes
            $error_msg = '';
            switch ($http_code) {
                case 403:
                    $error_msg = __('API key is invalid or not authorized. Please check your API key is correct and properly configured in AWS API Gateway usage plan.', 'wp-cognito-auth');
                    break;
                case 404:
                    $error_msg = __('API endpoint not found. Please verify your API URL is correct.', 'wp-cognito-auth');
                    break;
                case 429:
                    $error_msg = __('API rate limit exceeded. Please wait and try again.', 'wp-cognito-auth');
                    break;
                case 500:
                case 502:
                case 503:
                    $error_msg = __('API server error. Please check AWS Lambda function logs and try again.', 'wp-cognito-auth');
                    break;
                default:
                    $error_msg = sprintf(__('Connection failed. HTTP %d: %s', 'wp-cognito-auth'), $http_code, $body);
            }

            $full_error = sprintf('%s (HTTP %d)', $error_msg, $http_code);
            $this->log_message("Connection test failed: {$full_error}", 'error');
            return ['success' => false, 'message' => $full_error];
        }
    }

    public function get_config_debug_info() {
        $base_api_url = get_option('wp_cognito_sync_api_url');
        $api_key = get_option('wp_cognito_sync_api_key');

        // Construct URLs properly
        $base_url = rtrim($base_api_url, '/');
        if (substr($base_url, -5) === '/sync') {
            $base_url = substr($base_url, 0, -5);
        }

        return [
            'base_url_setting' => $base_api_url,
            'calculated_base_url' => $base_url,
            'test_url' => $base_url . '/test',
            'sync_url' => $base_url . '/sync',
            'api_key_set' => !empty($api_key),
            'api_key_length' => strlen($api_key ?? ''),
            'api_key_prefix' => !empty($api_key) ? substr($api_key, 0, 8) . '...' : 'Not set',
            'expected_format' => 'https://{api-id}.execute-api.{region}.amazonaws.com/{stage}',
            'example_url' => 'https://abc123def4.execute-api.us-west-2.amazonaws.com/prod'
        ];
    }

    /**
     * Test WordPress HTTP functionality and configuration
     */
    public function test_wp_http_functionality() {
        $results = [];

        // Test 1: Basic HTTP GET to a simple endpoint
        $simple_response = wp_remote_get('https://httpbin.org/get', ['timeout' => 10]);

        if (is_wp_error($simple_response)) {
            $results['basic_http'] = [
                'success' => false,
                'message' => 'Basic HTTP failed: ' . $simple_response->get_error_message()
            ];
        } else {
            $code = wp_remote_retrieve_response_code($simple_response);
            $results['basic_http'] = [
                'success' => $code === 200,
                'message' => $code === 200 ? 'Basic HTTP working' : "Basic HTTP returned code: {$code}"
            ];
        }

        // Test 2: SSL/TLS test to AWS
        $aws_response = wp_remote_get('https://aws.amazon.com/', ['timeout' => 10]);

        if (is_wp_error($aws_response)) {
            $results['aws_ssl'] = [
                'success' => false,
                'message' => 'AWS HTTPS failed: ' . $aws_response->get_error_message()
            ];
        } else {
            $code = wp_remote_retrieve_response_code($aws_response);
            $results['aws_ssl'] = [
                'success' => $code === 200 || $code === 301 || $code === 302,
                'message' => $code === 200 ? 'AWS HTTPS working' : "AWS HTTPS returned code: {$code}"
            ];
        }

        // Test 3: Check WordPress HTTP transport methods
        $transports = [];
        if (function_exists('curl_version')) {
            $transports[] = 'cURL';
        }
        if (function_exists('fsockopen')) {
            $transports[] = 'fsockopen';
        }
        if (function_exists('fopen') && ini_get('allow_url_fopen')) {
            $transports[] = 'fopen';
        }

        $results['transports'] = [
            'success' => !empty($transports),
            'message' => 'Available transports: ' . implode(', ', $transports)
        ];

        // Test 4: Check for any HTTP filters that might interfere
        $http_filters = [];
        if (has_filter('pre_http_request')) {
            $http_filters[] = 'pre_http_request';
        }
        if (has_filter('http_request_args')) {
            $http_filters[] = 'http_request_args';
        }
        if (has_filter('http_api_curl')) {
            $http_filters[] = 'http_api_curl';
        }

        $results['filters'] = [
            'success' => true,
            'message' => empty($http_filters) ? 'No HTTP filters detected' : 'HTTP filters present: ' . implode(', ', $http_filters)
        ];

        // Test 5: Direct test to your API Gateway base
        $base_api_url = get_option('wp_cognito_sync_api_url');
        if (!empty($base_api_url)) {
            $base_url = rtrim($base_api_url, '/');
            if (substr($base_url, -5) === '/sync') {
                $base_url = substr($base_url, 0, -5);
            }

            // Just test the base API Gateway URL without any API key
            $api_response = wp_remote_get($base_url, ['timeout' => 10]);

            if (is_wp_error($api_response)) {
                $results['api_base'] = [
                    'success' => false,
                    'message' => 'API Gateway base connection failed: ' . $api_response->get_error_message()
                ];
            } else {
                $code = wp_remote_retrieve_response_code($api_response);
                $results['api_base'] = [
                    'success' => $code === 403, // We expect 403 without API key, which means we reached the gateway
                    'message' => "API Gateway base returned code: {$code} (403 expected without API key)"
                ];
            }
        }

        return $results;
    }

    /**
     * Perform a comprehensive test of the API configuration
     */
    public function test_api_configuration() {
        $base_api_url = get_option('wp_cognito_sync_api_url');
        $api_key = get_option('wp_cognito_sync_api_key');

        $results = [
            'overall_success' => false,
            'tests' => []
        ];

        // Test 1: Check if URL and API key are set
        $results['tests']['config_check'] = [
            'name' => 'Configuration Check',
            'success' => !empty($base_api_url) && !empty($api_key),
            'message' => !empty($base_api_url) && !empty($api_key)
                ? 'API URL and key are configured'
                : 'Missing API URL or key'
        ];

        if (!$results['tests']['config_check']['success']) {
            return $results;
        }

        // Test 2: URL format validation
        $url_pattern = '/^https:\/\/[a-z0-9]+\.execute-api\.[a-z0-9-]+\.amazonaws\.com\/[a-z0-9]+$/i';
        $url_valid = preg_match($url_pattern, $base_api_url);

        $results['tests']['url_format'] = [
            'name' => 'URL Format Validation',
            'success' => (bool)$url_valid,
            'message' => $url_valid
                ? 'API URL format is valid'
                : 'API URL format appears invalid. Expected: https://{api-id}.execute-api.{region}.amazonaws.com/{stage}'
        ];

        // Test 3: Test endpoint connectivity
        $test_result = $this->test_connection();
        $results['tests']['connectivity'] = [
            'name' => 'Test Endpoint Connectivity',
            'success' => $test_result['success'],
            'message' => $test_result['message']
        ];

        // Test 4: API key format check (AWS API keys are typically 20-40 characters)
        $key_length = strlen($api_key);
        $key_format_ok = $key_length >= 20 && $key_length <= 50;

        $results['tests']['api_key_format'] = [
            'name' => 'API Key Format Check',
            'success' => $key_format_ok,
            'message' => $key_format_ok
                ? 'API key length appears valid'
                : "API key length ({$key_length} chars) may be invalid. AWS API keys are typically 20-40 characters."
        ];

        // Overall success if all critical tests pass
        $results['overall_success'] = $results['tests']['config_check']['success'] &&
                                     $results['tests']['connectivity']['success'];

        return $results;
    }

    public function bulk_sync_users($limit = 20, $offset = 0) {
        $users = get_users([
            'number' => $limit,
            'offset' => $offset,
            'fields' => 'all'
        ]);

        return $this->process_users_for_sync($users);
    }

    public function bulk_sync_users_by_role($role) {
        $users = get_users([
            'role' => $role,
            'fields' => 'all'
        ]);

        $this->log_message(sprintf('Starting bulk sync for role: %s. Found %d users.', $role, count($users)));

        return $this->process_users_for_sync($users);
    }

    private function process_users_for_sync($users) {
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
                $this->log_message(sprintf(
                    'Failed to sync user %s (%d): %s',
                    $user->user_email,
                    $user->ID,
                    $e->getMessage()
                ));
            }
        }

        $this->log_message(sprintf(
            'Bulk sync completed. Processed: %d, Created: %d, Updated: %d, Failed: %d',
            $stats['processed'],
            $stats['created'],
            $stats['updated'],
            $stats['failed']
        ));

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
