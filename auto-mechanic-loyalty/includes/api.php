<?php
if (!defined('ABSPATH')) exit;

/**
 * Hier zetten we o.a. de cron-taken en RDW/Weather API-logica.
 */

// Hook de dagelijkse (of test-interval) cron
add_action('aml_daily_cron_hook','aml_run_daily_tasks');

/**
 * Voert de dagelijkse taken uit:
 * - APK-herinneringen plannen
 * - Bandenwissel-check
 * - Pending berichtjes versturen
 */
function aml_run_daily_tasks(){
    error_log("🔥 aml_run_daily_tasks() START!");
    aml_schedule_apk_reminders();
    aml_check_tire_changes_for_all_garages();
    aml_send_pending_messages();
    error_log("✅ aml_run_daily_tasks() END!");
}

/**
 * Stuurt pending APK-herinneringen.
 */
function aml_schedule_apk_reminders(){
    global $wpdb;
    $tb_veh = $wpdb->prefix.'aml_vehicles';
    $tb_cus = $wpdb->prefix.'aml_customers';
    $tb_msg = $wpdb->prefix.'aml_messages';
    $tb_gar = $wpdb->prefix.'aml_garages';

    $apk_days = (int)get_option('aml_apk_reminder_days','30');
    $today    = date('Y-m-d');

    $vehicles = $wpdb->get_results("
        SELECT v.*, c.first_name, c.last_name, g.garage_name, g.apk_template,
               v.license_plate, v.make, v.model, v.apk_due_date
        FROM $tb_veh v
        JOIN $tb_cus c ON v.customer_id = c.customer_id
        JOIN $tb_gar g ON v.garage_id   = g.garage_id
    ");
    if (!$vehicles) return;

    foreach ($vehicles as $v) {
        // Bepaal de APK-datum (of herbereken)
        if (!empty($v->apk_due_date) && $v->apk_due_date != '0000-00-00') {
            $next_apk = $v->apk_due_date;
        } else {
            $next_apk = aml_calculate_next_apk_date($v->build_year, $v->fuel_type);
        }
        $diff = (strtotime($next_apk) - strtotime($today)) / 86400;
        if ($diff > 0 && $diff <= $apk_days) {
            // check of er al een reminder is voor vandaag
            $exists = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM $tb_msg
                WHERE vehicle_id=%d
                AND message_type='apk_reminder'
                AND status IN('pending','sent')
                AND DATE(scheduled_date)=%s
            ", $v->vehicle_id, $today));

            if (!$exists) {
                // Maak berichtje
                $template = $v->apk_template ?: "Beste {first_name}, je {make} {model} ({license_plate}) moet vóór {apk_due_date} APK gekeurd worden.";
                $search   = ['{first_name}','{last_name}','{license_plate}','{make}','{model}','{apk_due_date}','{garage_name}'];
                $replace  = [$v->first_name,$v->last_name,$v->license_plate,$v->make,$v->model,$next_apk,$v->garage_name];
                $msg      = str_replace($search, $replace, $template);

                $wpdb->insert($tb_msg, [
                    'customer_id'    => $v->customer_id,
                    'vehicle_id'     => $v->vehicle_id,
                    'garage_id'      => $v->garage_id,
                    'message_type'   => 'apk_reminder',
                    'message_content'=> $msg,
                    'status'         => 'pending',
                    'scheduled_date' => current_time('mysql'),
                    'direction'      => 'outbound'
                ]);
            }
        }
    }
}

/**
 * Hulpfunctie: berekent volgende APK-datum
 */
function aml_calculate_next_apk_date($build_year, $fuel_type){
    $this_year = (int)date('Y');
    $car_age   = $this_year - (int)$build_year;
    $now       = time();

    if ($fuel_type == 'diesel') {
        if($car_age < 3){
            $yrs  = 3 - $car_age;
            $next = strtotime("+$yrs year", $now);
        } else {
            $next = strtotime("+1 year", $now);
        }
    } else {
        if($car_age < 4){
            $yrs  = 4 - $car_age;
            $next = strtotime("+$yrs year", $now);
        } elseif($car_age < 8){
            $next = strtotime("+2 year", $now);
        } else {
            $next = strtotime("+1 year", $now);
        }
    }
    return date('Y-m-d', $next);
}

/**
 * Checkt of de bandenwissel nodig is (temperatuur check).
 */
function aml_check_tire_changes_for_all_garages(){
    global $wpdb;
    $tb_gar = $wpdb->prefix.'aml_garages';
    $garages = $wpdb->get_results("SELECT * FROM $tb_gar");
    if (!$garages) return;

    foreach($garages as $g){
        $res = aml_check_tire_change_conditions($g);
        if($res && $res['change_needed'] === true){
            aml_schedule_tire_change_notifications($g, $res['new_season']);
        }
    }
}

/**
 * Checkt het weer, indien <7 graden => winter, >7 => zomer
 */
function aml_check_tire_change_conditions($garage){
    if(empty($garage->weather_latitude) || empty($garage->weather_longitude)){
        return false;
    }
    $data = aml_get_weather_data($garage->weather_latitude, $garage->weather_longitude);
    if(!$data) return false;

    $temp_sum = 0;
    $count    = 0;
    foreach($data['list'] as $fc){
        if($count>=8) break; // bv. eerste 8x3h ~ 24 uur
        $temp_sum += $fc['main']['temp'];
        $count++;
    }
    if($count==0) return false;

    $avg_temp = $temp_sum / $count;
    $cur_season = get_option('aml_current_season','summer');

    if($avg_temp <= 7 && $cur_season=='summer'){
        return ['change_needed'=>true, 'new_season'=>'winter'];
    } elseif($avg_temp>7 && $cur_season=='winter'){
        return ['change_needed'=>true, 'new_season'=>'summer'];
    }
    return ['change_needed'=>false];
}

/**
 * Haalt weer op via OpenWeather (5-daagse forecast)
 */
function aml_get_weather_data($lat, $lon){
    $key = get_option('aml_weather_api_key','');
    if (!$key) return false;

    $url  = "https://api.openweathermap.org/data/2.5/forecast?lat=$lat&lon=$lon&units=metric&lang=nl&appid=$key";
    $resp = wp_remote_get($url);
    if(is_wp_error($resp)) return false;

    $body = wp_remote_retrieve_body($resp);
    $json = json_decode($body, true);
    if(!$json || empty($json['list'])) return false;

    return $json;
}

/**
 * Stuurt bandenwissel-notificaties naar alle klanten van deze garage.
 */
function aml_schedule_tire_change_notifications($garage, $new_season){
    global $wpdb;
    $tb_cus = $wpdb->prefix.'aml_customers';
    $tb_veh = $wpdb->prefix.'aml_vehicles';
    $tb_msg = $wpdb->prefix.'aml_messages';

    $winter_tmpl = $garage->winter_tires_template 
        ?: "Beste {first_name}, het wordt kouder! Tijd voor winterbanden op je {make} {model} ({license_plate}).";
    $summer_tmpl = $garage->summer_tires_template 
        ?: "Beste {first_name}, het wordt warmer! Tijd voor zomerbanden op je {make} {model} ({license_plate}).";

    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT c.*, v.vehicle_id, v.license_plate, v.make, v.model, v.current_tire_type
        FROM $tb_cus c
        JOIN $tb_veh v ON c.customer_id=v.customer_id
        WHERE c.garage_id=%d
    ", $garage->garage_id));

    if(!$rows) return;

    $to_type  = ($new_season=='winter') ? 'winter' : 'summer';
    $msg_type = ($new_season=='winter') ? 'winter_tires' : 'summer_tires';

    foreach($rows as $r){
        if($r->current_tire_type == $to_type) continue; // al de juiste banden

        $template = ($new_season=='winter') ? $winter_tmpl : $summer_tmpl;

        $search  = ['{first_name}','{last_name}','{license_plate}','{make}','{model}','{garage_name}'];
        $replace = [$r->first_name,$r->last_name,$r->license_plate,$r->make,$r->model,$garage->garage_name];
        $txt     = str_replace($search, $replace, $template);

        if($garage->calendly_link){
            $txt .= " Plan via: {$garage->calendly_link}";
        }
        if($garage->offers_loaner_cars){
            $txt .= " Reageer 'JA' voor leenauto.";
        }

        // Opslaan in messages
        $wpdb->insert($tb_msg, [
            'customer_id'     => $r->customer_id,
            'vehicle_id'      => $r->vehicle_id,
            'garage_id'       => $garage->garage_id,
            'message_type'    => $msg_type,
            'message_content' => $txt,
            'status'          => 'pending',
            'scheduled_date'  => current_time('mysql'),
            'direction'       => 'outbound'
        ]);
    }

    // Zet de huidige season
    update_option('aml_current_season', $new_season);
}

/**
 * Stuurt alle 'pending' berichten via de ingestelde methode (WhatsApp / e-mail / both).
 */
function aml_send_pending_messages(){
    global $wpdb;
    $tb = $wpdb->prefix.'aml_messages';
    $rows = $wpdb->get_results("
        SELECT * FROM $tb
        WHERE status='pending'
        ORDER BY message_id ASC
        LIMIT 50
    ");
    if(!$rows) return;

    $method = get_option('aml_notification_method','whatsapp');

    foreach($rows as $m){
        $cust = aml_get_customer($m->customer_id);
        if(!$cust){
            $wpdb->update($tb, [
                'status'     => 'failed',
                'sent_date'  => current_time('mysql'),
                'fail_reason'=> 'Geen klantinfo'
            ], ['message_id'=>$m->message_id]);
            continue;
        }

        // WhatsApp?
        $whatsapp_ok = true;
        if (in_array($method, ['whatsapp','both'])) {
            $res = aml_whatsapp_send_message_to_phone($cust->phone, $m->message_content);
            $whatsapp_ok = ($res === true);
        }

        // E-mail?
        $email_ok = true;
        if (in_array($method, ['email','both'])) {
            if(!aml_send_email_fallback($cust->email,$m->message_content)){
                $email_ok = false;
            }
        }

        // Bepalen status
        if($method === 'whatsapp'){
            if($whatsapp_ok){
                $wpdb->update($tb, [
                    'status'     => 'sent',
                    'sent_date'  => current_time('mysql'),
                    'fail_reason'=> ''
                ], ['message_id'=>$m->message_id]);
            } else {
                // Fallback e-mail
                if(aml_send_email_fallback($cust->email,$m->message_content)){
                    $wpdb->update($tb, [
                        'status'     => 'sent',
                        'sent_date'  => current_time('mysql'),
                        'fail_reason'=> 'WhatsApp mislukt, e-mail fallback'
                    ], ['message_id'=>$m->message_id]);
                } else {
                    $wpdb->update($tb, [
                        'status'     => 'failed',
                        'sent_date'  => current_time('mysql'),
                        'fail_reason'=> 'WhatsApp + e-mail mislukt'
                    ], ['message_id'=>$m->message_id]);
                }
            }
        } elseif($method === 'email'){
            if($email_ok){
                $wpdb->update($tb, [
                    'status'     => 'sent',
                    'sent_date'  => current_time('mysql'),
                    'fail_reason'=> ''
                ], ['message_id'=>$m->message_id]);
            } else {
                $wpdb->update($tb, [
                    'status'     => 'failed',
                    'sent_date'  => current_time('mysql'),
                    'fail_reason'=> 'E-mail mislukt'
                ], ['message_id'=>$m->message_id]);
            }
        } else { // both
            if($whatsapp_ok && $email_ok){
                $wpdb->update($tb, [
                    'status'     => 'sent',
                    'sent_date'  => current_time('mysql'),
                    'fail_reason'=> ''
                ], ['message_id'=>$m->message_id]);
            } else {
                $reason = "WhatsApp=".($whatsapp_ok?'OK':'NOK').", Email=".($email_ok?'OK':'NOK');
                $wpdb->update($tb, [
                    'status'     => 'failed',
                    'sent_date'  => current_time('mysql'),
                    'fail_reason'=> $reason
                ], ['message_id'=>$m->message_id]);
            }
        }
    }
}

/**
 * Haalt klantinfo op
 */
function aml_get_customer($customer_id){
    global $wpdb;
    $tb_cust= $wpdb->prefix.'aml_customers';
    return $wpdb->get_row($wpdb->prepare("
        SELECT * FROM $tb_cust
        WHERE customer_id=%d
    ", $customer_id));
}

/**
 * Stuurt e-mail (fallback).
 */
function aml_send_email_fallback($email, $content){
    if(!$email) return false;
    return wp_mail($email, "Auto Service Herinnering", $content);
}

/*-------------------------------------------------------------------
   RDW AJAX / Data
-------------------------------------------------------------------*/

/**
 * AJAX-endpoint voor RDW lookup
 */
add_action('wp_ajax_aml_rdw_lookup','aml_ajax_rdw_lookup');
function aml_ajax_rdw_lookup(){
    if(!is_user_logged_in()){
        wp_send_json_error(['msg'=>'Niet ingelogd'],403);
    }
    $plate = isset($_POST['plate']) ? sanitize_text_field($_POST['plate']) : '';
    if(!$plate){
        wp_send_json_error(['msg'=>'Geen kenteken meegestuurd']);
    }
    $info = aml_get_vehicle_data_rdw($plate);
    if($info){
        wp_send_json_success($info);
    } else {
        wp_send_json_error(['msg'=>'Geen gegevens gevonden of API-fout.']);
    }
}

/**
 * RDW-lookup via opendata.rdw.nl
 */
function aml_get_vehicle_data_rdw($license_plate){
    $license_plate = strtoupper(preg_replace('/[^A-Za-z0-9]/','',$license_plate));
    $app_token     = get_option('aml_rdw_app_token','');
    $api_url       = "https://opendata.rdw.nl/resource/m9d7-ebf2.json?kenteken=".urlencode($license_plate);

    $args = [];
    if($app_token){
       $args['headers'] = ['X-App-Token' => $app_token];
    }
    $resp = wp_remote_get($api_url, $args);
    if(is_wp_error($resp)) return false;
    if(wp_remote_retrieve_response_code($resp) != 200) return false;

    $body = wp_remote_retrieve_body($resp);
    $data = json_decode($body,true);
    if(!empty($data) && is_array($data)){
        $raw       = $data[0] ?? [];
        $raw_datum = $raw['datum_eerste_toelating'] ?? '';
        $bouwjaar  = (strlen($raw_datum)>=4) ? substr($raw_datum,0,4) : '';
        $brandstof = $raw['brandstof_omschrijving'] ?? '';
        $apk_date_sql = '';

        if(!empty($raw['vervaldatum_apk']) && strlen($raw['vervaldatum_apk'])==8){
            $apk = $raw['vervaldatum_apk'];
            $y   = substr($apk,0,4);
            $m   = substr($apk,4,2);
            $d   = substr($apk,6,2);
            $apk_date_sql = "$y-$m-$d";
        }
        return [
            'bouwjaar'  => $bouwjaar,
            'brandstof' => $brandstof,
            'model'     => $raw['handelsbenaming'] ?? '',
            'merk'      => $raw['merk'] ?? '',
            'apk_date'  => $apk_date_sql
        ];
    }
    return false;
}