<?php
/**
 * Plugin Name: ExpressMartKenya Cash on Delivery Condition
 * Description: Enable/disable Cash on Delivery based on cities and product brands
 * Version: 1.0.0
 * Author: <a href="https://chebonkelvin.com">Kelvin Chebon</a>
 * Text Domain: conditional-cod
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

class ConditionalCOD {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
    }
    
    public function init() {
        // Hook into WooCommerce payment gateway filters
        add_filter('woocommerce_available_payment_gateways', array($this, 'conditional_cod_availability'));
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add custom brand field to products
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_brand_field'));
        add_action('woocommerce_process_product_meta', array($this, 'save_brand_field'));
    }
    
    /**
     * Control COD availability based on conditions
     */
    public function conditional_cod_availability($available_gateways) {
        // Only run on checkout and cart pages
        if (!is_checkout() && !is_cart()) {
            return $available_gateways;
        }
        
        // Check if COD is available
        if (!isset($available_gateways['cod'])) {
            return $available_gateways;
        }
        
        // Get settings
        $allowed_cities = get_option('ccod_allowed_cities', array());
        $allowed_brands = get_option('ccod_allowed_brands', array());
        $enable_city_filter = get_option('ccod_enable_city_filter', 'no');
        $enable_brand_filter = get_option('ccod_enable_brand_filter', 'no');
        
        // Check city condition
        if ($enable_city_filter === 'yes' && !empty($allowed_cities)) {
            $customer_city = WC()->customer->get_shipping_city();
            if (empty($customer_city)) {
                $customer_city = WC()->customer->get_billing_city();
            }
            
            // Convert to lowercase for comparison
            $customer_city = strtolower(trim($customer_city));
            $allowed_cities = array_map('strtolower', array_map('trim', $allowed_cities));
            
            if (!in_array($customer_city, $allowed_cities)) {
                unset($available_gateways['cod']);
                return $available_gateways;
            }
        }
        
        // Check brand condition
        if ($enable_brand_filter === 'yes' && !empty($allowed_brands)) {
            $cart_has_allowed_brand = false;
            
            foreach (WC()->cart->get_cart() as $cart_item) {
                $product_id = $cart_item['product_id'];
                $product_brand = get_post_meta($product_id, '_product_brand', true);
                
                if (!empty($product_brand) && in_array($product_brand, $allowed_brands)) {
                    $cart_has_allowed_brand = true;
                    break;
                }
            }
            
            if (!$cart_has_allowed_brand) {
                unset($available_gateways['cod']);
                return $available_gateways;
            }
        }
        
        return $available_gateways;
    }
    
    /**
     * Add brand field to product edit page
     */
    public function add_brand_field() {
        woocommerce_wp_text_input(array(
            'id' => '_product_brand',
            'label' => __('Brand', 'conditional-cod'),
            'placeholder' => __('Enter product brand', 'conditional-cod'),
            'desc_tip' => true,
            'description' => __('Enter the brand name for this product', 'conditional-cod')
        ));
    }
    
    /**
     * Save brand field
     */
    public function save_brand_field($post_id) {
        $brand = isset($_POST['_product_brand']) ? sanitize_text_field($_POST['_product_brand']) : '';
        update_post_meta($post_id, '_product_brand', $brand);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Conditional COD', 'conditional-cod'),
            __('Conditional COD', 'conditional-cod'),
            'manage_woocommerce',
            'conditional-cod',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('ccod_settings', 'ccod_allowed_cities');
        register_setting('ccod_settings', 'ccod_allowed_brands');
        register_setting('ccod_settings', 'ccod_enable_city_filter');
        register_setting('ccod_settings', 'ccod_enable_brand_filter');
    }
    
    /**
     * Admin page content
     */
    public function admin_page() {
        if (isset($_POST['submit'])) {
            // Save cities
            $cities = isset($_POST['ccod_allowed_cities']) ? sanitize_textarea_field($_POST['ccod_allowed_cities']) : '';
            $cities_array = array_filter(array_map('trim', explode("\n", $cities)));
            update_option('ccod_allowed_cities', $cities_array);
            
            // Save brands
            $brands = isset($_POST['ccod_allowed_brands']) ? sanitize_textarea_field($_POST['ccod_allowed_brands']) : '';
            $brands_array = array_filter(array_map('trim', explode("\n", $brands)));
            update_option('ccod_allowed_brands', $brands_array);
            
            // Save toggles
            update_option('ccod_enable_city_filter', isset($_POST['ccod_enable_city_filter']) ? 'yes' : 'no');
            update_option('ccod_enable_brand_filter', isset($_POST['ccod_enable_brand_filter']) ? 'yes' : 'no');
            
            echo '<div class="notice notice-success"><p>' . __('Settings saved!', 'conditional-cod') . '</p></div>';
        }
        
        $allowed_cities = get_option('ccod_allowed_cities', array());
        $allowed_brands = get_option('ccod_allowed_brands', array());
        $enable_city_filter = get_option('ccod_enable_city_filter', 'no');
        $enable_brand_filter = get_option('ccod_enable_brand_filter', 'no');
        ?>
        <div class="wrap">
            <h1><?php _e('Conditional Cash on Delivery Settings', 'conditional-cod'); ?></h1>
            
            <form method="post" action="">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="ccod_enable_city_filter"><?php _e('Enable City Filter', 'conditional-cod'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="ccod_enable_city_filter" name="ccod_enable_city_filter" value="yes" <?php checked($enable_city_filter, 'yes'); ?> />
                            <p class="description"><?php _e('Enable to filter COD by cities', 'conditional-cod'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="ccod_allowed_cities"><?php _e('Allowed Cities', 'conditional-cod'); ?></label>
                        </th>
                        <td>
                            <textarea id="ccod_allowed_cities" name="ccod_allowed_cities" rows="10" cols="50" class="large-text"><?php echo esc_textarea(implode("\n", $allowed_cities)); ?></textarea>
                            <p class="description"><?php _e('Enter one city per line. COD will only be available for these cities.', 'conditional-cod'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="ccod_enable_brand_filter"><?php _e('Enable Brand Filter', 'conditional-cod'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="ccod_enable_brand_filter" name="ccod_enable_brand_filter" value="yes" <?php checked($enable_brand_filter, 'yes'); ?> />
                            <p class="description"><?php _e('Enable to filter COD by product brands', 'conditional-cod'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="ccod_allowed_brands"><?php _e('Allowed Brands', 'conditional-cod'); ?></label>
                        </th>
                        <td>
                            <textarea id="ccod_allowed_brands" name="ccod_allowed_brands" rows="10" cols="50" class="large-text"><?php echo esc_textarea(implode("\n", $allowed_brands)); ?></textarea>
                            <p class="description"><?php _e('Enter one brand per line. COD will only be available for products with these brands.', 'conditional-cod'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <h2><?php _e('How to Use', 'conditional-cod'); ?></h2>
            <ol>
                <li><?php _e('Enable the filters you want to use (City and/or Brand)', 'conditional-cod'); ?></li>
                <li><?php _e('For city filtering: Add allowed cities in the textarea above, one per line', 'conditional-cod'); ?></li>
                <li><?php _e('For brand filtering: Add allowed brands in the textarea above, one per line', 'conditional-cod'); ?></li>
                <li><?php _e('Go to your products and set the "Brand" field for each product', 'conditional-cod'); ?></li>
                <li><?php _e('COD will only be available when conditions are met', 'conditional-cod'); ?></li>
            </ol>
        </div>
        <?php
    }
}

// Initialize the plugin
new ConditionalCOD();

/**
 * Add brand column to products list in admin
 */
add_filter('manage_edit-product_columns', function($columns) {
    $columns['brand'] = __('Brand', 'conditional-cod');
    return $columns;
});

add_action('manage_product_posts_custom_column', function($column, $post_id) {
    if ($column === 'brand') {
        $brand = get_post_meta($post_id, '_product_brand', true);
        echo $brand ? esc_html($brand) : '—';
    }
}, 10, 2);

/**
 * Make brand column sortable
 */
add_filter('manage_edit-product_sortable_columns', function($columns) {
    $columns['brand'] = 'brand';
    return $columns;
});

add_action('pre_get_posts', function($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }
    
    if ($query->get('orderby') === 'brand') {
        $query->set('meta_key', '_product_brand');
        $query->set('orderby', 'meta_value');
    }
});
?>