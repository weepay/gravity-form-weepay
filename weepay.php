<?php
/*
Plugin Name: weepay Payment For Gravity Forms
Plugin URI: https://weepay.co
Description: weepay payment gateway turkey for gravity form
Version: 1.0
Author: weepay
Author URI: https://weepay.co

== Description ==

This is the official weepay payment gateway plugin for Gravity Forma. Allows you to accept credit cards, debit cards, netbanking and wallet with the gravity form plugin. It uses a seamles integration, allowing the customer to pay on your website without being redirected away from your website.

 */

define('GF_WEEPAY_VERSION', '1.0');

add_action('admin_post_nopriv_gf_weepay_webhook', "gf_weepay_webhook_init", 10);
add_action('gform_loaded', array('GF_Weepay_Bootstrap', 'load'), 5);

class GF_Weepay_Bootstrap
{
    public static function load()
    {
        if (method_exists('GFForms', 'include_payment_addon_framework') === false) {
            return;
        }

        require_once 'class-gf-weepay.php';

        GFAddOn::register('GFWeepay');

        add_filter('gform_currencies', function (array $currencies) {
            $currencies['TRY'] = array(
                'name' => __('Turkis Lira', 'gravityforms'),
                'symbol_left' => '',
                'symbol_right' => '&#8378;',
                'symbol_padding' => ' ',
                'thousand_separator' => ',',
                'decimal_separator' => '.',
                'decimals' => 2,
            );

            return $currencies;
        });
    }
}

function gf_weepay()
{
    return GFWeepay::get_instance();
}

// This is set to a priority of 10
// Initialize webhook processing
function gf_weepay_webhook_init()
{
    $gf_weepay = gf_weepay();

    $gf_weepay->process_webhook();
}