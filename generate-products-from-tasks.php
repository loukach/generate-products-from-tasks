<?php
/*
Plugin Name: Generate WooCommerce Products from Tasks
Description: Creates WooCommerce products from tasks in MongoDB (via render.com API)
Version: 2.7.0
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
            'tasks' => $this->api_url . '/tasks/full',
            'organizations' => $this->api_url . '/organizations'
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
        add_action('wp_ajax_sync_brands', array($this, 'handle_sync_brands'));

        // Defensive filter to prevent numeric category creation
        add_filter('pre_insert_term', array($this, 'prevent_numeric_categories'), 1, 2);

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

    public function sync_tasks_to_products($limit = null) {
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
            // Stop if we've reached the limit
            if ($limit !== null && $index >= $limit) {
                break;
            }
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

        error_log("[Generate Products] Task sync completed: $synced_count synced, $error_count errors, $skipped_count skipped");
        
        // Now run brand sync automatically
        error_log("[Generate Products] Starting automatic brand sync...");
        $brand_result = $this->sync_organizations_to_brands();
        
        $brands_synced = 0;
        $brands_errors = 0;
        $brand_message = '';
        
        if ($brand_result['success']) {
            $brands_synced = $brand_result['synced'];
            $brands_errors = $brand_result['errors'];
            $brand_message = "Brands: {$brands_synced} synced, {$brands_errors} errors";
        } else {
            $brands_errors = 1;
            $brand_message = "Brand sync failed: " . $brand_result['message'];
        }
        
        // Combined results  
        $total_errors = $error_count + $brands_errors;
        $combined_message = "Tasks: {$synced_count} synced, {$error_count} errors | {$brand_message}";
        
        error_log("[Generate Products] Complete sync finished: {$combined_message}");
        
        return array(
            'synced' => $synced_count,
            'errors' => $error_count,
            'skipped' => $skipped_count,
            'total' => count($tasks),
            'brands_synced' => $brands_synced,
            'brands_errors' => $brands_errors,
            'combined_message' => $combined_message,
            'brand_details' => $brand_result['success'] ? $brand_result['results'] : array(),
            'brand_images_attempted' => $brand_result['success'] ? ($brand_result['images_attempted'] ?? 0) : 0,
            'brand_images_success' => $brand_result['success'] ? ($brand_result['images_success'] ?? 0) : 0
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
            error_log('[Generate Products] Processing category: ' . $category_name . ' (type: ' . gettype($category_name) . ')');
            
            // Check if category already exists
            $term = term_exists($category_name, 'product_cat');
            error_log('[Generate Products] term_exists result: ' . print_r($term, true) . ' (type: ' . gettype($term) . ')');
            
            if (!$term) {
                // Category doesn't exist, create it
                error_log('[Generate Products] Creating new category: ' . $category_name);
                $term = wp_insert_term($category_name, 'product_cat');
                error_log('[Generate Products] wp_insert_term result: ' . print_r($term, true));
                
                if (!is_wp_error($term)) {
                    $category_ids[] = $term['term_id'];
                    error_log('[Generate Products] Added term_id to array: ' . $term['term_id']);
                } else {
                    error_log('[Generate Products] Failed to create category ' . $category_name . ': ' . $term->get_error_message());
                }
            } else {
                // Category exists
                $term_id = is_array($term) ? intval($term['term_id']) : intval($term);
                $category_ids[] = $term_id;
                error_log('[Generate Products] Using existing category: ' . $category_name . ' (ID: ' . $term_id . ')');
            }
        }
        
        if (!empty($category_ids)) {
            error_log('[Generate Products] About to call wp_set_object_terms with IDs: ' . print_r($category_ids, true));
            error_log('[Generate Products] Category IDs data types: ' . print_r(array_map('gettype', $category_ids), true));
            
            // Verify terms exist before setting
            foreach ($category_ids as $cat_id) {
                $term_check = get_term($cat_id, 'product_cat');
                if (is_wp_error($term_check) || !$term_check) {
                    error_log('[Generate Products] WARNING: Term ID ' . $cat_id . ' does not exist or is invalid');
                } else {
                    error_log('[Generate Products] Confirmed term exists: ID=' . $cat_id . ', Name=' . $term_check->name);
                }
            }
            
            $result = wp_set_object_terms($product_id, $category_ids, 'product_cat');
            
            if (is_wp_error($result)) {
                error_log('[Generate Products] Failed to set categories: ' . $result->get_error_message());
            } else {
                error_log('[Generate Products] wp_set_object_terms returned: ' . print_r($result, true));
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

        $limit = isset($_POST['limit']) && is_numeric($_POST['limit']) ? intval($_POST['limit']) : null;
        $result = $this->sync_tasks_to_products($limit);
        
        if ($result) {
            // Use combined message if available, otherwise fall back to old format
            $main_message = isset($result['combined_message']) ? 
                $result['combined_message'] : 
                sprintf('Sync completed: %d products synced, %d errors', $result['synced'], $result['errors']);
            
            $response_data = array(
                'message' => $main_message,
                'debug' => $result['debug'] ?? array(),
                'details' => array(
                    'products_synced' => $result['synced'],
                    'products_errors' => $result['errors'],
                    'products_skipped' => $result['skipped'] ?? 0,
                    'total_tasks' => $result['total'] ?? 0
                )
            );
            
            // Add brand information if available
            if (isset($result['brands_synced'])) {
                $response_data['details']['brands_synced'] = $result['brands_synced'];
                $response_data['details']['brands_errors'] = $result['brands_errors'];
                $response_data['details']['brand_images_attempted'] = $result['brand_images_attempted'] ?? 0;
                $response_data['details']['brand_images_success'] = $result['brand_images_success'] ?? 0;
                
                if (!empty($result['brand_details'])) {
                    $response_data['brand_details'] = array_slice($result['brand_details'], 0, 5); // Show first 5
                }
            }
            
            wp_send_json_success($response_data);
        } else {
            wp_send_json_error('Sync failed');
        }
    }

    public function schedule_sync() {
        if (get_option('render_products_auto_sync', true) && !wp_next_scheduled('product_sync_hourly')) {
            wp_schedule_event(time(), 'hourly', 'product_sync_hourly');
        }
    }

    public function prevent_numeric_categories($term, $taxonomy) {
        if ($taxonomy === 'product_cat' && is_numeric($term) && strlen($term) > 2) {
            error_log('[BLOCKED] Numeric category creation attempt: ' . $term . ' for taxonomy: ' . $taxonomy);
            error_log('[BLOCKED] Stack trace: ' . wp_debug_backtrace_summary());
            return new WP_Error('invalid_term_name', 'Numeric category names not allowed: ' . $term);
        }
        return $term;
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
                    <p><strong>Complete Sync:</strong> Syncs tasks to products AND organizations to brands automatically.</p>
                    <p>
                        <label for="sync-limit">Tasks to sync:</label>
                        <select id="sync-limit" style="margin: 0 10px;">
                            <option value="">All tasks</option>
                            <option value="1">1 task</option>
                            <option value="5">5 tasks</option>
                            <option value="10">10 tasks</option>
                            <option value="20">20 tasks</option>
                        </select>
                        <button type="button" class="button button-primary" id="manual-sync-btn">
                            Sync Tasks Now
                        </button>
                    </p>
                    <div id="sync-status"></div>
                </div>

                <div class="render-products-brands">
                    <h2>Brand Integration (Optional)</h2>
                    <p><strong>Brands-Only Sync:</strong> Use this if you only want to sync organizations to brands without syncing tasks.</p>
                    <p>
                        <button type="button" class="button button-secondary" id="sync-brands-btn">
                            Sync Brands Now
                        </button>
                    </p>
                    <div id="brands-status"></div>
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

    /**
     * Sync organizations from MongoDB to PWB brands
     */
    public function sync_organizations_to_brands() {
        error_log('[Generate Products - Brands] Starting organizations to brands sync - Version 2.7.0 with image support');
        
        // Check PWB plugin exists
        if (!taxonomy_exists('pwb-brand')) {
            error_log('[Generate Products - Brands] PWB plugin not found - pwb-brand taxonomy does not exist');
            return array(
                'success' => false,
                'message' => 'Perfect WooCommerce Brands plugin not found',
                'synced' => 0,
                'errors' => 1
            );
        }
        
        // Fetch organizations from API (fixed: replaced fetch_api_data with wp_remote_get)
        $response = wp_remote_get($this->endpoints['organizations']);
        
        if (is_wp_error($response)) {
            error_log('[Generate Products - Brands] Failed to fetch organizations: ' . $response->get_error_message());
            return array(
                'success' => false,
                'message' => 'Failed to fetch organizations: ' . $response->get_error_message(),
                'synced' => 0,
                'errors' => 1
            );
        }

        $http_code = wp_remote_retrieve_response_code($response);
        error_log('[Generate Products - Brands] Organizations API Response Code: ' . $http_code);
        
        if ($http_code !== 200) {
            error_log('[Generate Products - Brands] Unexpected HTTP response code: ' . $http_code);
            return array(
                'success' => false,
                'message' => 'Organizations API returned HTTP ' . $http_code,
                'synced' => 0,
                'errors' => 1
            );
        }

        $body = wp_remote_retrieve_body($response);
        $organizations = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[Generate Products - Brands] Invalid JSON response from organizations API: ' . json_last_error_msg());
            return array(
                'success' => false,
                'message' => 'Invalid JSON response from organizations API',
                'synced' => 0,
                'errors' => 1
            );
        }
        
        if (!is_array($organizations)) {
            error_log('[Generate Products - Brands] Organizations API response is not an array');
            return array(
                'success' => false,
                'message' => 'Invalid organizations data format',
                'synced' => 0,
                'errors' => 1
            );
        }
        
        error_log('[Generate Products - Brands] Fetched ' . count($organizations) . ' organizations from API');
        
        $synced = 0;
        $errors = 0;
        $results = array();
        $images_attempted = 0;
        $images_success = 0;
        
        foreach ($organizations as $org) {
            try {
                // Skip if no organization name
                if (empty($org['organizationName'])) {
                    error_log('[Generate Products - Brands] Skipping organization with empty name');
                    continue;
                }
                
                $org_name = sanitize_text_field($org['organizationName']);
                $org_id = isset($org['_id']['$oid']) ? $org['_id']['$oid'] : (isset($org['_id']) ? $org['_id'] : null);
                
                if (!$org_id) {
                    error_log('[Generate Products - Brands] Skipping organization without ID: ' . $org_name);
                    $errors++;
                    continue;
                }
                
                // Check if brand already exists
                $existing_brand = term_exists($org_name, 'pwb-brand');
                
                if ($existing_brand) {
                    // Brand exists, don't update
                    $brand_id = is_array($existing_brand) ? $existing_brand['term_id'] : $existing_brand;
                    error_log('[Generate Products - Brands] Using existing brand: ' . $org_name . ' (ID: ' . $brand_id . ')');
                    $results[] = 'existing: ' . $org_name;
                } else {
                    // Create new brand
                    $brand_args = array(
                        'description' => isset($org['description']) ? sanitize_text_field($org['description']) : '',
                        'slug' => sanitize_title($org_name)
                    );
                    
                    $brand_result = wp_insert_term($org_name, 'pwb-brand', $brand_args);
                    
                    if (is_wp_error($brand_result)) {
                        error_log('[Generate Products - Brands] Failed to create brand: ' . $org_name . ' - ' . $brand_result->get_error_message());
                        $errors++;
                        continue;
                    }
                    
                    $brand_id = $brand_result['term_id'];
                    
                    // Store MongoDB ID for linking
                    update_term_meta($brand_id, 'mongodb_org_id', $org_id);
                    
                    // Handle brand image if available
                    if (!empty($org['imageUrl'])) {
                        $images_attempted++;
                        $image_result = $this->download_and_attach_brand_image($brand_id, $org['imageUrl'], $org_name);
                        if ($image_result['success']) {
                            $images_success++;
                            error_log('[Generate Products - Brands] Set brand image for: ' . $org_name . ' (Attachment ID: ' . $image_result['attachment_id'] . ')');
                        } else {
                            error_log('[Generate Products - Brands] Failed to set image for ' . $org_name . ': ' . $image_result['message']);
                        }
                    }
                    
                    error_log('[Generate Products - Brands] Created new brand: ' . $org_name . ' (ID: ' . $brand_id . ')');
                    $synced++;
                    $results[] = 'created: ' . $org_name;
                }
                
            } catch (Exception $e) {
                error_log('[Generate Products - Brands] Exception processing organization ' . ($org['organizationName'] ?? 'unknown') . ': ' . $e->getMessage());
                $errors++;
            }
        }
        
        // Now assign brands to existing products
        $this->assign_brands_to_products();
        
        error_log('[Generate Products - Brands] Sync completed. Synced: ' . $synced . ', Errors: ' . $errors);
        
        return array(
            'success' => true,
            'message' => sprintf('Brand sync completed: %d brands synced, %d errors, %d/%d images', $synced, $errors, $images_success, $images_attempted),
            'synced' => $synced,
            'errors' => $errors,
            'results' => $results,
            'images_attempted' => $images_attempted,
            'images_success' => $images_success
        );
    }
    
    /**
     * Assign brands to existing products based on organization
     */
    private function assign_brands_to_products() {
        error_log('[Generate Products - Brands] Starting product brand assignment');
        
        // Get all products that have organization meta
        $products = get_posts(array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_product_brand',
                    'compare' => 'EXISTS'
                )
            )
        ));
        
        error_log('[Generate Products - Brands] Found ' . count($products) . ' products with organization data');
        
        $assigned = 0;
        $errors = 0;
        
        foreach ($products as $product) {
            $org_name = get_post_meta($product->ID, '_product_brand', true);
            
            if (empty($org_name)) {
                continue;
            }
            
            // Find matching brand by name
            $brand = get_term_by('name', $org_name, 'pwb-brand');
            
            if (!$brand) {
                error_log('[Generate Products - Brands] No brand found for organization: ' . $org_name . ' (Product ID: ' . $product->ID . ')');
                $errors++;
                continue;
            }
            
            // Assign brand to product (cast to int to avoid numeric categories)
            $result = wp_set_object_terms($product->ID, (int)$brand->term_id, 'pwb-brand', false);
            
            if (is_wp_error($result)) {
                error_log('[Generate Products - Brands] Failed to assign brand to product ' . $product->ID . ': ' . $result->get_error_message());
                $errors++;
            } else {
                error_log('[Generate Products - Brands] Assigned brand "' . $org_name . '" to product "' . $product->post_title . '" (ID: ' . $product->ID . ')');
                $assigned++;
            }
        }
        
        error_log('[Generate Products - Brands] Product brand assignment completed. Assigned: ' . $assigned . ', Errors: ' . $errors);
        
        return array('assigned' => $assigned, 'errors' => $errors);
    }
    
    /**
     * AJAX handler for brand sync
     */
    public function handle_sync_brands() {
        check_ajax_referer('render_products_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        $result = $this->sync_organizations_to_brands();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Download and attach brand image from URL
     */
    private function download_and_attach_brand_image($brand_id, $image_url, $brand_name) {
        try {
            // Include required WordPress functions for media handling
            if (!function_exists('media_sideload_image')) {
                require_once(ABSPATH . 'wp-admin/includes/media.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/image.php');
            }
            
            // Download and sideload the image
            $attachment_id = media_sideload_image($image_url, 0, $brand_name . ' logo', 'id');
            
            if (is_wp_error($attachment_id)) {
                return array(
                    'success' => false,
                    'message' => $attachment_id->get_error_message()
                );
            }
            
            // Try multiple possible meta keys for PWB brand images
            $meta_keys = array(
                'pwb_brand_image',    // Perfect WooCommerce Brands
                'thumbnail_id',       // WordPress standard
                'brand_thumbnail_id'  // Alternative
            );
            
            foreach ($meta_keys as $meta_key) {
                update_term_meta($brand_id, $meta_key, $attachment_id);
            }
            
            return array(
                'success' => true,
                'attachment_id' => $attachment_id,
                'message' => 'Brand image attached successfully'
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage()
            );
        }
    }
}

// Initialize the plugin
new Render_Tasks_To_Products();