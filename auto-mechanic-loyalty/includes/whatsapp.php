<?php
use Twilio\Rest\Client; // uit vendor/autoload

if (!defined('ABSPATH')) exit;

/**
 * Alle WhatsApp-functionaliteit: Inbound + Outbound + AI
 */

/**
 * Normaliseert NL telefoonnummers (past +31 aan, etc.)
 */
function aml_normalize_phone_number($phone){
    $phone = trim($phone);
    // Verwijder alle tekens behalve +, cijfers
    $phone = preg_replace('/[^0-9+]/','',$phone);

    // Simpele NL-logica
    // Voorbeeld: 06 -> +316
    if (substr($phone,0,2) === '06') {
        $phone = '+316'.substr($phone,2);
    } elseif (substr($phone,0,3) === '316') {
        $phone = '+'.$phone;
    } elseif (!str_starts_with($phone,'+31')) {
        $phone = '+31'.ltrim($phone,'0');
    }
    return $phone;
}

/**
 * Outbound: rechtstreeks Twilio message versturen naar een phone
 * Retourneert true of een foutmelding (string)
 */
function aml_whatsapp_send_message_to_phone($to_phone, $msg){
    $sid   = get_option('aml_twilio_sid','');
    $token = get_option('aml_twilio_token','');
    $from  = get_option('aml_twilio_whatsapp_number','');
    if(!$sid || !$token || !$from){
        return "Twilio-gegevens niet ingevuld.";
    }
    $to_phone = aml_normalize_phone_number($to_phone);

    try {
        $twilio = new Client($sid, $token);
        $twilio->messages->create("whatsapp:".$to_phone, [
            'from' => "whatsapp:".$from,
            'body' => $msg
        ]);
        return true;
    } catch (\Exception $ex){
        return $ex->getMessage();
    }
}

/**
 * Outbound: verstuurt en logt in aml_messages
 */
function aml_send_whatsapp_message($garage_id, $customer_id, $message_body){
    global $wpdb;

    // Klant opzoeken
    $cust = aml_get_customer($customer_id);
    if(!$cust) return false;

    // Huidige vehicle_id (laatste van klant)
    $vehicle_id = $wpdb->get_var($wpdb->prepare("
        SELECT vehicle_id 
        FROM {$wpdb->prefix}aml_vehicles
        WHERE customer_id=%d
        ORDER BY vehicle_id DESC
        LIMIT 1
    ", $cust->customer_id));
    if (!$vehicle_id) $vehicle_id = 0;

    // WhatsApp versturen
    $res = aml_whatsapp_send_message_to_phone($cust->phone, $message_body);
    $sent_ok = ($res === true);

    // Berichten-log
    $wpdb->insert($wpdb->prefix.'aml_messages', [
        'customer_id'    => $cust->customer_id,
        'vehicle_id'     => $vehicle_id,
        'garage_id'      => $garage_id,
        'message_type'   => 'outbound_whatsapp',
        'message_content'=> $message_body,
        'direction'      => 'outbound',
        'scheduled_date' => current_time('mysql'),
        'sent_date'      => current_time('mysql'),
        'status'         => $sent_ok ? 'sent' : 'failed',
        'fail_reason'    => $sent_ok ? '' : $res
    ]);

    return $sent_ok;
}

/*-------------------------------------------------------------------
   INBOUND TWILIO: webhook /twilio-inbound/
-------------------------------------------------------------------*/

// Als de rewrite voor twilio-inbound is gezet in (portal of elders), zullen we hierop reageren:
function aml_process_incoming_whatsapp(){
    error_log("🚀 Twilio Webhook - inbound WhatsApp.");

    $from_phone = isset($_POST['From']) ? str_replace('whatsapp:', '', $_POST['From']) : '';
    $message    = isset($_POST['Body']) ? $_POST['Body'] : '';
    $mediaUrl   = $_POST['MediaUrl0'] ?? '';
    $numMedia   = intval($_POST['NumMedia'] ?? '0');

    if (!$from_phone) {
        status_header(200);
        echo 'No from_phone';
        return;
    }

    $normFrom = aml_normalize_phone_number($from_phone);

    // Check of dit nummer een monteur-telefoon is
    global $wpdb;
    $tb_gar = $wpdb->prefix.'aml_garages';
    $monteurGarage = $wpdb->get_row($wpdb->prepare("
        SELECT * FROM $tb_gar WHERE monteur_phone=%s
    ", $normFrom));

    if ($monteurGarage) {
        // Monteur flow
        aml_process_incoming_mechanic_flow($normFrom, $message, $mediaUrl, $numMedia, $monteurGarage);
        status_header(200);
        echo 'OK - mechanic flow';
        exit;
    }

    // Anders => klantflow
    aml_process_incoming_customer_flow($normFrom, $message);
    status_header(200);
    echo 'OK';
    exit;
}

/**
 * Klantflow: bericht loggen en AI-antwoord sturen (indien openAI-sleutel)
 */
function aml_process_incoming_customer_flow($from_phone, $message){
    global $wpdb;

    // Zoeken of we deze klant kennen
    $cust = $wpdb->get_row($wpdb->prepare("
        SELECT c.*, c.garage_id
        FROM {$wpdb->prefix}aml_customers c
        WHERE c.phone=%s
        ORDER BY c.customer_id DESC
        LIMIT 1
    ", $from_phone));

    if (!$cust) {
        // Als onbekend => negeer of je zou 'auto-registreren'?
        error_log("Onbekende klant inbound WhatsApp: $from_phone");
        return;
    }

    // Vehicle id
    $vehicle_id = $wpdb->get_var($wpdb->prepare("
        SELECT vehicle_id 
        FROM {$wpdb->prefix}aml_vehicles
        WHERE customer_id=%d
        ORDER BY vehicle_id DESC
        LIMIT 1
    ", $cust->customer_id));
    if(!$vehicle_id) $vehicle_id=0;

    // Opslaan inbound bericht
    $wpdb->insert($wpdb->prefix.'aml_messages', [
        'customer_id'    => $cust->customer_id,
        'vehicle_id'     => $vehicle_id,
        'garage_id'      => $cust->garage_id,
        'message_type'   => 'inbound_whatsapp',
        'message_content'=> $message,
        'direction'      => 'inbound',
        'status'         => 'received',
        'scheduled_date' => current_time('mysql'),
        'sent_date'      => current_time('mysql')
    ]);

    // AI-antwoord
    aml_auto_reply_with_ai($cust->garage_id, $cust->customer_id, $message);
}

/**
 * AI-reply op binnenkomend bericht
 */
function aml_auto_reply_with_ai($garage_id, $customer_id, $incomingMsg){
    $api_key = get_option('aml_openai_api_key','');
    if(!$api_key){
        return; // geen AI-sleutel => geen auto-reply
    }

    global $wpdb;
    $garage = $wpdb->get_row($wpdb->prepare("
        SELECT * FROM {$wpdb->prefix}aml_garages
        WHERE garage_id=%d
    ", $garage_id));
    if(!$garage) return;

    $cust = aml_get_customer($customer_id);
    if(!$cust) return;

    // AI Lines uit DB
    $tb_ai = $wpdb->prefix.'aml_ai_lines';
    $lines = $wpdb->get_results($wpdb->prepare("
        SELECT line_title, line_content FROM $tb_ai
        WHERE garage_id=%d
    ", $garage_id));

    // System prompt
    $systemMsg = "Je bent een professionele autogarage genaamd '{$garage->garage_name}'.\n"
                ."Adres: {$garage->address}, {$garage->postcode} {$garage->city}.\n"
                ."Tel: {$garage->phone}.\n\n"
                ."AI Extra Context:\n".($garage->ai_extra_context ?: '(geen)');

    if($lines){
        $systemMsg.="\n\nAI Regels:\n";
        foreach($lines as $line){
            $systemMsg.="- {$line->line_title}: {$line->line_content}\n";
        }
    }
    $systemMsg.="\nKlant: {$cust->first_name} {$cust->last_name}, tel={$cust->phone}, mail={$cust->email}.\n";
    $systemMsg.="Antwoord als ervaren automonteur.\n";

    $messages = [
        ['role'=>'system','content'=>$systemMsg],
        ['role'=>'user','content'=>$incomingMsg]
    ];

    $reply = aml_call_openai_chat_api($api_key, $messages);
    if(!$reply) return;

    // Verstuur out via WhatsApp
    aml_send_whatsapp_message($garage_id, $customer_id, $reply);
}

/**
 * Aanroep OpenAI ChatCompletion (model GPT-3.5-turbo)
 */
function aml_call_openai_chat_api($api_key, $messages){
    $url = "https://api.openai.com/v1/chat/completions";
    $args = [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => "Bearer $api_key"
        ],
        'body' => json_encode([
            'model'     => 'gpt-3.5-turbo',
            'messages'  => $messages,
            'max_tokens'=> 200
        ]),
        'timeout' => 25
    ];
    $res = wp_remote_post($url, $args);

    if(is_wp_error($res)){
        error_log("OpenAI error: ".$res->get_error_message());
        return false;
    }
    $json = json_decode(wp_remote_retrieve_body($res), true);
    return $json['choices'][0]['message']['content'] ?? false;
}