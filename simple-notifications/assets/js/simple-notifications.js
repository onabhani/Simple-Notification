/**
 * Simple Notifications - Frontend JavaScript
 */
(function($) {
    'use strict';

    // Configuration
    var config = {
        ajaxUrl: simpleNotifications.ajaxUrl || '/wp-admin/admin-ajax.php',
        nonce: simpleNotifications.nonce || '',
        pollInterval: simpleNotifications.pollInterval || 30000,
        viewAllUrl: simpleNotifications.viewAllUrl || '/notifications/',
        i18n: simpleNotifications.i18n || {}
    };

    // State
    var state = {
        isOpen: false,
        isLoading: false,
        pollTimer: null,
        notifications: [],
        unreadCount: 0
    };

    // DOM Elements
    var $bell, $badge, $dropdown, $list, $markAllBtn;

    /**
     * Initialize the notification bell
     */
    function init() {
        $bell = $('#simple-notifications-bell');
        if (!$bell.length) {
            return;
        }

        $badge = $('#simple-notifications-badge');
        $dropdown = $('#simple-notifications-dropdown');
        $list = $('#simple-notifications-list');
        $markAllBtn = $('#simple-notifications-mark-all');

        bindEvents();
        startPolling();
    }

    /**
     * Bind event handlers
     */
    function bindEvents() {
        // Toggle dropdown
        $('#simple-notifications-bell-toggle').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleDropdown();
        });

        // Mark all as read
        $markAllBtn.on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            markAllAsRead();
        });

        // Close dropdown when clicking outside
        $(document).on('click', function(e) {
            if (state.isOpen && !$(e.target).closest('.simple-notifications-bell').length) {
                closeDropdown();
            }
        });

        // Handle notification click (mark as read)
        $list.on('click', '.simple-notifications-item', function() {
            var notificationId = $(this).data('id');
            if (notificationId) {
                markAsRead(notificationId);
            }
        });

        // Page-specific handlers
        initPageHandlers();
    }

    /**
     * Initialize handlers for the notifications page
     */
    function initPageHandlers() {
        var $page = $('#simple-notifications-page');
        if (!$page.length) {
            return;
        }

        // Mark all as read on page
        $('#simple-notifications-page-mark-all').on('click', function(e) {
            e.preventDefault();
            markAllAsRead(function() {
                $('.simple-notifications-page-item').removeClass('is-unread').addClass('is-read');
                $('.simple-notifications-page-item-mark-read').remove();
                $('.simple-notifications-page-unread').remove();
            });
        });

        // Clear all on page
        $('#simple-notifications-page-clear-all').on('click', function(e) {
            e.preventDefault();
            if (confirm('Are you sure you want to clear all notifications?')) {
                clearAll(function() {
                    $('#simple-notifications-page-list').html(
                        '<div class="simple-notifications-empty"><p>' +
                        config.i18n.noNotifications +
                        '</p></div>'
                    );
                    $('.simple-notifications-page-unread').remove();
                    $('.simple-notifications-page-pagination').remove();
                });
            }
        });

        // Mark single as read on page
        $page.on('click', '.simple-notifications-page-item-mark-read', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var notificationId = $btn.data('id');
            var $item = $btn.closest('.simple-notifications-page-item');

            markAsRead(notificationId, function() {
                $item.removeClass('is-unread').addClass('is-read');
                $btn.remove();
            });
        });

        // Delete single on page
        $page.on('click', '.simple-notifications-page-item-delete', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var notificationId = $btn.data('id');
            var $item = $btn.closest('.simple-notifications-page-item');

            deleteNotification(notificationId, function() {
                $item.slideUp(200, function() {
                    $(this).remove();
                    if (!$('.simple-notifications-page-item').length) {
                        $('#simple-notifications-page-list').html(
                            '<div class="simple-notifications-empty"><p>' +
                            config.i18n.noNotifications +
                            '</p></div>'
                        );
                    }
                });
            });
        });

        // Load more
        $('#simple-notifications-load-more').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var page = $btn.data('page');
            loadMoreNotifications(page, $btn);
        });

        // Click on notification link marks as read
        $page.on('click', 'a.simple-notifications-page-item-title', function() {
            var notificationId = $(this).data('notification-id');
            if (notificationId) {
                markAsRead(notificationId);
            }
        });
    }

    /**
     * Toggle dropdown visibility
     */
    function toggleDropdown() {
        if (state.isOpen) {
            closeDropdown();
        } else {
            openDropdown();
        }
    }

    /**
     * Open the dropdown
     */
    function openDropdown() {
        state.isOpen = true;
        $dropdown.addClass('is-open');
        loadNotifications();
    }

    /**
     * Close the dropdown
     */
    function closeDropdown() {
        state.isOpen = false;
        $dropdown.removeClass('is-open');
    }

    /**
     * Start polling for new notifications
     */
    function startPolling() {
        // Initial count fetch
        fetchUnreadCount();

        // Set up polling interval
        if (config.pollInterval > 0) {
            state.pollTimer = setInterval(function() {
                fetchUnreadCount();
            }, config.pollInterval);
        }
    }

    /**
     * Fetch unread count
     */
    function fetchUnreadCount() {
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'simple_notifications_count',
                nonce: config.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateBadge(response.unread_count);
                }
            }
        });
    }

    /**
     * Update the badge count
     */
    function updateBadge(count) {
        state.unreadCount = count;

        if (count > 0) {
            var displayCount = count > 99 ? '99+' : count;
            $badge.text(displayCount).addClass('has-unread');
        } else {
            $badge.text('').removeClass('has-unread');
        }
    }

    /**
     * Load notifications for dropdown
     */
    function loadNotifications() {
        if (state.isLoading) {
            return;
        }

        state.isLoading = true;
        $list.html('<div class="simple-notifications-loading"><span class="simple-notifications-spinner"></span></div>');

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'simple_notifications_get',
                nonce: config.nonce,
                limit: 10,
                unread_only: 'false'
            },
            success: function(response) {
                state.isLoading = false;

                if (response.success) {
                    state.notifications = response.notifications;
                    updateBadge(response.unread_count);
                    renderNotifications();
                } else {
                    $list.html('<div class="simple-notifications-empty">' + config.i18n.noNotifications + '</div>');
                }
            },
            error: function() {
                state.isLoading = false;
                $list.html('<div class="simple-notifications-empty">Error loading notifications</div>');
            }
        });
    }

    /**
     * Render notifications in dropdown
     */
    function renderNotifications() {
        if (!state.notifications.length) {
            $list.html('<div class="simple-notifications-empty">' + config.i18n.noNotifications + '</div>');
            return;
        }

        var html = '';
        $.each(state.notifications, function(i, notification) {
            var itemClass = 'simple-notifications-item';
            if (!notification.is_read) {
                itemClass += ' is-unread';
            }

            var url = notification.url || '#';

            html += '<a href="' + escapeHtml(url) + '" class="' + itemClass + '" data-id="' + notification.id + '">';
            html += '<span class="simple-notifications-item-source">' + escapeHtml(notification.source_label) + '</span>';
            html += '<span class="simple-notifications-item-title">' + escapeHtml(notification.title) + '</span>';
            html += '<span class="simple-notifications-item-time">' + escapeHtml(notification.time_ago) + '</span>';
            html += '</a>';
        });

        $list.html(html);
    }

    /**
     * Mark notification as read
     */
    function markAsRead(notificationId, callback) {
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'simple_notifications_mark_read',
                nonce: config.nonce,
                notification_id: notificationId
            },
            success: function(response) {
                if (response.success) {
                    updateBadge(response.unread_count);

                    // Update item in dropdown
                    $list.find('[data-id="' + notificationId + '"]').removeClass('is-unread');

                    if (typeof callback === 'function') {
                        callback();
                    }
                }
            }
        });
    }

    /**
     * Mark all as read
     */
    function markAllAsRead(callback) {
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'simple_notifications_mark_all_read',
                nonce: config.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateBadge(0);
                    $list.find('.simple-notifications-item').removeClass('is-unread');

                    if (typeof callback === 'function') {
                        callback();
                    }
                }
            }
        });
    }

    /**
     * Clear all notifications
     */
    function clearAll(callback) {
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'simple_notifications_clear_all',
                nonce: config.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateBadge(0);
                    state.notifications = [];
                    $list.html('<div class="simple-notifications-empty">' + config.i18n.noNotifications + '</div>');

                    if (typeof callback === 'function') {
                        callback();
                    }
                }
            }
        });
    }

    /**
     * Delete a single notification
     */
    function deleteNotification(notificationId, callback) {
        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'simple_notifications_delete',
                nonce: config.nonce,
                notification_id: notificationId
            },
            success: function(response) {
                if (response.success) {
                    updateBadge(response.unread_count);

                    if (typeof callback === 'function') {
                        callback();
                    }
                }
            }
        });
    }

    /**
     * Load more notifications on page
     */
    function loadMoreNotifications(page, $btn) {
        $btn.prop('disabled', true).text(config.i18n.loading);

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'simple_notifications_get',
                nonce: config.nonce,
                limit: 20,
                offset: (page - 1) * 20,
                unread_only: 'false'
            },
            success: function(response) {
                if (response.success && response.notifications.length) {
                    var html = '';
                    $.each(response.notifications, function(i, notification) {
                        html += renderPageItem(notification);
                    });

                    $('#simple-notifications-page-list').append(html);

                    if (response.has_more) {
                        $btn.prop('disabled', false).text('Load more').data('page', page + 1);
                    } else {
                        $btn.parent().remove();
                    }
                } else {
                    $btn.parent().remove();
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Load more');
            }
        });
    }

    /**
     * Render a page item
     */
    function renderPageItem(notification) {
        var itemClass = 'simple-notifications-page-item';
        itemClass += notification.is_read ? ' is-read' : ' is-unread';

        var html = '<div class="' + itemClass + '" data-id="' + notification.id + '">';
        html += '<div class="simple-notifications-page-item-content">';
        html += '<span class="simple-notifications-page-item-source">' + escapeHtml(notification.source_label) + '</span>';

        if (notification.url) {
            html += '<a href="' + escapeHtml(notification.url) + '" class="simple-notifications-page-item-title" data-notification-id="' + notification.id + '">';
            html += escapeHtml(notification.title);
            html += '</a>';
        } else {
            html += '<span class="simple-notifications-page-item-title">' + escapeHtml(notification.title) + '</span>';
        }

        html += '<span class="simple-notifications-page-item-time">' + escapeHtml(notification.time_ago) + '</span>';
        html += '</div>';
        html += '<div class="simple-notifications-page-item-actions">';

        if (!notification.is_read) {
            html += '<button type="button" class="simple-notifications-page-item-mark-read" data-id="' + notification.id + '" title="Mark as read">';
            html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>';
            html += '</button>';
        }

        html += '<button type="button" class="simple-notifications-page-item-delete" data-id="' + notification.id + '" title="Delete">';
        html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
        html += '</button>';
        html += '</div></div>';

        return html;
    }

    /**
     * Escape HTML entities
     */
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize on document ready
    $(document).ready(init);

})(jQuery);
