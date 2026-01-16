<?php
/**
 * AJAX handler for Simple Notifications
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Simple_Notifications_AJAX {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * API instance
     */
    private $api;

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
        $this->api = Simple_Notifications_API::get_instance();
        $this->init_hooks();
    }

    /**
     * Initialize AJAX hooks
     */
    private function init_hooks() {
        // Get notifications (polling)
        add_action( 'wp_ajax_simple_notifications_get', array( $this, 'ajax_get_notifications' ) );

        // Get unread count
        add_action( 'wp_ajax_simple_notifications_count', array( $this, 'ajax_get_count' ) );

        // Mark single as read
        add_action( 'wp_ajax_simple_notifications_mark_read', array( $this, 'ajax_mark_read' ) );

        // Mark all as read
        add_action( 'wp_ajax_simple_notifications_mark_all_read', array( $this, 'ajax_mark_all_read' ) );

        // Clear all notifications
        add_action( 'wp_ajax_simple_notifications_clear_all', array( $this, 'ajax_clear_all' ) );

        // Delete single notification
        add_action( 'wp_ajax_simple_notifications_delete', array( $this, 'ajax_delete' ) );
    }

    /**
     * Verify nonce and user
     *
     * @return bool|int User ID on success, false on failure
     */
    private function verify_request() {
        // Check nonce
        if ( ! check_ajax_referer( 'simple_notifications_nonce', 'nonce', false ) ) {
            return false;
        }

        // Check user is logged in
        if ( ! is_user_logged_in() ) {
            return false;
        }

        return get_current_user_id();
    }

    /**
     * Send JSON response
     *
     * @param bool  $success Success status
     * @param array $data    Response data
     */
    private function send_response( $success, $data = array() ) {
        wp_send_json( array_merge( array( 'success' => $success ), $data ) );
    }

    /**
     * AJAX: Get notifications
     */
    public function ajax_get_notifications() {
        $user_id = $this->verify_request();
        if ( ! $user_id ) {
            $this->send_response( false, array( 'message' => __( 'Unauthorized', 'simple-notifications' ) ) );
        }

        $limit  = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 10;
        $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
        $unread_only = isset( $_POST['unread_only'] ) && $_POST['unread_only'] === 'true';

        $args = array(
            'limit'  => min( $limit, 50 ), // Cap at 50
            'offset' => $offset,
        );

        if ( $unread_only ) {
            $args['is_read'] = 0;
        }

        $notifications = $this->api->get_notifications( $user_id, $args );
        $unread_count  = $this->api->get_unread_count( $user_id );

        // Format notifications
        $formatted = array();
        foreach ( $notifications as $notification ) {
            $formatted[] = $this->api->format_notification( $notification );
        }

        $this->send_response( true, array(
            'notifications' => $formatted,
            'unread_count'  => $unread_count,
            'has_more'      => count( $notifications ) === $args['limit'],
        ) );
    }

    /**
     * AJAX: Get unread count only
     */
    public function ajax_get_count() {
        $user_id = $this->verify_request();
        if ( ! $user_id ) {
            $this->send_response( false, array( 'message' => __( 'Unauthorized', 'simple-notifications' ) ) );
        }

        $count = $this->api->get_unread_count( $user_id );

        $this->send_response( true, array(
            'unread_count' => $count,
        ) );
    }

    /**
     * AJAX: Mark notification as read
     */
    public function ajax_mark_read() {
        $user_id = $this->verify_request();
        if ( ! $user_id ) {
            $this->send_response( false, array( 'message' => __( 'Unauthorized', 'simple-notifications' ) ) );
        }

        $notification_id = isset( $_POST['notification_id'] ) ? absint( $_POST['notification_id'] ) : 0;

        if ( ! $notification_id ) {
            $this->send_response( false, array( 'message' => __( 'Invalid notification ID', 'simple-notifications' ) ) );
        }

        $result = $this->api->mark_as_read( $notification_id, $user_id );
        $unread_count = $this->api->get_unread_count( $user_id );

        $this->send_response( $result, array(
            'unread_count' => $unread_count,
        ) );
    }

    /**
     * AJAX: Mark all as read
     */
    public function ajax_mark_all_read() {
        $user_id = $this->verify_request();
        if ( ! $user_id ) {
            $this->send_response( false, array( 'message' => __( 'Unauthorized', 'simple-notifications' ) ) );
        }

        $result = $this->api->mark_all_as_read( $user_id );

        $this->send_response( $result, array(
            'unread_count' => 0,
        ) );
    }

    /**
     * AJAX: Clear all notifications
     */
    public function ajax_clear_all() {
        $user_id = $this->verify_request();
        if ( ! $user_id ) {
            $this->send_response( false, array( 'message' => __( 'Unauthorized', 'simple-notifications' ) ) );
        }

        $result = $this->api->clear_all( $user_id );

        $this->send_response( $result, array(
            'unread_count' => 0,
        ) );
    }

    /**
     * AJAX: Delete single notification
     */
    public function ajax_delete() {
        $user_id = $this->verify_request();
        if ( ! $user_id ) {
            $this->send_response( false, array( 'message' => __( 'Unauthorized', 'simple-notifications' ) ) );
        }

        $notification_id = isset( $_POST['notification_id'] ) ? absint( $_POST['notification_id'] ) : 0;

        if ( ! $notification_id ) {
            $this->send_response( false, array( 'message' => __( 'Invalid notification ID', 'simple-notifications' ) ) );
        }

        $result = $this->api->delete( $notification_id, $user_id );
        $unread_count = $this->api->get_unread_count( $user_id );

        $this->send_response( $result, array(
            'unread_count' => $unread_count,
        ) );
    }
}
