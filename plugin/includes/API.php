<?php
/**
 * API communication and logging functionality for Cognito integration
 *
 * @package WP_Cognito_Auth
 */

namespace WP_Cognito_Auth;

/**
 * Class API
 *
 * Handles communication with Cognito API endpoints and manages operation logging.
 */
class API {
	const LOG_OPTION = 'wp_cognito_sync_logs';
	const MAX_LOGS   = 100;

	/**
	 * Log message for debugging and monitoring
	 *
	 * @param string $message Log message.
	 * @param string $level   Log level (info, warning, error).
	 */
	public function log_message( $message, $level = 'info' ) {
		$logs = get_option( self::LOG_OPTION, array() );

		array_unshift(
			$logs,
			array(
				'timestamp' => current_time( 'mysql' ),
				'message'   => $message,
				'level'     => $level,
			)
		);

		$logs = array_slice( $logs, 0, self::MAX_LOGS );
		update_option( self::LOG_OPTION, $logs );
	}

	/**
	 * Check if the last API response was a "User already exists" error
	 */
	private function is_user_already_exists_error() {
		$logs = get_option( self::LOG_OPTION, array() );

		// Check the most recent log entry for the specific error.
		if ( ! empty( $logs ) && isset( $logs[0]['message'] ) ) {
			$last_message = $logs[0]['message'];
			return (
				strpos( $last_message, 'Status 500' ) !== false &&
				strpos( $last_message, 'User account already exists' ) !== false
			);
		}

		return false;
	}

	/**
	 * Send data to AWS Lambda function
	 *
	 * Injects the configured tenant_id into the top-level payload and into
	 * any nested `user` object so the Lambda can route the request correctly.
	 *
	 * @param string $action Action to perform.
	 * @param array  $data   Data to send.
	 * @return array API response.
	 */
	private function send_to_lambda( $action, $data ) {
		$tenant_id = User_Data::get_tenant_id();

		if ( empty( $tenant_id ) ) {
			$this->log_message( 'Sync aborted: tenant ID is not configured (Sync Settings → Tenant ID).', 'error' );
			return false;
		}

		$payload = array(
			'action'   => $action,
			'tenantId' => $tenant_id,
		);

		foreach ( $data as $key => $value ) {
			if ( $key === 'user' && is_array( $value ) && empty( $value['tenantId'] ) ) {
				$value['tenantId'] = $tenant_id;
			}
			$payload[ $key ] = $value;
		}

		$this->log_message( "Sending request to Lambda: {$action} - " . wp_json_encode( $payload ) );

		return $this->send_api_request( $payload );
	}

	/**
	 * Send HTTP request to Cognito API
	 *
	 * @param array $data Request data.
	 * @return array API response.
	 */
	private function send_api_request( $data ) {
		$base_api_url = get_option( 'wp_cognito_sync_api_url' );
		$api_key      = get_option( 'wp_cognito_sync_api_key' );

		if ( empty( $base_api_url ) || empty( $api_key ) ) {
			$this->log_message( 'API configuration missing', 'error' );
			return false;
		}

		// Construct the sync URL properly.
		$base_url = rtrim( $base_api_url, '/' );
		if ( substr( $base_url, -5 ) !== '/sync' ) {
			$sync_url = $base_url . '/sync';
		} else {
			$sync_url = $base_url;
		}

		$response = wp_remote_post(
			$sync_url,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'x-api-key'    => $api_key,
				),
				'body'    => json_encode( $data ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_message( 'API request error: ' . $response->get_error_message(), 'error' );
			return false;
		}

		$body      = wp_remote_retrieve_body( $response );
		$http_code = wp_remote_retrieve_response_code( $response );

		// Accept any 2xx as success. The bulk_sync_enqueue path returns 202
		// (Accepted) because the actual Cognito work happens asynchronously
		// off the SQS queue; treating that as a failure made every successful
		// bulk run report "all failed" in the admin notice.
		if ( $http_code < 200 || $http_code >= 300 ) {
			$this->log_message( "API error: Status {$http_code} - {$body}", 'error' );
			return false;
		}

		try {
			$decoded_response = json_decode( $body, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$this->log_message( 'Invalid JSON response: ' . json_last_error_msg(), 'error' );
				return false;
			}

			return $decoded_response;
		} catch ( \Exception $e ) {
			$this->log_message( 'Failed to decode response: ' . $e->getMessage(), 'error' );
			return false;
		}
	}

	/**
	 * Create user in Cognito
	 *
	 * @param array $user_data User data to create.
	 * @return array Creation result.
	 */
	public function create_user( $user_data ) {
		$this->log_message( "Creating user in Cognito: {$user_data['email']}" );

		$result = $this->send_to_lambda( 'create', array( 'user' => $user_data ) );

		// Handle the case where user already exists in Cognito.
		if ( ! $result && $this->is_user_already_exists_error() ) {
			$this->log_message( "User already exists in Cognito, attempting update instead: {$user_data['email']}", 'warning' );

			// Try to update the existing user instead.
			// Use email as cognito_user_id for the update since we don't have the actual ID yet.
			$user_data['cognito_user_id'] = $user_data['email'];
			$result                       = $this->update_user( $user_data );

			if ( $result ) {
				$this->log_message( "Successfully recovered by updating existing Cognito user: {$user_data['email']}" );
				return $result;
			} else {
				$this->log_message( "Failed to update existing Cognito user: {$user_data['email']}", 'error' );
				return false;
			}
		}

		// Store Cognito User ID if present in response - add safety check.
		// Use sub attribute as it's the standard unique identifier, fallback to Username.
		if ( isset( $result['result']['User'] ) && isset( $user_data['wp_user_id'] ) ) {
			$cognito_user_id = null;

			// First try to get sub from User attributes (most reliable)
			if ( isset( $result['result']['User']['Attributes'] ) ) {
				foreach ( $result['result']['User']['Attributes'] as $attr ) {
					if ( $attr['Name'] === 'sub' ) {
						$cognito_user_id = $attr['Value'];
						break;
					}
				}
			}

			// Fallback to Username if sub not found.
			if ( ! $cognito_user_id && isset( $result['result']['User']['Username'] ) ) {
				$cognito_user_id = $result['result']['User']['Username'];
			}

			if ( $cognito_user_id ) {
				update_user_meta( $user_data['wp_user_id'], 'cognito_user_id', $cognito_user_id );
				$this->log_message( "Updated Cognito User ID: {$cognito_user_id} for WordPress user: {$user_data['wp_user_id']}" );
			} else {
				$this->log_message( 'Could not find sub or Username in response', 'error' );
			}
		}

		return $result;
	}

	/**
	 * Update user in Cognito
	 *
	 * @param array $user_data User data to update.
	 * @return array Update result.
	 */
	public function update_user( $user_data ) {
		$this->log_message( "Updating user in Cognito: {$user_data['email']}" );

		$result = $this->send_to_lambda( 'update', array( 'user' => $user_data ) );

		// Persist the resolved Cognito sub returned by the Lambda. v3.0 always
		// surfaces it under result.User.Attributes (sub) so this same block
		// handles both the create-fallback path (Lambda actually created a new
		// user) and the normal update path (Lambda resolved an existing sub).
		// Comparing against stored meta — not against the request payload —
		// also self-heals legacy records that had the email written in as the
		// "sub" by older Lambda versions.
		if ( $result && isset( $result['result']['User'] ) && isset( $user_data['wp_user_id'] ) ) {
			$new_cognito_user_id = null;

			if ( isset( $result['result']['User']['Attributes'] ) ) {
				foreach ( $result['result']['User']['Attributes'] as $attr ) {
					if ( $attr['Name'] === 'sub' ) {
						$new_cognito_user_id = $attr['Value'];
						break;
					}
				}
			}

			if ( ! $new_cognito_user_id && isset( $result['result']['User']['Username'] ) ) {
				$new_cognito_user_id = $result['result']['User']['Username'];
			}

			if ( $new_cognito_user_id ) {
				$existing = get_user_meta( $user_data['wp_user_id'], 'cognito_user_id', true );
				if ( $new_cognito_user_id !== $existing ) {
					update_user_meta( $user_data['wp_user_id'], 'cognito_user_id', $new_cognito_user_id );
					$this->log_message( "Updated cognito_user_id for WordPress user {$user_data['wp_user_id']}: '{$existing}' -> '{$new_cognito_user_id}'" );
				}
			}
		}

		return $result;
	}

	/**
	 * Delete user from Cognito
	 *
	 * @param array $user_data User data for deletion.
	 * @return array Deletion result.
	 */
	public function delete_user( $user_data ) {
		$this->log_message( "Deleting user from Cognito: {$user_data['email']}" );
		return $this->send_to_lambda( 'delete', array( 'user' => $user_data ) );
	}

	/**
	 * Resolve Cognito subs for a batch of WordPress users.
	 *
	 * Used to backfill `cognito_user_id` user meta after bulk sync — the async
	 * SQS path never returns the sub to WordPress, so we have to ask for it
	 * after the consumer Lambda has created the Cognito records. Lambda caps
	 * the batch at 100; we also enforce the cap here so callers can't blow
	 * through the 15s wp_remote_post timeout.
	 *
	 * @param array $lookups Array of [ 'wp_user_id' => int, 'email' => string ].
	 * @return array|false { mappings: { wp_user_id: sub }, notFound: [], errored: [] } or false on transport failure.
	 */
	public function resolve_subs( $lookups ) {
		if ( empty( $lookups ) ) {
			return array(
				'mappings'  => array(),
				'notFound'  => array(),
				'errored'   => array(),
				'requested' => 0,
				'resolved'  => 0,
			);
		}

		$count = count( $lookups );
		if ( $count > 100 ) {
			$this->log_message( "resolve_subs called with {$count} lookups — capping at 100", 'warning' );
			$lookups = array_slice( $lookups, 0, 100 );
		}

		$response = $this->send_to_lambda( 'resolve_subs', array( 'lookups' => array_values( $lookups ) ) );
		if ( ! is_array( $response ) || ! isset( $response['result'] ) ) {
			return false;
		}
		return $response['result'];
	}

	/**
	 * Enqueue a batch of users for asynchronous sync via SQS.
	 *
	 * The Lambda fans the batch out to its SQS queue (one message per user) and
	 * returns immediately with `{ enqueuedCount, failedCount, failed }`. This
	 * keeps WordPress's 15s `wp_remote_post` window safe even for large bulk
	 * runs because the actual Cognito work happens asynchronously.
	 *
	 * @param array $users_batch Array of canonical User_Data payloads (max 25).
	 * @return array|false API response or false on failure.
	 */
	public function bulk_sync_enqueue( $users_batch ) {
		if ( empty( $users_batch ) ) {
			return array(
				'enqueuedCount' => 0,
				'failedCount'   => 0,
				'failed'        => array(),
			);
		}

		$count = count( $users_batch );
		$this->log_message( "Enqueuing batch of {$count} user(s) for async sync" );

		$response = $this->send_to_lambda( 'bulk_sync_enqueue', array( 'users' => $users_batch ) );

		// The Lambda response shape is {message: 'Accepted', result: {enqueuedCount, failedCount, failed}}.
		// Unwrap the envelope so callers see a flat result regardless of whether
		// the server ever changes the wrapper convention.
		if ( is_array( $response ) && isset( $response['result'] ) && is_array( $response['result'] ) ) {
			return $response['result'];
		}

		return $response;
	}

	/**
	 * Get operation logs
	 *
	 * @param int $limit Maximum number of logs to return.
	 * @return array Log entries.
	 */
	public function get_logs( $limit = 50 ) {
		return array_slice( get_option( self::LOG_OPTION, array() ), 0, $limit );
	}

	/**
	 * Clear all operation logs
	 */
	public function clear_logs() {
		update_option( self::LOG_OPTION, array() );
	}

	/**
	 * Get formatted logs for display - useful for debugging
	 */
	/**
	 * Get formatted logs for display
	 *
	 * @param int $limit Maximum number of logs to format.
	 * @return array Formatted log entries.
	 */
	public function get_formatted_logs( $limit = 50 ) {
		$logs      = $this->get_logs( $limit );
		$formatted = array();

		foreach ( $logs as $log ) {
			$formatted[] = sprintf(
				'[%s] %s: %s',
				$log['timestamp'],
				strtoupper( $log['level'] ),
				$log['message']
			);
		}

		return implode( "\n", $formatted );
	}

	/**
	 * Search logs for specific patterns - useful for debugging specific issues
	 */
	/**
	 * Search logs by term
	 *
	 * @param string $search_term Term to search for.
	 * @param int    $limit       Maximum results to return.
	 * @return array Matching log entries.
	 */
	public function search_logs( $search_term, $limit = 100 ) {
		$logs          = $this->get_logs( $limit );
		$matching_logs = array();

		foreach ( $logs as $log ) {
			if ( stripos( $log['message'], $search_term ) !== false ) {
				$matching_logs[] = $log;
			}
		}

		return $matching_logs;
	}

	/**
	 * Test API connection to Cognito
	 *
	 * @return array Test results with success status and details.
	 */
	public function test_connection() {
		$base_api_url = get_option( 'wp_cognito_sync_api_url' );
		$api_key      = get_option( 'wp_cognito_sync_api_key' );

		if ( empty( $base_api_url ) || empty( $api_key ) ) {
			return array(
				'success' => false,
				'message' => __( 'API URL and Key are required', 'wp-cognito-auth' ),
			);
		}

		// Construct the test URL properly.
		// Remove any trailing /sync if present, then add /test.
		$base_url = rtrim( $base_api_url, '/' );
		if ( substr( $base_url, -5 ) === '/sync' ) {
			$base_url = substr( $base_url, 0, -5 );
		}
		$test_url = $base_url . '/test';

		// Log the essential request details.
		$this->log_message( "Testing connection to: {$test_url}" );

		// Prepare request arguments.
		$request_args = array(
			'headers'     => array(
				'x-api-key'    => $api_key,
				'Content-Type' => 'application/json',
				'User-Agent'   => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
			),
			'timeout'     => 15,
			'sslverify'   => true,
			'blocking'    => true,
			'httpversion' => '1.1',
		);

		// Use GET request to the /test endpoint which returns a simple 200 response.
		$response = wp_remote_get( $test_url, $request_args );

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			$this->log_message( "WordPress HTTP Error: {$error_message}", 'error' );
			return array(
				'success' => false,
				'message' => __( 'Connection failed: ', 'wp-cognito-auth' ) . $error_message,
			);
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = wp_remote_retrieve_body( $response );

		$this->log_message( "Response HTTP Code: {$http_code}" );

		if ( $http_code === 200 ) {
			$decoded = json_decode( $body, true );
			if ( json_last_error() === JSON_ERROR_NONE && isset( $decoded['message'] ) ) {
				$message = $decoded['message'];
				$this->log_message( "Connection test successful: {$message}" );
				return array(
					'success' => true,
					'message' => $message,
				);
			} else {
				// Even if JSON parsing fails, 200 means success.
				$this->log_message( 'Connection test successful' );
				return array(
					'success' => true,
					'message' => __( 'Connection successful!', 'wp-cognito-auth' ),
				);
			}
		} else {
			// Provide specific error messages for common HTTP codes.
			$error_msg = '';
			switch ( $http_code ) {
				case 403:
					$error_msg = __( 'API key is invalid or not authorized. Please check your API key is correct and properly configured in AWS API Gateway usage plan.', 'wp-cognito-auth' );
					break;
				case 404:
					$error_msg = __( 'API endpoint not found. Please verify your API URL is correct.', 'wp-cognito-auth' );
					break;
				case 429:
					$error_msg = __( 'API rate limit exceeded. Please wait and try again.', 'wp-cognito-auth' );
					break;
				case 500:
				case 502:
				case 503:
					$error_msg = __( 'API server error. Please check AWS Lambda function logs and try again.', 'wp-cognito-auth' );
					break;
				default:
					$error_msg = sprintf( __( 'Connection failed. HTTP %1$d: %2$s', 'wp-cognito-auth' ), $http_code, $body );
			}

			$full_error = sprintf( '%s (HTTP %d)', $error_msg, $http_code );
			$this->log_message( "Connection test failed: {$full_error}", 'error' );
			return array(
				'success' => false,
				'message' => $full_error,
			);
		}
	}

	/**
	 * Get configuration debug information
	 *
	 * @return array Debug configuration details.
	 */
	public function get_config_debug_info() {
		$base_api_url = get_option( 'wp_cognito_sync_api_url' );
		$api_key      = get_option( 'wp_cognito_sync_api_key' );

		// Construct URLs properly.
		$base_url = rtrim( $base_api_url, '/' );
		if ( substr( $base_url, -5 ) === '/sync' ) {
			$base_url = substr( $base_url, 0, -5 );
		}

		return array(
			'base_url_setting'    => $base_api_url,
			'calculated_base_url' => $base_url,
			'test_url'            => $base_url . '/test',
			'sync_url'            => $base_url . '/sync',
			'api_key_set'         => ! empty( $api_key ),
			'api_key_length'      => strlen( $api_key ?? '' ),
			'api_key_prefix'      => ! empty( $api_key ) ? substr( $api_key, 0, 8 ) . '...' : 'Not set',
			'expected_format'     => 'https://{api-id}.execute-api.{region}.amazonaws.com/{stage}',
			'example_url'         => 'https://abc123def4.execute-api.us-west-2.amazonaws.com/prod',
		);
	}

	/**
	 * Test WordPress HTTP functionality and configuration
	 */
	public function test_wp_http_functionality() {
		$results = array();

		// Test 1: Basic HTTP GET to a simple endpoint.
		$simple_response = wp_remote_get( 'https://httpbin.org/get', array( 'timeout' => 10 ) );

		if ( is_wp_error( $simple_response ) ) {
			$results['basic_http'] = array(
				'success' => false,
				'message' => 'Basic HTTP failed: ' . $simple_response->get_error_message(),
			);
		} else {
			$code                  = wp_remote_retrieve_response_code( $simple_response );
			$results['basic_http'] = array(
				'success' => $code === 200,
				'message' => $code === 200 ? 'Basic HTTP working' : "Basic HTTP returned code: {$code}",
			);
		}

		// Test 2: SSL/TLS test to AWS.
		$aws_response = wp_remote_get( 'https://aws.amazon.com/', array( 'timeout' => 10 ) );

		if ( is_wp_error( $aws_response ) ) {
			$results['aws_ssl'] = array(
				'success' => false,
				'message' => 'AWS HTTPS failed: ' . $aws_response->get_error_message(),
			);
		} else {
			$code               = wp_remote_retrieve_response_code( $aws_response );
			$results['aws_ssl'] = array(
				'success' => $code === 200 || $code === 301 || $code === 302,
				'message' => $code === 200 ? 'AWS HTTPS working' : "AWS HTTPS returned code: {$code}",
			);
		}

		// Test 3: Check WordPress HTTP transport methods.
		$transports = array();
		if ( function_exists( 'curl_version' ) ) {
			$transports[] = 'cURL';
		}
		if ( function_exists( 'fsockopen' ) ) {
			$transports[] = 'fsockopen';
		}
		if ( function_exists( 'fopen' ) && ini_get( 'allow_url_fopen' ) ) {
			$transports[] = 'fopen';
		}

		$results['transports'] = array(
			'success' => ! empty( $transports ),
			'message' => 'Available transports: ' . implode( ', ', $transports ),
		);

		// Test 4: Check for any HTTP filters that might interfere.
		$http_filters = array();
		if ( has_filter( 'pre_http_request' ) ) {
			$http_filters[] = 'pre_http_request';
		}
		if ( has_filter( 'http_request_args' ) ) {
			$http_filters[] = 'http_request_args';
		}
		if ( has_filter( 'http_api_curl' ) ) {
			$http_filters[] = 'http_api_curl';
		}

		$results['filters'] = array(
			'success' => true,
			'message' => empty( $http_filters ) ? 'No HTTP filters detected' : 'HTTP filters present: ' . implode( ', ', $http_filters ),
		);

		// Test 5: Direct test to your API Gateway base.
		$base_api_url = get_option( 'wp_cognito_sync_api_url' );
		if ( ! empty( $base_api_url ) ) {
			$base_url = rtrim( $base_api_url, '/' );
			if ( substr( $base_url, -5 ) === '/sync' ) {
				$base_url = substr( $base_url, 0, -5 );
			}

			// Just test the base API Gateway URL without any API key.
			$api_response = wp_remote_get( $base_url, array( 'timeout' => 10 ) );

			if ( is_wp_error( $api_response ) ) {
				$results['api_base'] = array(
					'success' => false,
					'message' => 'API Gateway base connection failed: ' . $api_response->get_error_message(),
				);
			} else {
				$code                = wp_remote_retrieve_response_code( $api_response );
				$results['api_base'] = array(
					'success' => $code === 403, // We expect 403 without API key, which means we reached the gateway.
					'message' => "API Gateway base returned code: {$code} (403 expected without API key)",
				);
			}
		}

		return $results;
	}

	/**
	 * Perform a comprehensive test of the API configuration
	 */
	public function test_api_configuration() {
		$base_api_url = get_option( 'wp_cognito_sync_api_url' );
		$api_key      = get_option( 'wp_cognito_sync_api_key' );

		$results = array(
			'overall_success' => false,
			'tests'           => array(),
		);

		// Test 1: Check if URL and API key are set.
		$results['tests']['config_check'] = array(
			'name'    => 'Configuration Check',
			'success' => ! empty( $base_api_url ) && ! empty( $api_key ),
			'message' => ! empty( $base_api_url ) && ! empty( $api_key )
				? 'API URL and key are configured'
				: 'Missing API URL or key',
		);

		if ( ! $results['tests']['config_check']['success'] ) {
			return $results;
		}

		// Test 2: URL format validation.
		$url_pattern = '/^https:\/\/[a-z0-9]+\.execute-api\.[a-z0-9-]+\.amazonaws\.com\/[a-z0-9]+$/i';
		$url_valid   = preg_match( $url_pattern, $base_api_url );

		$results['tests']['url_format'] = array(
			'name'    => 'URL Format Validation',
			'success' => (bool) $url_valid,
			'message' => $url_valid
				? 'API URL format is valid'
				: 'API URL format appears invalid. Expected: https://{api-id}.execute-api.{region}.amazonaws.com/{stage}',
		);

		// Test 3: Test endpoint connectivity.
		$test_result                      = $this->test_connection();
		$results['tests']['connectivity'] = array(
			'name'    => 'Test Endpoint Connectivity',
			'success' => $test_result['success'],
			'message' => $test_result['message'],
		);

		// Test 4: API key format check (AWS API keys are typically 20-40 characters)
		$key_length    = strlen( $api_key );
		$key_format_ok = $key_length >= 20 && $key_length <= 50;

		$results['tests']['api_key_format'] = array(
			'name'    => 'API Key Format Check',
			'success' => $key_format_ok,
			'message' => $key_format_ok
				? 'API key length appears valid'
				: "API key length ({$key_length} chars) may be invalid. AWS API keys are typically 20-40 characters.",
		);

		// Overall success if all critical tests pass.
		$results['overall_success'] = $results['tests']['config_check']['success'] &&
									$results['tests']['connectivity']['success'];

		return $results;
	}

	/**
	 * Get total user count for statistics
	 *
	 * @return int Total number of users.
	 */
	public function get_user_count() {
		return count( get_users( array( 'fields' => 'ID' ) ) );
	}

	/**
	 * Get synchronization statistics
	 *
	 * @return array Sync statistics and metrics.
	 */
	public function get_sync_statistics() {
		$total_users        = $this->get_user_count();
		$users_with_cognito = count(
			get_users(
				array(
					'meta_key'     => 'cognito_user_id',
					'meta_compare' => 'EXISTS',
					'fields'       => 'ID',
				)
			)
		);

		return array(
			'total_users'     => $total_users,
			'synced_users'    => $users_with_cognito,
			'unsynced_users'  => $total_users - $users_with_cognito,
			'sync_percentage' => $total_users > 0 ? round( ( $users_with_cognito / $total_users ) * 100, 2 ) : 0,
		);
	}
}
