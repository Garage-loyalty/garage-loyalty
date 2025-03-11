<?php
use Twilio\Rest\Client; // uit vendor/autoload

if (!defined('ABSPATH')) exit;

/**
 * Logica voor de Monteur Flow (foto kenteken -> OCR -> klant+voertuig aanmaken).
 */

/**
 * Verwerkt een binnenkomend bericht van de monteur
 */
function aml_process_incoming_mechanic_flow($from_phone, $text, $mediaUrl, $numMedia, $garage){
    global $wpdb;
    $tb_sessions = $wpdb->prefix.'aml_mechanic_sessions';

    // Zoek bestaande sessie
    $session = $wpdb->get_row($wpdb->prepare("
        SELECT * FROM $tb_sessions
        WHERE phone=%s
        ORDER BY session_id DESC
        LIMIT 1
    ", $from_phone));

    if (!$session) {
        // Maak nieuwe sessie
        $wpdb->insert($tb_sessions, ['phone'=>$from_phone, 'current_step'=>0]);
        $sessionId = $wpdb->insert_id;
        $session   = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tb_sessions WHERE session_id=%d",$sessionId));
    }
    $step = (int)$session->current_step;

    // Stap 0: vraag foto
    if($step === 0){
        if($numMedia > 0 && $mediaUrl){
            // OCR via GPT-4 Vision
            $plate = aml_chatgpt_ocr_whatsapp_image($mediaUrl);
            if($plate){
                $wpdb->update($tb_sessions, [
                    'license_plate'=> $plate,
                    'current_step' => 1
                ], ['session_id'=>$session->session_id]);

                aml_send_mechanic_reply($from_phone,
                    "Kenteken herkend als *$plate*.\n" .
                    "Geef nu de klantnaam (bijv. 'Jan Jansen')."
                );
            } else {
                aml_send_mechanic_reply($from_phone,
                    "Kon geen kenteken uitlezen. Stuur een duidelijke foto."
                );
            }
        } else {
            aml_send_mechanic_reply($from_phone,
                "Welkom monteur! Stuur svp een foto van het kenteken."
            );
        }
        return;
    }

    // Stap 1: klantnaam ontvangen
    if($step === 1){
        $custName = trim($text);
        if(!$custName){
            aml_send_mechanic_reply($from_phone, "Geldige klantnaam a.u.b.");
            return;
        }
        $wpdb->update($tb_sessions, [
            'customer_name'=> $custName,
            'current_step' => 2
        ], ['session_id'=>$session->session_id]);

        aml_send_mechanic_reply($from_phone,
            "Bedankt. Geef nu de *km-stand* op (bijv. 123000)."
        );
        return;
    }

    // Stap 2: km-stand
    if($step === 2){
        $km = intval($text);
        if($km < 1){
            aml_send_mechanic_reply($from_phone, "Ongeldige km-stand. Probeer opnieuw.");
            return;
        }
        $wpdb->update($tb_sessions, [
            'km_stand'    => $km,
            'current_step'=> 3
        ], ['session_id'=>$session->session_id]);

        // RDW-lookup
        $plate   = $session->license_plate;
        $rdwData = aml_get_vehicle_data_rdw($plate);
        if($rdwData){
            $merk = $rdwData['merk']  ?? '';
            $mdl  = $rdwData['model'] ?? '';
            $bjr  = $rdwData['bouwjaar']  ?? '';
            $apk  = $rdwData['apk_date']  ?? '';
            $msg  = "RDW-gegevens:\n*Merk*: $merk\n*Model*: $mdl\n*Bouwjaar*: $bjr\n*APK*: $apk\n\n";
            $msg .= "Kloppen deze gegevens?\nAntwoord 'ja' of 'stop'.";
        } else {
            $msg  = "Geen RDW-resultaat. Toch doorgaan?\nAntwoord 'ja' of 'stop'.";
        }
        aml_send_mechanic_reply($from_phone, $msg);
        return;
    }

    // Stap 3: 'ja' => aanmaken in DB
    if($step === 3){
        $lower = strtolower(trim($text));
        if($lower === 'ja'){
            $plate   = $session->license_plate;
            $cName   = $session->customer_name;
            $kmStand = $session->km_stand;
            $garage_id = $garage->garage_id;

            // Splits klantnaam
            $parts = explode(' ', $cName, 2);
            $fname = $parts[0] ?? $cName;
            $lname = $parts[1] ?? '';

            // 1) Nieuwe klant
            $wpdb->insert($wpdb->prefix.'aml_customers', [
                'garage_id'  => $garage_id,
                'first_name' => $fname,
                'last_name'  => $lname,
                'phone'      => $from_phone,
                'email'      => ''
            ]);
            $custId = $wpdb->insert_id;

            // 2) Nieuw voertuig
            $rdw   = aml_get_vehicle_data_rdw($plate);
            $make  = $rdw['merk']      ?? '';
            $model = $rdw['model']     ?? '';
            $bjr   = intval($rdw['bouwjaar'] ?? date('Y'));
            $apk   = $rdw['apk_date']  ?? null;

            $fuel = 'benzine';
            if(!empty($rdw['brandstof'])){
                $fL = strtolower($rdw['brandstof']);
                if(str_contains($fL,'diesel')){
                    $fuel = 'diesel';
                } elseif(str_contains($fL,'electr')){
                    $fuel = 'elektrisch';
                }
            }

            $wpdb->insert($wpdb->prefix.'aml_vehicles', [
                'customer_id'   => $custId,
                'garage_id'     => $garage_id,
                'license_plate' => $plate,
                'make'          => $make,
                'model'         => $model,
                'fuel_type'     => $fuel,
                'build_year'    => $bjr,
                'mileage'       => $kmStand,
                'apk_due_date'  => $apk
            ]);

            // Flow afronden
            $wpdb->update($tb_sessions, [
                'current_step'=> 4
            ], ['session_id'=>$session->session_id]);

            aml_send_mechanic_reply($from_phone,
                "Klant + Voertuig aangemaakt!\n" .
                "Klant: $cName\nKenteken: $plate\nKM-stand: $kmStand\n" .
                "Klaar!"
            );
        } else {
            // Afbreken
            aml_send_mechanic_reply($from_phone,
                "Invoer afgebroken. Stuur opnieuw een kentekenfoto om te beginnen."
            );
            $wpdb->delete($tb_sessions, ['session_id'=>$session->session_id]);
        }
        return;
    }

    // Stap >=4 => sessie is klaar
    if($numMedia > 0 && $mediaUrl){
        // Nieuwe foto => start nieuwe flow
        $wpdb->delete($tb_sessions, ['session_id'=>$session->session_id]);
        aml_process_incoming_mechanic_flow($from_phone, $text, $mediaUrl, $numMedia, $garage);
    } else {
        aml_send_mechanic_reply($from_phone, 
            "Sessie is al voltooid. Stuur opnieuw een kentekenfoto om te starten."
        );
    }
}

/*--------------------------------------------------
  Kleine helpers voor de monteurflow
--------------------------------------------------*/

/**
 * Verstuur antwoord naar de monteur
 */
function aml_send_mechanic_reply($to_phone, $text){
    $res = aml_whatsapp_send_message_to_phone($to_phone, $text);
    if($res !== true){
        error_log("MechanicFlow: Fout bij verzenden: $res");
    }
}

/**
 * Download de WhatsApp-afbeelding via Twilio en doe OCR met GPT-4 Vision
 */
function aml_chatgpt_ocr_whatsapp_image($media_url){
    $api_key = get_option('aml_openai_api_key','');
    if(!$api_key){
        error_log("OCR error: geen OpenAI API Key.");
        return '';
    }

    // 1) Download afbeelding van Twilio
    $base64_image = aml_download_whatsapp_image($media_url);
    if(!$base64_image){
        return '';
    }

    // 2) OCR via GPT-4 Vision
    // We doen hier een fictieve promptstructuur met "image_url" etc.;
    // In de praktijk is GPT-4 Vision alleen via ChatGPT web-interface beschikbaar
    // of via limited API. Dus zie dit als pseudo-code / concept.

    $payload = json_encode([
        "model" => "gpt-4-turbo",
        "messages" => [
            [
                "role"    => "user",
                "content" => [
                    ["type" => "text", "text" => "Wat is het kenteken in deze afbeelding? Geef alleen het kenteken terug zonder extra tekst."],
                    ["type" => "image_url", "image_url" => ["url" => "data:image/jpeg;base64," . $base64_image]]
                ]
            ]
        ],
        "max_tokens" => 10
    ]);

    $args = [
        'headers' => [
            "Content-Type"  => "application/json",
            "Authorization" => "Bearer $api_key"
        ],
        'body'    => $payload,
        'timeout' => 30
    ];

    $res = wp_remote_post("https://api.openai.com/v1/chat/completions", $args);

    if(is_wp_error($res)){
        error_log("OCR error: ".$res->get_error_message());
        return '';
    }
    $response_body = wp_remote_retrieve_body($res);
    error_log("OCR GPT-4 response: $response_body");

    $json  = json_decode($response_body,true);
    $plate = $json['choices'][0]['message']['content'] ?? '';
    $plate = strtoupper(preg_replace('/[^A-Z0-9-]/', '', $plate));

    if(!$plate){
        error_log("OCR error: geen geldige kenteken output.");
    }
    return $plate;
}

/**
 * Downloadt de afbeelding (mediaUrl) van Twilio
 * en geeft de base64-string terug of false
 */
function aml_download_whatsapp_image($media_url){
    $sid   = get_option('aml_twilio_sid');
    $token = get_option('aml_twilio_token');
    if(!$sid || !$token){
        return false;
    }

    $ch = curl_init($media_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_FAILONERROR, true);
    curl_setopt($ch, CURLOPT_USERPWD, "$sid:$token");
    $image_data = curl_exec($ch);
    $status     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err        = curl_error($ch);
    curl_close($ch);

    if($err || $status >= 400 || !$image_data){
        error_log("Fout bij downloaden WhatsApp-afbeelding. HTTP=$status, err=$err");
        return false;
    }
    return base64_encode($image_data);
}