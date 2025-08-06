<?php
/*
Plugin Name: Generate WooCommerce Products from Tasks
Description: Creates WooCommerce products from tasks in MongoDB (via render.com API)
Version: 1.0.0
Author: Lucas Gros
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        ?>
        <div class="notice notice-error">
            <p><?php _e('Generate WooCommerce Products from Tasks requires WooCommerce to be installed and active.', 'generate-products-from-tasks'); ?></p>
        </div>
        <?php
    });
    return;
}

class Render_Tasks_To_Products {
    private $api_url = JOY_AI_BACKEND_URL . '/api';
    private $endpoints;
    private $attribute_mappings = [
        'location' => 'Location',
        'region' => 'Region',
        'taskTime' => 'Task Time',
        'commitment' => 'Commitment',
        'openFor' => 'Open For',
        'skills' => 'Skills',
        'traits' => 'Traits'
    ];

    public function __construct() {
        $this->endpoints = [
            'tasks' => $this->api_url . '/tasks/full'
        ];

        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));

        // Schedule auto sync
        add_action('init', array($this, 'schedule_sync'));
        add_action('product_sync_hourly', array($this, 'sync_tasks_to_products'));

        // Add AJAX handlers
        add_action('wp_ajax_manual_sync_products', array($this, 'handle_manual_sync'));
        add_action('wp_ajax_fetch_tasks_for_products', array($this, 'fetch_tasks'));

        // Add admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Initialize WooCommerce attributes on activation
        register_activation_hook(__FILE__, array($this, 'create_product_attributes'));
    }

    public function create_product_attributes() {
        // Create WooCommerce attributes for task fields
        foreach ($this->attribute_mappings as $slug => $name) {
            $attribute_slug = 'pa_' . sanitize_title($slug);
            
            if (!taxonomy_exists($attribute_slug)) {
                $args = array(
                    'slug' => $slug,
                    'name' => $name,
                    'type' => 'text',
                    'order_by' => 'menu_order',
                    'has_archives' => false,
                );
                
                wc_create_attribute($args);
                
                // Register the taxonomy
                register_taxonomy($attribute_slug, 'product', array(
                    'labels' => array(
                        'name' => $name,
                    ),
                    'hierarchical' => false,
                    'show_ui' => false,
                    'query_var' => true,
                    'rewrite' => false,
                ));
            }
        }
        
        // Clear caches
        delete_transient('wc_attribute_taxonomies');
        WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Generate Products from Tasks',
            'Tasks to Products',
            'manage_woocommerce',
            'render-tasks-to-products',
            array($this, 'render_admin_page')
        );
    }

    public function register_settings() {
        register_setting('render_products_settings', 'render_products_post_status');
        register_setting('render_products_settings', 'render_products_auto_sync');
        register_setting('render_products_settings', 'render_products_default_price');
        register_setting('render_products_settings', 'render_products_default_stock');
        register_setting('render_products_settings', 'render_products_visibility');
    }

    public function enqueue_admin_assets($hook) {
        if ('woocommerce_page_render-tasks-to-products' !== $hook) {
            return;
        }

        wp_enqueue_script(
            'render-products-admin',
            plugin_dir_url(__FILE__) . 'admin/js/admin.js',
            array('jquery'),
            '1.0.0',
            true
        );

        wp_localize_script('render-products-admin', 'renderProductsAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('render_products_nonce')
        ));

        wp_enqueue_style(
            'render-products-admin',
            plugin_dir_url(__FILE__) . 'admin/css/admin.css',
            array(),
            '1.0.0'
        );
    }

    public function fetch_tasks() {
        check_ajax_referer('render_products_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $response = wp_remote_get($this->endpoints['tasks']);
        
        if (is_wp_error($response)) {
            wp_send_json_error('Failed to fetch tasks: ' . $response->get_error_message());
            return;
        }

        $tasks = json_decode(wp_remote_retrieve_body($response), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error('Invalid JSON response');
            return;
        }

        wp_send_json_success($tasks);
    }

    public function sync_tasks_to_products() {
        error_log('[Generate Products] Starting sync from: ' . $this->endpoints['tasks']);
        
        $response = wp_remote_get($this->endpoints['tasks']);
        
        if (is_wp_error($response)) {
            error_log('[Generate Products] Failed to fetch tasks: ' . $response->get_error_message());
            return false;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        error_log('[Generate Products] API Response Code: ' . $http_code);
        
        if ($http_code !== 200) {
            error_log('[Generate Products] Unexpected HTTP response code: ' . $http_code);
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $tasks = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[Generate Products] Invalid JSON response: ' . json_last_error_msg());
            error_log('[Generate Products] Response body: ' . substr($body, 0, 500));
            return false;
        }

        error_log('[Generate Products] Successfully fetched ' . count($tasks) . ' tasks');
        
        $synced_count = 0;
        $error_count = 0;
        $skipped_count = 0;

        foreach ($tasks as $index => $task) {
            // Debug first task structure
            if ($index === 0) {
                error_log('[Generate Products] First task structure: ' . print_r(array_keys($task), true));
            }
            
            if (!isset($task['_id']) || !isset($task['title'])) {
                error_log('[Generate Products] Skipping task at index ' . $index . ' - missing _id or title');
                $error_count++;
                continue;
            }
            
            // Skip inactive tasks (case-insensitive)
            if (isset($task['status']) && strtolower($task['status']) !== 'active') {
                $skipped_count++;
                continue;
            }

            error_log('[Generate Products] Processing task: ' . $task['_id'] . ' - ' . $task['title']);
            
            $product_id = $this->create_or_update_product($task);
            
            if ($product_id) {
                $synced_count++;
                error_log('[Generate Products] Successfully synced task ' . $task['_id'] . ' as product ID: ' . $product_id);
            } else {
                $error_count++;
                error_log('[Generate Products] Failed to sync task ' . $task['_id']);
            }
        }

        error_log("[Generate Products] Sync completed: $synced_count synced, $error_count errors, $skipped_count skipped");
        
        return array(
            'synced' => $synced_count,
            'errors' => $error_count,
            'skipped' => $skipped_count,
            'total' => count($tasks)
        );
    }

    private function create_or_update_product($task) {
        // Check if product already exists
        $existing_product_id = $this->get_product_by_task_id($task['_id']);
        
        $action = $existing_product_id ? 'update' : 'create';
        error_log('[Generate Products] Will ' . $action . ' product for task: ' . $task['_id']);
        
        $product_data = array(
            'post_title' => sanitize_text_field($task['title']),
            'post_content' => isset($task['description']) ? sanitize_textarea_field($task['description']) : '',
            'post_status' => get_option('render_products_post_status', 'draft'),
            'post_type' => 'product',
        );

        if ($existing_product_id) {
            $product_data['ID'] = $existing_product_id;
            $product_id = wp_update_post($product_data);
            error_log('[Generate Products] Updated existing product ID: ' . $existing_product_id);
        } else {
            $product_id = wp_insert_post($product_data);
            error_log('[Generate Products] Created new product ID: ' . $product_id);
        }

        if (is_wp_error($product_id)) {
            error_log('[Generate Products] Failed to create/update product: ' . $product_id->get_error_message());
            return false;
        }

        // Set product type
        wp_set_object_terms($product_id, 'simple', 'product_type');

        // Update product meta
        update_post_meta($product_id, '_task_id', sanitize_text_field($task['_id']));
        update_post_meta($product_id, '_price', get_option('render_products_default_price', '0'));
        update_post_meta($product_id, '_regular_price', get_option('render_products_default_price', '0'));
        update_post_meta($product_id, '_stock_status', 'instock');
        update_post_meta($product_id, '_manage_stock', 'no');
        update_post_meta($product_id, '_visibility', get_option('render_products_visibility', 'visible'));

        // Set organization as brand
        if (isset($task['organization'])) {
            update_post_meta($product_id, '_product_brand', sanitize_text_field($task['organization']));
        }

        // Handle categories
        if (isset($task['categories']) && is_array($task['categories'])) {
            $this->set_product_categories($product_id, $task['categories']);
        }

        // Handle tags
        if (isset($task['tags']) && is_array($task['tags'])) {
            $this->set_product_tags($product_id, $task['tags']);
        }

        // Set product attributes
        $this->set_product_attributes($product_id, $task);

        // Set default product image
        $this->set_default_product_image($product_id);

        return $product_id;
    }

    private function get_product_by_task_id($task_id) {
        global $wpdb;
        
        $product_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta 
            WHERE meta_key = '_task_id' AND meta_value = %s 
            LIMIT 1",
            $task_id
        ));
        
        return $product_id ? intval($product_id) : null;
    }

    private function set_product_categories($product_id, $categories) {
        error_log('[Generate Products] Setting categories for product ' . $product_id . ': ' . print_r($categories, true));
        
        $category_ids = array();
        
        foreach ($categories as $category_name) {
            // Debug: Check if category is numeric
            error_log('[Generate Products] Processing category: ' . $category_name . ' (type: ' . gettype($category_name) . ')');
            
            // Use category name exactly as it comes from the API
            $term = term_exists($category_name, 'product_cat');
            
            if (!$term) {
                $term = wp_insert_term($category_name, 'product_cat');
                error_log('[Generate Products] Created new category: ' . $category_name);
            }
            
            if (!is_wp_error($term)) {
                $category_ids[] = is_array($term) ? $term['term_id'] : $term;
            } else {
                error_log('[Generate Products] Failed to create category ' . $category_name . ': ' . $term->get_error_message());
            }
        }
        
        if (!empty($category_ids)) {
            $result = wp_set_object_terms($product_id, $category_ids, 'product_cat');
            if (is_wp_error($result)) {
                error_log('[Generate Products] Failed to set categories: ' . $result->get_error_message());
            } else {
                error_log('[Generate Products] Successfully set ' . count($category_ids) . ' categories');
            }
        }
    }

    private function map_category_name($category) {
        $category_map = [
            'Sports & Recreation' => '⚽ Sports & Recreation',
            'Environmental' => '🌱 Environmental',
            'Educational' => '🎓 Educational',
            'Arts & Culture' => '🎨 Arts & Culture',
            'Animal care' => '🐴 Animal care',
            'Family Friendly' => '👨‍👩‍👧‍👦 Family Friendly',
            'People with Diverse Abilities' => '👨‍🦽 People with Diverse Abilities',
            'Seniors' => '👴🏽 Seniors',
            'Retail' => '💰 Retail',
            'Administration' => '📑 Administration',
            'Maintenance' => '🛠 Maintenance',
            'Anything with Food' => '🥤 Anything with Food',
            'Children/Young People' => '🧒🏻 Children/Young People',
            'Social/Care Giving' => '🩺 Social/Care Giving',
            'Other' => '🙂 Other'
        ];
        
        return isset($category_map[$category]) ? $category_map[$category] : $category;
    }

    private function set_product_tags($product_id, $tags) {
        $tag_names = array();
        
        foreach ($tags as $tag) {
            $tag_name = is_array($tag) ? $tag['name'] : $tag;
            $tag_names[] = sanitize_text_field($tag_name);
        }
        
        if (!empty($tag_names)) {
            wp_set_object_terms($product_id, $tag_names, 'product_tag');
        }
    }

    private function set_product_attributes($product_id, $task) {
        $attributes = array();
        
        foreach ($this->attribute_mappings as $task_field => $attribute_name) {
            if (!isset($task[$task_field])) {
                continue;
            }
            
            $value = $task[$task_field];
            $attribute_slug = sanitize_title($task_field);
            
            // Handle array values (like openFor)
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            
            $attributes[$attribute_slug] = array(
                'name' => $attribute_name,
                'value' => $value,
                'is_visible' => 1,
                'is_taxonomy' => 0
            );
        }
        
        if (!empty($attributes)) {
            update_post_meta($product_id, '_product_attributes', $attributes);
        }
    }

    private function set_default_product_image($product_id) {
        // Check if product already has an image
        if (has_post_thumbnail($product_id)) {
            return;
        }
        
        // Set default image path from your site
        $default_image_url = 'https://joyfromgiving.org/wp-content/uploads/2025/02/universal-photo.png';
        
        // Check if image already exists in media library
        $attachment_id = attachment_url_to_postid($default_image_url);
        
        if ($attachment_id) {
            set_post_thumbnail($product_id, $attachment_id);
        }
    }

    public function handle_manual_sync() {
        check_ajax_referer('render_products_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $result = $this->sync_tasks_to_products();
        
        if ($result) {
            wp_send_json_success(array(
                'message' => sprintf('Sync completed: %d products synced, %d errors', 
                    $result['synced'], 
                    $result['errors']
                )
            ));
        } else {
            wp_send_json_error('Sync failed');
        }
    }

    public function schedule_sync() {
        if (get_option('render_products_auto_sync', true) && !wp_next_scheduled('product_sync_hourly')) {
            wp_schedule_event(time(), 'hourly', 'product_sync_hourly');
        }
    }

    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>Generate WooCommerce Products from Tasks</h1>
            
            <div class="render-products-container">
                <div class="render-products-settings">
                    <h2>Settings</h2>
                    <form method="post" action="options.php">
                        <?php settings_fields('render_products_settings'); ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Product Status</th>
                                <td>
                                    <select name="render_products_post_status">
                                        <?php
                                        $current_status = get_option('render_products_post_status', 'draft');
                                        $statuses = array('publish', 'draft', 'pending');
                                        foreach ($statuses as $status) {
                                            printf(
                                                '<option value="%s" %s>%s</option>',
                                                esc_attr($status),
                                                selected($current_status, $status, false),
                                                esc_html(ucfirst($status))
                                            );
                                        }
                                        ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Default Price</th>
                                <td>
                                    <input type="number" 
                                        name="render_products_default_price" 
                                        value="<?php echo esc_attr(get_option('render_products_default_price', '0')); ?>"
                                        step="0.01"
                                        min="0"
                                        class="regular-text">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Product Visibility</th>
                                <td>
                                    <select name="render_products_visibility">
                                        <?php
                                        $current_visibility = get_option('render_products_visibility', 'visible');
                                        $visibilities = array(
                                            'visible' => 'Shop and search results',
                                            'catalog' => 'Shop only',
                                            'search' => 'Search results only',
                                            'hidden' => 'Hidden'
                                        );
                                        foreach ($visibilities as $value => $label) {
                                            printf(
                                                '<option value="%s" %s>%s</option>',
                                                esc_attr($value),
                                                selected($current_visibility, $value, false),
                                                esc_html($label)
                                            );
                                        }
                                        ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Auto Sync</th>
                                <td>
                                    <input type="checkbox" 
                                        name="render_products_auto_sync" 
                                        value="1" 
                                        <?php checked(get_option('render_products_auto_sync', true)); ?>>
                                    <label>Enable automatic hourly synchronization</label>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button(); ?>
                    </form>
                </div>

                <div class="render-products-actions">
                    <h2>Actions</h2>
                    <p>
                        <button type="button" class="button button-primary" id="manual-sync-btn">
                            Sync Tasks Now
                        </button>
                    </p>
                    <div id="sync-status"></div>
                </div>

                <div class="render-products-preview">
                    <h2>Tasks Preview</h2>
                    <p>
                        <button type="button" class="button" id="fetch-tasks-btn">
                            Fetch Tasks
                        </button>
                    </p>
                    <div id="tasks-preview"></div>
                </div>
            </div>
        </div>
        <?php
    }
}

// Initialize the plugin
new Render_Tasks_To_Products();