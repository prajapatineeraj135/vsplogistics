/**
 * Professional Notification System
 * 
 * Provides toast-style notifications that auto-dismiss after 8 seconds
 * Supports multiple notification types: success, error, warning, info
 * 
 * @author: Development Team
 * @version: 1.0
 */

// Notification container initialization
(function() {
    // Create notification container on page load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', createNotificationContainer);
    } else {
        createNotificationContainer();
    }
})();

/**
 * Create the notification container element
 */
function createNotificationContainer() {
    if (document.getElementById('notification-container')) return;
    
    const container = document.createElement('div');
    container.id = 'notification-container';
    container.className = 'notification-container';
    document.body.appendChild(container);
}

/**
 * Show success notification (green)
 * @param {string} message - The success message to display
 * @param {number} duration - Duration in milliseconds (default: 5000)
 */
function showSuccess(message, duration = 5000) {
    showNotification(message, 'success', duration);
}

/**
 * Show error notification (red)
 * @param {string} message - The error message to display
 * @param {number} duration - Duration in milliseconds (default: 5000)
 */
function showError(message, duration = 5000) {
    showNotification(message, 'error', duration);
}

/**
 * Show warning notification (orange)
 * @param {string} message - The warning message to display
 * @param {number} duration - Duration in milliseconds (default: 5000)
 */
function showWarning(message, duration = 5000) {
    showNotification(message, 'warning', duration);
}

/**
 * Show info notification (blue)
 * @param {string} message - The info message to display
 * @param {number} duration - Duration in milliseconds (default: 5000)
 */
function showInfo(message, duration = 5000) {
    showNotification(message, 'info', duration);
}

/**
 * Core notification function
 * Creates and displays a notification toast
 * 
 * @param {string} message - The notification message
 * @param {string} type - Notification type (success, error, warning, info)
 * @param {number} duration - Duration in milliseconds before auto-dismiss
 */
function showNotification(message, type = 'info', duration = 5000) {
    // Ensure container exists
    createNotificationContainer();
    
    const container = document.getElementById('notification-container');
    if (!container) return;
    
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    
    // Add icon based on type
    const icon = getNotificationIcon(type);
    
    // Build notification HTML
    notification.innerHTML = `
        <div class="notification-icon">${icon}</div>
        <div class="notification-content">
            <div class="notification-message">${escapeHtml(message)}</div>
        </div>
        <button class="notification-close" onclick="closeNotification(this.parentElement)">&times;</button>
    `;
    
    // Add to container
    container.appendChild(notification);
    
    // Trigger animation
    setTimeout(() => {
        notification.classList.add('notification-show');
    }, 3);
    
    // Auto-remove after duration
    setTimeout(() => {
        closeNotification(notification);
    }, duration);
}

/**
 * Get icon for notification type
 * @param {string} type - Notification type
 * @returns {string} - HTML icon
 */
function getNotificationIcon(type) {
    const icons = {
        'success': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>',
        'error': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>',
        'warning': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
        'info': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
    };
    return icons[type] || icons.info;
}

/**
 * Close/dismiss a notification
 * @param {HTMLElement} notification - The notification element to close
 */
function closeNotification(notification) {
    if (!notification) return;
    
    notification.classList.remove('notification-show');
    notification.classList.add('notification-hide');
    
    // Remove from DOM after animation
    setTimeout(() => {
        if (notification.parentElement) {
            notification.parentElement.removeChild(notification);
        }
    }, 3000);
}

/**
 * Escape HTML to prevent XSS
 * @param {string} text - Text to escape
 * @returns {string} - Escaped HTML
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Clear all notifications
 */
function clearAllNotifications() {
    const container = document.getElementById('notification-container');
    if (container) {
        container.innerHTML = '';
    }
}
