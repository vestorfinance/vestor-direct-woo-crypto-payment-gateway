<?php

function vestor_pay_enqueue_styles() {
    wp_enqueue_style('vestor-pay-styles', plugin_dir_url(__FILE__) . 'vestor-pay-styles.css?ver=11');
}
add_action('wp_enqueue_scripts', 'vestor_pay_enqueue_styles');
