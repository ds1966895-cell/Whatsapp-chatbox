
<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Save an incoming agent reply to a visitor conversation.
 */
function wewc_bridge_save_agent_reply($conversation_id, $message) {

    global $wpdb;

    $conversation_id = absint($conversation_id);

    // Validate message
    if (!is_string($message)) {

        return new WP_Error(
            'wewc_invalid_reply',
            'Invalid reply format.'
        );

    }

    $message = sanitize_textarea_field(
        trim($message)
    );

    if (
        $conversation_id === 0 ||
        $message === '' ||
        strlen($message) > 4000
    ) {

        return new WP_Error(
            'wewc_invalid_reply',
            'Invalid conversation or message.'
        );

    }

    // Database tables
    $conversations_table =
        $wpdb->prefix . 'wewc_conversations';

    $messages_table =
        $wpdb->prefix . 'wewc_messages';

    // Verify conversation exists
    $conversation = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM $conversations_table
             WHERE id = %d
             AND status = 'open'",
            $conversation_id
        )
    );

    if (!$conversation) {

        return new WP_Error(
            'wewc_conversation_not_found',
            'Conversation does not exist.'
        );

    }

    $now = current_time('mysql', true);

    // Save reply
    $inserted = $wpdb->insert(
        $messages_table,
        array(
            'conversation_id' => $conversation_id,
            'sender'          => 'agent',
            'body'            => $message,
            'created_at'      => $now
        ),
        array(
            '%d',
            '%s',
            '%s',
            '%s'
        )
    );

    if ($inserted === false) {

        return new WP_Error(
            'wewc_reply_save_failed',
            'Unable to save agent reply.'
        );

    }

    $message_id = (int) $wpdb->insert_id;

    // Update conversation timestamp
    $wpdb->update(
        $conversations_table,
        array('updated_at' => $now),
        array('id' => $conversation_id),
        array('%s'),
        array('%d')
    );

    return $message_id;

}


/**
 * Prepare queued message for WhatsApp delivery.
 */
function wewc_bridge_prepare_notification($queue_id) {

    global $wpdb;

    $queue_id = absint($queue_id);

    $outbox = $wpdb->prefix . 'wewc_outbox';
    $messages = $wpdb->prefix . 'wewc_messages';

    // Retrieve pending message.
    $record = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT
                q.id AS queue_id,
                q.message_id,
                q.conversation_id,
                m.body
             FROM $outbox q
             INNER JOIN $messages m
                ON m.id = q.message_id
             WHERE q.id = %d
               AND q.status = 'pending'
               AND m.sender = 'visitor'
             LIMIT 1",
            $queue_id
        ),
        ARRAY_A
    );

    if (!$record) {
        return new WP_Error(
            'wewc_queue_not_found',
            'Pending message not found.'
        );
    }

    // Get receiving WhatsApp number.
    $recipient = wewc_sanitize_phone(
        get_option('wewc_phone', '')
    );

    // Validate international number.
    if (!preg_match('/^[1-9][0-9]{7,14}$/', $recipient)) {
        return new WP_Error(
            'wewc_invalid_recipient',
            'Configure a valid WhatsApp number.'
        );
    }

    $conversation_id = (int) $record['conversation_id'];

    // Keep notification within a reasonable length.
    $body = wp_html_excerpt(
        $record['body'],
        3000,
        '...'
    );

    // Format notification.
    $text = "New Website Chat\n";
    $text .= "Conversation: #{$conversation_id}\n\n";
    $text .= "Visitor Message:\n{$body}\n\n";
    $text .= "Reply format:\n";
    $text .= "#{$conversation_id} Your reply here";

    return array(
        'queue_id' => (int) $record['queue_id'],
        'message_id' => (int) $record['message_id'],
        'conversation_id' => $conversation_id,
        'recipient' => $recipient,
        'text' => $text
    );
}


/**
 * Process an incoming WhatsApp reply.
 *
 * Expected format:
 * #4 Your reply here
 */
function wewc_bridge_process_incoming_reply($text) {

    // Validate incoming message.
    if (!is_string($text)) {

        return new WP_Error(
            'wewc_invalid_reply',
            'Invalid incoming message.'
        );

    }

    $text = trim($text);

    // Extract conversation ID and reply.
    if (!preg_match(
        '/\A#([1-9][0-9]{0,18})[ \t]+([\s\S]+)\z/u',
        $text,
        $matches
    )) {

        return new WP_Error(
            'wewc_invalid_format',
            'Use format: #4 Your reply here'
        );

    }

    // Extract conversation ID.
    $conversation_id = absint($matches[1]);

    // Extract actual reply.
    $reply = trim($matches[2]);

    if (
        $conversation_id === 0 ||
        $reply === '' ||
        strlen($reply) > 4000
    ) {

        return new WP_Error(
            'wewc_invalid_message',
            'Invalid conversation or reply.'
        );

    }

    // Save reply to the correct conversation.
    return wewc_bridge_save_agent_reply(
        $conversation_id,
        $reply
    );

}