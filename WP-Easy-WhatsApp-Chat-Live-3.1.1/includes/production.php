<?php
/**
 * WP Easy WhatsApp Chat, live production runtime.
 *
 * The live WordPress site is the webhook server. No ngrok, PowerShell,
 * LocalWP, desktop process, or Task Scheduler is required on the live site.
 *
 * The plugin will use an existing 360dialog API key from either:
 *   1) WEWC_360_API_KEY in wp-config.php, or
 *   2) the wewc_360_api_key WordPress option.
 *
 * If the saved endpoint is unknown, the runtime automatically tries the
 * production endpoint first and falls back to the 360dialog Sandbox endpoint
 * on HTTP 401. This allows an existing Sandbox key to keep working on a live
 * WordPress domain without changing the site's URL or using ngrok.
 */

if (!defined('ABSPATH')) {
    exit;
}

function wewc_get_360_api_key() {
    if (defined('WEWC_360_API_KEY') && is_string(WEWC_360_API_KEY) && trim(WEWC_360_API_KEY) !== '') {
        return trim(WEWC_360_API_KEY);
    }

    return trim((string) get_option('wewc_360_api_key', ''));
}

function wewc_360_default_messages_url() {
    return 'https://waba-v2.360dialog.io/messages';
}

function wewc_360_sandbox_messages_url() {
    return 'https://waba-sandbox.360dialog.io/v1/messages';
}

function wewc_get_360_messages_url() {
    if (defined('WEWC_360_MESSAGES_URL') && is_string(WEWC_360_MESSAGES_URL) && trim(WEWC_360_MESSAGES_URL) !== '') {
        return rtrim(trim(WEWC_360_MESSAGES_URL), '/');
    }

    $saved = trim((string) get_option('wewc_360_messages_url', ''));
    if ($saved !== '') {
        return wewc_sanitize_360_messages_url($saved);
    }

    $resolved = trim((string) get_option('wewc_360_resolved_messages_url', ''));
    if ($resolved !== '') {
        return wewc_sanitize_360_messages_url($resolved);
    }

    return wewc_360_default_messages_url();
}

function wewc_get_360_webhook_secret() {
    return trim((string) get_option('wewc_360_webhook_secret', ''));
}

function wewc_generate_webhook_secret() {
    try {
        return bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        return wp_generate_password(64, false, false);
    }
}

function wewc_360_is_sandbox_url($url) {
    return strtolower((string) wp_parse_url($url, PHP_URL_HOST)) === 'waba-sandbox.360dialog.io';
}

function wewc_360_production_webhook_url() {
    return esc_url_raw(rest_url('wewc/v1/360dialog/inbound'));
}

function wewc_get_360_webhook_url() {
    $url = wewc_360_production_webhook_url();
    $secret = wewc_get_360_webhook_secret();

    // Sandbox documentation exposes only a URL for webhook configuration.
    // Keep the existing sandbox-compatible query-secret fallback available.
    if (wewc_360_is_sandbox_url(wewc_get_360_messages_url()) && strlen($secret) >= 32) {
        return add_query_arg('key', rawurlencode($secret), $url);
    }

    return $url;
}

function wewc_get_360_webhook_config_url($messages_url = null) {
    $messages_url = $messages_url ?: wewc_get_360_messages_url();
    if (wewc_360_is_sandbox_url($messages_url)) {
        return 'https://waba-sandbox.360dialog.io/v1/configs/webhook';
    }
    return 'https://waba-v2.360dialog.io/v1/configs/webhook';
}

function wewc_360_alternate_messages_url($url) {
    if (wewc_360_is_sandbox_url($url)) {
        return wewc_360_default_messages_url();
    }
    return wewc_360_sandbox_messages_url();
}

function wewc_360_request($method, $url, $body = null) {
    $api_key = wewc_get_360_api_key();

    if ($api_key === '') {
        return new WP_Error('wewc_360_api_key_missing', '360dialog API key is not configured.');
    }

    $args = array(
        'method'  => strtoupper($method),
        'timeout' => 20,
        'headers' => array(
            'D360-API-KEY' => $api_key,
            'Accept'       => 'application/json',
        ),
    );

    if ($body !== null) {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body'] = wp_json_encode($body);
    }

    $response = wp_remote_request($url, $args);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $raw  = (string) wp_remote_retrieve_body($response);
    $data = json_decode($raw, true);

    return array(
        'code' => $code,
        'body' => $raw,
        'data' => is_array($data) ? $data : array(),
        'url'  => $url,
    );
}

/**
 * Configure the live webhook automatically.
 *
 * Production uses a custom header. Sandbox uses the query-string secret
 * because the sandbox setup is documented around a webhook URL only.
 */
function wewc_configure_360dialog_webhook() {
    $secret = wewc_get_360_webhook_secret();

    if (!is_ssl()) {
        $result = new WP_Error('wewc_https_required', 'The live WordPress site must use HTTPS before the WhatsApp webhook can be configured.');
        update_option('wewc_360_last_webhook_status', 'failed', false);
        update_option('wewc_360_last_webhook_error', $result->get_error_message(), false);
        return $result;
    }

    if (strlen($secret) < 32) {
        $secret = wewc_generate_webhook_secret();
        update_option('wewc_360_webhook_secret', $secret, false);
    }

    $primary_messages_url = wewc_get_360_messages_url();
    $candidates = array($primary_messages_url);
    $alternate = wewc_360_alternate_messages_url($primary_messages_url);
    if ($alternate !== $primary_messages_url) {
        $candidates[] = $alternate;
    }

    $last_error = '';

    foreach ($candidates as $messages_url) {
        $sandbox = wewc_360_is_sandbox_url($messages_url);
        $webhook_url = $sandbox
            ? add_query_arg('key', rawurlencode($secret), wewc_360_production_webhook_url())
            : wewc_360_production_webhook_url();

        $body = array('url' => $webhook_url);
        if (!$sandbox) {
            $body['headers'] = array(
                'X-WEWC-Webhook-Secret' => $secret,
            );
        }

        $result = wewc_360_request(
            'POST',
            wewc_get_360_webhook_config_url($messages_url),
            $body
        );

        if (is_wp_error($result)) {
            $last_error = $result->get_error_message();
            continue;
        }

        if (in_array($result['code'], array(200, 201), true)) {
            update_option('wewc_360_resolved_messages_url', rtrim($messages_url, '/'), false);
            update_option('wewc_360_webhook_mode', $sandbox ? 'sandbox' : 'production', false);
            update_option('wewc_360_last_webhook_status', 'configured', false);
            update_option('wewc_360_last_webhook_error', '', false);
            update_option('wewc_360_last_webhook_url', $webhook_url, false);
            update_option('wewc_360_last_webhook_configured_at', current_time('mysql', true), false);

            return array(
                'success' => true,
                'url'     => $webhook_url,
                'code'    => $result['code'],
                'mode'    => $sandbox ? 'sandbox' : 'production',
            );
        }

        $message = 'HTTP ' . $result['code'];
        if (!empty($result['data']['meta']['developer_message'])) {
            $message .= ': ' . sanitize_text_field($result['data']['meta']['developer_message']);
        } elseif (!empty($result['data']['error']['message'])) {
            $message .= ': ' . sanitize_text_field($result['data']['error']['message']);
        } elseif (!empty($result['data']['message'])) {
            $message .= ': ' . sanitize_text_field($result['data']['message']);
        }

        $last_error = $message;

        // Only a credential mismatch should trigger endpoint auto-discovery.
        if ((int) $result['code'] !== 401) {
            break;
        }
    }

    update_option('wewc_360_last_webhook_status', 'failed', false);
    update_option('wewc_360_last_webhook_error', $last_error !== '' ? $last_error : 'Unable to configure the 360dialog webhook.', false);

    return new WP_Error('wewc_360_webhook_config_failed', $last_error !== '' ? $last_error : 'Unable to configure the 360dialog webhook.');
}

function wewc_360_request_with_auto_endpoint($method, $url, $body = null) {
    $result = wewc_360_request($method, $url, $body);
    if (is_wp_error($result)) {
        return $result;
    }

    if ((int) $result['code'] !== 401) {
        return $result;
    }

    $alternate = wewc_360_alternate_messages_url($url);
    if ($alternate === $url) {
        return $result;
    }

    $fallback = wewc_360_request($method, $alternate, $body);
    if (!is_wp_error($fallback) && (int) $fallback['code'] !== 401) {
        if (in_array((int) $fallback['code'], array(200, 201), true)) {
            update_option('wewc_360_resolved_messages_url', rtrim($alternate, '/'), false);
            update_option('wewc_360_webhook_mode', wewc_360_is_sandbox_url($alternate) ? 'sandbox' : 'production', false);
        }
        return $fallback;
    }

    return $result;
}

function wewc_activate_plugin() {
    if (function_exists('wewc_create_chat_tables')) {
        wewc_create_chat_tables();
    }

    if (wewc_get_360_webhook_secret() === '') {
        update_option('wewc_360_webhook_secret', wewc_generate_webhook_secret(), false);
    }

    if (function_exists('wp_next_scheduled') && !wp_next_scheduled('wewc_process_outbox')) {
        wp_schedule_event(time() + 60, 'wewc_every_minute', 'wewc_process_outbox');
    }

    // Automatically configure the webhook if a credential already exists.
    if (wewc_get_360_api_key() !== '') {
        wewc_configure_360dialog_webhook();
    }
}

function wewc_deactivate_plugin() {
    wp_clear_scheduled_hook('wewc_process_outbox');
    wp_clear_scheduled_hook('wewc_configure_webhook_once');
}

add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['wewc_every_minute'])) {
        $schedules['wewc_every_minute'] = array(
            'interval' => 60,
            'display'  => 'Every Minute',
        );
    }
    return $schedules;
});

add_action('wewc_configure_webhook_once', 'wewc_configure_360dialog_webhook');

foreach (array('wewc_360_api_key', 'wewc_360_messages_url', 'wewc_360_webhook_secret') as $wewc_option_name) {
    add_action('updated_option_' . $wewc_option_name, function () {
        if (wewc_get_360_api_key() !== '' && !wp_next_scheduled('wewc_configure_webhook_once')) {
            wp_schedule_single_event(time() + 5, 'wewc_configure_webhook_once');
        }
    });
}

function wewc_360_get_webhook_configuration() {
    return wewc_360_request('GET', wewc_get_360_webhook_config_url());
}

function wewc_get_production_bridge_status() {
    $api_key = wewc_get_360_api_key();
    $recipient = preg_replace('/\D+/', '', (string) get_option('wewc_phone', ''));
    $secret = wewc_get_360_webhook_secret();

    if (!is_ssl()) {
        return array(
            'label' => 'HTTPS required',
            'message' => 'The live site must use HTTPS for WhatsApp webhook delivery.',
        );
    }

    if ($api_key === '') {
        return array(
            'label' => 'WhatsApp credential missing',
            'message' => 'No 360dialog API key is available in WordPress. The plugin cannot authenticate to WhatsApp without a valid provider credential.',
        );
    }

    if (!preg_match('/\A[1-9][0-9]{7,14}\z/D', $recipient)) {
        return array(
            'label' => 'Recipient missing',
            'message' => 'Enter the administrator WhatsApp number with country code.',
        );
    }

    if (strlen($secret) < 32) {
        return array(
            'label' => 'Initializing',
            'message' => 'The plugin will generate its private webhook secret automatically.',
        );
    }

    if (get_option('wewc_360_last_webhook_status', '') === 'configured') {
        $mode = get_option('wewc_360_webhook_mode', 'production');
        return array(
            'label' => 'Connected',
            'message' => 'Live WordPress webhook is configured automatically (' . sanitize_text_field($mode) . ' mode).',
        );
    }

    $last_error = get_option('wewc_360_last_webhook_error', '');
    if ($last_error !== '') {
        return array(
            'label' => 'Needs provider authentication',
            'message' => $last_error,
        );
    }

    return array(
        'label' => 'Auto-configuring',
        'message' => 'WordPress will configure its live webhook automatically.',
    );
}

add_action('admin_post_wewc_configure_360dialog_webhook', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized.', 'WP Easy WhatsApp Chat', array('response' => 403));
    }

    check_admin_referer('wewc_configure_360dialog_webhook');

    $result = wewc_configure_360dialog_webhook();
    $message = is_wp_error($result)
        ? $result->get_error_message()
        : 'Live 360dialog webhook configured successfully.';

    $url = add_query_arg(
        'wewc_provider_message',
        rawurlencode($message),
        admin_url('options-general.php?page=wewc-settings')
    );

    wp_safe_redirect($url);
    exit;
});
