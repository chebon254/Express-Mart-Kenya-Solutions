<?php
/*
Plugin Name: COD Restriction by Brand
Plugin URI: https://chebonkelvin.com
Description: Disable Cash on Delivery (COD) payment method for specific product brands
Version: 1.0
Author: Chebon Kelvin
Author URI: https://chebonkelvin.com
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class COD_Brand_Restrictions {
    public function __construct() {
        // Hook to modify available payment gateways
        add_filter('woocommerce_available_payment_gateways', [$this, 'restrict_cod_for_brands']);
    }

    /**
     * Disable COD for specific brands
     * 
     * @param array $available_gateways Available payment gateways
     * @return array Modified payment gateways
     */
    public function restrict_cod_for_brands($available_gateways) {
        // Check if COD gateway exists
        if (!isset($available_gateways['cod'])) {
            return $available_gateways;
        }

        // List of brand slugs where COD should be disabled
        $restricted_brands = [
            'apple',
            'jbl',
            'samsung',
            'vision-plus',
            'xiaomi'
        ];

        // Check if cart contains products from restricted brands
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $product_brands = $this->get_product_brands($product);

            // If any product brand is in restricted list, remove COD
            foreach ($product_brands as $brand) {
                if (in_array($brand, $restricted_brands)) {
                    unset($available_gateways['cod']);
                    break 2; // Exit both loops
                }
            }
        }

        return $available_gateways;
    }

    /**
     * Get brands for a product
     * 
     * @param WC_Product $product Product object
     * @return array Product brands
     */
    private function get_product_brands($product) {
        $brands = [];

        // Check if using WooCommerce Brands official plugin
        if (taxonomy_exists('product_brand')) {
            $product_brands = get_the_terms($product->get_id(), 'product_brand');
            if ($product_brands && !is_wp_error($product_brands)) {
                foreach ($product_brands as $brand) {
                    $brands[] = $brand->slug;
                }
            }
        }

        // Check for other common brand taxonomy plugins
        $alternative_taxonomies = [
            'pwb-brand',      // Perfect WooCommerce Brands
            'product_brand',  // Additional potential taxonomy
        ];

        foreach ($alternative_taxonomies as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                $product_brands = get_the_terms($product->get_id(), $taxonomy);
                if ($product_brands && !is_wp_error($product_brands)) {
                    foreach ($product_brands as $brand) {
                        $brands[] = $brand->slug;
                    }
                }
            }
        }

        return $brands;
    }
}

// Initialize the plugin
add_action('plugins_loaded', function() {
    new COD_Brand_Restrictions();
});