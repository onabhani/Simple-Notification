<?php
/**
 * Plugin Name: Simple Notifications
 * Plugin URI: https://github.com/onabhani/Simple-Notification
 * Description: Lightweight on-site notification system for Gravity Flow tasks, HR requests, and custom events.
 * Version: 1.0.0
 * Author: Omar Nabhani
 * Author URI: https://github.com/onabhani
 * License: GPLv2 or later
 * Text Domain: simple-notifications
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'SIMPLE_NOTIFICATIONS_VERSION', '1.0.0' );
define( 'SIMPLE_NOTIFICATIONS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SIMPLE_NOTIFICATIONS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SIMPLE_NOTIFICATIONS_POLL_INTERVAL', 30000 ); // 30 seconds default

// Include required files
require_once SIMPLE_NOTIFICATIONS_PLUGIN_DIR . 'includes/class-simple-notifications-db.php';
require_once SIMPLE_NOTIFICATIONS_PLUGIN_DIR . 'includes/class-simple-notifications-api.php';
require_once SIMPLE_NOTIFICATIONS_PLUGIN_DIR . 'includes/class-simple-notifications-ajax.php';
require_once SIMPLE_NOTIFICATIONS_PLUGIN_DIR . 'includes/class-simple-notifications-frontend.php';
require_once SIMPLE_NOTIFICATIONS_PLUGIN_DIR . 'includes/class-simple-notifications-integrations.php';

/**
 * Main plugin class
 */
class Simple_Notifications {

    /**
     * Single instance of the class
     */
    private static $instance = null;

    /**
     * Get single instance
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Initialize components
        add_action( 'init', array( $this, 'init' ) );

        // Load text domain
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
    }

    /**
     * Initialize plugin components
     */
    public function init() {
        // Initialize database handler
        Simple_Notifications_DB::get_instance();

        // Initialize API
        Simple_Notifications_API::get_instance();

        // Initialize AJAX handlers
        Simple_Notifications_AJAX::get_instance();

        // Initialize frontend (only on frontend)
        if ( ! is_admin() ) {
            Simple_Notifications_Frontend::get_instance();
        }

        // Initialize integrations
        Simple_Notifications_Integrations::get_instance();
    }

    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'simple-notifications',
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages'
        );
    }
}

// Activation hook
register_activation_hook( __FILE__, array( 'Simple_Notifications_DB', 'activate' ) );

// Deactivation hook
register_deactivation_hook( __FILE__, array( 'Simple_Notifications_DB', 'deactivate' ) );

// Initialize the plugin
add_action( 'plugins_loaded', array( 'Simple_Notifications', 'get_instance' ) );

/**
 * Helper function to create a notification
 *
 * @param int    $user_id     User ID to notify
 * @param string $source_type Source type (e.g., 'gravityflow', 'hr_leave', 'hr_loan')
 * @param int    $source_id   Source ID (entry ID, request ID, etc.)
 * @param string $title       Notification title
 * @param string $url         Click-through URL
 * @return int|false          Notification ID on success, false on failure
 */
function simple_notifications_create( $user_id, $source_type, $source_id, $title, $url = '' ) {
    return Simple_Notifications_API::get_instance()->create_notification( $user_id, $source_type, $source_id, $title, $url );
}

/**
 * Helper function to get unread count for a user
 *
 * @param int $user_id User ID (defaults to current user)
 * @return int Unread notification count
 */
function simple_notifications_unread_count( $user_id = null ) {
    if ( null === $user_id ) {
        $user_id = get_current_user_id();
    }
    return Simple_Notifications_API::get_instance()->get_unread_count( $user_id );
}

/**
 * Helper function to mark notification as read
 *
 * @param int $notification_id Notification ID
 * @return bool Success
 */
function simple_notifications_mark_read( $notification_id ) {
    return Simple_Notifications_API::get_instance()->mark_as_read( $notification_id );
}

/**
 * Helper function to clear all notifications for a user
 *
 * @param int $user_id User ID (defaults to current user)
 * @return bool Success
 */
function simple_notifications_clear_all( $user_id = null ) {
    if ( null === $user_id ) {
        $user_id = get_current_user_id();
    }
    return Simple_Notifications_API::get_instance()->clear_all( $user_id );
}
