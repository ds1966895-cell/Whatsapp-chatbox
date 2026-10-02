<?php
/**
 * WP Easy WhatsApp Chat, 360dialog production inbound webhook.
 *
 * The live WordPress REST endpoint receives the provider callback directly.
 * 360dialog supports custom webhook headers, so the plugin uses a private
 * X-WEWC-Webhook-Secret header instead of an ngrok URL or desktop process.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', 'wewc_register_360dialog_webhook');

function wewc_register_360dialog_webhook() {
    register_rest_route('wewc/v1', '/360dialog/inbound', array(
        'methods'             => 'POST',
        'callback'            => 'wewc_handle_360dialog_webhook',
        'permission_callback' => '__return_true',
    ));
}

function wewc_resolve_360dialog_reply_conversation($message, $body, $outbox_table) {
    global $wpdb;

    $context_id = '';

    if (
        isset($message['context']) &&
        is_array($message['context']) &&
        isset($message['context']['id']) &&
        is_string($message['context']['id'])
    ) {
        $context_id = trim($message['context']['id']);
    }

    // Preferred route: native WhatsApp Reply -> context.id -> outbox row.
    if ($context_id !== '') {
        if (!preg_match('/\Awamid\.[A-Za-z0-9_+=\/-]{8,180}\z/D', $context_id)) {
            return new WP_Error(
                'wewc_360_invalid_context_id',
                'Invalid WhatsApp reply context ID.'
            );
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, conversation_id
                 FROM $outbox_table
                 WHERE external_message_id = %s
                   AND status = 'sent'
                 LIMIT 1",
                sanitize_text_field($context_id)
            )
        );

        if (!$row) {
            return new WP_Error(
                'wewc_360_context_not_found',
                'The WhatsApp reply target could not be mapped to a website conversation.'
            );
        }

        return array(
            'conversation_id' => (int) $row->conversation_id,
            'reply'           => $body,
            'routing'         => 'context',
        );
    }

    // Backward-compatible fallback for the previous manual reply format.
    if (preg_match('/\A\s*#([1-9][0-9]{0,9})[ \t]+([\s\S]+)\z/u', $body, $matches)) {
        return array(
            'conversation_id' => (int) $matches[1],
            'reply'           => $matches[2],
            'routing'         => 'legacy',
        );
    }

    return new WP_Error(
        'wewc_360_not_a_reply',
        'Message is not a WhatsApp Reply to a website chat notification.'
    );
}

function wewc_handle_360dialog_webhook($request) {
    if (!function_exists('wewc_get_360_webhook_secret')) {
        return new WP_Error(
            'wewc_360_runtime_missing',
            'Production runtime is unavailable.',
            array('status' => 503)
        );
    }

    $secret = wewc_get_360_webhook_secret();
    if (strlen($secret) < 32) {
        return new WP_Error(
            'wewc_360_not_configured',
            '360dialog webhook authentication is not configured.',
            array('status' => 503)
        );
    }

    // Production uses the private custom header. Sandbox uses the URL query
    // secret because its documented webhook setup is URL-only. Accept both
    // forms so an existing installation can be upgraded without breaking it.
    $provided = (string) $request->get_header('x-wewc-webhook-secret');

    if ($provided === '') {
        $query = $request->get_query_params();
        $provided = isset($query['key']) && is_string($query['key'])
            ? $query['key']
            : '';
    }

    if ($provided === '' || !hash_equals($secret, $provided)) {
        return new WP_Error(
            'wewc_360_unauthorized',
            'Unauthorized webhook request.',
            array('status' => 403)
        );
    }

    $configured_phone = preg_replace('/\D+/', '', (string) get_option('wewc_phone', ''));
    if (!preg_match('/\A[1-9][0-9]{7,14}\z/D', $configured_phone)) {
        return new WP_Error(
            'wewc_360_phone_missing',
            'The administrator WhatsApp number is not configured.',
            array('status' => 503)
        );
    }

    if (!function_exists('wewc_bridge_save_unique_reply')) {
        return new WP_Error(
            'wewc_360_bridge_missing',
            'Reply-saving function is unavailable.',
            array('status' => 503)
        );
    }

    $payload = $request->get_json_params();
    if (!is_array($payload)) {
        return new WP_Error(
            'wewc_360_bad_json',
            'Expected a JSON object.',
            array('status' => 400)
        );
    }

    $messages = array();

    if (isset($payload['entry']) && is_array($payload['entry'])) {
        foreach ($payload['entry'] as $entry) {
            if (!is_array($entry) || !isset($entry['changes']) || !is_array($entry['changes'])) {
                continue;
            }

            foreach ($entry['changes'] as $change) {
                if (!is_array($change) || ($change['field'] ?? '') !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? null;
                if (is_array($value) && isset($value['messages']) && is_array($value['messages'])) {
                    foreach ($value['messages'] as $message) {
                        $messages[] = $message;
                    }
                }
            }
        }
    }

    // Keep support for flat webhook payloads used by older sandbox setups.
    if (isset($payload['messages']) && is_array($payload['messages'])) {
        foreach ($payload['messages'] as $message) {
            $messages[] = $message;
        }
    }

    if (!$messages) {
        return new WP_REST_Response(array('success' => true, 'processed' => 0), 200);
    }

    global $wpdb;
    $outbox_table = $wpdb->prefix . 'wewc_outbox';
    $processed = 0;

    foreach ($messages as $message) {
        if (!is_array($message) || ($message['type'] ?? '') !== 'text') {
            continue;
        }

        // In this product's architecture, the configured number is the
        // administrator's WhatsApp destination that receives website alerts.
        $from = $message['from'] ?? null;
        if (
            !is_string($from) ||
            !hash_equals($configured_phone, preg_replace('/\D+/', '', $from))
        ) {
            return new WP_Error(
                'wewc_360_sender_rejected',
                'Message is not from the authorized administrator WhatsApp number.',
                array('status' => 403)
            );
        }

        $body = $message['text']['body'] ?? null;
        if (!is_string($body)) {
            continue;
        }

        $body = trim($body);
        if ($body === '' || strlen($body) > 4000) {
            continue;
        }

        $external_id = $message['id'] ?? null;
        if (
            !is_string($external_id) ||
            !preg_match('/\Awamid\.[A-Za-z0-9_+=\/-]{8,180}\z/D', $external_id)
        ) {
            return new WP_Error(
                'wewc_360_invalid_id',
                'Invalid WhatsApp message ID.',
                array('status' => 400)
            );
        }

        $resolved = wewc_resolve_360dialog_reply_conversation(
            $message,
            $body,
            $outbox_table
        );

        if (is_wp_error($resolved)) {
            // Ignore ordinary inbound messages. Only a native Reply or the
            // legacy #conversation format can update a website conversation.
            if ($resolved->get_error_code() === 'wewc_360_not_a_reply') {
                continue;
            }

            return new WP_Error(
                'wewc_360_reply_route_failed',
                'Unable to route the WhatsApp reply.',
                array('status' => 503)
            );
        }

        $saved = wewc_bridge_save_unique_reply(
            (int) $resolved['conversation_id'],
            $resolved['reply'],
            $external_id
        );

        if (is_wp_error($saved)) {
            return new WP_Error(
                'wewc_360_reply_not_saved',
                'Unable to save the WhatsApp reply.',
                array('status' => 503)
            );
        }

        $processed++;
    }

    return new WP_REST_Response(
        array(
            'success'   => true,
            'processed' => $processed,
        ),
        200
    );
}
