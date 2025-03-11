<?php
if (!defined('ABSPATH')) exit;

/**
 * Checkt of de plugin geüpdatet is en voert install (DB upgrade) uit indien nodig.
 */
function aml_pro_check_update(){
    $current_version = '2.7';
    $saved_version   = get_option('aml_plugin_version','');
    if ($saved_version !== $current_version) {
        aml_pro_install_plugin();
        update_option('aml_plugin_version', $current_version);
    }
}

/**
 * Aangeroepen bij (re)activatie van de plugin.
 * Creëert of wijzigt de DB-tabellen en zet standaardopties.
 */
function aml_pro_install_plugin(){
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    // Tabelnamen
    $tb_garages        = $wpdb->prefix . 'aml_garages';
    $tb_customers      = $wpdb->prefix . 'aml_customers';
    $tb_vehicles       = $wpdb->prefix . 'aml_vehicles';
    $tb_messages       = $wpdb->prefix . 'aml_messages';
    $tb_ai_lines       = $wpdb->prefix . 'aml_ai_lines';
    $tb_mech_sessions  = $wpdb->prefix . 'aml_mechanic_sessions';

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // Tabel: Garages
    $sql_garages = "
        CREATE TABLE IF NOT EXISTS $tb_garages (
          garage_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          manager_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
          garage_name VARCHAR(200) NOT NULL,
          address VARCHAR(255) DEFAULT '',
          postcode VARCHAR(20) DEFAULT '',
          city VARCHAR(100) DEFAULT '',
          phone VARCHAR(20) DEFAULT '',
          monteur_phone VARCHAR(30) DEFAULT '',
          offers_loaner_cars TINYINT(1) DEFAULT 0,
          calendly_link VARCHAR(255) DEFAULT '',
          weather_latitude DECIMAL(10,6) DEFAULT NULL,
          weather_longitude DECIMAL(10,6) DEFAULT NULL,
          apk_template TEXT DEFAULT NULL,
          winter_tires_template TEXT DEFAULT NULL,
          summer_tires_template TEXT DEFAULT NULL,
          ai_extra_context TEXT DEFAULT NULL,
          PRIMARY KEY (garage_id)
        ) $charset;
    ";
    dbDelta($sql_garages);

    // Check of 'monteur_phone' kolom al bestaat, anders toevoegen
    $cols = $wpdb->get_results("SHOW COLUMNS FROM $tb_garages");
    $existing_columns = array_map(function($c){ return $c->Field; }, $cols);
    if (!in_array('monteur_phone', $existing_columns)) {
        $wpdb->query("ALTER TABLE $tb_garages ADD COLUMN monteur_phone VARCHAR(30) DEFAULT ''");
    }

    // Tabel: Klanten
    dbDelta("
        CREATE TABLE IF NOT EXISTS $tb_customers (
          customer_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          garage_id BIGINT UNSIGNED NOT NULL,
          first_name VARCHAR(100) NOT NULL,
          last_name VARCHAR(100) NOT NULL,
          phone VARCHAR(20) NOT NULL,
          email VARCHAR(100) DEFAULT '',
          PRIMARY KEY (customer_id),
          KEY (garage_id)
        ) $charset;
    ");

    // Tabel: Voertuigen
    dbDelta("
        CREATE TABLE IF NOT EXISTS $tb_vehicles (
          vehicle_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          customer_id BIGINT UNSIGNED NOT NULL,
          garage_id BIGINT UNSIGNED NOT NULL,
          license_plate VARCHAR(20) NOT NULL,
          make VARCHAR(100) DEFAULT '',
          model VARCHAR(100) DEFAULT '',
          fuel_type ENUM('benzine','diesel','elektrisch') DEFAULT 'benzine',
          build_year YEAR NOT NULL,
          last_service_date DATE DEFAULT NULL,
          last_service_mileage INT DEFAULT 0,
          mileage INT DEFAULT 0,
          annual_mileage INT DEFAULT 0,
          current_tire_type ENUM('summer','winter') DEFAULT 'summer',
          tire_info VARCHAR(200) DEFAULT '',
          apk_due_date DATE DEFAULT NULL,
          PRIMARY KEY (vehicle_id),
          KEY (customer_id),
          KEY (garage_id)
        ) $charset;
    ");

    // Tabel: Berichten
    dbDelta("
        CREATE TABLE IF NOT EXISTS $tb_messages (
          message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          customer_id BIGINT UNSIGNED NOT NULL,
          vehicle_id BIGINT UNSIGNED NOT NULL,
          garage_id BIGINT UNSIGNED NOT NULL,
          message_type VARCHAR(50) DEFAULT '',
          message_content TEXT,
          direction ENUM('inbound','outbound') DEFAULT 'outbound',
          status ENUM('pending','sent','failed','received') DEFAULT 'pending',
          scheduled_date DATETIME DEFAULT NULL,
          sent_date DATETIME DEFAULT NULL,
          fail_reason TEXT,
          PRIMARY KEY (message_id),
          KEY (customer_id),
          KEY (vehicle_id),
          KEY (garage_id)
        ) $charset;
    ");

    // Tabel: AI Lines
    dbDelta("
        CREATE TABLE IF NOT EXISTS $tb_ai_lines (
          line_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          garage_id BIGINT UNSIGNED NOT NULL,
          line_title VARCHAR(200) NOT NULL,
          line_content TEXT DEFAULT NULL,
          PRIMARY KEY (line_id),
          KEY (garage_id)
        ) $charset;
    ");

    // Tabel: monteur-sessies
    dbDelta("
        CREATE TABLE IF NOT EXISTS $tb_mech_sessions (
            session_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            phone VARCHAR(30) NOT NULL,
            current_step TINYINT NOT NULL DEFAULT 0,
            license_plate VARCHAR(30) DEFAULT '',
            customer_name VARCHAR(150) DEFAULT '',
            km_stand INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (session_id),
            KEY (phone)
        ) $charset;
    ");

    // Standaard plugin-opties instellen als ze niet bestaan
    if (get_option('aml_apk_reminder_days') === false)        add_option('aml_apk_reminder_days','30');
    if (get_option('aml_weather_api_key') === false)          add_option('aml_weather_api_key','');
    if (get_option('aml_twilio_sid') === false)               add_option('aml_twilio_sid','');
    if (get_option('aml_twilio_token') === false)             add_option('aml_twilio_token','');
    if (get_option('aml_twilio_whatsapp_number') === false)   add_option('aml_twilio_whatsapp_number','');
    if (get_option('aml_current_season') === false)           add_option('aml_current_season','summer');
    if (get_option('aml_rdw_app_token') === false)            add_option('aml_rdw_app_token','');
    if (get_option('aml_test_whatsapp_enabled') === false)    add_option('aml_test_whatsapp_enabled','no');
    if (get_option('aml_notification_method') === false)      add_option('aml_notification_method','whatsapp');
    if (get_option('aml_openai_api_key') === false)           add_option('aml_openai_api_key','');

    // User role aanmaken
    add_role('garage_manager', 'Garage Manager', [
        'read'      => true,
        'edit_posts'=> false
    ]);

    // Cron job aanmaken
    aml_setup_cron_test();
}

/**
 * Uitvoeren bij deactiveren
 */
function aml_pro_deactivate_plugin(){
    // Verwijdert alleen de schedule, data blijft bestaan
    wp_clear_scheduled_hook('aml_daily_cron_hook');
}

/**
 * Voegt een (test) interval van 2 minuten toe aan cron_schedules
 */
add_filter('cron_schedules','aml_add_test_schedule');
function aml_add_test_schedule($schedules){
    $schedules['every_two_minutes'] = [
        'interval' => 120,
        'display'  => 'Elke 2 minuten (TEST)'
    ];
    return $schedules;
}

/**
 * Plant onze testcron in als hij nog niet bestaat.
 */
function aml_setup_cron_test(){
    if (!wp_next_scheduled('aml_daily_cron_hook')) {
        // Hier dus een test-interval, in productie zou je 'daily' kunnen gebruiken
        wp_schedule_event(time(), 'every_two_minutes','aml_daily_cron_hook');
    }
}