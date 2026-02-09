<?php
// e_api/send_notification.php
// Isolated FCM sender – updated for kreait/firebase-php ^8.1 (v8 syntax)

require_once __DIR__ . '/vendor/autoload.php';

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

function sendFcmNotification(string $deviceToken, string $title, string $body, array $data = []): bool {
    $serviceAccountPath = __DIR__ . '/firebase-service-account.json';

    if (!file_exists($serviceAccountPath)) {
        error_log("[FCM] Service account JSON missing: " . $serviceAccountPath);
        return false;
    }

    try {
        $factory = (new Factory)->withServiceAccount($serviceAccountPath);
        $messaging = $factory->createMessaging();

        $notification = Notification::create($title, $body);

        // Correct v8 syntax: use CloudMessage::new() + toToken()
        $message = CloudMessage::new()
            ->toToken($deviceToken)
            ->withNotification($notification)
            ->withData($data);

        $messaging->send($message);

        error_log("[FCM] Sent successfully to token: " . substr($deviceToken, 0, 15) . "...");
        return true;
    } catch (Throwable $e) {
        error_log("[FCM] Send failed: " . $e->getMessage());
        return false;
    }
}