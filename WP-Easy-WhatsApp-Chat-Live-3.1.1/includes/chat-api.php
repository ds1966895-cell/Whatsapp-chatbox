<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register Live Chat REST API Routes
 */
function wewc_register_chat_routes() {

    register_rest_route(
        'wewc/v1',
        '/session',
        array(
            'methods'  => 'POST',
            'callback' => 'wewc_create_visitor_session',
            'permission_callback' => '__return_true'
        )
    );

    
    register_rest_route(
        'wewc/v1',
        '/message',
        array(
            'methods'  => 'POST',
            'callback' => 'wewc_save_visitor_message',
            'permission_callback' => '__return_true'
        )
    );


    register_rest_route(
        'wewc/v1',
        '/messages',
        array(
            'methods' => 'POST',
            'callback' => 'wewc_get_chat_messages',
            'permission_callback' => '__return_true'
        )
    );

    register_rest_route(
        'wewc/v1',
        '/health',
        array(
            'methods' => 'GET',
            'callback' => 'wewc_chat_health',
            'permission_callback' => '__return_true'
        )
    );

}

add_action(
    'rest_api_init',
    'wewc_register_chat_routes'
);


/**
 * Public health endpoint. It never exposes credentials.
 */
function wewc_chat_health() {
    return new WP_REST_Response(
        array(
            'success' => true,
            'plugin'  => 'WP Easy WhatsApp Chat',
            'version' => '3.0.0',
            'https'   => is_ssl(),
            'enabled' => (int) get_option('wewc_enabled', 1) === 1,
        ),
        200
    );
}

/**
 * Create New Visitor Session
 */
function wewc_create_visitor_session($request) {

    global $wpdb;

    // Basic rate limiting by connection IP.
    $ip = isset($_SERVER['REMOTE_ADDR'])
        ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
        : 'unknown';

    $rate_key = 'wewc_session_' . substr(
        hash_hmac('sha256', $ip, wp_salt('auth')),
        0,
        32
    );

    $attempts = (int) get_transient($rate_key);

    if ($attempts >= 20) {

        return new WP_Error(
            'wewc_rate_limit',
            'Too many session requests. Please try again later.',
            array('status' => 429)
        );

    }

    // Generate cryptographically secure token.
    try {

        $visitor_token = bin2hex(random_bytes(32));

    } catch (Throwable $e) {

        return new WP_Error(
            'wewc_token_error',
            'Unable to create chat session.',
            array('status' => 500)
        );

    }

    // Store only token hash.
    $token_hash = hash(
        'sha256',
        $visitor_token
    );

    // Conversation table.
    $table = $wpdb->prefix . 'wewc_conversations';

    // Current UTC time.
    $now = current_time('mysql', true);

    // Create conversation.
    $inserted = $wpdb->insert(
        $table,
        array(
            'visitor_token_hash' => $token_hash,
            'status'             => 'open',
            'created_at'         => $now,
            'updated_at'         => $now
        ),
        array(
            '%s',
            '%s',
            '%s',
            '%s'
        )
    );

    if ($inserted === false) {

        return new WP_Error(
            'wewc_database_error',
            'Unable to create conversation.',
            array('status' => 500)
        );

    }

    // Update rate limit.
    set_transient(
        $rate_key,
        $attempts + 1,
        HOUR_IN_SECONDS
    );

    // Get conversation ID.
    $conversation_id = (int) $wpdb->insert_id;

    // Return session credentials.
    $response = new WP_REST_Response(
        array(
            'success'         => true,
            'conversation_id' => $conversation_id,
            'visitor_token'   => $visitor_token,
            'status'          => 'open'
        ),
        201
    );

    $response->header(
        'Cache-Control',
        'private, no-store'
    );

    return $response;

}


/**
 * Save visitor message
 */

/**
 * Save visitor message and create delivery queue entry.
 */
function wewc_save_visitor_message($request) {

    global $wpdb;

    $conversation_id = absint(
        $request->get_param('conversation_id')
    );

    $token = $request->get_param('visitor_token');

    $body = $request->get_param('message');

    // Validate request
    if (
        !is_string($token) ||
        !preg_match('/^[a-f0-9]{64}$/D', $token) ||
        !is_string($body)
    ) {

        return new WP_Error(
            'wewc_invalid_request',
            'Invalid chat request.',
            array('status' => 400)
        );

    }

    // Sanitize message
    $body = sanitize_textarea_field(trim($body));

    if (
        $conversation_id === 0 ||
        $body === '' ||
        strlen($body) > 4000
    ) {

        return new WP_Error(
            'wewc_invalid_message',
            'Message is empty or too long.',
            array('status' => 400)
        );

    }

    // Hash visitor token
    $token_hash = hash('sha256', $token);

    // Database tables
    $conversations = $wpdb->prefix . 'wewc_conversations';

    $messages = $wpdb->prefix . 'wewc_messages';

    $outbox = $wpdb->prefix . 'wewc_outbox';


    /**
     * Verify conversation ownership
     */
    $conversation = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM $conversations
             WHERE id = %d
             AND visitor_token_hash = %s
             AND status = 'open'",
            $conversation_id,
            $token_hash
        )
    );

    if (!$conversation) {

        return new WP_Error(
            'wewc_unauthorized',
            'Conversation not found or access denied.',
            array('status' => 403)
        );

    }


    /**
     * Message rate limiting
     */
    $rate_key = 'wewc_msg_' . substr($token_hash, 0, 32);

    $attempts = (int) get_transient($rate_key);

    if ($attempts >= 10) {

        return new WP_Error(
            'wewc_message_limit',
            'Too many messages. Please wait.',
            array('status' => 429)
        );

    }


    /**
     * Current UTC time
     */
    $now = current_time('mysql', true);


    /**
     * Start database transaction
     */
    if ($wpdb->query('START TRANSACTION') === false) {

        return new WP_Error(
            'wewc_transaction_failed',
            'Unable to start message transaction.',
            array('status' => 500)
        );

    }


    /**
     * 1. Save original visitor message
     */
    $inserted = $wpdb->insert(
        $messages,
        array(
            'conversation_id' => $conversation_id,
            'sender' => 'visitor',
            'body' => $body,
            'created_at' => $now
        ),
        array(
            '%d',
            '%s',
            '%s',
            '%s'
        )
    );

    if ($inserted === false) {

        $wpdb->query('ROLLBACK');

        return new WP_Error(
            'wewc_save_failed',
            'Unable to save visitor message.',
            array('status' => 500)
        );

    }

    // Capture message ID immediately
    $message_id = (int) $wpdb->insert_id;


    /**
     * 2. Add message to WhatsApp delivery queue
     */
    $queued = $wpdb->insert(
        $outbox,
        array(
            'message_id' => $message_id,
            'conversation_id' => $conversation_id,
            'provider' => 'unconfigured',
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now
        ),
        array(
            '%d',
            '%d',
            '%s',
            '%s',
            '%d',
            '%s',
            '%s'
        )
    );

    if ($queued === false) {

        $wpdb->query('ROLLBACK');

        return new WP_Error(
            'wewc_queue_failed',
            'Unable to queue message for delivery.',
            array('status' => 500)
        );

    }

    // Capture queue ID immediately, before any later database writes.
    $queue_id = (int) $wpdb->insert_id;


    /**
     * 3. Update conversation timestamp
     */
    $updated = $wpdb->update(
        $conversations,
        array(
            'updated_at' => $now
        ),
        array(
            'id' => $conversation_id
        ),
        array('%s'),
        array('%d')
    );

    if ($updated === false) {

        $wpdb->query('ROLLBACK');

        return new WP_Error(
            'wewc_update_failed',
            'Unable to update conversation.',
            array('status' => 500)
        );

    }


    /**
     * Commit database changes
     */
    if ($wpdb->query('COMMIT') === false) {

        $wpdb->query('ROLLBACK');

        return new WP_Error(
            'wewc_commit_failed',
            'Unable to complete message transaction.',
            array('status' => 500)
        );

    }


    /**
     * Update rate limit
     */
    set_transient(
        $rate_key,
        $attempts + 1,
        MINUTE_IN_SECONDS
    );


    /**
     * Attempt immediate WhatsApp delivery after the database transaction
     * has committed. A provider failure must never roll back the visitor
     * message that was already safely saved.
     */
    $delivery_status = 'pending';

    if (
        function_exists('wewc_360_send_outbox_item') &&
        function_exists('wewc_get_360_api_key') &&
        wewc_get_360_api_key() !== ''
    ) {
        $delivery_result = wewc_360_send_outbox_item($queue_id);
        $delivery_status = is_wp_error($delivery_result) ? 'failed' : 'sent';
    }

    /**
     * Return successful response
     */
    $response = new WP_REST_Response(
        array(
            'success' => true,
            'message_id' => $message_id,
            'status' => 'saved',
            'delivery_status' => $delivery_status
        ),
        201
    );

    $response->header(
        'Cache-Control',
        'private, no-store'
    );

    return $response;

}


/**
 * Retrieve visitor conversation messages
 */
function wewc_get_chat_messages($request) {

    global $wpdb;

    $conversation_id = absint(
        $request->get_param('conversation_id')
    );

    $token = $request->get_param('visitor_token');

    $after_id = absint(
        $request->get_param('after_id')
    );

    // Validate private token
    if (
        !is_string($token) ||
        !preg_match('/^[a-f0-9]{64}$/D', $token) ||
        $conversation_id === 0
    ) {
        return new WP_Error(
            'wewc_invalid_request',
            'Invalid conversation credentials.',
            array('status' => 400)
        );
    }

    $token_hash = hash('sha256', $token);

    $conversations = $wpdb->prefix . 'wewc_conversations';

    $messages_table = $wpdb->prefix . 'wewc_messages';

    // Verify conversation ownership
    $conversation = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM $conversations
             WHERE id = %d
             AND visitor_token_hash = %s
             AND status = 'open'",
            $conversation_id,
            $token_hash
        )
    );

    if (!$conversation) {
        return new WP_Error(
            'wewc_access_denied',
            'Conversation not found or access denied.',
            array('status' => 403)
        );
    }

    // Retrieve messages
    $messages = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, sender, body, created_at
             FROM $messages_table
             WHERE conversation_id = %d
             AND id > %d
             ORDER BY id ASC
             LIMIT 50",
            $conversation_id,
            $after_id
        ),
        ARRAY_A
    );

    if ($messages === null) {
        return new WP_Error(
            'wewc_read_failed',
            'Unable to retrieve messages.',
            array('status' => 500)
        );
    }

    $response = new WP_REST_Response(
        array(
            'success' => true,
            'messages' => $messages
        ),
        200
    );

    $response->header(
        'Cache-Control',
        'private, no-store'
    );

    return $response;
}


/**
 * Save an incoming WhatsApp reply only once.
 */
function wewc_bridge_save_unique_reply(
    $conversation_id,
    $reply,
    $message_sid
) {

    global $wpdb;

    $conversation_id = absint($conversation_id);

    // Validate incoming message ID.
    if (
        !is_string($message_sid) ||
        !preg_match(
            '/\A(?:(?:SM|MM)[a-f0-9]{32}|wamid\.[A-Za-z0-9_+=\/-]{8,180})\z/iD',
            $message_sid
        )
    ) {
        return new WP_Error(
            'wewc_invalid_sid',
            'Invalid WhatsApp message ID.'
        );
    }

    // Validate reply.
    if (!is_string($reply)) {
        return new WP_Error(
            'wewc_invalid_reply',
            'Invalid reply.'
        );
    }

    $reply = sanitize_textarea_field(trim($reply));

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

    $messages = $wpdb->prefix . 'wewc_messages';

    $conversations = $wpdb->prefix . 'wewc_conversations';

    // Check whether this message was already received.
    $existing = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, conversation_id
             FROM $messages
             WHERE external_message_id = %s
             LIMIT 1",
            $message_sid
        )
    );

    if ($existing) {

        if ((int) $existing->conversation_id !== $conversation_id) {
            return new WP_Error(
                'wewc_sid_conflict',
                'Message ID belongs to another conversation.'
            );
        }

        // Return original message ID.
        return (int) $existing->id;
    }

    // Verify conversation.
    $conversation = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM $conversations
             WHERE id = %d
             AND status = 'open'",
            $conversation_id
        )
    );

    if (!$conversation) {
        return new WP_Error(
            'wewc_conversation_not_found',
            'Conversation not found.'
        );
    }

    $now = current_time('mysql', true);

    // Save new agent reply.
    $inserted = $wpdb->insert(
        $messages,
        array(
            'conversation_id' => $conversation_id,
            'sender' => 'agent',
            'body' => $reply,
            'external_message_id' => $message_sid,
            'created_at' => $now
        ),
        array('%d', '%s', '%s', '%s', '%s')
    );

    if ($inserted === false) {

        // Handle a duplicate inserted by another request.
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, conversation_id
                 FROM $messages
                 WHERE external_message_id = %s
                 LIMIT 1",
                $message_sid
            )
        );

        if ($existing) {

            if ((int) $existing->conversation_id !== $conversation_id) {
                return new WP_Error(
                    'wewc_sid_conflict',
                    'Message ID belongs to another conversation.'
                );
            }

            return (int) $existing->id;
        }

        return new WP_Error(
            'wewc_reply_save_failed',
            'Unable to save reply.'
        );
    }

    $message_id = (int) $wpdb->insert_id;

    // Update conversation timestamp.
    $wpdb->update(
        $conversations,
        array('updated_at' => $now),
        array('id' => $conversation_id),
        array('%s'),
        array('%d')
    );

    return $message_id;
}