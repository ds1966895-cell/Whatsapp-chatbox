
<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Create Live Chat Database Tables
 */
function wewc_create_chat_tables() {

    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    // Database Tables
    $conversations_table =
        $wpdb->prefix . 'wewc_conversations';

    $messages_table =
        $wpdb->prefix . 'wewc_messages';

    $outbox_table =
        $wpdb->prefix . 'wewc_outbox';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';


    /**
     * 1. Conversations Table
     */
    $sql_conversations = "CREATE TABLE $conversations_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        visitor_token_hash char(64) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'open',
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY visitor_token_hash (visitor_token_hash)
    ) $charset_collate;";


    /**
     * 2. Messages Table
     */
    $sql_messages = "CREATE TABLE $messages_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        conversation_id bigint(20) unsigned NOT NULL,
        sender varchar(20) NOT NULL,
        body longtext NOT NULL,
        external_message_id varchar(191) DEFAULT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY conversation_id (conversation_id),
        UNIQUE KEY external_message_id (external_message_id)
    ) $charset_collate;";


    /**
     * 3. WhatsApp Delivery Queue
     */
    $sql_outbox = "CREATE TABLE $outbox_table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        message_id bigint(20) unsigned NOT NULL,
        conversation_id bigint(20) unsigned NOT NULL,
        provider varchar(30) NOT NULL DEFAULT 'unconfigured',
        status varchar(20) NOT NULL DEFAULT 'pending',
        attempts int(11) NOT NULL DEFAULT 0,
        external_message_id varchar(191) DEFAULT NULL,
        last_error text NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY message_id (message_id),
        KEY conversation_id (conversation_id),
        KEY status (status),
        KEY provider_status (provider, status, updated_at)
    ) $charset_collate;";


    // Create or update existing tables
    dbDelta($sql_conversations);

    dbDelta($sql_messages);

    dbDelta($sql_outbox);


    // Save database version
    update_option(
        'wewc_db_version',
        '1.2.0',
        false
    );

}