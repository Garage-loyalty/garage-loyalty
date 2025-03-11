<?php
/**
 * Plugin Name: Auto Mechanic Loyalty - Pro Demo (Inbound + MonteurFlow + Kenteken + AI + APK + Twilio + RDW)
 * Description: Uitgebreide plugin met RDW-koppeling, WhatsApp inbound/outbound, AI (optioneel), APK-herinnering, bandenwissels, AI per garage, koppeling inkomende berichten en monteur-flow.
 * Version: 2.9
 * Author: Jack Wullems
 */

if (!defined('ABSPATH')) exit;

// Laad de Composer autoloader (voor Twilio e.a.)
require __DIR__ . '/vendor/autoload.php';

// Includebestanden
require __DIR__ . '/includes/database.php';
require __DIR__ . '/includes/api.php';
require __DIR__ . '/includes/whatsapp.php';
require __DIR__ . '/includes/mechanic-flow.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/portal.php';

// Hooks voor activering en deactivering
register_activation_hook(__FILE__, 'aml_pro_install_plugin');
register_deactivation_hook(__FILE__, 'aml_pro_deactivate_plugin');

// Versie-check (upgrade routine)
add_action('plugins_loaded', 'aml_pro_check_update');

// Eventueel je CSS-bestand inladen
function aml_enqueue_styles() {
    // WordPress voegt automatisch een versie-query toe, tenzij je een tweede parameter meegeeft
    wp_enqueue_style(
        'aml-style',
        plugin_dir_url(__FILE__) . 'assets/css/style.css',
        array(),
        '2.7'
    );
}
add_action('wp_enqueue_scripts', 'aml_enqueue_styles');