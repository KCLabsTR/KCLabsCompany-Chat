<?php
/*
Plugin Name: KC Labs - Company Chat
Plugin URI:  https://kirgec.com
Description: Elementor uyumlu, şirket içi anlık iletişim ve bildirimli chat sistemi.
Version:     1.0.0
Author:      Mehmet KIRGEÇ
License:     GPLv2 or later
Text Domain: company-chat
Tested up to: 6.4
Requires PHP: 7.4
Requires at least: 5.0
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class KCLabs_CompanyChat {
    const VERSION = '1.0.0';
    public function __construct() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
        add_action( 'init', array( $this, 'register_shortcodes' ) );
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'elementor/widgets/widgets_registered', array( $this, 'register_elementor_widget' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_upload_chat_file', array( $this, 'upload_chat_file' ) );
        add_action( 'wp_ajax_nopriv_upload_chat_file', '__return_false' );
    }

    public function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $messages_table = $wpdb->prefix . 'chat_messages';
        $status_table   = $wpdb->prefix . 'chat_user_status';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_messages = "CREATE TABLE $messages_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sender_id bigint(20) unsigned NOT NULL,
            receiver_id bigint(20) unsigned NOT NULL,
            message_text text,
            is_read tinyint(1) DEFAULT 0,
            read_timestamp datetime NULL,
            file_url text NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        $sql_status = "CREATE TABLE $status_table (
            user_id bigint(20) unsigned NOT NULL,
            status varchar(20) DEFAULT 'offline',
            last_seen datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (user_id)
        ) $charset_collate;";

        dbDelta( $sql_messages );
        dbDelta( $sql_status );
    }

    public function deactivate() {
        // Optionally clean up things here
    }

    public function register_admin_menu() {
        add_menu_page( 'Company Chat', 'Company Chat', 'manage_options', 'company-chat', array( $this, 'admin_page' ) );
    }

    public function admin_page() {
        if ( isset( $_POST['kclabs_chat_save'] ) && check_admin_referer( 'kclabs_chat_settings' ) ) {
            update_option( 'kclabs_chat_ws_url', sanitize_text_field( $_POST['kclabs_chat_ws_url'] ) );
            echo '<div class="updated"><p>' . esc_html__( 'Settings saved', 'company-chat' ) . '</p></div>';
        }

        $ws_url = get_option( 'kclabs_chat_ws_url', '' );
        echo '<div class="wrap"><h1>Company Chat Settings</h1>';
        echo '<form method="post">';
        wp_nonce_field( 'kclabs_chat_settings' );
        echo '<table class="form-table"><tr><th scope="row"><label for="kclabs_chat_ws_url">WebSocket URL</label></th>';
        echo '<td><input type="text" name="kclabs_chat_ws_url" id="kclabs_chat_ws_url" value="' . esc_attr( $ws_url ) . '" class="regular-text" /></td></tr></table>';
        submit_button();
        echo '<input type="hidden" name="kclabs_chat_save" value="1" />';
        echo '</form></div>';
    }

    public function register_shortcodes() {
        add_shortcode( 'company_chat_window', array( $this, 'render_chat_window' ) );
    }

    public function render_chat_window() {
        ob_start();
        include plugin_dir_path( __FILE__ ) . 'chat-ui.php';
        return ob_get_clean();
    }

    public function enqueue_assets() {
        if ( ! is_user_logged_in() ) {
            return;
        }
        wp_enqueue_style( 'kclabs-chat', plugin_dir_url( __FILE__ ) . 'chat.css', array(), self::VERSION );
        wp_enqueue_script( 'kclabs-chat', plugin_dir_url( __FILE__ ) . 'chat.js', array( 'jquery' ), self::VERSION, true );
        $settings = array(
            'ws_url' => get_option( 'kclabs_chat_ws_url', '' ),
            'nonce'  => wp_create_nonce( 'kclabs_chat_file' ),
        );
        wp_localize_script( 'kclabs-chat', 'kclabsChat', $settings );
    }

    public function upload_chat_file() {
        check_ajax_referer( 'kclabs_chat_file', 'nonce' );
        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( __( 'Permission denied', 'company-chat' ) );
        }
        if ( empty( $_FILES['file'] ) ) {
            wp_send_json_error( __( 'No file', 'company-chat' ) );
        }
        $file = wp_handle_upload( $_FILES['file'], array( 'test_form' => false ) );
        if ( isset( $file['url'] ) ) {
            wp_send_json_success( array( 'url' => $file['url'] ) );
        }
        wp_send_json_error( __( 'Upload error', 'company-chat' ) );
    }

    public function register_elementor_widget() {
        if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
            return;
        }
        require_once plugin_dir_path( __FILE__ ) . 'elementor-widget.php';
        \Elementor\Plugin::instance()->widgets_manager->register_widget_type( new Company_Chat_Widget() );
    }
}

new KCLabs_CompanyChat();

function kclabs_chat_add_message( $sender_id, $receiver_id, $message_text = '', $file_url = null ) {
    global $wpdb;
    $table = $wpdb->prefix . 'chat_messages';
    $wpdb->insert( $table, array(
        'sender_id'   => $sender_id,
        'receiver_id' => $receiver_id,
        'message_text'=> $message_text,
        'file_url'    => $file_url,
        'created_at'  => current_time( 'mysql' ),
    ) );
    return $wpdb->insert_id;
}

function kclabs_chat_fetch_messages( $user1_id, $user2_id, $after = null ) {
    global $wpdb;
    $table = $wpdb->prefix . 'chat_messages';
    $query = $wpdb->prepare( "SELECT * FROM $table WHERE ((sender_id=%d AND receiver_id=%d) OR (sender_id=%d AND receiver_id=%d))".
                             ($after?" AND created_at > %s":"") .
                             " ORDER BY created_at ASC",
                             $user1_id, $user2_id, $user2_id, $user1_id, $after );
    return $after ? $wpdb->get_results( $query, ARRAY_A ) : $wpdb->get_results( $query, ARRAY_A );
}

function kclabs_chat_mark_message_read( $message_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'chat_messages';
    return $wpdb->update( $table, array( 'is_read' => 1, 'read_timestamp' => current_time( 'mysql' ) ), array( 'id' => $message_id ) );
}

function kclabs_chat_update_user_status( $user_id, $status ) {
    global $wpdb;
    $table = $wpdb->prefix . 'chat_user_status';
    if ( $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM $table WHERE user_id = %d", $user_id ) ) ) {
        $wpdb->update( $table, array( 'status' => $status, 'last_seen' => current_time( 'mysql' ) ), array( 'user_id' => $user_id ) );
    } else {
        $wpdb->insert( $table, array( 'user_id' => $user_id, 'status' => $status, 'last_seen' => current_time( 'mysql' ) ) );
    }
}

function kclabs_chat_get_users_online() {
    global $wpdb;
    $table = $wpdb->prefix . 'chat_user_status';
    return $wpdb->get_results( "SELECT * FROM $table WHERE status='online'", ARRAY_A );
}

?>