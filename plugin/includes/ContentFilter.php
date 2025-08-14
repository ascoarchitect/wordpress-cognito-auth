<?php
/**
 * Content filtering and shortcode functionality for Cognito authentication
 *
 * @package WP_Cognito_Auth
 */

namespace WP_Cognito_Auth;

/**
 * Class ContentFilter
 *
 * Handles content filtering, shortcodes, and page access restrictions based on Cognito authentication.
 */
class ContentFilter {
	/**
	 * Constructor - Set up hooks and shortcodes
	 */
	public function __construct() {
		add_filter( 'the_content', array( $this, 'filter_content' ), 10, 1 );
		add_action( 'init', array( $this, 'register_shortcodes' ) );
		add_action( 'wp', array( $this, 'restrict_page_access' ) );
		add_action( 'wp_head', array( $this, 'add_cognito_button_styles' ) );
	}

	/**
	 * Register shortcodes for Cognito functionality
	 */
	public function register_shortcodes() {
		add_shortcode( 'cognito_restrict', array( $this, 'shortcode_restrict_content' ) );
		add_shortcode( 'cognito_user_info', array( $this, 'shortcode_user_info' ) );
	}

	/**
	 * Shortcode to restrict content by role
	 *
	 * @param array  $atts    Shortcode attributes.
	 * @param string $content Content within shortcode tags.
	 * @return string Filtered content or access denial message.
	 *
	 * Usage: [cognito_restrict roles="administrator,editor"]Content here[/cognito_restrict]
	 * Usage: [cognito_restrict groups="admin,premium"]Content here[/cognito_restrict]
	 * Usage: [cognito_restrict login_only="1"]Content here[/cognito_restrict]
	 * Usage: [cognito_restrict login_only="true" message="Please log in to view this content"]Content here[/cognito_restrict]
	 */
	public function shortcode_restrict_content( $atts, $content = '' ) {
		$atts = shortcode_atts(
			array(
				'roles'      => '',
				'groups'     => '',
				'login_only' => '',
				'message'    => __( 'You need to be logged in with the appropriate permissions to view this content.', 'wp-cognito-auth' ),
				'login_url'  => '',
			),
			$atts
		);

		if ( ! is_user_logged_in() ) {
			$current_url = get_permalink();
			if ( ! empty( $atts['login_url'] ) ) {
				$login_url = $atts['login_url'];
			} else {
				$login_url = $this->get_login_url( $current_url );
			}

			$button_text = $this->get_login_button_text();

			return '<div class="cognito-restricted-content">' .
					'<p>' . esc_html( $atts['message'] ) . '</p>' .
					'<p><a href="' . esc_url( $login_url ) . '" class="button cognito-login-button">' . $button_text . '</a></p>' .
					'</div>';
		}

		$current_user    = wp_get_current_user();
		$required_roles  = array_map( 'trim', explode( ',', $atts['roles'] ) );
		$required_groups = array_map( 'trim', explode( ',', $atts['groups'] ) );

		// If login_only is set, allow access to any authenticated user.
		if ( ! empty( $atts['login_only'] ) && ( $atts['login_only'] === '1' || $atts['login_only'] === 'true' ) ) {
			return do_shortcode( $content );
		}

		// Check WordPress roles.
		if ( ! empty( $atts['roles'] ) ) {
			$user_has_role = false;
			foreach ( $required_roles as $role ) {
				if ( in_array( $role, $current_user->roles ) ) {
					$user_has_role = true;
					break;
				}
			}
			if ( ! $user_has_role ) {
				// For logged-in users who don't have the right role, offer re-authentication.
				$current_url = get_permalink();
				$reauth_url  = $this->get_login_url( $current_url, true );
				$button_text = $this->get_login_button_text( true );

				$actions = sprintf(
					'<p><a href="%s" class="button cognito-login-button">%s</a></p>',
					esc_url( $reauth_url ),
					$button_text
				);

				return '<div class="cognito-restricted-content"><p>' . esc_html( $atts['message'] ) . '</p>' . $actions . '</div>';
			}
		}

		// Check Cognito groups (if user is Cognito user)
		if ( ! empty( $atts['groups'] ) ) {
			$cognito_id = get_user_meta( $current_user->ID, 'cognito_user_id', true );
			if ( ! empty( $cognito_id ) ) {
				$user_groups = get_user_meta( $current_user->ID, 'cognito_groups', true );
				if ( empty( $user_groups ) ) {
					$user_groups = array();
				}

				$user_has_group = false;
				foreach ( $required_groups as $group ) {
					if ( in_array( $group, $user_groups ) ) {
						$user_has_group = true;
						break;
					}
				}
				if ( ! $user_has_group ) {
					// For logged-in users who don't have the right group, offer re-authentication.
					$current_url = get_permalink();
					$reauth_url  = $this->get_login_url( $current_url, true );
					$button_text = $this->get_login_button_text( true );

					$actions = sprintf(
						'<p><a href="%s" class="button cognito-login-button">%s</a></p>',
						esc_url( $reauth_url ),
						$button_text
					);

					return '<div class="cognito-restricted-content"><p>' . esc_html( $atts['message'] ) . '</p>' . $actions . '</div>';
				}
			} else {
				// User is not a Cognito user but groups are required - offer re-authentication.
				$current_url = get_permalink();
				$reauth_url  = $this->get_login_url( $current_url, true );

				$actions = sprintf(
					'<p><a href="%s" class="button cognito-login-button">%s</a></p>',
					esc_url( $reauth_url ),
					__( 'Login with Cognito account', 'wp-cognito-auth' )
				);

				return '<div class="cognito-restricted-content"><p>' . esc_html( $atts['message'] ) . '</p>' . $actions . '</div>';
			}
		}

		return do_shortcode( $content );
	}

	/**
	 * Shortcode to display user information
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string User information or default value.
	 *
	 * Usage: [cognito_user_info field="first_name"]
	 */
	public function shortcode_user_info( $atts ) {
		$atts = shortcode_atts(
			array(
				'field'   => 'display_name',
				'default' => '',
			),
			$atts
		);

		if ( ! is_user_logged_in() ) {
			return $atts['default'];
		}

		$current_user = wp_get_current_user();
		$cognito_id   = get_user_meta( $current_user->ID, 'cognito_user_id', true );

		switch ( $atts['field'] ) {
			case 'first_name':
				return get_user_meta( $current_user->ID, 'first_name', true ) ?: $atts['default'];
			case 'last_name':
				return get_user_meta( $current_user->ID, 'last_name', true ) ?: $atts['default'];
			case 'email':
				return $current_user->user_email;
			case 'username':
				return $current_user->user_login;
			case 'display_name':
				return $current_user->display_name;
			case 'cognito_id':
				return $cognito_id ?: $atts['default'];
			case 'is_cognito_user':
				return ! empty( $cognito_id ) ? 'yes' : 'no';
			case 'roles':
				return implode( ', ', $current_user->roles );
			case 'cognito_groups':
				$groups = get_user_meta( $current_user->ID, 'cognito_groups', true );
				return ! empty( $groups ) ? implode( ', ', $groups ) : $atts['default'];
			default:
				return get_user_meta( $current_user->ID, $atts['field'], true ) ?: $atts['default'];
		}
	}

	/**
	 * Filter content for [cognito_restrict] shortcodes
	 *
	 * @param string $content Post content to filter.
	 * @return string Processed content.
	 */
	public function filter_content( $content ) {
		// Only process if content contains cognito shortcodes.
		if ( strpos( $content, '[cognito_' ) === false ) {
			return $content;
		}

		return do_shortcode( $content );
	}

	/**
	 * Restrict entire pages/posts based on meta fields
	 */
	public function restrict_page_access() {
		if ( ! is_singular() || is_admin() ) {
			return;
		}

		global $post;
		$required_roles     = get_post_meta( $post->ID, '_cognito_required_roles', true );
		$required_groups    = get_post_meta( $post->ID, '_cognito_required_groups', true );
		$require_login_only = get_post_meta( $post->ID, '_cognito_require_login_only', true );

		if ( empty( $required_roles ) && empty( $required_groups ) && ! $require_login_only ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			$this->redirect_to_login();
			return;
		}

		// If login-only is enabled, allow access to any authenticated user.
		if ( $require_login_only ) {
			return;
		}

		// If no specific roles or groups are required but login_only is false, no restrictions apply.
		if ( empty( $required_roles ) && empty( $required_groups ) ) {
			return;
		}

		$current_user   = wp_get_current_user();
		$access_granted = false;

		// Check WordPress roles.
		if ( ! empty( $required_roles ) ) {
			$required_roles = is_array( $required_roles ) ? $required_roles : array( $required_roles );
			foreach ( $required_roles as $role ) {
				if ( in_array( $role, $current_user->roles ) ) {
					$access_granted = true;
					break;
				}
			}
		}

		// Check Cognito groups.
		if ( ! $access_granted && ! empty( $required_groups ) ) {
			$cognito_id = get_user_meta( $current_user->ID, 'cognito_user_id', true );
			if ( ! empty( $cognito_id ) ) {
				$user_groups = get_user_meta( $current_user->ID, 'cognito_groups', true );
				if ( ! empty( $user_groups ) ) {
					$required_groups = is_array( $required_groups ) ? $required_groups : array( $required_groups );
					foreach ( $required_groups as $group ) {
						if ( in_array( $group, $user_groups ) ) {
							$access_granted = true;
							break;
						}
					}
				}
			}
		}

		if ( ! $access_granted ) {
			$this->show_access_denied();
		}
	}

	/**
	 * Helper function to generate login URL based on force Cognito setting
	 *
	 * @param string $redirect_url   URL to redirect to after login.
	 * @param bool   $include_reauth Whether to include reauthentication parameter.
	 * @return string Login URL.
	 */
	private function get_login_url( $redirect_url, $include_reauth = false ) {
		$features      = get_option( 'wp_cognito_features', array() );
		$force_cognito = get_option( 'wp_cognito_auth_force_cognito', false );

		if ( ! empty( $features['authentication'] ) && $force_cognito ) {
			// Force Cognito - direct to Cognito login.
			$params = array(
				'cognito_login' => '1',
				'redirect_to'   => urlencode( $redirect_url ),
			);
			if ( $include_reauth ) {
				$params['reauth'] = '1';
			}
			return add_query_arg( $params, wp_login_url() );
		} else {
			// Don't force - go to WordPress login page.
			return add_query_arg( 'redirect_to', urlencode( $redirect_url ), wp_login_url() );
		}
	}

	/**
	 * Helper function to get appropriate button text based on force setting
	 *
	 * @param bool $include_different Whether to include "different account" text.
	 * @return string Login button text.
	 */
	private function get_login_button_text( $include_different = false ) {
		$features      = get_option( 'wp_cognito_features', array() );
		$force_cognito = get_option( 'wp_cognito_auth_force_cognito', false );

		// Use customizable button text for Cognito authentication.
		if ( ! empty( $features['authentication'] ) ) {
			if ( $include_different ) {
				if ( $force_cognito ) {
					// For force mode, offer login with different account.
					$custom_text = get_option( 'wp_cognito_auth_login_button_text', 'Login with Cognito' );
					// Replace "Login" with "Login with different" to maintain context.
					return str_replace( 'Login', __( 'Login with different', 'wp-cognito-auth' ), $custom_text );
				} else {
					return __( 'Login with different account', 'wp-cognito-auth' );
				}
			} elseif ( $force_cognito ) {
					// Use the customizable text.
					return get_option( 'wp_cognito_auth_login_button_text', 'Login with Cognito' );
			} else {
					return __( 'Login', 'wp-cognito-auth' );
			}
		} else {
			return $include_different ?
					__( 'Login with different account', 'wp-cognito-auth' ) :
					__( 'Login', 'wp-cognito-auth' );
		}
	}

	/**
	 * Redirect user to login page
	 */
	private function redirect_to_login() {
		$current_url = get_permalink();
		$login_url   = $this->get_login_url( $current_url );

		wp_redirect( $login_url );
		exit;
	}

	/**
	 * Show access denied page with re-authentication options
	 */
	private function show_access_denied() {
		$current_url = get_permalink();
		$reauth_url  = $this->get_login_url( $current_url, true );
		$reauth_text = $this->get_login_button_text( true );

		$message = apply_filters(
			'cognito_access_denied_message',
			__( 'You do not have permission to access this content.', 'wp-cognito-auth' )
		);

		$actions = array(
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( $reauth_url ),
				$reauth_text
			),
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( home_url() ),
				__( 'Return to homepage', 'wp-cognito-auth' )
			),
		);

		$message .= '<p>' . implode( ' | ', $actions ) . '</p>';

		wp_die(
			$message,
			__( 'Access Denied', 'wp-cognito-auth' ),
			array(
				'response'  => 403,
				'back_link' => false,
			)
		);
	}

	/**
	 * Add custom styles for Cognito buttons
	 */
	public function add_cognito_button_styles() {
		// Only add styles if authentication feature is enabled.
		$features = get_option( 'wp_cognito_features', array() );
		if ( empty( $features['authentication'] ) ) {
			return;
		}

		$button_color = get_option( 'wp_cognito_auth_login_button_color', '#ff9900' );
		$text_color   = get_option( 'wp_cognito_auth_login_button_text_color', '#ffffff' );
		$hover_color  = $this->darken_hex_color( $button_color, 20 );
		?>
		<style type="text/css">
			.cognito-login-button {
				background-color: <?php echo esc_attr( $button_color ); ?> !important;
				border-color: <?php echo esc_attr( $button_color ); ?> !important;
				color: <?php echo esc_attr( $text_color ); ?> !important;
				text-decoration: none !important;
				transition: all 0.2s ease;
			}
			
			.cognito-login-button:hover,
			.cognito-login-button:focus {
				background-color: <?php echo esc_attr( $hover_color ); ?> !important;
				border-color: <?php echo esc_attr( $hover_color ); ?> !important;
				color: <?php echo esc_attr( $text_color ); ?> !important;
			}
			
			.cognito-restricted-content {
				padding: 20px;
				background-color: #f9f9f9;
				border: 1px solid #ddd;
				border-radius: 4px;
				margin: 20px 0;
				text-align: center;
			}
			
			.cognito-restricted-content p {
				margin-bottom: 15px;
			}
		</style>
		<?php
	}

	/**
	 * Helper function to darken a hex color by a percentage
	 *
	 * @param string $hex     Hex color code (with or without #).
	 * @param int    $percent Percentage to darken (0-100).
	 * @return string Darkened hex color code.
	 */
	private function darken_hex_color( $hex, $percent ) {
		// Remove # if present.
		$hex = str_replace( '#', '', $hex );

		// Validate hex color.
		if ( strlen( $hex ) !== 6 ) {
			return '#ff9900'; // Return default if invalid.
		}

		// Parse RGB values.
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );

		// Darken by percentage.
		$factor = ( 100 - $percent ) / 100;
		$r      = max( 0, min( 255, floor( $r * $factor ) ) );
		$g      = max( 0, min( 255, floor( $g * $factor ) ) );
		$b      = max( 0, min( 255, floor( $b * $factor ) ) );

		// Convert back to hex.
		return sprintf( '#%02x%02x%02x', $r, $g, $b );
	}
}
