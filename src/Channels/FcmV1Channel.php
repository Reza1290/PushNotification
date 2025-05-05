<?php

namespace Edujugon\PushNotification\Channels;

use Edujugon\PushNotification\Messages\PushMessage;

class FcmV1Channel extends GcmChannel
{
    /**
     * {@inheritdoc}
     */
    protected function pushServiceName()
    {
        return 'fcmv1';
    }

    /**
     * {@inheritdoc}
     */

    protected function buildData(PushMessage $message)
{
    $data = [];

    // Set notification title and body
    if ($message->title !== null || $message->body !== null) {
        $data['notification'] = [
            'title' => $message->title,
            'body' => $message->body,
        ];
    }

    // Set Android-specific notification data
    if ($message->icon || $message->color || $message->sound || $message->click_action || $message->badge) {
        $data['android']['notification'] = [];

        if (!empty($message->icon)) {
            $data['android']['notification']['icon'] = $message->icon;
        }
        if (!empty($message->color)) {
            $data['android']['notification']['color'] = $message->color;
        }
        if (!empty($message->sound)) {
            $data['android']['notification']['sound'] = $message->sound;
        }
        if (!empty($message->click_action)) {
            $data['android']['notification']['click_action'] = $message->click_action;
            $data['android']['notification']['body'] = $message->body; // Add body to Android
        }
        if (!empty($message->badge)) {
            $data['android']['notification']['notification_count'] = $message->badge;
        }
    }

    // Set data payload
    if (!empty($message->data)) {
        $data['data'] = $message->data;
    }

    // Set APNS (Apple Push Notification Service) payload
    $data['apns']['payload']['aps']['category'] = 'NEW_MESSAGE_CATEGORY';

    // Set topic if available
    if (!empty($message->topic)) {
        $data['topic'] = $message->topic;
    }

    return $data;
}

}