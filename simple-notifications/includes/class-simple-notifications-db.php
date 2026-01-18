<?php
/**
 * Database handler for Simple Notifications
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Simple_Notifications_DB {

    /**
     * Database version
     */
    const DB_VERSION = '1.0.0';

    /**
     * Table name (without prefix)
     */
    const TABLE_NAME = 'simple_notifications';

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Full table name with prefix
     */
    private $table_name;

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
        global $wpdb;
        $this->table_name = $wpdb->prefix . self::TABLE_NAME;

        // Check for database updates
        add_action( 'plugins_loaded', array( $this, 'check_db_update' ) );
    }

    /**
     * Get table name
     */
    public function get_table_name() {
        return $this->table_name;
    }

    /**
     * Activation hook - create tables
     */
    public static function activate() {
        self::create_table();
        update_option( 'simple_notifications_db_version', self::DB_VERSION );
        update_option( 'simple_notifications_activated', time() );
    }

    /**
     * Deactivation hook
     */
    public static function deactivate() {
        // Clean up scheduled events if any
        wp_clear_scheduled_hook( 'simple_notifications_cleanup' );
    }

    /**
     * Check for database updates
     */
    public function check_db_update() {
        $installed_version = get_option( 'simple_notifications_db_version' );
        if ( $installed_version !== self::DB_VERSION ) {
            self::create_table();
            update_option( 'simple_notifications_db_version', self::DB_VERSION );
        }
    }

    /**
     * Create the notifications table
     */
    private static function create_table() {
        global $wpdb;

        $table_name      = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            source_type varchar(50) NOT NULL,
            source_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            title varchar(255) NOT NULL,
            url varchar(2048) NOT NULL DEFAULT '',
            is_read tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY source_type (source_type),
            KEY is_read (is_read),
            KEY created_at (created_at),
            KEY user_unread (user_id, is_read)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Insert a notification
     *
     * @param array $data Notification data
     * @return int|false Insert ID or false on failure
     */
    public function insert( $data ) {
        global $wpdb;

        $defaults = array(
            'user_id'     => 0,
            'source_type' => '',
            'source_id'   => 0,
            'title'       => '',
            'url'         => '',
            'is_read'     => 0,
            'created_at'  => current_time( 'mysql' ),
        );

        $data = wp_parse_args( $data, $defaults );

        $result = $wpdb->insert(
            $this->table_name,
            array(
                'user_id'     => absint( $data['user_id'] ),
                'source_type' => sanitize_text_field( $data['source_type'] ),
                'source_id'   => absint( $data['source_id'] ),
                'title'       => sanitize_text_field( $data['title'] ),
                'url'         => esc_url_raw( $data['url'] ),
                'is_read'     => absint( $data['is_read'] ),
                'created_at'  => $data['created_at'],
            ),
            array( '%d', '%s', '%d', '%s', '%s', '%d', '%s' )
        );

        if ( false === $result ) {
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Get notifications for a user
     *
     * @param int   $user_id User ID
     * @param array $args    Query arguments
     * @return array Notifications
     */
    public function get_notifications( $user_id, $args = array() ) {
        global $wpdb;

        $defaults = array(
            'limit'       => 10,
            'offset'      => 0,
            'is_read'     => null,
            'source_type' => null,
            'orderby'     => 'created_at',
            'order'       => 'DESC',
        );

        $args = wp_parse_args( $args, $defaults );

        $where = array( 'user_id = %d' );
        $values = array( absint( $user_id ) );

        if ( null !== $args['is_read'] ) {
            $where[] = 'is_read = %d';
            $values[] = absint( $args['is_read'] );
        }

        if ( null !== $args['source_type'] ) {
            $where[] = 'source_type = %s';
            $values[] = sanitize_text_field( $args['source_type'] );
        }

        $where_clause = implode( ' AND ', $where );
        $order        = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
        $orderby      = in_array( $args['orderby'], array( 'id', 'created_at', 'is_read' ), true ) ? $args['orderby'] : 'created_at';

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
            array_merge( $values, array( absint( $args['limit'] ), absint( $args['offset'] ) ) )
        );

        return $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * Get unread count for a user
     *
     * @param int $user_id User ID
     * @return int Unread count
     */
    public function get_unread_count( $user_id ) {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE user_id = %d AND is_read = 0",
                absint( $user_id )
            )
        );
    }

    /**
     * Mark notification as read
     *
     * @param int $notification_id Notification ID
     * @param int $user_id         User ID (for security)
     * @return bool Success
     */
    public function mark_as_read( $notification_id, $user_id = null ) {
        global $wpdb;

        $where = array( 'id' => absint( $notification_id ) );

        if ( null !== $user_id ) {
            $where['user_id'] = absint( $user_id );
        }

        $result = $wpdb->update(
            $this->table_name,
            array( 'is_read' => 1 ),
            $where,
            array( '%d' ),
            array_fill( 0, count( $where ), '%d' )
        );

        return false !== $result;
    }

    /**
     * Mark all notifications as read for a user
     *
     * @param int $user_id User ID
     * @return bool Success
     */
    public function mark_all_as_read( $user_id ) {
        global $wpdb;

        $result = $wpdb->update(
            $this->table_name,
            array( 'is_read' => 1 ),
            array( 'user_id' => absint( $user_id ), 'is_read' => 0 ),
            array( '%d' ),
            array( '%d', '%d' )
        );

        return false !== $result;
    }

    /**
     * Delete a notification
     *
     * @param int $notification_id Notification ID
     * @param int $user_id         User ID (for security)
     * @return bool Success
     */
    public function delete( $notification_id, $user_id = null ) {
        global $wpdb;

        $where = array( 'id' => absint( $notification_id ) );

        if ( null !== $user_id ) {
            $where['user_id'] = absint( $user_id );
        }

        $result = $wpdb->delete(
            $this->table_name,
            $where,
            array_fill( 0, count( $where ), '%d' )
        );

        return false !== $result;
    }

    /**
     * Delete all notifications for a user
     *
     * @param int $user_id User ID
     * @return bool Success
     */
    public function delete_all( $user_id ) {
        global $wpdb;

        $result = $wpdb->delete(
            $this->table_name,
            array( 'user_id' => absint( $user_id ) ),
            array( '%d' )
        );

        return false !== $result;
    }

    /**
     * Get a single notification
     *
     * @param int $notification_id Notification ID
     * @return array|null Notification data or null
     */
    public function get( $notification_id ) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE id = %d",
                absint( $notification_id )
            ),
            ARRAY_A
        );
    }

    /**
     * Check if notification exists for source
     *
     * @param int    $user_id     User ID
     * @param string $source_type Source type
     * @param int    $source_id   Source ID
     * @return bool Exists
     */
    public function exists( $user_id, $source_type, $source_id ) {
        global $wpdb;

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE user_id = %d AND source_type = %s AND source_id = %d",
                absint( $user_id ),
                sanitize_text_field( $source_type ),
                absint( $source_id )
            )
        );

        return $count > 0;
    }
}
