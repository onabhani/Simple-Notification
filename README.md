# Simple Notifications

A lightweight WordPress notification system for Gravity Flow tasks, HR requests, and custom events.

## Features

- Database-backed notifications (cross-session persistence)
- Frontend notification bell with dropdown
- Full notifications page with shortcode
- AJAX-based polling for real-time updates
- Mark as read, click-through to source, bulk clear
- Gravity Flow integration
- Simple HR Suite integration
- Extensible API for custom notifications

## Installation

1. Upload the `simple-notifications` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. The notification bell will automatically appear in your primary navigation menu

## Usage

### Shortcodes

**Notification Bell:**
```
[simple_notifications_bell]
```

**Notifications Page:**
```
[simple_notifications_page per_page="20"]
```

### Creating Notifications

Use the helper function to create notifications from your code:

```php
simple_notifications_create(
    $user_id,      // User to notify
    'source_type', // e.g., 'gravityflow', 'hr_leave', 'custom'
    $source_id,    // Entry ID, request ID, etc.
    'Title',       // Notification message
    'https://...'  // Click-through URL (optional)
);
```

### Hooks & Filters

**Filter menu locations:**
```php
add_filter( 'simple_notifications_menu_locations', function( $locations ) {
    return array( 'primary', 'header-menu' );
});
```

**Filter poll interval (ms):**
```php
add_filter( 'simple_notifications_poll_interval', function( $interval ) {
    return 60000; // 60 seconds
});
```

**Add custom source labels:**
```php
add_filter( 'simple_notifications_source_labels', function( $labels ) {
    $labels['my_custom_type'] = 'My Custom Type';
    return $labels;
});
```

**Hook into notification creation:**
```php
add_action( 'simple_notifications_created', function( $notification_id, $data ) {
    // Do something when a notification is created
}, 10, 2 );
```

## Database Schema

```sql
wp_simple_notifications
├── id (bigint, PK, auto-increment)
├── user_id (bigint, indexed)
├── source_type (varchar) -- 'gravityflow', 'hr_leave', 'hr_loan', etc.
├── source_id (bigint) -- entry ID, request ID, etc.
├── title (varchar)
├── url (varchar) -- click-through destination
├── is_read (tinyint, default 0)
├── created_at (datetime)
```

## Integrations

### Gravity Flow

Automatically creates notifications when:
- A workflow step is assigned to a user
- (Optional) A step is completed

### Simple HR Suite

Automatically creates notifications when:
- Leave/loan/expense/overtime requests are created (to approvers)
- Request status changes (to requester)

To define custom approvers:
```php
add_filter( 'simple_notifications_hr_approvers', function( $approvers, $source_type, $request ) {
    // Return array of user IDs
    return array( 1, 2, 3 );
}, 10, 3 );
```

## License

GPLv2 or later
