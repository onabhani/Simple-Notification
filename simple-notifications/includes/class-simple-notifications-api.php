<?php
/**
 * Notification API for Simple Notifications
 *
 * This class provides the internal API that other plugins can use
 * to create and manage notifications.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Simple_Notifications_API {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Database handler
     */
    private $db;

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
        $this->db = Simple_Notifications_DB::get_instance();
    }

    /**
     * Create a notification
     *
     * @param int    $user_id     User ID to notify
     * @param string $source_type Source type (e.g., 'gravityflow', 'hr_leave', 'hr_loan')
     * @param int    $source_id   Source ID (entry ID, request ID, etc.)
     * @param string $title       Notification title/message
     * @param string $url         Click-through URL (optional)
     * @return int|false          Notification ID on success, false on failure
     */
    public function create_notification( $user_id, $source_type, $source_id, $title, $url = '' ) {
        // Validate user exists
        if ( ! get_user_by( 'id', $user_id ) ) {
            return false;
        }

        // Allow filtering/modification before creation
        $notification_data = apply_filters( 'simple_notifications_before_create', array(
            'user_id'     => $user_id,
            'source_type' => $source_type,
            'source_id'   => $source_id,
            'title'       => $title,
            'url'         => $url,
        ) );

        // Allow cancellation
        if ( false === $notification_data ) {
            return false;
        }

        // Check for duplicate (same source for same user)
        $allow_duplicates = apply_filters( 'simple_notifications_allow_duplicates', false, $notification_data );
        if ( ! $allow_duplicates && $this->db->exists( $user_id, $source_type, $source_id ) ) {
            return false;
        }

        // Insert notification (bell notification - always created)
        $notification_id = $this->db->insert( $notification_data );

        if ( $notification_id ) {
            // Fire action after creation
            do_action( 'simple_notifications_created', $notification_id, $notification_data );

            // Handle email notifications with DOFS user preference filters
            $this->maybe_send_email_notification( $notification_id, $notification_data );
        }

        return $notification_id;
    }

    /**
     * Handle email notification sending with DOFS preference filters
     *
     * @param int   $notification_id Notification ID
     * @param array $notification_data Notification data
     */
    private function maybe_send_email_notification( $notification_id, $notification_data ) {
        $user_id     = $notification_data['user_id'];
        $source_type = $notification_data['source_type'];

        // Check if user wants email notification for this type
        $wants_email = apply_filters( 'dofs_user_wants_email_notification', true, $user_id, $source_type );

        if ( ! $wants_email ) {
            return;
        }

        // Check if should send immediately or add to digest
        $send_now = apply_filters( 'dofs_should_send_notification_now', true, $user_id, $source_type );

        if ( $send_now ) {
            // Send email immediately
            do_action( 'simple_notifications_send_email', $notification_id, $notification_data );
        } else {
            // Add to digest queue
            do_action( 'simple_notifications_queue_digest', $notification_id, $notification_data );
        }
    }

    /**
     * Get notifications for a user
     *
     * @param int   $user_id User ID
     * @param array $args    Query arguments
     * @return array Notifications
     */
    public function get_notifications( $user_id, $args = array() ) {
        $notifications = $this->db->get_notifications( $user_id, $args );

        // Allow filtering
        return apply_filters( 'simple_notifications_get_notifications', $notifications, $user_id, $args );
    }

    /**
     * Get unread notifications for a user
     *
     * @param int   $user_id User ID
     * @param int   $limit   Number of notifications to retrieve
     * @return array Notifications
     */
    public function get_unread( $user_id, $limit = 10 ) {
        return $this->get_notifications( $user_id, array(
            'is_read' => 0,
            'limit'   => $limit,
        ) );
    }

    /**
     * Get unread count for a user
     *
     * @param int $user_id User ID
     * @return int Unread count
     */
    public function get_unread_count( $user_id ) {
        $count = $this->db->get_unread_count( $user_id );

        // Allow filtering (e.g., for capping display)
        return apply_filters( 'simple_notifications_unread_count', $count, $user_id );
    }

    /**
     * Mark a notification as read
     *
     * @param int $notification_id Notification ID
     * @param int $user_id         User ID (optional, for security validation)
     * @return bool Success
     */
    public function mark_as_read( $notification_id, $user_id = null ) {
        $result = $this->db->mark_as_read( $notification_id, $user_id );

        if ( $result ) {
            do_action( 'simple_notifications_marked_read', $notification_id, $user_id );
        }

        return $result;
    }

    /**
     * Mark all notifications as read for a user
     *
     * @param int $user_id User ID
     * @return bool Success
     */
    public function mark_all_as_read( $user_id ) {
        $result = $this->db->mark_all_as_read( $user_id );

        if ( $result ) {
            do_action( 'simple_notifications_marked_all_read', $user_id );
        }

        return $result;
    }

    /**
     * Clear (delete) all notifications for a user
     *
     * @param int $user_id User ID
     * @return bool Success
     */
    public function clear_all( $user_id ) {
        $result = $this->db->delete_all( $user_id );

        if ( $result ) {
            do_action( 'simple_notifications_cleared_all', $user_id );
        }

        return $result;
    }

    /**
     * Delete a single notification
     *
     * @param int $notification_id Notification ID
     * @param int $user_id         User ID (optional, for security)
     * @return bool Success
     */
    public function delete( $notification_id, $user_id = null ) {
        $result = $this->db->delete( $notification_id, $user_id );

        if ( $result ) {
            do_action( 'simple_notifications_deleted', $notification_id, $user_id );
        }

        return $result;
    }

    /**
     * Get a single notification
     *
     * @param int $notification_id Notification ID
     * @return array|null Notification data or null
     */
    public function get( $notification_id ) {
        return $this->db->get( $notification_id );
    }

    /**
     * Format notification for display
     *
     * @param array $notification Raw notification data
     * @return array Formatted notification
     */
    public function format_notification( $notification ) {
        $formatted = array(
            'id'          => (int) $notification['id'],
            'title'       => esc_html( $notification['title'] ),
            'url'         => esc_url( $notification['url'] ),
            'source_type' => esc_html( $notification['source_type'] ),
            'source_id'   => (int) $notification['source_id'],
            'is_read'     => (bool) $notification['is_read'],
            'created_at'  => $notification['created_at'],
            'time_ago'    => $this->human_time_diff( $notification['created_at'] ),
        );

        // Add source label
        $formatted['source_label'] = $this->get_source_label( $notification['source_type'] );

        return apply_filters( 'simple_notifications_format_notification', $formatted, $notification );
    }

    /**
     * Get human-readable time difference
     *
     * @param string $datetime MySQL datetime
     * @return string Human-readable time
     */
    private function human_time_diff( $datetime ) {
        $timestamp = strtotime( $datetime );
        $diff      = human_time_diff( $timestamp, current_time( 'timestamp' ) );

        /* translators: %s: human-readable time difference */
        return sprintf( __( '%s ago', 'simple-notifications' ), $diff );
    }

    /**
     * Get source type label
     *
     * @param string $source_type Source type
     * @return string Label
     */
    public function get_source_label( $source_type ) {
        $labels = apply_filters( 'simple_notifications_source_labels', array(
            'gravityflow' => __( 'Workflow Task', 'simple-notifications' ),
            'hr_leave'    => __( 'Leave Request', 'simple-notifications' ),
            'hr_loan'     => __( 'Loan Request', 'simple-notifications' ),
            'hr_expense'  => __( 'Expense Request', 'simple-notifications' ),
            'hr_overtime' => __( 'Overtime Request', 'simple-notifications' ),
            'mention'     => __( 'Mention', 'simple-notifications' ),
            'custom'      => __( 'Notification', 'simple-notifications' ),
        ) );

        return isset( $labels[ $source_type ] ) ? $labels[ $source_type ] : ucfirst( str_replace( '_', ' ', $source_type ) );
    }
}
