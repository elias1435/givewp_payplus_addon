<?php
/**
*Plugin Name: GiveWP PayPlus Addon 
*Description: GiveWP accept payment from Payplus payment gateway.
*Version: 1.0
*Author: A.wahab
*/

// Register the gateways 
add_action('givewp_register_payment_gateway', static function ($paymentGatewayRegister) {
    include 'class-gvpplus.php';
    $paymentGatewayRegister->registerGateway(GiveWPPplusGateways::class);
});

if (!class_exists('GvpplusAdmin')){
    include_once 'class-admin-gvpplus.php';
    return new GvpplusAdmin();
}


global $wp_filesystem;
if (empty($wp_filesystem)) {
    require_once (ABSPATH .'/wp-admin/includes/file.php');
    WP_Filesystem();
}

