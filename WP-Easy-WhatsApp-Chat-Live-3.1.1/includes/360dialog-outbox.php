<?php
/**
 * WP Easy WhatsApp Chat, 360dialog production outbound sender.
 *
 * Messages are sent directly from WordPress to the 360dialog Messaging API.
 * No local tunnel or desktop process is involved.
 */

if (!defined('ABSPATH')) {
    exit;
}

function wewc_360_build_notification_payload($recipient, $conversation_id, $visitor_body) {
    $mode = function_exists('wewc_sanitize_360_notification_mode')
        ? wewc_sanitize_360_notification_mode(get_option('wewc_360_notification_mode', 'text'))
        : 'text';

    if ($mode === 'template') {
        $template_name = sanitize_key((string) get_option('wewc_360_template_name', ''));
        $language = sanitize_text_field((string) get_option('wewc_360_template_language', 'en_US'));
        $param_count = (int) get_option('wewc_360_template_params', 2);

        if ($template_name === '') {
            return new WP_Error(
                'wewc_360_template_missing',
                'Template mode is enabled, but no approved WhatsApp template name is configured.'
            );
        }

        if (!preg_match('/^[A-Za-z0-9_\-]{1,512}$/', $template_name)) {
            return new WP_Error(
                'wewc_360_template_invalid',
                'The configured WhatsApp template name is invalid.'
            );
        }

        if ($language === '') {
            return new WP_Error(
                'wewc_360_template_language_missing',
                'Template language is required.'
            );
        }

        $parameters = array();
        if ($param_count === 1) {
            $parameters[] = array(
                'type' => 'text',
                'text' => $visitor_body,
            );
        } else {
            $parameters[] = array(
                'type' => 'text',
                'text' => (string) $conversation_id,
            );
            $parameters[] = array(
                'type' => 'text',
                'text' => $visitor_body,
            );
        }

        return array(
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $recipient,
            'type'              => 'template',
            'template'          => array(
                'name'       => $template_name,
                'language'   => array(
                    'code' => $language,
                ),
                'components' => array(
                    array(
                        'type'       => 'body',
                        'parameters' => $parameters,
                    ),
                ),
            ),
        );
    }

    $notification =
        "New Website Chat\n" .
        'Conversation: #' . (int) $conversation_id . "\n\n" .
        "Visitor Message:\n" .
        $visitor_body . "\n\n" .
        "Reply directly to this message to respond in the website chat.";

    return array(
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $recipient,
        'type'              => 'text',
        'text'              => array(
            'body' => $notification,
        ),
    );
}

/**
 * Send one pending website-chat outbox item through 360dialog.
 *
 * The returned WhatsApp message ID is stored in the outbox. A native WhatsApp
 * Reply to that message contains context.id, allowing the inbound webhook to
 * route the response back to the correct website conversation.
 */
function wewc_360_send_outbox_item($queue_id) {
    global $wpdb;

    $queue_id = absint($queue_id);
    if ($queue_id === 0) {
        return new WP_Error('wewc_360_bad_queue_id', 'Invalid outbox queue ID.');
    }

    if (!function_exists('wewc_get_360_api_key') || !function_exists('wewc_360_request')) {
        return new WP_Error('wewc_360_runtime_missing', '360dialog production runtime is unavailable.');
    }

    $api_key = wewc_get_360_api_key();
    if ($api_key === '') {
        return new WP_Error('wewc_360_api_key_missing', '360dialog API key is not configured.');
    }

    $recipient = preg_replace('/\D+/', '', (string) get_option('wewc_phone', ''));
    if (!preg_match('/\A[1-9][0-9]{7,14}\z/D', $recipient)) {
        return new WP_Error('wewc_360_recipient_invalid', 'Configured administrator WhatsApp number is invalid.');
    }

    $outbox   = $wpdb->prefix . 'wewc_outbox';
    $messages = $wpdb->prefix . 'wewc_messages';

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT
                o.id AS queue_id,
                o.message_id,
                o.conversation_id,
                o.status,
                o.attempts,
                m.sender,
                m.body
             FROM $outbox o
             INNER JOIN $messages m ON m.id = o.message_id
             WHERE o.id = %d
             LIMIT 1",
            $queue_id
        )
    );

    if (!$row) {
        return new WP_Error('wewc_360_queue_missing', 'Outbox item was not found.');
    }

    if ($row->sender !== 'visitor') {
        return new WP_Error('wewc_360_not_visitor_message', 'Only visitor messages can be sent from the outbox.');
    }

    if ($row->status !== 'pending') {
        return new WP_Error(
            'wewc_360_queue_not_pending',
            'Outbox item is not pending. Current status: ' . sanitize_text_field((string) $row->status)
        );
    }

    $visitor_body = sanitize_textarea_field((string) $row->body);
    if ($visitor_body === '') {
        return new WP_Error('wewc_360_empty_message', 'Visitor message is empty.');
    }

    if (function_exists('mb_substr')) {
        $visitor_body = mb_substr($visitor_body, 0, 3000, 'UTF-8');
    } else {
        $visitor_body = substr($visitor_body, 0, 3000);
    }

    $payload = wewc_360_build_notification_payload(
        $recipient,
        (int) $row->conversation_id,
        $visitor_body
    );

    if (is_wp_error($payload)) {
        return $payload;
    }

    $now = current_time('mysql', true);

    // Atomic claim prevents two cron workers or two HTTP requests from sending
    // the same outbox row simultaneously.
    $claimed = $wpdb->query(
        $wpdb->prepare(
            "UPDATE $outbox
             SET status = 'processing',
                 provider = '360dialog',
                 attempts = attempts + 1,
                 last_error = NULL,
                 updated_at = %s
             WHERE id = %d
               AND status = 'pending'",
            $now,
            $queue_id
        )
    );

    if ($claimed !== 1) {
        return new WP_Error('wewc_360_claim_failed', 'Unable to claim the pending outbox item.');
    }

    $response = wewc_360_request_with_auto_endpoint(
        'POST',
        wewc_get_360_messages_url(),
        $payload
    );

    if (is_wp_error($response)) {
        $error = sanitize_text_field($response->get_error_message());
        $wpdb->update(
            $outbox,
            array(
                'status'     => 'failed',
                'last_error' => $error,
                'updated_at' => current_time('mysql', true),
            ),
            array('id' => $queue_id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        return new WP_Error('wewc_360_transport_failed', '360dialog request failed: ' . $error);
    }

    $http_code = (int) $response['code'];
    $data = is_array($response['data']) ? $response['data'] : array();

    $external_id = '';
    if (isset($data['messages'][0]['id']) && is_string($data['messages'][0]['id'])) {
        $external_id = sanitize_text_field($data['messages'][0]['id']);
    }

    if (!in_array($http_code, array(200, 201), true) || $external_id === '') {
        $provider_error = 'HTTP ' . $http_code;
        $message = $data['error']['message'] ?? $data['meta']['developer_message'] ?? null;
        if (is_string($message) && $message !== '') {
            $provider_error .= ': ' . sanitize_text_field($message);
        }

        $wpdb->update(
            $outbox,
            array(
                'status'     => 'failed',
                'last_error' => $provider_error,
                'updated_at' => current_time('mysql', true),
            ),
            array('id' => $queue_id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        return new WP_Error('wewc_360_send_failed', '360dialog rejected the message. ' . $provider_error);
    }

    $updated = $wpdb->update(
        $outbox,
        array(
            'status'              => 'sent',
            'provider'            => '360dialog',
            'external_message_id' => $external_id,
            'last_error'          => NULL,
            'updated_at'          => current_time('mysql', true),
        ),
        array('id' => $queue_id),
        array('%s', '%s', '%s', '%s', '%s'),
        array('%d')
    );

    if ($updated === false) {
        return new WP_Error(
            'wewc_360_sent_but_update_failed',
            'WhatsApp accepted the message, but the outbox status could not be updated.'
        );
    }

    return array(
        'success'             => true,
        'queue_id'            => $queue_id,
        'conversation_id'     => (int) $row->conversation_id,
        'status'              => 'sent',
        'http_code'           => $http_code,
        'external_message_id' => $external_id,
    );
}
