<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Company_Chat_Widget extends \Elementor\Widget_Base {
    public function get_name() {
        return 'company_chat_widget';
    }

    public function get_title() {
        return __( 'Company Chat', 'company-chat' );
    }

    public function get_icon() {
        return 'eicon-chat';
    }

    public function get_categories() {
        return [ 'general' ];
    }

    protected function render() {
        echo do_shortcode( '[company_chat_window]' );
    }
}