<?php
/**
 * Integrations for Simple Notifications
 *
 * Hooks into Gravity Flow and Simple HR Suite to create notifications
 * when tasks are assigned or requests are created.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Simple_Notifications_Integrations {

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
     * Initialize integration hooks
     */
    private function init_hooks() {
        // Gravity Flow integration
        add_action( 'gravityflow_post_process_workflow', array( $this, 'handle_gravityflow_workflow' ), 10, 4 );
        add_action( 'gravityflow_step_complete', array( $this, 'handle_gravityflow_step_complete' ), 10, 4 );
        add_action( 'gravityflow_assignee_status_update', array( $this, 'handle_gravityflow_assignee_update' ), 10, 4 );

        // Simple HR Suite integration - Leave requests
        add_action( 'simple_hr_leave_request_created', array( $this, 'handle_hr_leave_created' ), 10, 2 );
        add_action( 'simple_hr_leave_request_status_changed', array( $this, 'handle_hr_leave_status_changed' ), 10, 3 );

        // Simple HR Suite integration - Loan requests
        add_action( 'simple_hr_loan_request_created', array( $this, 'handle_hr_loan_created' ), 10, 2 );
        add_action( 'simple_hr_loan_request_status_changed', array( $this, 'handle_hr_loan_status_changed' ), 10, 3 );

        // Simple HR Suite integration - Expense requests
        add_action( 'simple_hr_expense_request_created', array( $this, 'handle_hr_expense_created' ), 10, 2 );
        add_action( 'simple_hr_expense_request_status_changed', array( $this, 'handle_hr_expense_status_changed' ), 10, 3 );

        // Simple HR Suite integration - Overtime requests
        add_action( 'simple_hr_overtime_request_created', array( $this, 'handle_hr_overtime_created' ), 10, 2 );
        add_action( 'simple_hr_overtime_request_status_changed', array( $this, 'handle_hr_overtime_status_changed' ), 10, 3 );

        // Generic HR action hooks (fallback pattern)
        add_action( 'simple_hr_request_assigned', array( $this, 'handle_hr_request_assigned' ), 10, 4 );
        add_action( 'simple_hr_request_approved', array( $this, 'handle_hr_request_approved' ), 10, 3 );
        add_action( 'simple_hr_request_rejected', array( $this, 'handle_hr_request_rejected' ), 10, 3 );

        // Allow custom integrations
        do_action( 'simple_notifications_register_integrations', $this );
    }

    /* =========================================
       GRAVITY FLOW INTEGRATION
       ========================================= */

    /**
     * Handle Gravity Flow workflow processing
     *
     * @param array  $form    Form object
     * @param int    $entry_id Entry ID
     * @param int    $step_id  Step ID
     * @param object $step     Step object
     */
    public function handle_gravityflow_workflow( $form, $entry_id, $step_id, $step ) {
        if ( ! class_exists( 'Gravity_Flow' ) ) {
            return;
        }

        // Get assignees for the current step
        $assignees = $this->get_gravityflow_assignees( $step, $entry_id );

        if ( empty( $assignees ) ) {
            return;
        }

        $entry = GFAPI::get_entry( $entry_id );
        if ( is_wp_error( $entry ) ) {
            return;
        }

        // Generate notification details
        $step_name = $step->get_name();
        $form_title = rgar( $form, 'title', __( 'Form', 'simple-notifications' ) );

        /* translators: 1: Step name, 2: Form title */
        $title = sprintf(
            __( 'New task: %1$s (%2$s)', 'simple-notifications' ),
            $step_name,
            $form_title
        );

        $url = $this->get_gravityflow_entry_url( $entry_id, $form );

        // Create notification for each assignee
        foreach ( $assignees as $user_id ) {
            $this->api->create_notification(
                $user_id,
                'gravityflow',
                $entry_id,
                $title,
                $url
            );
        }
    }

    /**
     * Handle Gravity Flow step completion
     *
     * @param int    $step_id  Step ID
     * @param int    $entry_id Entry ID
     * @param object $form     Form object
     * @param object $step     Step object
     */
    public function handle_gravityflow_step_complete( $step_id, $entry_id, $form, $step ) {
        // Optionally notify the entry creator when a step is complete
        $entry = GFAPI::get_entry( $entry_id );
        if ( is_wp_error( $entry ) || empty( $entry['created_by'] ) ) {
            return;
        }

        $notify_on_complete = apply_filters( 'simple_notifications_gravityflow_notify_on_complete', false, $step, $entry );
        if ( ! $notify_on_complete ) {
            return;
        }

        $user_id = (int) $entry['created_by'];
        $step_name = $step->get_name();

        /* translators: %s: Step name */
        $title = sprintf(
            __( 'Step completed: %s', 'simple-notifications' ),
            $step_name
        );

        $url = $this->get_gravityflow_entry_url( $entry_id, $form );

        $this->api->create_notification(
            $user_id,
            'gravityflow',
            $entry_id,
            $title,
            $url
        );
    }

    /**
     * Handle Gravity Flow assignee status update
     *
     * @param object $assignee Assignee object
     * @param object $entry    Entry object
     * @param object $form     Form object
     * @param object $step     Step object
     */
    public function handle_gravityflow_assignee_update( $assignee, $entry, $form, $step ) {
        // This can be used to notify when someone else processes an entry
        // that affects another assignee
    }

    /**
     * Get assignees for a Gravity Flow step
     *
     * @param object $step     Step object
     * @param int    $entry_id Entry ID
     * @return array User IDs
     */
    private function get_gravityflow_assignees( $step, $entry_id ) {
        $user_ids = array();

        if ( ! method_exists( $step, 'get_assignees' ) ) {
            return $user_ids;
        }

        $assignees = $step->get_assignees();

        foreach ( $assignees as $assignee ) {
            if ( $assignee->get_type() === 'user_id' ) {
                $user_ids[] = (int) $assignee->get_id();
            } elseif ( $assignee->get_type() === 'role' ) {
                $role_users = get_users( array( 'role' => $assignee->get_id() ) );
                foreach ( $role_users as $user ) {
                    $user_ids[] = $user->ID;
                }
            } elseif ( $assignee->get_type() === 'email' ) {
                $user = get_user_by( 'email', $assignee->get_id() );
                if ( $user ) {
                    $user_ids[] = $user->ID;
                }
            }
        }

        return array_unique( $user_ids );
    }

    /**
     * Get Gravity Flow entry URL
     *
     * @param int   $entry_id Entry ID
     * @param array $form     Form object
     * @return string URL
     */
    private function get_gravityflow_entry_url( $entry_id, $form ) {
        if ( class_exists( 'Gravity_Flow' ) ) {
            $workflow_url = gravity_flow()->get_workflow_url( array(
                'id' => $entry_id,
            ) );
            if ( $workflow_url ) {
                return $workflow_url;
            }
        }

        // Fallback to admin entry URL
        return admin_url( 'admin.php?page=gravityflow-inbox&id=' . $entry_id );
    }

    /* =========================================
       SIMPLE HR SUITE INTEGRATION
       ========================================= */

    /**
     * Handle HR leave request created
     *
     * @param int   $request_id Request ID
     * @param array $request    Request data
     */
    public function handle_hr_leave_created( $request_id, $request ) {
        $this->notify_hr_approvers( 'hr_leave', $request_id, $request, __( 'New leave request', 'simple-notifications' ) );
    }

    /**
     * Handle HR leave request status changed
     *
     * @param int    $request_id Request ID
     * @param string $new_status New status
     * @param array  $request    Request data
     */
    public function handle_hr_leave_status_changed( $request_id, $new_status, $request ) {
        $this->notify_hr_requester( 'hr_leave', $request_id, $request, $new_status, __( 'Leave request', 'simple-notifications' ) );
    }

    /**
     * Handle HR loan request created
     *
     * @param int   $request_id Request ID
     * @param array $request    Request data
     */
    public function handle_hr_loan_created( $request_id, $request ) {
        $this->notify_hr_approvers( 'hr_loan', $request_id, $request, __( 'New loan request', 'simple-notifications' ) );
    }

    /**
     * Handle HR loan request status changed
     *
     * @param int    $request_id Request ID
     * @param string $new_status New status
     * @param array  $request    Request data
     */
    public function handle_hr_loan_status_changed( $request_id, $new_status, $request ) {
        $this->notify_hr_requester( 'hr_loan', $request_id, $request, $new_status, __( 'Loan request', 'simple-notifications' ) );
    }

    /**
     * Handle HR expense request created
     *
     * @param int   $request_id Request ID
     * @param array $request    Request data
     */
    public function handle_hr_expense_created( $request_id, $request ) {
        $this->notify_hr_approvers( 'hr_expense', $request_id, $request, __( 'New expense request', 'simple-notifications' ) );
    }

    /**
     * Handle HR expense request status changed
     *
     * @param int    $request_id Request ID
     * @param string $new_status New status
     * @param array  $request    Request data
     */
    public function handle_hr_expense_status_changed( $request_id, $new_status, $request ) {
        $this->notify_hr_requester( 'hr_expense', $request_id, $request, $new_status, __( 'Expense request', 'simple-notifications' ) );
    }

    /**
     * Handle HR overtime request created
     *
     * @param int   $request_id Request ID
     * @param array $request    Request data
     */
    public function handle_hr_overtime_created( $request_id, $request ) {
        $this->notify_hr_approvers( 'hr_overtime', $request_id, $request, __( 'New overtime request', 'simple-notifications' ) );
    }

    /**
     * Handle HR overtime request status changed
     *
     * @param int    $request_id Request ID
     * @param string $new_status New status
     * @param array  $request    Request data
     */
    public function handle_hr_overtime_status_changed( $request_id, $new_status, $request ) {
        $this->notify_hr_requester( 'hr_overtime', $request_id, $request, $new_status, __( 'Overtime request', 'simple-notifications' ) );
    }

    /**
     * Handle generic HR request assigned
     *
     * @param string $request_type Type of request (leave, loan, etc.)
     * @param int    $request_id   Request ID
     * @param int    $user_id      Assigned user ID
     * @param array  $request      Request data
     */
    public function handle_hr_request_assigned( $request_type, $request_id, $user_id, $request ) {
        $source_type = 'hr_' . sanitize_key( $request_type );

        /* translators: %s: Request type */
        $title = sprintf(
            __( 'New %s request requires your approval', 'simple-notifications' ),
            $request_type
        );

        $url = $this->get_hr_request_url( $request_type, $request_id );

        $this->api->create_notification( $user_id, $source_type, $request_id, $title, $url );
    }

    /**
     * Handle generic HR request approved
     *
     * @param string $request_type Type of request
     * @param int    $request_id   Request ID
     * @param array  $request      Request data
     */
    public function handle_hr_request_approved( $request_type, $request_id, $request ) {
        $user_id = isset( $request['user_id'] ) ? (int) $request['user_id'] : 0;
        if ( ! $user_id ) {
            return;
        }

        $source_type = 'hr_' . sanitize_key( $request_type );

        /* translators: %s: Request type */
        $title = sprintf(
            __( 'Your %s request has been approved', 'simple-notifications' ),
            $request_type
        );

        $url = $this->get_hr_request_url( $request_type, $request_id );

        $this->api->create_notification( $user_id, $source_type, $request_id, $title, $url );
    }

    /**
     * Handle generic HR request rejected
     *
     * @param string $request_type Type of request
     * @param int    $request_id   Request ID
     * @param array  $request      Request data
     */
    public function handle_hr_request_rejected( $request_type, $request_id, $request ) {
        $user_id = isset( $request['user_id'] ) ? (int) $request['user_id'] : 0;
        if ( ! $user_id ) {
            return;
        }

        $source_type = 'hr_' . sanitize_key( $request_type );

        /* translators: %s: Request type */
        $title = sprintf(
            __( 'Your %s request has been rejected', 'simple-notifications' ),
            $request_type
        );

        $url = $this->get_hr_request_url( $request_type, $request_id );

        $this->api->create_notification( $user_id, $source_type, $request_id, $title, $url );
    }

    /**
     * Notify HR approvers about a new request
     *
     * @param string $source_type Source type
     * @param int    $request_id  Request ID
     * @param array  $request     Request data
     * @param string $title_prefix Title prefix
     */
    private function notify_hr_approvers( $source_type, $request_id, $request, $title_prefix ) {
        // Get approvers - this depends on how Simple HR Suite structures this
        $approvers = $this->get_hr_approvers( $source_type, $request );

        if ( empty( $approvers ) ) {
            return;
        }

        $requester_name = '';
        if ( ! empty( $request['user_id'] ) ) {
            $user = get_user_by( 'id', $request['user_id'] );
            if ( $user ) {
                $requester_name = $user->display_name;
            }
        }

        /* translators: 1: Title prefix, 2: Requester name */
        $title = $requester_name
            ? sprintf( __( '%1$s from %2$s', 'simple-notifications' ), $title_prefix, $requester_name )
            : $title_prefix;

        $url = $this->get_hr_request_url( $source_type, $request_id );

        foreach ( $approvers as $user_id ) {
            $this->api->create_notification( $user_id, $source_type, $request_id, $title, $url );
        }
    }

    /**
     * Notify HR requester about status change
     *
     * @param string $source_type  Source type
     * @param int    $request_id   Request ID
     * @param array  $request      Request data
     * @param string $new_status   New status
     * @param string $title_prefix Title prefix
     */
    private function notify_hr_requester( $source_type, $request_id, $request, $new_status, $title_prefix ) {
        $user_id = isset( $request['user_id'] ) ? (int) $request['user_id'] : 0;
        if ( ! $user_id ) {
            return;
        }

        $status_label = $this->get_status_label( $new_status );

        /* translators: 1: Title prefix, 2: Status */
        $title = sprintf(
            __( '%1$s %2$s', 'simple-notifications' ),
            $title_prefix,
            $status_label
        );

        $url = $this->get_hr_request_url( $source_type, $request_id );

        $this->api->create_notification( $user_id, $source_type, $request_id, $title, $url );
    }

    /**
     * Get HR approvers based on request type
     *
     * @param string $source_type Source type
     * @param array  $request     Request data
     * @return array User IDs
     */
    private function get_hr_approvers( $source_type, $request ) {
        // Allow filtering to define approvers
        $approvers = apply_filters( 'simple_notifications_hr_approvers', array(), $source_type, $request );

        if ( ! empty( $approvers ) ) {
            return $approvers;
        }

        // Fallback: Get users with HR manager role
        $hr_roles = apply_filters( 'simple_notifications_hr_approver_roles', array( 'hr_manager', 'administrator' ) );

        $users = get_users( array(
            'role__in' => $hr_roles,
        ) );

        return wp_list_pluck( $users, 'ID' );
    }

    /**
     * Get HR request URL
     *
     * @param string $source_type Source type
     * @param int    $request_id  Request ID
     * @return string URL
     */
    private function get_hr_request_url( $source_type, $request_id ) {
        // Allow custom URL via filter
        $url = apply_filters( 'simple_notifications_hr_request_url', '', $source_type, $request_id );

        if ( $url ) {
            return $url;
        }

        // Default fallback - adjust based on your Simple HR Suite structure
        $type = str_replace( 'hr_', '', $source_type );
        return admin_url( 'admin.php?page=simple-hr-' . $type . '&id=' . $request_id );
    }

    /**
     * Get human-readable status label
     *
     * @param string $status Status key
     * @return string Label
     */
    private function get_status_label( $status ) {
        $labels = apply_filters( 'simple_notifications_status_labels', array(
            'pending'   => __( 'is pending', 'simple-notifications' ),
            'approved'  => __( 'has been approved', 'simple-notifications' ),
            'rejected'  => __( 'has been rejected', 'simple-notifications' ),
            'cancelled' => __( 'has been cancelled', 'simple-notifications' ),
            'completed' => __( 'has been completed', 'simple-notifications' ),
        ) );

        return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
    }

    /* =========================================
       PUBLIC API FOR CUSTOM INTEGRATIONS
       ========================================= */

    /**
     * Create a notification (public method for custom integrations)
     *
     * @param int    $user_id     User ID
     * @param string $source_type Source type
     * @param int    $source_id   Source ID
     * @param string $title       Title
     * @param string $url         URL
     * @return int|false Notification ID or false
     */
    public function create_notification( $user_id, $source_type, $source_id, $title, $url = '' ) {
        return $this->api->create_notification( $user_id, $source_type, $source_id, $title, $url );
    }
}
