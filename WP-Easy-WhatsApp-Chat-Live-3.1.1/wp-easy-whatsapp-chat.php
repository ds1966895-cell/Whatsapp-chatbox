<?php
/**
 * Plugin Name: WP Easy WhatsApp Chat
 * Description: Live WordPress chat bridge to WhatsApp through 360dialog. Automatically uses the live WordPress HTTPS URL, with no ngrok, PowerShell, LocalWP, or Task Scheduler required on the live site.
 * Version: 3.1.0
 * Author: Awais-Ali
 */

if (!defined('ABSPATH')) {
    exit;
}



/**
 * Production runtime.
 * WordPress communicates directly with 360dialog over HTTPS and exposes
 * its own live REST URL as the webhook endpoint. ngrok, PowerShell and
 * Task Scheduler are not used on the production server.
 */
require_once plugin_dir_path(__FILE__) . 'includes/chat-storage.php';
require_once plugin_dir_path(__FILE__) . 'includes/chat-bridge.php';
require_once plugin_dir_path(__FILE__) . 'includes/360dialog-outbox.php';
require_once plugin_dir_path(__FILE__) . 'includes/360dialog-webhook.php';
require_once plugin_dir_path(__FILE__) . 'includes/production.php';
require_once plugin_dir_path(__FILE__) . 'includes/chat-api.php';

register_activation_hook(__FILE__, 'wewc_activate_plugin');
register_deactivation_hook(__FILE__, 'wewc_deactivate_plugin');

/**
 * 1. Register plugin settings
 */

function wewc_register_settings() {

    register_setting(
        'wewc_settings_group',
        'wewc_phone',
        array(
            'sanitize_callback' => 'wewc_sanitize_phone',
            'default' => ''
        )
    );

    register_setting(
        'wewc_settings_group',
        'wewc_message',
        array(
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => 'Hello! I need more information.'
        )
    );

    // Enable / Disable WhatsApp Button
    register_setting(
        'wewc_settings_group',
        'wewc_enabled',
        array(
            'sanitize_callback' => 'absint',
            'default' => 1
        )
    );

    
    register_setting(
        'wewc_settings_group',
        'wewc_position',
        array(
            'sanitize_callback' => 'wewc_sanitize_position',
            'default' => 'right'
        )
    );

    
    // Custom Button Color
    register_setting(
        'wewc_settings_group',
        'wewc_button_color',
        array(
            'sanitize_callback' => 'sanitize_hex_color',
            'default' => '#25D366'
        )
    );

    
    // Custom Button Text
    register_setting(
        'wewc_settings_group',
        'wewc_button_text',
        array(
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'Chat on WhatsApp'
        )
    );

    
    // Device Visibility Setting
    register_setting(
        'wewc_settings_group',
        'wewc_device_visibility',
        array(
            'sanitize_callback' => 'wewc_sanitize_visibility',
            'default' => 'both'
        )
    );

    
    // Agent Name
    register_setting(
        'wewc_settings_group',
        'wewc_agent_name',
        array(
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'Customer Support'
        )
    );

    // Agent Welcome Message
    register_setting(
        'wewc_settings_group',
        'wewc_greeting',
        array(
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => "Hello! 👋\nHow can we help you today?"
        )
    );

    
    // Agent Profile Photo
    register_setting(
        'wewc_settings_group',
        'wewc_agent_avatar',
        array(
            'sanitize_callback' => 'wewc_sanitize_agent_avatar',
            'default' => 0
        )
    );

    
    // Enable Business Hours
    register_setting(
        'wewc_settings_group',
        'wewc_hours_enabled',
        array(
            'sanitize_callback' => 'absint',
            'default' => 0
        )
    );

    // Opening Time
    register_setting(
        'wewc_settings_group',
        'wewc_open_time',
        array(
            'sanitize_callback' => 'wewc_sanitize_open_time',
            'default' => '09:00'
        )
    );

    // Closing Time
    register_setting(
        'wewc_settings_group',
        'wewc_close_time',
        array(
            'sanitize_callback' => 'wewc_sanitize_close_time',
            'default' => '18:00'
        )
    );

    register_setting('wewc_settings_group', 'wewc_360_api_key', array(
        'sanitize_callback' => 'wewc_sanitize_360_api_key', 'default' => ''
    ));

    register_setting('wewc_settings_group', 'wewc_360_messages_url', array(
        'sanitize_callback' => 'wewc_sanitize_360_messages_url', 'default' => 'https://waba-v2.360dialog.io/messages'
    ));

    register_setting('wewc_settings_group', 'wewc_360_webhook_secret', array(
        'sanitize_callback' => 'wewc_sanitize_360_webhook_secret', 'default' => ''
    ));

    register_setting('wewc_settings_group', 'wewc_360_notification_mode', array(
        'sanitize_callback' => 'wewc_sanitize_360_notification_mode', 'default' => 'text'
    ));

    register_setting('wewc_settings_group', 'wewc_360_template_name', array(
        'sanitize_callback' => 'sanitize_key', 'default' => ''
    ));

    register_setting('wewc_settings_group', 'wewc_360_template_language', array(
        'sanitize_callback' => 'sanitize_text_field', 'default' => 'en_US'
    ));

    register_setting('wewc_settings_group', 'wewc_360_template_params', array(
        'sanitize_callback' => 'absint', 'default' => 2
    ));
}

add_action('admin_init', 'wewc_register_settings');


/**
 * 2. Sanitize WhatsApp number
 */
function wewc_sanitize_phone($phone) {

    $phone = sanitize_text_field($phone);

    // Keep numbers only
    return preg_replace('/[^0-9]/', '', $phone);
}

function wewc_sanitize_360_api_key($value) {
    return sanitize_text_field((string) $value);
}

function wewc_sanitize_360_messages_url($value) {
    $value = esc_url_raw((string) $value);
    if ($value === '') {
        return 'https://waba-v2.360dialog.io/messages';
    }
    $host = strtolower((string) wp_parse_url($value, PHP_URL_HOST));
    $scheme = strtolower((string) wp_parse_url($value, PHP_URL_SCHEME));
    $allowed_hosts = array('waba-v2.360dialog.io', 'waba-sandbox.360dialog.io');
    if ($scheme !== 'https' || !in_array($host, $allowed_hosts, true)) {
        return 'https://waba-v2.360dialog.io/messages';
    }
    return rtrim($value, '/');
}

function wewc_sanitize_360_webhook_secret($value) {
    $value = sanitize_text_field((string) $value);
    if (strlen($value) < 32) {
        return '';
    }
    return substr($value, 0, 128);
}

function wewc_sanitize_360_notification_mode($value) {
    return in_array($value, array('text', 'template'), true) ? $value : 'text';
}


function wewc_sanitize_position($position) {

    $allowed_positions = array('left', 'right');

    if (in_array($position, $allowed_positions, true)) {
        return $position;
    }

    return 'right';
}


function wewc_sanitize_visibility($visibility) {

    $allowed = array(
        'both',
        'mobile',
        'desktop'
    );

    if (in_array($visibility, $allowed, true)) {
        return $visibility;
    }

    return 'both';
}



/**
 * Validate time format HH:MM
 */
function wewc_validate_business_time($time, $fallback) {

    if (!is_string($time)) {
        return $fallback;
    }

    $time = sanitize_text_field($time);

    if (preg_match(
        '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/',
        $time
    )) {
        return $time;
    }

    return $fallback;
}


/**
 * Validate opening time
 */
function wewc_sanitize_open_time($time) {

    return wewc_validate_business_time(
        $time,
        '09:00'
    );
}


/**
 * Validate closing time
 */
function wewc_sanitize_close_time($time) {

    return wewc_validate_business_time(
        $time,
        '18:00'
    );
}


/**
 * Check whether business is currently open
 */
function wewc_is_business_open($open, $close, $now) {

    // Same opening and closing time means 24 hours.
    if ($open === $close) {
        return true;
    }

    // Normal schedule, e.g. 09:00 to 18:00.
    if ($open < $close) {

        return (
            $now >= $open &&
            $now < $close
        );

    }

    // Overnight schedule, e.g. 22:00 to 06:00.
    return (
        $now >= $open ||
        $now < $close
    );
}

function wewc_sanitize_agent_avatar($value) {

    $image_id = absint($value);

    if ($image_id === 0) {
        return 0;
    }

    if (!wp_attachment_is_image($image_id)) {
        return 0;
    }

    return $image_id;
}

/**
 * 3. Add settings page in WordPress dashboard
 */
function wewc_add_settings_page() {

    add_options_page(
        'WhatsApp Chat Settings',
        'WhatsApp Chat',
        'manage_options',
        'wewc-settings',
        'wewc_settings_page'
    );
}

add_action('admin_menu', 'wewc_add_settings_page');


/**
 * Load Media Library on plugin settings page
 */
function wewc_admin_enqueue_assets($hook) {

    // Load only on our plugin settings page
    if ($hook !== 'settings_page_wewc-settings') {
        return;
    }

    // Load WordPress Media Library
    wp_enqueue_media();

    // Load our admin JavaScript
    wp_enqueue_script(
        'wewc-admin',
        plugin_dir_url(__FILE__) . 'assets/js/admin.js',
        array('media-views'),
        '1.9.0',
        true
    );
}

add_action(
    'admin_enqueue_scripts',
    'wewc_admin_enqueue_assets'
);


/**
 * 4. Display settings page
 */
function wewc_settings_page() {

    if (!current_user_can('manage_options')) {
        return;
    }

    ?>

<div class="wrap">

    <h1>WhatsApp Chat Settings</h1>

    <p>
        Configure your floating WhatsApp chat button.
    </p>

    <form method="post" action="options.php">

        <?php settings_fields('wewc_settings_group'); ?>

        <table class="form-table">

            <tr>
                <th scope="row">
                    <label for="wewc_phone">
                        WhatsApp Number
                    </label>
                </th>

                <td>

                    <input type="text" id="wewc_phone" name="wewc_phone"
                        value="<?php echo esc_attr(get_option('wewc_phone', '')); ?>" class="regular-text"
                        placeholder="923001234567">

                    <p class="description">
                        Enter your number with country code.
                        Example: 923001234567
                    </p>

                </td>
            </tr>


            <tr>
                <th scope="row">
                    <label for="wewc_message">
                        Default Message
                    </label>
                </th>

                <td>

                    <textarea id="wewc_message" name="wewc_message" rows="4"
                        class="large-text"><?php echo esc_textarea(get_option('wewc_message', 'Hello! I need more information.')); ?></textarea>

                    <p class="description">
                        This message will appear when a visitor opens WhatsApp.
                    </p>

                </td>
            </tr>


            <tr>
                <th scope="row">
                    Enable WhatsApp Button
                </th>

                <td>

                    <input type="hidden" name="wewc_enabled" value="0">

                    <label>

                        <input type="checkbox" name="wewc_enabled" value="1" <?php
                checked(
                    1,
                    (int) get_option('wewc_enabled', 1)
                );
                ?>>

                        Show WhatsApp button on website

                    </label>

                </td>
            </tr>


            <tr>
                <th scope="row">
                    <label for="wewc_position">
                        Button Position
                    </label>
                </th>

                <td>
                    <select id="wewc_position" name="wewc_position">

                        <option value="right" <?php selected(
                    get_option('wewc_position', 'right'),
                    'right'
                ); ?>>
                            Bottom Right
                        </option>

                        <option value="left" <?php selected(
                    get_option('wewc_position', 'right'),
                    'left'
                ); ?>>
                            Bottom Left
                        </option>

                    </select>
                </td>
            </tr>


            <tr>
                <th scope="row">
                    <label for="wewc_button_color">
                        Button Color
                    </label>
                </th>

                <td>
                    <input type="color" id="wewc_button_color" name="wewc_button_color" value="<?php echo esc_attr(
                get_option('wewc_button_color', '#25D366')
            ); ?>">

                    <p class="description">
                        Choose your preferred WhatsApp button color.
                    </p>
                </td>
            </tr>


            <tr>
                <th scope="row">
                    <label for="wewc_button_text">
                        Button Text
                    </label>
                </th>

                <td>
                    <input type="text" id="wewc_button_text" name="wewc_button_text" value="<?php echo esc_attr(
                get_option(
                    'wewc_button_text',
                    'Chat on WhatsApp'
                )
            ); ?>" class="regular-text" placeholder="Chat on WhatsApp">

                    <p class="description">
                        Enter the text displayed on your WhatsApp button.
                    </p>
                </td>
            </tr>


            <tr>
                <th scope="row">
                    <label for="wewc_device_visibility">
                        Device Visibility
                    </label>
                </th>

                <td>

                    <select id="wewc_device_visibility" name="wewc_device_visibility">

                        <option value="both" <?php selected(
                    get_option('wewc_device_visibility', 'both'),
                    'both'
                ); ?>>
                            Both Mobile & Desktop
                        </option>

                        <option value="mobile" <?php selected(
                    get_option('wewc_device_visibility', 'both'),
                    'mobile'
                ); ?>>
                            Mobile Only
                        </option>

                        <option value="desktop" <?php selected(
                    get_option('wewc_device_visibility', 'both'),
                    'desktop'
                ); ?>>
                            Desktop Only
                        </option>

                    </select>

                    <p class="description">
                        Select which devices should display
                        the WhatsApp chat button.
                    </p>

                </td>
            </tr>


            <!-- Agent Name -->

            <tr>
                <th scope="row">
                    <label for="wewc_agent_name">
                        Agent Name
                    </label>
                </th>

                <td>
                    <input type="text" id="wewc_agent_name" name="wewc_agent_name" value="<?php echo esc_attr(
                get_option(
                    'wewc_agent_name',
                    'Customer Support'
                )
            ); ?>" class="regular-text" placeholder="Customer Support">

                    <p class="description">
                        Enter the name displayed in the chat header.
                    </p>
                </td>
            </tr>


            <!-- Agent Welcome Message -->

            <tr>
                <th scope="row">
                    <label for="wewc_greeting">
                        Welcome Message
                    </label>
                </th>

                <td>
                    <textarea id="wewc_greeting" name="wewc_greeting" rows="4" class="large-text"><?php echo esc_textarea(
            get_option(
                'wewc_greeting',
                "Hello! 👋\nHow can we help you today?"
            )
        ); ?></textarea>

                    <p class="description">
                        This message appears inside the chat popup.
                    </p>
                </td>
            </tr>


            <!-- Agent Profile Photo -->

            <tr>
                <th scope="row">
                    Agent Profile Photo
                </th>

                <td>

                    <?php

        $avatar_id = absint(
            get_option('wewc_agent_avatar', 0)
        );

        $avatar_url = $avatar_id
            ? wp_get_attachment_image_url(
                $avatar_id,
                'thumbnail'
            )
            : false;

        ?>

                    <!-- Image Preview -->

                    <img id="wewc-agent-avatar-preview" src="<?php echo esc_url($avatar_url ?: ''); ?>"
                        alt="Agent photo preview" width="72" height="72" style="
                width:72px;
                height:72px;
                object-fit:cover;
                border-radius:50%;
                display:<?php echo $avatar_url ? 'block' : 'none'; ?>;
                margin-bottom:12px;
            ">

                    <!-- Hidden Attachment ID -->

                    <input type="hidden" id="wewc_agent_avatar" name="wewc_agent_avatar"
                        value="<?php echo esc_attr($avatar_id); ?>">

                    <!-- Select Image Button -->

                    <button type="button" id="wewc-upload-avatar" class="button button-secondary">
                        Select Profile Photo
                    </button>

                    <!-- Remove Image Button -->

                    <button type="button" id="wewc-remove-avatar" class="button"
                        style="display:<?php echo $avatar_url ? 'inline-block' : 'none'; ?>;">
                        Remove Photo
                    </button>

                    <p class="description">
                        Select an agent photo from your WordPress Media Library.
                    </p>

                </td>
            </tr>


            <!-- Business Hours Settings -->

            <tr>
                <th scope="row">
                    Business Hours
                </th>

                <td>

                    <input type="hidden" name="wewc_hours_enabled" value="0">

                    <label>

                        <input type="checkbox" name="wewc_hours_enabled" value="1" <?php checked(
                    1,
                    (int) get_option('wewc_hours_enabled', 0)
                ); ?>>

                        Enable automatic business hours status

                    </label>

                    <p class="description">
                        Display whether your business is within
                        or outside configured working hours.
                    </p>

                </td>
            </tr>


            <!-- Opening Time -->

            <tr>
                <th scope="row">
                    <label for="wewc_open_time">
                        Opening Time
                    </label>
                </th>

                <td>

                    <input type="time" id="wewc_open_time" name="wewc_open_time" value="<?php echo esc_attr(
                wewc_sanitize_open_time(
                    get_option('wewc_open_time', '09:00')
                )
            ); ?>">

                </td>
            </tr>


            <!-- Closing Time -->

            <tr>
                <th scope="row">
                    <label for="wewc_close_time">
                        Closing Time
                    </label>
                </th>

                <td>

                    <input type="time" id="wewc_close_time" name="wewc_close_time" value="<?php echo esc_attr(
                wewc_sanitize_close_time(
                    get_option('wewc_close_time', '18:00')
                )
            ); ?>">

                    <p class="description">
                        Same hours apply every day.
                        Identical opening and closing times mean 24-hour availability.
                    </p>

                    <p class="description">
                        Current site timezone:
                        <strong>
                            <?php echo esc_html(wp_timezone_string()); ?>
                        </strong>
                    </p>

                </td>
            </tr>

        </table>

        <hr style="margin:28px 0;">
        <h2>Production WhatsApp Bridge</h2>
        <p>WordPress uses the live site's HTTPS REST endpoint directly. No ngrok, PowerShell, LocalWP tunnel, or Task Scheduler is required on the production server.</p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="wewc_360_api_key">360dialog API Key</label></th>
                <td>
                    <?php $wewc_api_from_config = defined('WEWC_360_API_KEY') && is_string(WEWC_360_API_KEY) && trim(WEWC_360_API_KEY) !== ''; ?>
                    <?php if ($wewc_api_from_config) : ?>
                        <input type="text" class="regular-text" value="Configured in wp-config.php" disabled>
                        <p class="description">Using WEWC_360_API_KEY from wp-config.php.</p>
                    <?php else : ?>
                        <input type="password" autocomplete="new-password" id="wewc_360_api_key" name="wewc_360_api_key" value="<?php echo esc_attr(get_option('wewc_360_api_key', '')); ?>" class="regular-text">
                        <p class="description">Your 360dialog Messaging API key.</p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wewc_360_messages_url">Messaging API URL</label></th>
                <td>
                    <input type="url" id="wewc_360_messages_url" name="wewc_360_messages_url" value="<?php echo esc_attr(get_option('wewc_360_messages_url', 'https://waba-v2.360dialog.io/messages')); ?>" class="large-text">
                    <p class="description">Production default: https://waba-v2.360dialog.io/messages</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wewc_360_webhook_secret">Webhook Secret</label></th>
                <td>
                    <input type="password" autocomplete="new-password" id="wewc_360_webhook_secret" name="wewc_360_webhook_secret" value="<?php echo esc_attr(get_option('wewc_360_webhook_secret', '')); ?>" class="large-text">
                    <p class="description">Private header value sent by 360dialog. A secret is generated automatically on activation.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wewc_360_notification_mode">Notification Mode</label></th>
                <td>
                    <select id="wewc_360_notification_mode" name="wewc_360_notification_mode">
                        <option value="text" <?php selected(get_option('wewc_360_notification_mode', 'text'), 'text'); ?>>Free-form text</option>
                        <option value="template" <?php selected(get_option('wewc_360_notification_mode', 'text'), 'template'); ?>>Approved WhatsApp template</option>
                    </select>
                    <p class="description">For production business-initiated notifications, use an approved template unless the administrator has an open WhatsApp service window.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wewc_360_template_name">Template Name</label></th>
                <td>
                    <input type="text" id="wewc_360_template_name" name="wewc_360_template_name" value="<?php echo esc_attr(get_option('wewc_360_template_name', '')); ?>" class="regular-text">
                    <p class="description">Required when Notification Mode is Template. Use the exact approved template name.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wewc_360_template_language">Template Language</label></th>
                <td>
                    <input type="text" id="wewc_360_template_language" name="wewc_360_template_language" value="<?php echo esc_attr(get_option('wewc_360_template_language', 'en_US')); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wewc_360_template_params">Template Body Parameters</label></th>
                <td>
                    <select id="wewc_360_template_params" name="wewc_360_template_params">
                        <option value="1" <?php selected((int) get_option('wewc_360_template_params', 2), 1); ?>>1, visitor message</option>
                        <option value="2" <?php selected((int) get_option('wewc_360_template_params', 2), 2); ?>>2, conversation ID + visitor message</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">Live Webhook URL</th>
                <td>
                    <code style="display:block;max-width:100%;word-break:break-all;"><?php echo esc_html(rest_url('wewc/v1/360dialog/inbound')); ?></code>
                    <p class="description">This is your real live-domain endpoint. There is no forwarding tunnel.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Bridge Status</th>
                <td>
                    <?php $wewc_bridge_status = wewc_get_production_bridge_status(); ?>
                    <strong><?php echo esc_html($wewc_bridge_status['label']); ?></strong>
                    <p class="description"><?php echo esc_html($wewc_bridge_status['message']); ?></p>
                </td>
            </tr>
        </table>

        <?php submit_button('Save Changes'); ?>

    </form>



    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
        <input type="hidden" name="action" value="wewc_configure_360dialog_webhook">
        <?php wp_nonce_field('wewc_configure_360dialog_webhook'); ?>
        <?php submit_button('Configure Live 360dialog Webhook', 'secondary', 'submit', false); ?>
    </form>

    <?php if (isset($_GET['wewc_provider_message'])) : ?>
        <div class="notice notice-info inline"><p><?php echo esc_html(wp_unslash($_GET['wewc_provider_message'])); ?></p></div>
    <?php endif; ?>

</div>

<?php
}



/**
 * 5. Load frontend CSS and JavaScript
 */
function wewc_enqueue_assets() {

    $enabled = (int) get_option('wewc_enabled', 1);

    $phone = wewc_sanitize_phone(
        get_option('wewc_phone', '')
    );

    if ($enabled !== 1) {
    return;
    }   

    // Load CSS
    wp_enqueue_style(
        'wewc-frontend',
        plugin_dir_url(__FILE__) . 'assets/css/frontend.css',
        array(),
        '1.7.0'
    );

    // Load JavaScript
    wp_enqueue_script(
        'wewc-frontend',
        plugin_dir_url(__FILE__) . 'assets/js/frontend.js',
        array(),
        '1.7.1',
        true
    );

    
    // Pass REST API URL to frontend JavaScript.
    wp_localize_script(
        'wewc-frontend',
        'wewcChatConfig',
        array(
            'apiBase' => esc_url_raw(
                rest_url('wewc/v1/')
            )
        )
    );
}

add_action('wp_enqueue_scripts', 'wewc_enqueue_assets');


/**
 * 6. Display WhatsApp Chat Widget
 */
function wewc_display_whatsapp_button() {

    // Check whether button is enabled
    $enabled = (int) get_option('wewc_enabled', 1);

    

    // WhatsApp number
    $phone = wewc_sanitize_phone(
        get_option('wewc_phone', '')
    );

    if (empty($phone)) {
        return;
    }

    // Default message
    $message = get_option(
        'wewc_message',
        'Hello! I need more information.'
    );

    // Button position
    $position = wewc_sanitize_position(
        get_option('wewc_position', 'right')
    );

    // Device visibility
    $visibility = wewc_sanitize_visibility(
        get_option('wewc_device_visibility', 'both')
    );

    // Button color
    $button_color = sanitize_hex_color(
        get_option('wewc_button_color', '#25D366')
    );

    if (empty($button_color)) {
        $button_color = '#25D366';
    }

    // Button text
    $button_text = sanitize_text_field(
        get_option(
            'wewc_button_text',
            'Chat on WhatsApp'
        )
    );

    if (trim($button_text) === '') {
        $button_text = 'Chat on WhatsApp';
    }

    
    // Get Agent Name
    $agent_name = sanitize_text_field(
        get_option(
            'wewc_agent_name',
            'Customer Support'
        )
    );

    // Fallback if empty
    if (trim($agent_name) === '') {
        $agent_name = 'Customer Support';
    }


    // Get Welcome Message
    $greeting = sanitize_textarea_field(
        get_option(
            'wewc_greeting',
            "Hello! 👋\nHow can we help you today?"
        )
    );

    // Fallback if empty
    if (trim($greeting) === '') {
        $greeting = "Hello! 👋\nHow can we help you today?";
    }

    
    // Agent Profile Photo
    $avatar_id = absint(
        get_option('wewc_agent_avatar', 0)
    );

    $avatar_url = false;

    if (
        $avatar_id &&
        wp_attachment_is_image($avatar_id)
    ) {

        $avatar_url = wp_get_attachment_image_url(
            $avatar_id,
            'thumbnail'
        );

    }

    
    // Business Hours Settings
    $hours_enabled = (int) get_option(
        'wewc_hours_enabled',
        0
    );

    $open_time = wewc_sanitize_open_time(
        get_option('wewc_open_time', '09:00')
    );

    $close_time = wewc_sanitize_close_time(
        get_option('wewc_close_time', '18:00')
    );

    // Get current time in WordPress site timezone
    $current_time = current_datetime()->format('H:i');

    // Calculate business availability
    $is_open = wewc_is_business_open(
        $open_time,
        $close_time,
        $current_time
    );

    // Generate display text
    $status_text = $is_open
        ? 'Within business hours'
        : 'Outside business hours';

    // WhatsApp URL
    $url = 'https://wa.me/' . $phone .
        '?text=' . rawurlencode($message);

    ?>

<div class="wewc-widget wewc-position-<?php echo esc_attr($position); ?> wewc-device-<?php echo esc_attr($visibility); ?>"
    style="--wewc-color: <?php echo esc_attr($button_color); ?>;">

    <!-- Chat Popup -->
    <section id="wewc-chat-panel" class="wewc-panel" role="dialog" aria-modal="false" aria-labelledby="wewc-chat-title"
        hidden>

        <!-- Header -->
        <div class="wewc-panel-header">


            <div class="wewc-avatar">

                <?php if ($avatar_url) : ?>

                <img src="<?php echo esc_url($avatar_url); ?>" alt="" width="42" height="42" loading="lazy"
                    decoding="async">

                <?php else : ?>

                <span aria-hidden="true">W</span>

                <?php endif; ?>

            </div>

            <div class="wewc-agent-info">


                <strong id="wewc-chat-title">
                    <?php echo esc_html($agent_name); ?>
                </strong>


                <?php if ($hours_enabled === 1) : ?>

                <span class="wewc-business-status <?php echo $is_open ? 'wewc-open' : 'wewc-closed'; ?>">

                    <span class="wewc-status-dot" aria-hidden="true"></span>

                    <?php echo esc_html($status_text); ?>

                </span>

                <?php else : ?>

                <span>
                    Contact us on WhatsApp
                </span>

                <?php endif; ?>

            </div>

            <button type="button" class="wewc-close" aria-label="Close chat">
                &times;
            </button>

        </div>

        <!-- Chat Body -->
        <div class="wewc-panel-body">


            <div class="wewc-message">
                <?php echo nl2br(esc_html($greeting)); ?>
            </div>


            <!-- Live Chat Messages -->

            <div class="wewc-chat-messages" id="wewc-chat-messages" aria-live="polite" aria-label="Chat conversation">
            </div>

            <!-- Chat Status -->

            <div class="wewc-chat-status" id="wewc-chat-status" role="status">
                Open the chat to start messaging.
            </div>

            <!-- Message Form -->

            <form class="wewc-chat-form" id="wewc-chat-form">

                <input type="text" id="wewc-chat-input" class="wewc-chat-input" placeholder="Type your message..."
                    maxlength="1000" autocomplete="off" aria-label="Type your message" disabled required>

                <button type="submit" class="wewc-chat-send" id="wewc-chat-send" disabled>
                    Send
                </button>

            </form>

        </div>

    </section>


    <!-- Floating Button -->
    <button type="button" class="wewc-trigger" aria-expanded="false" aria-controls="wewc-chat-panel">

        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
            <path
                d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8A8.5 8.5 0 0 1 12.5 3H13a8.5 8.5 0 0 1 8 8v.5z" />
        </svg>

        <span>
            <?php echo esc_html($button_text); ?>
        </span>

    </button>

</div>

<?php
}

add_action('wp_footer', 'wewc_display_whatsapp_button');