<?php
namespace WP_Cognito_Auth;

class ContentFilter {
    public function __construct() {
        add_filter('the_content', array($this, 'filter_content'), 10, 1);
        add_action('init', array($this, 'register_shortcodes'));
        add_action('wp', array($this, 'restrict_page_access'));
    }
    
    public function register_shortcodes() {
        add_shortcode('cognito_restrict', array($this, 'shortcode_restrict_content'));
        add_shortcode('cognito_user_info', array($this, 'shortcode_user_info'));
    }
    
    /**
     * Shortcode to restrict content by role
     * Usage: [cognito_restrict roles="administrator,editor"]Content here[/cognito_restrict]
     */
    public function shortcode_restrict_content($atts, $content = '') {
        $atts = shortcode_atts(array(
            'roles' => '',
            'groups' => '',
            'message' => __('You need to be logged in with the appropriate permissions to view this content.', 'wp-cognito-auth'),
            'login_url' => ''
        ), $atts);
        
        if (!is_user_logged_in()) {
            $login_url = !empty($atts['login_url']) ? $atts['login_url'] : add_query_arg('cognito_login', '1', wp_login_url());
            return '<div class="cognito-restricted-content">' . 
                   '<p>' . esc_html($atts['message']) . '</p>' .
                   '<p><a href="' . esc_url($login_url) . '" class="button">' . __('Login with Cognito', 'wp-cognito-auth') . '</a></p>' .
                   '</div>';
        }
        
        $current_user = wp_get_current_user();
        $required_roles = array_map('trim', explode(',', $atts['roles']));
        $required_groups = array_map('trim', explode(',', $atts['groups']));
        
        // Check WordPress roles
        if (!empty($atts['roles'])) {
            $user_has_role = false;
            foreach ($required_roles as $role) {
                if (in_array($role, $current_user->roles)) {
                    $user_has_role = true;
                    break;
                }
            }
            if (!$user_has_role) {
                return '<div class="cognito-restricted-content"><p>' . esc_html($atts['message']) . '</p></div>';
            }
        }
        
        // Check Cognito groups (if user is Cognito user)
        if (!empty($atts['groups'])) {
            $cognito_id = get_user_meta($current_user->ID, 'cognito_user_id', true);
            if (!empty($cognito_id)) {
                $user_groups = get_user_meta($current_user->ID, 'cognito_groups', true);
                if (empty($user_groups)) {
                    $user_groups = array();
                }
                
                $user_has_group = false;
                foreach ($required_groups as $group) {
                    if (in_array($group, $user_groups)) {
                        $user_has_group = true;
                        break;
                    }
                }
                if (!$user_has_group) {
                    return '<div class="cognito-restricted-content"><p>' . esc_html($atts['message']) . '</p></div>';
                }
            }
        }
        
        return do_shortcode($content);
    }
    
    /**
     * Shortcode to display user information
     * Usage: [cognito_user_info field="first_name"]
     */
    public function shortcode_user_info($atts) {
        $atts = shortcode_atts(array(
            'field' => 'display_name',
            'default' => ''
        ), $atts);
        
        if (!is_user_logged_in()) {
            return $atts['default'];
        }
        
        $current_user = wp_get_current_user();
        $cognito_id = get_user_meta($current_user->ID, 'cognito_user_id', true);
        
        switch ($atts['field']) {
            case 'first_name':
                return get_user_meta($current_user->ID, 'first_name', true) ?: $atts['default'];
            case 'last_name':
                return get_user_meta($current_user->ID, 'last_name', true) ?: $atts['default'];
            case 'email':
                return $current_user->user_email;
            case 'username':
                return $current_user->user_login;
            case 'display_name':
                return $current_user->display_name;
            case 'cognito_id':
                return $cognito_id ?: $atts['default'];
            case 'is_cognito_user':
                return !empty($cognito_id) ? 'yes' : 'no';
            case 'roles':
                return implode(', ', $current_user->roles);
            case 'cognito_groups':
                $groups = get_user_meta($current_user->ID, 'cognito_groups', true);
                return !empty($groups) ? implode(', ', $groups) : $atts['default'];
            default:
                return get_user_meta($current_user->ID, $atts['field'], true) ?: $atts['default'];
        }
    }
    
    /**
     * Filter content for [cognito_restrict] shortcodes
     */
    public function filter_content($content) {
        // Only process if content contains cognito shortcodes
        if (strpos($content, '[cognito_') === false) {
            return $content;
        }
        
        return do_shortcode($content);
    }
    
    /**
     * Restrict entire pages/posts based on meta fields
     */
    public function restrict_page_access() {
        if (!is_singular() || is_admin()) {
            return;
        }
        
        global $post;
        $required_roles = get_post_meta($post->ID, '_cognito_required_roles', true);
        $required_groups = get_post_meta($post->ID, '_cognito_required_groups', true);
        
        if (empty($required_roles) && empty($required_groups)) {
            return;
        }
        
        if (!is_user_logged_in()) {
            $this->redirect_to_login();
            return;
        }
        
        $current_user = wp_get_current_user();
        $access_granted = false;
        
        // Check WordPress roles
        if (!empty($required_roles)) {
            $required_roles = is_array($required_roles) ? $required_roles : array($required_roles);
            foreach ($required_roles as $role) {
                if (in_array($role, $current_user->roles)) {
                    $access_granted = true;
                    break;
                }
            }
        }
        
        // Check Cognito groups
        if (!$access_granted && !empty($required_groups)) {
            $cognito_id = get_user_meta($current_user->ID, 'cognito_user_id', true);
            if (!empty($cognito_id)) {
                $user_groups = get_user_meta($current_user->ID, 'cognito_groups', true);
                if (!empty($user_groups)) {
                    $required_groups = is_array($required_groups) ? $required_groups : array($required_groups);
                    foreach ($required_groups as $group) {
                        if (in_array($group, $user_groups)) {
                            $access_granted = true;
                            break;
                        }
                    }
                }
            }
        }
        
        if (!$access_granted) {
            $this->show_access_denied();
        }
    }
    
    private function redirect_to_login() {
        $login_url = add_query_arg(array(
            'cognito_login' => '1',
            'redirect_to' => get_permalink()
        ), home_url('/'));
        
        wp_redirect($login_url);
        exit;
    }
    
    private function show_access_denied() {
        $message = apply_filters('cognito_access_denied_message', 
            __('You do not have permission to access this content.', 'wp-cognito-auth')
        );
        
        wp_die($message, __('Access Denied', 'wp-cognito-auth'), array(
            'response' => 403,
            'back_link' => true
        ));
    }
}
