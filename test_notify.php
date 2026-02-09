<?php
require_once 'send_notification.php';

$deviceToken = 'cniMOln5SKySxOOPC3e0wL:APA91bEhygWrc_B0RCEAVTpAZt2EVxn3jVuILhRikGdIUBDFvh6HaXmrsN1AnfENhyhS32hzBeOSQmPzDe3n7_Ge0vASFPbaAG19bfKz0RcP3BIX-0BkH5Q'; // ← your token
$title = "Test from Server";
$body = "This is a manual test notification";

$push_data = [
    'assessment_id' => '999',
    'student_id' => '1',
    'type' => 'assessment_done'
];

$sent = sendFcmNotification($deviceToken, $title, $body, $push_data);

echo $sent ? "Notification sent successfully!" : "Failed to send.";
?>