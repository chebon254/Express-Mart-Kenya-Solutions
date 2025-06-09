<?php
/**
 * Plugin Name: Pickup Station for WooCommerce (Clean)
 * Description: Clean fixed version - Modern pickup station selector for WooCommerce with HPOS support
 * Version: 2.0.4
 * Author: Your Name
 * Text Domain: pickup-station-wc
 * Requires at least: 5.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.8
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define constants safely
if (!defined('PICKUP_STATION_VERSION')) {
    define('PICKUP_STATION_VERSION', '2.0.4');
}
if (!defined('PICKUP_STATION_PATH')) {
    define('PICKUP_STATION_PATH', plugin_dir_path(__FILE__));
}
if (!defined('PICKUP_STATION_URL')) {
    define('PICKUP_STATION_URL', plugin_dir_url(__FILE__));
}

// Check PHP version first
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo sprintf(
            esc_html__('Pickup Station requires PHP 7.4 or higher. You are running version %s. Please contact your hosting provider.', 'pickup-station-wc'),
            PHP_VERSION
        );
        echo '</p></div>';
    });
    return;
}

// HPOS Compatibility Declaration (safely)
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        try {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        } catch (Exception $e) {
            error_log('Pickup Station HPOS compatibility error: ' . $e->getMessage());
        }
    }
});

// Safe plugin initialization
add_action('plugins_loaded', 'pickup_station_init_plugin', 20);

function pickup_station_init_plugin() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('Pickup Station for WooCommerce requires WooCommerce to be installed and active.', 'pickup-station-wc');
            echo '</p></div>';
        });
        return;
    }
    
    // Check WooCommerce version
    if (defined('WC_VERSION') && version_compare(WC_VERSION, '8.0', '<')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo sprintf(
                esc_html__('Pickup Station requires WooCommerce 8.0 or higher. You are running version %s.', 'pickup-station-wc'),
                WC_VERSION
            );
            echo '</p></div>';
        });
        return;
    }
    
    // Initialize the plugin safely
    try {
        new Pickup_Station_Plugin();
        
        // Initialize shipping method when WooCommerce shipping is loaded
        add_action('woocommerce_shipping_init', 'pickup_station_init_shipping_method');
        
    } catch (Exception $e) {
        add_action('admin_notices', function() use ($e) {
            echo '<div class="notice notice-error"><p>';
            echo sprintf(
                esc_html__('Pickup Station encountered an error: %s', 'pickup-station-wc'),
                esc_html($e->getMessage())
            );
            echo '</p></div>';
        });
        error_log('Pickup Station initialization error: ' . $e->getMessage());
    }
}

// Initialize shipping method class ONLY when WooCommerce shipping is ready
function pickup_station_init_shipping_method() {
    if (!class_exists('WC_Shipping_Method')) {
        return;
    }
    
    // Define shipping method class
    if (!class_exists('WC_Pickup_Station_Shipping_Method')) {
        class WC_Pickup_Station_Shipping_Method extends WC_Shipping_Method {
            
            public function __construct($instance_id = 0) {
                $this->id = 'pickup_station';
                $this->instance_id = absint($instance_id);
                $this->method_title = esc_html__('Pickup Station', 'pickup-station-wc');
                $this->method_description = esc_html__('Allow customers to select pickup stations with custom pricing', 'pickup-station-wc');
                $this->supports = array(
                    'shipping-zones',
                    'instance-settings',
                    'instance-settings-modal'
                );
                
                $this->init();
            }
            
            public function init() {
                $this->init_form_fields();
                $this->init_settings();
                
                $this->title = $this->get_option('title', $this->method_title);
                $this->enabled = $this->get_option('enabled', 'yes');
                
                add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
            }
            
            public function init_form_fields() {
                $this->form_fields = array(
                    'enabled' => array(
                        'title' => esc_html__('Enable/Disable', 'pickup-station-wc'),
                        'type' => 'checkbox',
                        'label' => esc_html__('Enable pickup station shipping', 'pickup-station-wc'),
                        'default' => 'yes'
                    ),
                    'title' => array(
                        'title' => esc_html__('Method Title', 'pickup-station-wc'),
                        'type' => 'text',
                        'description' => esc_html__('This controls the title shown during checkout', 'pickup-station-wc'),
                        'default' => esc_html__('Pickup Station', 'pickup-station-wc'),
                        'desc_tip' => true
                    )
                );
            }
            
            public function calculate_shipping($package = array()) {
                // Check for pickup station selection in various sources
                $station_id = $this->get_selected_station_id();
                
                if ($station_id) {
                    global $wpdb;
                    $table_name = $wpdb->prefix . 'pickup_stations';
                    $station = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d AND status = 'active'", intval($station_id)));
                    
                    if ($station) {
                        $rate = array(
                            'id' => $this->get_rate_id(),
                            'label' => sprintf('%s - %s', $this->title, $station->name),
                            'cost' => $station->shipping_price,
                            'package' => $package,
                            'meta_data' => array(
                                'pickup_station_id' => $station->id,
                                'pickup_station_name' => $station->name
                            )
                        );
                        
                        $this->add_rate($rate);
                    }
                }
            }
            
            private function get_selected_station_id() {
                // Check POST data first
                if (isset($_POST['pickup_station']) && !empty($_POST['pickup_station'])) {
                    return intval($_POST['pickup_station']);
                }
                
                // Check session if WooCommerce session is available
                if (function_exists('WC') && WC()->session) {
                    $session_station = WC()->session->get('pickup_station_id');
                    if ($session_station) {
                        return intval($session_station);
                    }
                }
                
                return false;
            }
            
            public function is_available($package = array()) {
                return $this->enabled === 'yes';
            }
        }
    }
    
    // Add the shipping method to WooCommerce
    add_filter('woocommerce_shipping_methods', function($methods) {
        $methods['pickup_station'] = 'WC_Pickup_Station_Shipping_Method';
        return $methods;
    });
}

/**
 * Main Plugin Class
 */
class Pickup_Station_Plugin {
    
    public function __construct() {
        // Safe hooks
        register_activation_hook(__FILE__, array($this, 'safe_activate'));
        register_deactivation_hook(__FILE__, array($this, 'safe_deactivate'));
        
        // Initialize with error handling
        add_action('init', array($this, 'safe_init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Only load scripts if we're in the right place
        if (is_admin()) {
            add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        } else {
            add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        }
        
        // AJAX handlers with safety checks
        add_action('wp_ajax_save_pickup_station', array($this, 'ajax_save_pickup_station'));
        add_action('wp_ajax_delete_pickup_station', array($this, 'ajax_delete_pickup_station'));
        add_action('wp_ajax_get_pickup_station_details', array($this, 'ajax_get_pickup_station_details'));
        add_action('wp_ajax_nopriv_get_pickup_station_details', array($this, 'ajax_get_pickup_station_details'));
        
        // Session handling for pickup station
        add_action('wp_ajax_save_pickup_station_session', array($this, 'ajax_save_pickup_station_session'));
        add_action('wp_ajax_nopriv_save_pickup_station_session', array($this, 'ajax_save_pickup_station_session'));
    }
    
    public function safe_activate() {
        try {
            $this->create_tables();
            flush_rewrite_rules();
            
            // Add success notice
            add_option('pickup_station_activation_notice', true);
        } catch (Exception $e) {
            error_log('Pickup Station activation error: ' . $e->getMessage());
        }
    }
    
    public function safe_deactivate() {
        flush_rewrite_rules();
    }
    
    public function create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pickup_stations';
        
        // Check if table already exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
            return; // Table already exists
        }
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(200) NOT NULL,
            address text NOT NULL,
            city varchar(100) NOT NULL,
            phone varchar(30) NOT NULL,
            shipping_price decimal(10,2) NOT NULL DEFAULT 0.00,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY city (city)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $result = dbDelta($sql);
        
        // Add version option
        update_option('pickup_station_db_version', PICKUP_STATION_VERSION);
        
        error_log('Pickup Station table created successfully');
    }
    
    public function safe_init() {
        // Show activation notice
        if (get_option('pickup_station_activation_notice')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo esc_html__('Pickup Station plugin activated successfully! Go to the Pickup Stations menu to add your first station.', 'pickup-station-wc');
                echo '</p></div>';
            });
            delete_option('pickup_station_activation_notice');
        }
        
        // Load text domain safely
        load_plugin_textdomain('pickup-station-wc', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Only add hooks if WooCommerce is properly loaded
        if (class_exists('WC_Order') && function_exists('wc_get_order')) {
            // Checkout hooks - Fixed order and timing
            add_action('woocommerce_checkout_before_order_review', array($this, 'add_pickup_selection_field'), 5);
            add_action('woocommerce_checkout_process', array($this, 'validate_pickup_selection'));
            add_action('woocommerce_checkout_create_order', array($this, 'save_pickup_selection_to_order'));
            
            // Display hooks
            add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_pickup_in_admin'));
            add_action('woocommerce_email_order_meta', array($this, 'display_pickup_in_email'), 10, 3);
            add_action('woocommerce_order_details_after_order_table', array($this, 'display_pickup_in_frontend'));
            
            // Shipping calculation trigger
            add_action('woocommerce_checkout_update_order_review', array($this, 'trigger_shipping_calculation'));
        }
    }
    
    public function enqueue_scripts() {
        // Only load on checkout page
        if (is_checkout()) {
            wp_enqueue_script(
                'pickup-station-checkout',
                PICKUP_STATION_URL . 'assets/checkout.js',
                array('jquery'),
                PICKUP_STATION_VERSION,
                true
            );
            
            wp_localize_script('pickup-station-checkout', 'pickup_station_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('pickup_station_nonce'),
                'i18n' => array(
                    'select_station' => esc_html__('Please select a pickup station', 'pickup-station-wc'),
                    'loading' => esc_html__('Loading...', 'pickup-station-wc'),
                    'address' => esc_html__('Address', 'pickup-station-wc'),
                    'city' => esc_html__('City', 'pickup-station-wc'),
                    'phone' => esc_html__('Phone', 'pickup-station-wc'),
                    'shipping_cost' => esc_html__('Shipping Cost', 'pickup-station-wc'),
                    'error_loading' => esc_html__('Error loading station details', 'pickup-station-wc')
                )
            ));
            
            wp_enqueue_style(
                'pickup-station-checkout',
                PICKUP_STATION_URL . 'assets/checkout.css',
                array(),
                PICKUP_STATION_VERSION
            );
        }
    }
    
    public function admin_enqueue_scripts($hook) {
        if ($hook === 'toplevel_page_pickup-stations') {
            wp_enqueue_script(
                'pickup-station-admin',
                PICKUP_STATION_URL . 'assets/admin.js',
                array('jquery'),
                PICKUP_STATION_VERSION,
                true
            );
            
            wp_localize_script('pickup-station-admin', 'pickup_admin_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('pickup_station_admin_nonce'),
                'i18n' => array(
                    'confirm_delete' => esc_html__('Are you sure you want to delete this pickup station?', 'pickup-station-wc'),
                    'success_added' => esc_html__('Pickup station added successfully!', 'pickup-station-wc'),
                    'success_deleted' => esc_html__('Pickup station deleted successfully!', 'pickup-station-wc'),
                    'error_occurred' => esc_html__('An error occurred. Please try again.', 'pickup-station-wc')
                )
            ));
            
            wp_enqueue_style(
                'pickup-station-admin',
                PICKUP_STATION_URL . 'assets/admin.css',
                array(),
                PICKUP_STATION_VERSION
            );
        }
    }
    
    public function add_admin_menu() {
        add_menu_page(
            esc_html__('Pickup Stations', 'pickup-station-wc'),
            esc_html__('Pickup Stations', 'pickup-station-wc'),
            'manage_woocommerce',
            'pickup-stations',
            array($this, 'admin_page'),
            'dashicons-location-alt',
            56
        );
    }
    
    public function admin_page() {
        // Handle form submission
        if (isset($_POST['submit_station']) && wp_verify_nonce($_POST['pickup_nonce'], 'pickup_station_admin_nonce')) {
            $this->handle_station_form();
        }
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Pickup Stations', 'pickup-station-wc'); ?></h1>
            
            <div class="notice notice-info">
                <p><?php esc_html_e('Manage your pickup stations here. Customers will be able to select these during checkout.', 'pickup-station-wc'); ?></p>
                <p><strong><?php esc_html_e('Setup Instructions:', 'pickup-station-wc'); ?></strong></p>
                <ol>
                    <li><?php esc_html_e('Add pickup stations below', 'pickup-station-wc'); ?></li>
                    <li><?php esc_html_e('Go to WooCommerce > Settings > Shipping', 'pickup-station-wc'); ?></li>
                    <li><?php esc_html_e('Add "Pickup Station" shipping method to your shipping zones', 'pickup-station-wc'); ?></li>
                    <li><?php esc_html_e('Configure the method settings and enable it', 'pickup-station-wc'); ?></li>
                </ol>
                <p><strong><?php esc_html_e('Status:', 'pickup-station-wc'); ?></strong></p>
                <ul>
                    <li>✅ <?php esc_html_e('Plugin activated successfully', 'pickup-station-wc'); ?></li>
                    <li>✅ <?php printf(esc_html__('WooCommerce version: %s', 'pickup-station-wc'), defined('WC_VERSION') ? WC_VERSION : 'Unknown'); ?></li>
                    <li>✅ <?php printf(esc_html__('PHP version: %s', 'pickup-station-wc'), PHP_VERSION); ?></li>
                    <?php
                    global $wpdb;
                    $table_name = $wpdb->prefix . 'pickup_stations';
                    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
                    ?>
                    <li><?php echo $table_exists ? '✅' : '❌'; ?> <?php esc_html_e('Database table', 'pickup-station-wc'); ?></li>
                    <?php if ($table_exists): ?>
                        <?php $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'active'"); ?>
                        <li>📍 <?php printf(esc_html__('Active stations: %d', 'pickup-station-wc'), $count); ?></li>
                    <?php endif; ?>
                </ul>
            </div>
            
            <div class="card" style="max-width: none;">
                <h2><?php esc_html_e('Add New Pickup Station', 'pickup-station-wc'); ?></h2>
                <form method="post" action="">
                    <?php wp_nonce_field('pickup_station_admin_nonce', 'pickup_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="station_name"><?php esc_html_e('Station Name', 'pickup-station-wc'); ?></label></th>
                            <td><input name="station_name" type="text" id="station_name" class="regular-text" required placeholder="e.g., Downtown Branch" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="station_address"><?php esc_html_e('Address', 'pickup-station-wc'); ?></label></th>
                            <td><textarea name="station_address" id="station_address" rows="3" cols="50" class="large-text" required placeholder="Full street address"></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="station_city"><?php esc_html_e('City', 'pickup-station-wc'); ?></label></th>
                            <td><input name="station_city" type="text" id="station_city" class="regular-text" required placeholder="City name" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="station_phone"><?php esc_html_e('Phone', 'pickup-station-wc'); ?></label></th>
                            <td><input name="station_phone" type="tel" id="station_phone" class="regular-text" required placeholder="Contact phone number" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="shipping_price"><?php esc_html_e('Shipping Price', 'pickup-station-wc'); ?></label></th>
                            <td>
                                <input name="shipping_price" type="number" id="shipping_price" step="0.01" min="0" class="regular-text" required placeholder="0.00" />
                                <p class="description"><?php 
                                    if (function_exists('get_woocommerce_currency')) {
                                        echo sprintf(esc_html__('Price in %s (0 for free pickup)', 'pickup-station-wc'), get_woocommerce_currency());
                                    } else {
                                        echo esc_html__('Shipping fee for this pickup station (0 for free pickup)', 'pickup-station-wc');
                                    }
                                ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="submit_station" class="button-primary" value="<?php esc_attr_e('Add Pickup Station', 'pickup-station-wc'); ?>" />
                    </p>
                </form>
            </div>
            
            <?php $this->display_stations_list(); ?>
        </div>
        <?php
    }
    
    private function handle_station_form() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pickup_stations';
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'name' => sanitize_text_field($_POST['station_name']),
                'address' => sanitize_textarea_field($_POST['station_address']),
                'city' => sanitize_text_field($_POST['station_city']),
                'phone' => sanitize_text_field($_POST['station_phone']),
                'shipping_price' => floatval($_POST['shipping_price'])
            ),
            array('%s', '%s', '%s', '%s', '%f')
        );
        
        if ($result) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo esc_html__('Pickup station added successfully!', 'pickup-station-wc');
                echo '</p></div>';
            });
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error is-dismissible"><p>';
                echo esc_html__('Error adding pickup station. Please try again.', 'pickup-station-wc');
                echo '</p></div>';
            });
        }
    }
    
    private function display_stations_list() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pickup_stations';
        
        // Check if table exists first
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Database table not found. Please deactivate and reactivate the plugin.', 'pickup-station-wc') . '</p></div>';
            return;
        }
        
        $stations = $wpdb->get_results("SELECT * FROM $table_name WHERE status = 'active' ORDER BY name ASC");
        
        echo '<div class="card" style="max-width: none;">';
        echo '<h2>' . esc_html__('Existing Pickup Stations', 'pickup-station-wc') . '</h2>';
        
        if (empty($stations)) {
            echo '<p>' . esc_html__('No pickup stations found. Add your first station above.', 'pickup-station-wc') . '</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th style="width: 20%;">' . esc_html__('Name', 'pickup-station-wc') . '</th>';
            echo '<th style="width: 30%;">' . esc_html__('Address', 'pickup-station-wc') . '</th>';
            echo '<th style="width: 15%;">' . esc_html__('City', 'pickup-station-wc') . '</th>';
            echo '<th style="width: 15%;">' . esc_html__('Phone', 'pickup-station-wc') . '</th>';
            echo '<th style="width: 10%;">' . esc_html__('Price', 'pickup-station-wc') . '</th>';
            echo '<th style="width: 10%;">' . esc_html__('Actions', 'pickup-station-wc') . '</th>';
            echo '</tr></thead><tbody>';
            
            foreach ($stations as $station) {
                echo '<tr>';
                echo '<td><strong>' . esc_html($station->name) . '</strong></td>';
                echo '<td>' . esc_html($station->address) . '</td>';
                echo '<td>' . esc_html($station->city) . '</td>';
                echo '<td>' . esc_html($station->phone) . '</td>';
                echo '<td>' . (function_exists('wc_price') ? wc_price($station->shipping_price) : '$' . number_format($station->shipping_price, 2)) . '</td>';
                echo '<td><button type="button" class="button delete-station" data-id="' . esc_attr($station->id) . '">' . esc_html__('Delete', 'pickup-station-wc') . '</button></td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        }
        echo '</div>';
    }
    
    public function add_pickup_selection_field($checkout = null) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pickup_stations';
        
        // Safety check
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            return;
        }
        
        $stations = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE status = %s ORDER BY name ASC", 'active'));
        
        if (empty($stations)) {
            return;
        }
        
        echo '<div id="pickup-station-selection" class="pickup-station-field">';
        echo '<h3>' . esc_html__('Pickup Station', 'pickup-station-wc') . '</h3>';
        
        $options = array('' => esc_html__('Select a pickup station', 'pickup-station-wc'));
        foreach ($stations as $station) {
            $price_display = function_exists('wc_price') ? wc_price($station->shipping_price) : '$' . number_format($station->shipping_price, 2);
            $options[$station->id] = sprintf('%s - %s', $station->name, $price_display);
        }
        
        $selected_value = '';
        if (function_exists('WC') && WC()->session) {
            $selected_value = WC()->session->get('pickup_station_id', '');
        }
        
        if (function_exists('woocommerce_form_field')) {
            woocommerce_form_field('pickup_station', array(
                'type' => 'select',
                'class' => array('pickup-station-dropdown form-row-wide'),
                'label' => esc_html__('Choose Pickup Location', 'pickup-station-wc'),
                'required' => false, // We'll validate manually
                'options' => $options
            ), $selected_value);
        } else {
            // Fallback if woocommerce_form_field is not available
            echo '<p class="form-row form-row-wide">';
            echo '<label for="pickup_station">' . esc_html__('Choose Pickup Location', 'pickup-station-wc') . ' <span class="required">*</span></label>';
            echo '<select name="pickup_station" id="pickup_station" class="pickup-station-dropdown">';
            foreach ($options as $value => $label) {
                echo '<option value="' . esc_attr($value) . '"' . selected($selected_value, $value, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
            echo '</p>';
        }
        
        echo '<div id="pickup-station-details" class="pickup-station-details"></div>';
        echo '</div>';
    }
    
    public function validate_pickup_selection() {
        // Check if pickup station shipping method is selected
        $chosen_methods = WC()->session->get('chosen_shipping_methods', array());
        $pickup_method_selected = false;
        
        foreach ($chosen_methods as $method) {
            if (strpos($method, 'pickup_station') !== false) {
                $pickup_method_selected = true;
                break;
            }
        }
        
        // Only validate if pickup station shipping is selected
        if ($pickup_method_selected && empty($_POST['pickup_station'])) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice(esc_html__('Please select a pickup station.', 'pickup-station-wc'), 'error');
            }
        }
    }
    
    public function trigger_shipping_calculation($posted_data) {
        // Save pickup station selection to session for shipping calculation
        if (!empty($_POST['pickup_station']) && function_exists('WC') && WC()->session) {
            WC()->session->set('pickup_station_id', sanitize_text_field($_POST['pickup_station']));
        }
    }
    
    public function save_pickup_selection_to_order($order) {
        if (!empty($_POST['pickup_station']) && is_object($order) && method_exists($order, 'update_meta_data')) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'pickup_stations';
            $station = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", intval($_POST['pickup_station'])));
            
            if ($station) {
                $order->update_meta_data('_pickup_station_id', $station->id);
                $order->update_meta_data('_pickup_station_name', $station->name);
                $order->update_meta_data('_pickup_station_address', $station->address);
                $order->update_meta_data('_pickup_station_city', $station->city);
                $order->update_meta_data('_pickup_station_phone', $station->phone);
                $order->update_meta_data('_pickup_station_price', $station->shipping_price);
                $order->save();
                
                // Clear session after saving to order
                if (function_exists('WC') && WC()->session) {
                    WC()->session->__unset('pickup_station_id');
                }
            }
        }
    }
    
    public function display_pickup_in_admin($order) {
        if (is_object($order) && method_exists($order, 'get_meta')) {
            $station_name = $order->get_meta('_pickup_station_name');
            if ($station_name) {
                echo '<div class="pickup-station-admin-details">';
                echo '<h3>' . esc_html__('Pickup Station Details', 'pickup-station-wc') . '</h3>';
                echo '<p><strong>' . esc_html__('Station:', 'pickup-station-wc') . '</strong> ' . esc_html($station_name) . '</p>';
                echo '<p><strong>' . esc_html__('Address:', 'pickup-station-wc') . '</strong> ' . esc_html($order->get_meta('_pickup_station_address')) . '</p>';
                echo '<p><strong>' . esc_html__('City:', 'pickup-station-wc') . '</strong> ' . esc_html($order->get_meta('_pickup_station_city')) . '</p>';
                echo '<p><strong>' . esc_html__('Phone:', 'pickup-station-wc') . '</strong> ' . esc_html($order->get_meta('_pickup_station_phone')) . '</p>';
                echo '</div>';
            }
        }
    }
    
    public function display_pickup_in_email($order, $sent_to_admin, $plain_text) {
        if (is_object($order) && method_exists($order, 'get_meta')) {
            $station_name = $order->get_meta('_pickup_station_name');
            if ($station_name) {
                if ($plain_text) {
                    echo "\n" . esc_html__('PICKUP STATION', 'pickup-station-wc') . "\n";
                    echo esc_html__('Station: ', 'pickup-station-wc') . $station_name . "\n";
                    echo esc_html__('Address: ', 'pickup-station-wc') . $order->get_meta('_pickup_station_address') . "\n";
                    echo esc_html__('City: ', 'pickup-station-wc') . $order->get_meta('_pickup_station_city') . "\n";
                    echo esc_html__('Phone: ', 'pickup-station-wc') . $order->get_meta('_pickup_station_phone') . "\n";
                } else {
                    echo '<h2>' . esc_html__('Pickup Station', 'pickup-station-wc') . '</h2>';
                    echo '<p><strong>' . esc_html__('Station:', 'pickup-station-wc') . '</strong> ' . esc_html($station_name) . '</p>';
                    echo '<p><strong>' . esc_html__('Address:', 'pickup-station-wc') . '</strong> ' . esc_html($order->get_meta('_pickup_station_address')) . '</p>';
                    echo '<p><strong>' . esc_html__('City:', 'pickup-station-wc') . '</strong> ' . esc_html($order->get_meta('_pickup_station_city')) . '</p>';
                    echo '<p><strong>' . esc_html__('Phone:', 'pickup-station-wc') . '</strong> ' . esc_html($order->get_meta('_pickup_station_phone')) . '</p>';
                }
            }
        }
    }
    
    public function display_pickup_in_frontend($order) {
        if (is_object($order) && method_exists($order, 'get_meta')) {
            $station_name = $order->get_meta('_pickup_station_name');
            if ($station_name) {
                echo '<h2>' . esc_html__('Pickup Station', 'pickup-station-wc') . '</h2>';
                echo '<table class="woocommerce-table shop_table pickup-station-details">';
                echo '<tr><th>' . esc_html__('Station:', 'pickup-station-wc') . '</th><td>' . esc_html($station_name) . '</td></tr>';
                echo '<tr><th>' . esc_html__('Address:', 'pickup-station-wc') . '</th><td>' . esc_html($order->get_meta('_pickup_station_address')) . '</td></tr>';
                echo '<tr><th>' . esc_html__('City:', 'pickup-station-wc') . '</th><td>' . esc_html($order->get_meta('_pickup_station_city')) . '</td></tr>';
                echo '<tr><th>' . esc_html__('Phone:', 'pickup-station-wc') . '</th><td>' . esc_html($order->get_meta('_pickup_station_phone')) . '</td></tr>';
                echo '</table>';
            }
        }
    }
    
    public function ajax_save_pickup_station() {
        if (!check_ajax_referer('pickup_station_admin_nonce', 'nonce', false)) {
            wp_send_json_error(esc_html__('Security check failed', 'pickup-station-wc'));
        }
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(esc_html__('Unauthorized', 'pickup-station-wc'));
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'pickup_stations';
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'name' => sanitize_text_field($_POST['station_name']),
                'address' => sanitize_textarea_field($_POST['station_address']),
                'city' => sanitize_text_field($_POST['station_city']),
                'phone' => sanitize_text_field($_POST['station_phone']),
                'shipping_price' => floatval($_POST['shipping_price'])
            ),
            array('%s', '%s', '%s', '%s', '%f')
        );
        
        if ($result) {
            wp_send_json_success(esc_html__('Station added successfully', 'pickup-station-wc'));
        } else {
            wp_send_json_error(esc_html__('Failed to add station', 'pickup-station-wc'));
        }
    }
    
    public function ajax_delete_pickup_station() {
        if (!check_ajax_referer('pickup_station_admin_nonce', 'nonce', false)) {
            wp_send_json_error(esc_html__('Security check failed', 'pickup-station-wc'));
        }
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(esc_html__('Unauthorized', 'pickup-station-wc'));
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'pickup_stations';
        
        $result = $wpdb->update(
            $table_name,
            array('status' => 'deleted'),
            array('id' => intval($_POST['station_id'])),
            array('%s'),
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success(esc_html__('Station deleted successfully', 'pickup-station-wc'));
        } else {
            wp_send_json_error(esc_html__('Failed to delete station', 'pickup-station-wc'));
        }
    }
    
    public function ajax_get_pickup_station_details() {
        if (!check_ajax_referer('pickup_station_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
        }

        if (empty($_POST['station_id'])) {
            wp_send_json_error('No station ID provided');
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'pickup_stations';
        $station = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d AND status = 'active'", intval($_POST['station_id'])));

        if ($station) {
            $price_display = function_exists('wc_price') 
                ? wc_price($station->shipping_price) 
                : number_format($station->shipping_price, 2);

            wp_send_json_success(array(
                'name' => $station->name,
                'address' => $station->address,
                'city' => $station->city,
                'phone' => $station->phone,
                'price' => $price_display
            ));
        } else {
            wp_send_json_error('Station not found');
        }
    }

    public function ajax_save_pickup_station_session() {
        if (!check_ajax_referer('pickup_station_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
        }

        if (function_exists('WC') && WC()->session && !empty($_POST['station_id'])) {
            WC()->session->set('pickup_station_id', sanitize_text_field($_POST['station_id']));
            wp_send_json_success('Session updated');
        } else {
            wp_send_json_error('Failed to update session');
        }
    }

}
    

add_action('wp_head', function () {
    if (is_checkout()) {
        echo '<style>
        .pickup-station-field {
            margin: 20px 0 !important;
            padding: 20px !important;
            background: #f9f9f9 !important;
            border: 1px solid #ddd !important;
            border-radius: 5px !important;
        }
        .pickup-station-field h3 {
            margin: 0 0 15px 0 !important;
            color: #333 !important;
            border-bottom: 2px solid #0073aa !important;
            padding-bottom: 10px !important;
        }
        .pickup-station-info {
            background: #fff !important;
            padding: 15px !important;
            border: 1px solid #e1e1e1 !important;
            border-radius: 3px !important;
            margin-top: 10px !important;
        }
        .pickup-station-info h4 {
            margin: 0 0 10px 0 !important;
            color: #0073aa !important;
            font-weight: 600 !important;
        }
        .pickup-station-details {
            margin-top: 15px !important;
            padding-top: 15px !important;
            border-top: 1px solid #ddd !important;
        }
        </style>';
    }
});

// Add admin styles
add_action('admin_head', function() {
    $screen = get_current_screen();
    if ($screen && $screen->id === 'toplevel_page_pickup-stations') {
        echo '<style>
        .pickup-station-admin-details {
            background: #f9f9f9 !important;
            border: 1px solid #e1e1e1 !important;
            border-radius: 3px !important;
            padding: 15px !important;
            margin: 15px 0 !important;
        }
        .pickup-station-admin-details h3 {
            margin: 0 0 10px 0 !important;
            color: #23282d !important;
            border-bottom: 1px solid #e1e1e1 !important;
            padding-bottom: 8px !important;
        }
        .delete-station {
            background: #d63638 !important;
            color: #fff !important;
            border: none !important;
            padding: 6px 12px !important;
            border-radius: 3px !important;
            cursor: pointer !important;
        }
        .delete-station:hover {
            background: #b32d2e !important;
        }
        </style>';
    }
});

// Add basic JavaScript for delete buttons (fallback if JS file doesn't exist)
add_action('admin_footer', function() {
    $screen = get_current_screen();
    if ($screen && $screen->id === 'toplevel_page_pickup-stations') {
        echo '<script>
        jQuery(document).ready(function($) {
            $(".delete-station").on("click", function() {
                if (confirm("' . esc_js(__('Are you sure you want to delete this pickup station?', 'pickup-station-wc')) . '")) {
                    var button = $(this);
                    var stationId = button.data("id");
                    var row = button.closest("tr");
                    
                    button.prop("disabled", true).text("' . esc_js(__('Deleting...', 'pickup-station-wc')) . '");
                    
                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: {
                            action: "delete_pickup_station",
                            nonce: "' . wp_create_nonce('pickup_station_admin_nonce') . '",
                            station_id: stationId
                        },
                        success: function(response) {
                            if (response.success) {
                                row.fadeOut(300, function() {
                                    $(this).remove();
                                });
                            } else {
                                alert("' . esc_js(__('Error:', 'pickup-station-wc')) . ' " + (response.data || "' . esc_js(__('Failed to delete station', 'pickup-station-wc')) . '"));
                                button.prop("disabled", false).text("' . esc_js(__('Delete', 'pickup-station-wc')) . '");
                            }
                        },
                        error: function() {
                            alert("' . esc_js(__('Error: Failed to delete station', 'pickup-station-wc')) . '");
                            button.prop("disabled", false).text("' . esc_js(__('Delete', 'pickup-station-wc')) . '");
                        }
                    });
                }
            });
        });
        </script>';
    }
});

// Add basic JavaScript for checkout (fallback if JS file doesn't exist)
add_action('wp_footer', function() {
    if (is_checkout()) {
        echo '<script>
        jQuery(document).ready(function($) {
            var isUpdating = false;
            
            $(document).on("change", "#pickup_station", function() {
                var selectedStation = $(this).val();
                var detailsContainer = $("#pickup-station-details");
                
                detailsContainer.html("");
                
                if (selectedStation && !isUpdating) {
                    detailsContainer.html("<p>' . esc_js(__('Loading station details...', 'pickup-station-wc')) . '</p>");
                    
                    // Save to session
                    $.ajax({
                        url: "' . admin_url('admin-ajax.php') . '",
                        type: "POST",
                        data: {
                            action: "save_pickup_station_session",
                            nonce: "' . wp_create_nonce('pickup_station_nonce') . '",
                            station_id: selectedStation
                        }
                    });
                    
                    // Get station details
                    $.ajax({
                        url: "' . admin_url('admin-ajax.php') . '",
                        type: "POST",
                        data: {
                            action: "get_pickup_station_details",
                            nonce: "' . wp_create_nonce('pickup_station_nonce') . '",
                            station_id: selectedStation
                        },
                        success: function(response) {
                            if (response.success) {
                                var station = response.data;
                                var html = "<div class=\"pickup-station-info\">" +
                                          "<h4>" + station.name + "</h4>" +
                                          "<p><strong>' . esc_js(__('Address:', 'pickup-station-wc')) . '</strong> " + station.address + "</p>" +
                                          "<p><strong>' . esc_js(__('City:', 'pickup-station-wc')) . '</strong> " + station.city + "</p>" +
                                          "<p><strong>' . esc_js(__('Phone:', 'pickup-station-wc')) . '</strong> " + station.phone + "</p>" +
                                          "<p><strong>' . esc_js(__('Shipping Cost:', 'pickup-station-wc')) . '</strong> " + station.price + "</p>" +
                                          "</div>";
                                detailsContainer.html(html);
                            } else {
                                detailsContainer.html("<p>' . esc_js(__('Error loading station details', 'pickup-station-wc')) . '</p>");
                            }
                        },
                        error: function() {
                            detailsContainer.html("<p>' . esc_js(__('Error loading station details', 'pickup-station-wc')) . '</p>");
                        }
                    });
                    
                    // Update shipping calculations
                    isUpdating = true;
                    $("body").trigger("update_checkout");
                    setTimeout(function() {
                        isUpdating = false;
                    }, 1000);
                }
            });
            
            // Preserve selection on checkout update
            $(document).on("updated_checkout", function() {
                var sessionStation = "' . (function_exists('WC') && WC()->session ? WC()->session->get('pickup_station_id', '') : '') . '";
                if (sessionStation && $("#pickup_station").val() !== sessionStation) {
                    $("#pickup_station").val(sessionStation).trigger("change");
                }
            });
        });
        </script>';
    }
});


?>