<?php 
class GvpplusAdmin {
 public function __construct(){
 	add_filter( 'give_get_sections_gateways', array($this,'register_sections_gvpplus'), 1);
 	add_filter( 'give_get_settings_gateways', array($this,'register_settings_gvpplus_field') );
    add_action('admin_footer',array($this,'gvpplus_style_admin_footer'));
 }
 public function register_sections_gvpplus( $sections ) {
    $sections['gvpplus-settings'] = __( 'PayPlus', 'give' );
    return $sections;
}
public function register_settings_gvpplus_field( $settings ) {
            $section = give_get_current_setting_section();
            switch ( $section ) {
            case 'gvpplus-settings':
            
            $settings = [
                            [
                                'type' => 'title',
                                'id'   => 'give_title_gateway_settings_gvpplus',
                            ],
                            [
                                'name'    => __('Description', 'gvpplus'),
                                'desc'    => __('This controls the description which the user sees during checkout', 'gvpplus'),
                                'id'      => 'gvpplus_description',
                                'wrapper_class' => 'gvpplus_description_wrap',
                                'type'    => 'textarea',
                                'default' => '',
                                
                            ],
                            [
                                'name'    => __('API KEY', 'gvpplus'),
                                'desc'    => __('PayPlus API Key you can find in your account under Settings. ', 'gvpplus'),
                                'id'      => 'gvpplus_api_key',
                                'type'    => 'text',
                                'default' => '',
                                
                            ],
                            [
                                'name'    => __('SECRET KEY', 'gvpplus'),
                                'desc'    => __('PayPlus Secret Key you can find in your account under Settings. ', 'gvpplus'),
                                'id'      => 'gvpplus_secret_key',
                                'type'    => 'text',
                                'default' => '',
                                
                            ],
                            [
                                'name'    => __('Payment Page UID', 'gvpplus'),
                                'desc'    => __('Your payment page UID can be found under Payment Pages in your side menu in PayPlus account. ', 'gvpplus'),
                                'id'      => 'gvpplus_page_uid',
                                'type'    => 'text',
                                'default' => '',
                                
                            ],
                         
                            [
                                'type' => 'sectionend',
                                'id'   => 'give_title_gateway_settings_gvpplus',
                            ],
                    ];

             break;

            } 
             $settings = apply_filters('give_settings_gvpplus-settings', $settings);
            return $settings;
        }
        public function gvpplus_style_admin_footer(){
            ?>
            <style type="text/css">
                .give-forminp.give-forminp-textarea textarea {width: 100%;height: 60px;}
            </style>
            <?php
        }
        
      
    
}