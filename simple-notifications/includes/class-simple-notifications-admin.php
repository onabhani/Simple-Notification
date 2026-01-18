<?php
/**
 * Admin settings for Simple Notifications
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Simple_Notifications_Admin {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Option name
     */
    const OPTION_NAME = 'simple_notifications_settings';

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
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // Apply saved settings to filters
        add_action( 'init', array( $this, 'apply_settings' ), 5 );
    }

    /**
     * Get settings with defaults
     */
    public static function get_settings() {
        $defaults = array(
            'display_mode'       => 'header', // 'header', 'floating', 'both', 'none'
            'floating_position'  => 'bottom-right',
            'add_to_nav_menu'    => 'no',
            'poll_interval'      => 30,
            'notifications_page' => 0,
        );

        $settings = get_option( self::OPTION_NAME, array() );
        return wp_parse_args( $settings, $defaults );
    }

    /**
     * Apply saved settings to filters
     */
    public function apply_settings() {
        $settings = self::get_settings();

        // Display mode
        add_filter( 'simple_notifications_show_floating_bell', function() use ( $settings ) {
            return in_array( $settings['display_mode'], array( 'floating', 'both' ), true );
        });

        // Nav menu
        add_filter( 'simple_notifications_add_to_nav_menu', function() use ( $settings ) {
            return $settings['add_to_nav_menu'] === 'yes';
        });

        // Floating position
        add_filter( 'simple_notifications_floating_position', function() use ( $settings ) {
            return $settings['floating_position'];
        });

        // Poll interval
        add_filter( 'simple_notifications_poll_interval', function() use ( $settings ) {
            return absint( $settings['poll_interval'] ) * 1000; // Convert to milliseconds
        });

        // Notifications page
        add_filter( 'simple_notifications_page_id', function() use ( $settings ) {
            return absint( $settings['notifications_page'] );
        });
    }

    /**
     * Add settings page to admin menu
     */
    public function add_settings_page() {
        add_options_page(
            __( 'Simple Notifications', 'simple-notifications' ),
            __( 'Simple Notifications', 'simple-notifications' ),
            'manage_options',
            'simple-notifications',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'simple_notifications_settings_group',
            self::OPTION_NAME,
            array( $this, 'sanitize_settings' )
        );

        // Display Section
        add_settings_section(
            'simple_notifications_display',
            __( 'Display Settings', 'simple-notifications' ),
            array( $this, 'render_display_section' ),
            'simple-notifications'
        );

        add_settings_field(
            'display_mode',
            __( 'Bell Display Mode', 'simple-notifications' ),
            array( $this, 'render_display_mode_field' ),
            'simple-notifications',
            'simple_notifications_display'
        );

        add_settings_field(
            'floating_position',
            __( 'Floating Position', 'simple-notifications' ),
            array( $this, 'render_floating_position_field' ),
            'simple-notifications',
            'simple_notifications_display'
        );

        add_settings_field(
            'add_to_nav_menu',
            __( 'Add to Navigation Menu', 'simple-notifications' ),
            array( $this, 'render_nav_menu_field' ),
            'simple-notifications',
            'simple_notifications_display'
        );

        // General Section
        add_settings_section(
            'simple_notifications_general',
            __( 'General Settings', 'simple-notifications' ),
            array( $this, 'render_general_section' ),
            'simple-notifications'
        );

        add_settings_field(
            'poll_interval',
            __( 'Poll Interval (seconds)', 'simple-notifications' ),
            array( $this, 'render_poll_interval_field' ),
            'simple-notifications',
            'simple_notifications_general'
        );

        add_settings_field(
            'notifications_page',
            __( 'Notifications Page', 'simple-notifications' ),
            array( $this, 'render_notifications_page_field' ),
            'simple-notifications',
            'simple_notifications_general'
        );
    }

    /**
     * Sanitize settings
     */
    public function sanitize_settings( $input ) {
        $sanitized = array();

        $sanitized['display_mode'] = in_array( $input['display_mode'], array( 'header', 'floating', 'both', 'none' ), true )
            ? $input['display_mode']
            : 'header';

        $sanitized['floating_position'] = in_array( $input['floating_position'], array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' ), true )
            ? $input['floating_position']
            : 'bottom-right';

        $sanitized['add_to_nav_menu'] = isset( $input['add_to_nav_menu'] ) && $input['add_to_nav_menu'] === 'yes' ? 'yes' : 'no';

        $sanitized['poll_interval'] = absint( $input['poll_interval'] );
        if ( $sanitized['poll_interval'] < 10 ) {
            $sanitized['poll_interval'] = 10;
        }
        if ( $sanitized['poll_interval'] > 300 ) {
            $sanitized['poll_interval'] = 300;
        }

        $sanitized['notifications_page'] = absint( $input['notifications_page'] );

        return $sanitized;
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <form action="options.php" method="post">
                <?php
                settings_fields( 'simple_notifications_settings_group' );
                do_settings_sections( 'simple-notifications' );
                submit_button();
                ?>
            </form>

            <hr>

            <h2><?php esc_html_e( 'Shortcodes', 'simple-notifications' ); ?></h2>
            <table class="widefat" style="max-width: 600px;">
                <tbody>
                    <tr>
                        <td><code>[simple_notifications_bell]</code></td>
                        <td><?php esc_html_e( 'Display the notification bell anywhere', 'simple-notifications' ); ?></td>
                    </tr>
                    <tr>
                        <td><code>[simple_notifications_page]</code></td>
                        <td><?php esc_html_e( 'Display the full notifications page', 'simple-notifications' ); ?></td>
                    </tr>
                </tbody>
            </table>

            <h2><?php esc_html_e( 'Theme Integration', 'simple-notifications' ); ?></h2>
            <p><?php esc_html_e( 'For DOFS Dashboard integration, use these hooks in your theme:', 'simple-notifications' ); ?></p>
            <table class="widefat" style="max-width: 600px;">
                <tbody>
                    <tr>
                        <td><code>do_action('dofs_notification_count');</code></td>
                        <td><?php esc_html_e( 'Output badge count', 'simple-notifications' ); ?></td>
                    </tr>
                    <tr>
                        <td><code>do_action('dofs_topbar_notifications');</code></td>
                        <td><?php esc_html_e( 'Output notification dropdown', 'simple-notifications' ); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render display section description
     */
    public function render_display_section() {
        echo '<p>' . esc_html__( 'Configure how the notification bell is displayed on your site.', 'simple-notifications' ) . '</p>';
    }

    /**
     * Render general section description
     */
    public function render_general_section() {
        echo '<p>' . esc_html__( 'Configure general notification settings.', 'simple-notifications' ) . '</p>';
    }

    /**
     * Render display mode field
     */
    public function render_display_mode_field() {
        $settings = self::get_settings();
        $options = array(
            'header'   => __( 'Header only (DOFS Dashboard)', 'simple-notifications' ),
            'floating' => __( 'Floating bell only', 'simple-notifications' ),
            'both'     => __( 'Both header and floating', 'simple-notifications' ),
            'none'     => __( 'None (use shortcode only)', 'simple-notifications' ),
        );
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[display_mode]" id="display_mode">
            <?php foreach ( $options as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['display_mode'], $value ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e( 'Choose how to display the notification bell. "Header" uses DOFS Dashboard hooks.', 'simple-notifications' ); ?>
        </p>
        <?php
    }

    /**
     * Render floating position field
     */
    public function render_floating_position_field() {
        $settings = self::get_settings();
        $positions = array(
            'bottom-right' => __( 'Bottom Right', 'simple-notifications' ),
            'bottom-left'  => __( 'Bottom Left', 'simple-notifications' ),
            'top-right'    => __( 'Top Right', 'simple-notifications' ),
            'top-left'     => __( 'Top Left', 'simple-notifications' ),
        );
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[floating_position]" id="floating_position">
            <?php foreach ( $positions as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['floating_position'], $value ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e( 'Position of the floating bell (when enabled).', 'simple-notifications' ); ?>
        </p>
        <?php
    }

    /**
     * Render nav menu field
     */
    public function render_nav_menu_field() {
        $settings = self::get_settings();
        ?>
        <label>
            <input type="checkbox"
                   name="<?php echo esc_attr( self::OPTION_NAME ); ?>[add_to_nav_menu]"
                   value="yes"
                   <?php checked( $settings['add_to_nav_menu'], 'yes' ); ?>>
            <?php esc_html_e( 'Automatically add bell to navigation menus', 'simple-notifications' ); ?>
        </label>
        <p class="description">
            <?php esc_html_e( 'If enabled, adds the bell to primary navigation menus.', 'simple-notifications' ); ?>
        </p>
        <?php
    }

    /**
     * Render poll interval field
     */
    public function render_poll_interval_field() {
        $settings = self::get_settings();
        ?>
        <input type="number"
               name="<?php echo esc_attr( self::OPTION_NAME ); ?>[poll_interval]"
               value="<?php echo esc_attr( $settings['poll_interval'] ); ?>"
               min="10"
               max="300"
               step="5"
               style="width: 80px;">
        <p class="description">
            <?php esc_html_e( 'How often to check for new notifications (10-300 seconds).', 'simple-notifications' ); ?>
        </p>
        <?php
    }

    /**
     * Render notifications page field
     */
    public function render_notifications_page_field() {
        $settings = self::get_settings();
        wp_dropdown_pages( array(
            'name'              => self::OPTION_NAME . '[notifications_page]',
            'selected'          => $settings['notifications_page'],
            'show_option_none'  => __( '— Select a page —', 'simple-notifications' ),
            'option_none_value' => 0,
        ) );
        ?>
        <p class="description">
            <?php esc_html_e( 'Page containing [simple_notifications_page] shortcode for "View all" link.', 'simple-notifications' ); ?>
        </p>
        <?php
    }
}
