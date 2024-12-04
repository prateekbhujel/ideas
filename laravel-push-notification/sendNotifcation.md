# Laravel Push Notifications Helper

This file contains all the code and step-by-step explanations to implement a lightweight push notification system in Laravel. The system uses the Laravel Notification feature and integrates the browser Notification API without external dependencies.

---

## Project Description:
1. A Laravel backend that uses the `sendNotification` helper to manage notifications (database and push notifications).
2. A single frontend JavaScript file (`notifications.js`) to handle the display of browser notifications.
3. Notifications are fetched dynamically without requiring a page reload.

---

## File Structure:
1. **Laravel Backend Code**:
   - Helper function: `sendNotification` (in a helper file).
   - Notification class: `SendNotification.php`.
   - Routes: Add in `web.php`.
   - Migration for database notifications.

2. **Frontend Code**:
   - A single JavaScript file (`notifications.js`) for polling and browser notifications.

3. **Documentation**:
   - This text file serves as a detailed explanation.

---

### Backend Code:

#### 1. Helper Functions (`NotificationHelper.php`):
Create a helper file in `app/Helpers/NotificationHelper.php`.

```php
<?php

if (!function_exists('sendNotification')) {
    /**
     * Sends a notification to the user.
     * 
     * @param mixed $user The user object.
     * @param array $notification An associative array containing title, message, and link.
     * 
     * @return void
     */
    function sendNotification($user, $notification = [])
    {
        // 1. Send a Laravel Notification (stored in database)
        \Notification::send($user, new App\Notifications\SendNotification($notification));

        // 2. Broadcast the push notification
        $pushNotification = [
            'title' => $notification['title'] ?? 'New Notification',
            'msg' => $notification['msg'] ?? '',
            'link' => $notification['link'] ?? '',
        ];

        broadcastPushNotification($pushNotification);
    }
}

if (!function_exists('broadcastPushNotification')) {
    /**
     * Broadcasts a push notification.
     * 
     * @param array $data Push notification payload (title, message, link).
     * 
     * @return void
     */
    function broadcastPushNotification($data)
    {
        \Cache::put('push_notification', $data, now()->addMinutes(5));
    }
}


```js
// notifications.js

const fetchAndShowPushNotification = async () => {
    try {
        const response = await fetch('/push-notifications/fetch');
        const notification = await response.json();

        if (notification && notification.title) {
            showBrowserNotification(notification.title, notification.msg, notification.link);
        }
    } catch (error) {
        console.error("Error fetching push notification:", error);
    }
};

const showBrowserNotification = (title, body, link) => {
    if (Notification.permission === "granted") {
        const notification = new Notification(title, { body });

        // Handle notification click
        notification.onclick = () => {
            if (link) {
                window.open(link, '_blank');
            }
        };
    }
};

const requestNotificationPermission = () => {
    if (Notification.permission === "default") {
        Notification.requestPermission();
    }
};

const initPushNotifications = (pollInterval = 5000) => {
    requestNotificationPermission();
    setInterval(fetchAndShowPushNotification, pollInterval);
};

initPushNotifications();
