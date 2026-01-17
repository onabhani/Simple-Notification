<?php
/**
 * Frontend handler for Simple Notifications
 *
 * Handles the bell component, shortcodes, and frontend assets.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Simple_Notifications_Frontend {

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
     * Initialize hooks
     */
    private function init_hooks() {
        // Enqueue scripts and styles
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // Add bell to nav menu (disabled by default when using DOFS dashboard)
        if ( apply_filters( 'simple_notifications_add_to_nav_menu', true ) ) {
            add_filter( 'wp_nav_menu_items', array( $this, 'add_bell_to_menu' ), 10, 2 );
        }

        // Register shortcodes
        add_shortcode( 'simple_notifications_bell', array( $this, 'shortcode_bell' ) );
        add_shortcode( 'simple_notifications_page', array( $this, 'shortcode_page' ) );

        // Add AJAX URL to head
        add_action( 'wp_head', array( $this, 'output_ajax_url' ) );

        // Simple Dashboard (DOFS) integration
        add_action( 'dofs_topbar_notifications', array( $this, 'render_dofs_dropdown' ) );
        add_action( 'dofs_notification_count', array( $this, 'render_dofs_badge_count' ) );

        // Generic action hook for custom placements
        add_action( 'simple_notifications_render_bell', array( $this, 'action_render_bell' ) );
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_assets() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        wp_enqueue_style(
            'simple-notifications',
            SIMPLE_NOTIFICATIONS_PLUGIN_URL . 'assets/css/simple-notifications.css',
            array(),
            SIMPLE_NOTIFICATIONS_VERSION
        );

        wp_enqueue_script(
            'simple-notifications',
            SIMPLE_NOTIFICATIONS_PLUGIN_URL . 'assets/js/simple-notifications.js',
            array( 'jquery' ),
            SIMPLE_NOTIFICATIONS_VERSION,
            true
        );

        // Localize script
        wp_localize_script( 'simple-notifications', 'simpleNotifications', array(
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'simple_notifications_nonce' ),
            'pollInterval' => apply_filters( 'simple_notifications_poll_interval', SIMPLE_NOTIFICATIONS_POLL_INTERVAL ),
            'i18n'         => array(
                'noNotifications' => __( 'No notifications', 'simple-notifications' ),
                'markAllRead'     => __( 'Mark all as read', 'simple-notifications' ),
                'clearAll'        => __( 'Clear all', 'simple-notifications' ),
                'viewAll'         => __( 'View all notifications', 'simple-notifications' ),
                'loading'         => __( 'Loading...', 'simple-notifications' ),
            ),
            'viewAllUrl'   => $this->get_notifications_page_url(),
        ) );
    }

    /**
     * Output AJAX URL in head
     */
    public function output_ajax_url() {
        if ( ! is_user_logged_in() ) {
            return;
        }
        ?>
        <script type="text/javascript">
            var simpleNotificationsAjaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
        </script>
        <?php
    }

    /**
     * Get notifications page URL
     */
    private function get_notifications_page_url() {
        $page_id = apply_filters( 'simple_notifications_page_id', 0 );
        if ( $page_id ) {
            return get_permalink( $page_id );
        }
        return home_url( '/notifications/' );
    }

    /**
     * Add bell to navigation menu
     */
    public function add_bell_to_menu( $items, $args ) {
        if ( ! is_user_logged_in() ) {
            return $items;
        }

        // Get menu locations to add bell to
        $menu_locations = apply_filters( 'simple_notifications_menu_locations', array( 'primary', 'main-menu', 'header-menu' ) );

        if ( ! isset( $args->theme_location ) || ! in_array( $args->theme_location, $menu_locations, true ) ) {
            return $items;
        }

        $bell_html = $this->render_bell();
        $items    .= '<li class="menu-item simple-notifications-menu-item">' . $bell_html . '</li>';

        return $items;
    }

    /**
     * Render bell via action hook
     */
    public function action_render_bell() {
        if ( ! is_user_logged_in() ) {
            return;
        }
        echo $this->render_bell();
    }

    /**
     * Render dropdown content for DOFS dashboard topbar
     * Outputs just the notification list (dropdown content without the bell icon)
     */
    public function render_dofs_dropdown() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user_id       = get_current_user_id();
        $notifications = $this->api->get_notifications( $user_id, array(
            'limit' => 10,
        ) );
        ?>
        <div class="simple-notifications-dofs-dropdown" id="simple-notifications-dropdown">
            <div class="simple-notifications-list" id="simple-notifications-list">
                <?php if ( empty( $notifications ) ) : ?>
                    <div class="simple-notifications-empty">
                        <?php esc_html_e( 'No notifications', 'simple-notifications' ); ?>
                    </div>
                <?php else : ?>
                    <?php foreach ( $notifications as $notification ) : ?>
                        <?php $formatted = $this->api->format_notification( $notification ); ?>
                        <a href="<?php echo esc_url( $formatted['url'] ?: '#' ); ?>"
                           class="simple-notifications-item <?php echo $formatted['is_read'] ? 'is-read' : 'is-unread'; ?>"
                           data-id="<?php echo esc_attr( $formatted['id'] ); ?>">
                            <span class="simple-notifications-item-source"><?php echo esc_html( $formatted['source_label'] ); ?></span>
                            <span class="simple-notifications-item-title"><?php echo esc_html( $formatted['title'] ); ?></span>
                            <span class="simple-notifications-item-time"><?php echo esc_html( $formatted['time_ago'] ); ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="simple-notifications-footer">
                <a href="<?php echo esc_url( $this->get_notifications_page_url() ); ?>" class="simple-notifications-view-all">
                    <?php esc_html_e( 'View all notifications', 'simple-notifications' ); ?>
                </a>
                <button type="button" class="simple-notifications-mark-all-link" id="simple-notifications-mark-all">
                    <?php esc_html_e( 'Mark all as read', 'simple-notifications' ); ?>
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Render badge count for DOFS dashboard topbar
     * Outputs just the number (or empty if zero)
     */
    public function render_dofs_badge_count() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $unread_count = $this->api->get_unread_count( get_current_user_id() );

        // Output the count with a span for JS updates
        ?>
        <span class="simple-notifications-badge-count" id="simple-notifications-badge" data-count="<?php echo esc_attr( $unread_count ); ?>">
            <?php if ( $unread_count > 0 ) : ?>
                <?php echo esc_html( $unread_count > 99 ? '99+' : $unread_count ); ?>
            <?php endif; ?>
        </span>
        <?php
    }

    /**
     * Shortcode: Notification bell
     */
    public function shortcode_bell( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '';
        }

        $atts = shortcode_atts( array(
            'class' => '',
        ), $atts, 'simple_notifications_bell' );

        return $this->render_bell( $atts['class'] );
    }

    /**
     * Shortcode: Notifications page
     */
    public function shortcode_page( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'Please log in to view your notifications.', 'simple-notifications' ) . '</p>';
        }

        $atts = shortcode_atts( array(
            'per_page' => 20,
        ), $atts, 'simple_notifications_page' );

        return $this->render_notifications_page( absint( $atts['per_page'] ) );
    }

    /**
     * Render the notification bell
     */
    public function render_bell( $extra_class = '' ) {
        $unread_count = $this->api->get_unread_count( get_current_user_id() );

        ob_start();
        ?>
        <div class="simple-notifications-bell <?php echo esc_attr( $extra_class ); ?>" id="simple-notifications-bell">
            <div class="simple-notifications-bell-icon" id="simple-notifications-bell-toggle">
                <svg width="20" height="20" viewBox="0 0 448 512" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                    <path fill="currentColor" d="M439.39 362.29c-19.32-20.76-55.47-51.99-55.47-154.29 0-77.7-54.48-139.9-127.94-155.16V32c0-17.67-14.32-32-31.98-32s-31.98 14.33-31.98 32v20.84C118.56 68.1 64.08 130.3 64.08 208c0 102.3-36.15 133.53-55.47 154.29-6 6.45-8.66 14.16-8.61 21.71.11 16.4 12.98 32 32.1 32h383.8c19.12 0 32-15.6 32.1-32 .05-7.55-2.61-15.27-8.61-21.71zM67.53 368c21.22-27.97 44.42-74.33 44.53-159.42 0-.2-.06-.38-.06-.58 0-61.86 50.14-112 112-112s112 50.14 112 112c0 .2-.06.38-.06.58.11 85.1 23.31 131.46 44.53 159.42H67.53zM224 512c35.32 0 63.97-28.65 63.97-64H160.03c0 35.35 28.65 64 63.97 64z"/>
                </svg>
                <span class="simple-notifications-badge <?php echo $unread_count > 0 ? 'has-unread' : ''; ?>" id="simple-notifications-badge">
                    <?php echo $unread_count > 0 ? esc_html( $unread_count > 99 ? '99+' : $unread_count ) : ''; ?>
                </span>
            </div>
            <div class="simple-notifications-dropdown" id="simple-notifications-dropdown">
                <div class="simple-notifications-header">
                    <span class="simple-notifications-title"><?php esc_html_e( 'Notifications', 'simple-notifications' ); ?></span>
                    <button type="button" class="simple-notifications-mark-all" id="simple-notifications-mark-all" title="<?php esc_attr_e( 'Mark all as read', 'simple-notifications' ); ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                            <polyline points="22 4 12 14.01 9 11.01"/>
                        </svg>
                    </button>
                </div>
                <div class="simple-notifications-list" id="simple-notifications-list">
                    <div class="simple-notifications-loading">
                        <span class="simple-notifications-spinner"></span>
                    </div>
                </div>
                <div class="simple-notifications-footer">
                    <a href="<?php echo esc_url( $this->get_notifications_page_url() ); ?>" class="simple-notifications-view-all">
                        <?php esc_html_e( 'View all notifications', 'simple-notifications' ); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the full notifications page
     */
    public function render_notifications_page( $per_page = 20 ) {
        $user_id = get_current_user_id();
        $page    = isset( $_GET['notif_page'] ) ? max( 1, absint( $_GET['notif_page'] ) ) : 1;
        $offset  = ( $page - 1 ) * $per_page;

        $notifications = $this->api->get_notifications( $user_id, array(
            'limit'  => $per_page,
            'offset' => $offset,
        ) );

        $unread_count = $this->api->get_unread_count( $user_id );

        ob_start();
        ?>
        <div class="simple-notifications-page" id="simple-notifications-page">
            <div class="simple-notifications-page-header">
                <h2><?php esc_html_e( 'Notifications', 'simple-notifications' ); ?></h2>
                <?php if ( $unread_count > 0 ) : ?>
                    <span class="simple-notifications-page-unread">
                        <?php
                        /* translators: %d: number of unread notifications */
                        printf( esc_html__( '%d unread', 'simple-notifications' ), $unread_count );
                        ?>
                    </span>
                <?php endif; ?>
                <div class="simple-notifications-page-actions">
                    <button type="button" class="simple-notifications-btn" id="simple-notifications-page-mark-all">
                        <?php esc_html_e( 'Mark all as read', 'simple-notifications' ); ?>
                    </button>
                    <button type="button" class="simple-notifications-btn simple-notifications-btn-danger" id="simple-notifications-page-clear-all">
                        <?php esc_html_e( 'Clear all', 'simple-notifications' ); ?>
                    </button>
                </div>
            </div>

            <div class="simple-notifications-page-list" id="simple-notifications-page-list">
                <?php if ( empty( $notifications ) ) : ?>
                    <div class="simple-notifications-empty">
                        <p><?php esc_html_e( 'No notifications yet.', 'simple-notifications' ); ?></p>
                    </div>
                <?php else : ?>
                    <?php foreach ( $notifications as $notification ) : ?>
                        <?php $formatted = $this->api->format_notification( $notification ); ?>
                        <div class="simple-notifications-page-item <?php echo $formatted['is_read'] ? 'is-read' : 'is-unread'; ?>" data-id="<?php echo esc_attr( $formatted['id'] ); ?>">
                            <div class="simple-notifications-page-item-content">
                                <span class="simple-notifications-page-item-source"><?php echo esc_html( $formatted['source_label'] ); ?></span>
                                <?php if ( ! empty( $formatted['url'] ) ) : ?>
                                    <a href="<?php echo esc_url( $formatted['url'] ); ?>" class="simple-notifications-page-item-title" data-notification-id="<?php echo esc_attr( $formatted['id'] ); ?>">
                                        <?php echo esc_html( $formatted['title'] ); ?>
                                    </a>
                                <?php else : ?>
                                    <span class="simple-notifications-page-item-title"><?php echo esc_html( $formatted['title'] ); ?></span>
                                <?php endif; ?>
                                <span class="simple-notifications-page-item-time"><?php echo esc_html( $formatted['time_ago'] ); ?></span>
                            </div>
                            <div class="simple-notifications-page-item-actions">
                                <?php if ( ! $formatted['is_read'] ) : ?>
                                    <button type="button" class="simple-notifications-page-item-mark-read" data-id="<?php echo esc_attr( $formatted['id'] ); ?>" title="<?php esc_attr_e( 'Mark as read', 'simple-notifications' ); ?>">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <polyline points="20 6 9 17 4 12"/>
                                        </svg>
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="simple-notifications-page-item-delete" data-id="<?php echo esc_attr( $formatted['id'] ); ?>" title="<?php esc_attr_e( 'Delete', 'simple-notifications' ); ?>">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <line x1="18" y1="6" x2="6" y2="18"/>
                                        <line x1="6" y1="6" x2="18" y2="18"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ( count( $notifications ) === $per_page ) : ?>
                <div class="simple-notifications-page-pagination">
                    <button type="button" class="simple-notifications-btn" id="simple-notifications-load-more" data-page="<?php echo esc_attr( $page + 1 ); ?>">
                        <?php esc_html_e( 'Load more', 'simple-notifications' ); ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
