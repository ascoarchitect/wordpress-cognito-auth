<?php
namespace WP_Cognito_Auth;

class MetaBox {
    public function __construct() {
        add_action('add_meta_boxes', array($this, 'add_restriction_meta_box'));
        add_action('save_post', array($this, 'save_restriction_meta_box'));
    }
    
    public function add_restriction_meta_box() {
        $post_types = get_post_types(array('public' => true));
        
        foreach ($post_types as $post_type) {
            add_meta_box(
                'cognito-content-restrictions',
                __('Cognito Access Restrictions', 'wp-cognito-auth'),
                array($this, 'render_restriction_meta_box'),
                $post_type,
                'side',
                'default'
            );
        }
    }
    
    public function render_restriction_meta_box($post) {
        wp_nonce_field('cognito_restrictions_nonce', 'cognito_restrictions_nonce');
        
        $required_roles = get_post_meta($post->ID, '_cognito_required_roles', true);
        $required_groups = get_post_meta($post->ID, '_cognito_required_groups', true);
        $require_login_only = get_post_meta($post->ID, '_cognito_require_login_only', true);
        
        if (!is_array($required_roles)) {
            $required_roles = array();
        }
        if (!is_array($required_groups)) {
            $required_groups = array();
        }
        ?>
        <div class="cognito-restrictions-meta">
            <p><strong><?php _e('Restrict access to this content based on:', 'wp-cognito-auth'); ?></strong></p>
            
            <div class="cognito-restriction-section">
                <h4><?php _e('Authentication Level', 'wp-cognito-auth'); ?></h4>
                <label style="display: block; margin-bottom: 10px;">
                    <input type="checkbox" name="cognito_require_login_only" value="1" <?php checked($require_login_only, true); ?>>
                    <strong><?php _e('Require login only (any authenticated user)', 'wp-cognito-auth'); ?></strong>
                </label>
                <p class="description" style="margin-bottom: 10px;">
                    <?php _e('If checked, any logged-in user can access this content, regardless of their role or group membership. This overrides the specific role and group restrictions below.', 'wp-cognito-auth'); ?>
                </p>
            </div>
            
            <div class="cognito-restriction-section">
                <h4><?php _e('WordPress Roles', 'wp-cognito-auth'); ?></h4>
                <p class="description" style="margin-bottom: 5px;">
                    <?php _e('Only users with these specific roles can access this content (ignored if "Require login only" is checked above)', 'wp-cognito-auth'); ?>
                </p>
                <?php
                $roles = get_editable_roles();
                foreach ($roles as $role_key => $role_info) {
                    $checked = in_array($role_key, $required_roles);
                    echo '<label style="display: block; margin-bottom: 5px;">';
                    echo '<input type="checkbox" name="cognito_required_roles[]" value="' . esc_attr($role_key) . '"' . checked($checked, true, false) . '> ';
                    echo esc_html($role_info['name']);
                    echo '</label>';
                }
                ?>
            </div>
            
            <div class="cognito-restriction-section" style="margin-top: 15px;">
                <h4><?php _e('Cognito Groups', 'wp-cognito-auth'); ?></h4>
                <p class="description"><?php _e('Enter Cognito group names (one per line). Only users in these groups can access this content (ignored if "Require login only" is checked above)', 'wp-cognito-auth'); ?></p>
                <textarea name="cognito_required_groups" rows="4" style="width: 100%;"><?php echo esc_textarea(implode("\n", $required_groups)); ?></textarea>
            </div>
            
            <div style="margin-top: 15px;">
                <p class="description">
                    <?php _e('Priority: "Require login only" overrides all other restrictions. If unchecked and any roles or groups are selected, only users with those specific permissions can access this content.', 'wp-cognito-auth'); ?>
                </p>
            </div>
        </div>
        
        <style>
        .cognito-restrictions-meta h4 {
            margin: 10px 0 5px 0;
            font-weight: 600;
        }
        .cognito-restriction-section {
            border: 1px solid #ddd;
            padding: 10px;
            background: #f9f9f9;
            margin-bottom: 10px;
        }
        .cognito-restriction-section.login-only {
            background: #e8f5e8;
            border-color: #4caf50;
        }
        </style>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var $loginOnly = $('input[name="cognito_require_login_only"]');
            var $roleSection = $('.cognito-restriction-section').eq(1);
            var $groupSection = $('.cognito-restriction-section').eq(2);
            
            function toggleSections() {
                if ($loginOnly.is(':checked')) {
                    $roleSection.slideUp();
                    $groupSection.slideUp();
                    $('.cognito-restriction-section').eq(0).addClass('login-only');
                } else {
                    $roleSection.slideDown();
                    $groupSection.slideDown();
                    $('.cognito-restriction-section').eq(0).removeClass('login-only');
                }
            }
            
            $loginOnly.on('change', toggleSections);
            toggleSections(); // Initial state
        });
        </script>
        <?php
    }
    
    public function save_restriction_meta_box($post_id) {
        if (!isset($_POST['cognito_restrictions_nonce']) || 
            !wp_verify_nonce($_POST['cognito_restrictions_nonce'], 'cognito_restrictions_nonce')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Save login-only requirement
        $require_login_only = isset($_POST['cognito_require_login_only']) ? true : false;
        update_post_meta($post_id, '_cognito_require_login_only', $require_login_only);
        
        // Save required roles
        $required_roles = isset($_POST['cognito_required_roles']) ? $_POST['cognito_required_roles'] : array();
        $required_roles = array_map('sanitize_text_field', $required_roles);
        update_post_meta($post_id, '_cognito_required_roles', $required_roles);
        
        // Save required groups
        $required_groups = isset($_POST['cognito_required_groups']) ? $_POST['cognito_required_groups'] : '';
        $required_groups = array_filter(array_map('trim', explode("\n", sanitize_textarea_field($required_groups))));
        update_post_meta($post_id, '_cognito_required_groups', $required_groups);
    }
}
