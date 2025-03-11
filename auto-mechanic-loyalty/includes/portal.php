<?php
if (!defined('ABSPATH')) exit;

/**
 * Portal-functionaliteit: /garage-login en /garage rewrite + content
 */

/*-------------------------------------------------------------------
   1. Rewrite rules
-------------------------------------------------------------------*/
add_action('init','aml_portal_rewrite_rules');
function aml_portal_rewrite_rules(){
    add_rewrite_rule('garage-login/?$', 'index.php?aml_garage_login=1','top');
    add_rewrite_rule('garage/?$',       'index.php?aml_garage_portal=1','top');

    // Inbound webhook
    add_rewrite_rule('twilio-inbound/?$', 'index.php?aml_twilio_inbound=1','top');
}

add_filter('query_vars', function($vars){
    $vars[] = 'aml_garage_login';
    $vars[] = 'aml_garage_portal';
    $vars[] = 'aml_twilio_inbound';
    return $vars;
});

add_action('template_redirect', function(){
    if(get_query_var('aml_garage_login')){
        aml_render_garage_login_page();
        exit;
    }
    if(get_query_var('aml_garage_portal')){
        aml_render_garage_portal_page();
        exit;
    }
    if(get_query_var('aml_twilio_inbound')){
        // Wordt opgepakt door aml_process_incoming_whatsapp() (whatsapp.php)
        aml_process_incoming_whatsapp();
        exit;
    }
});

/*-------------------------------------------------------------------
   2. Garage Login/Registratie
-------------------------------------------------------------------*/
function aml_render_garage_login_page(){
    if(is_user_logged_in() && (current_user_can('garage_manager') || current_user_can('administrator'))){
        wp_safe_redirect(home_url('/garage'));
        exit;
    }

    // Inloggen
    if(isset($_POST['aml_login_submit'])){
        check_admin_referer('aml_login_action','aml_login_nonce');
        $user = wp_signon([
            'user_login'    => sanitize_text_field($_POST['user_login']),
            'user_password' => sanitize_text_field($_POST['user_pass']),
            'remember'      => true
        ], false);
        if(is_wp_error($user)){
            echo "<div style='color:red;margin:20px;'>Fout bij inloggen: ".esc_html($user->get_error_message())."</div>";
        } else {
            if(user_can($user,'garage_manager') || user_can($user,'administrator')){
                wp_safe_redirect(home_url('/garage'));
                exit;
            } else {
                echo "<div style='color:red;margin:20px;'>Je hebt geen garage-manager rechten.</div>";
            }
        }
    }

    // Registreren
    if(isset($_POST['aml_register_submit'])){
        check_admin_referer('aml_reg_action','aml_reg_nonce');
        $username   = sanitize_user($_POST['user_login_reg']);
        $email      = sanitize_email($_POST['user_email_reg']);
        $pass       = sanitize_text_field($_POST['user_pass_reg']);
        $garageName = sanitize_text_field($_POST['garage_name_reg']);

        if(username_exists($username) || email_exists($email)){
            echo "<div style='color:red;margin:20px;'>Gebruikersnaam of e-mail bestaat al.</div>";
        } else {
            $user_id = wp_create_user($username, $pass, $email);
            if(is_wp_error($user_id)){
                echo "<div style='color:red;margin:20px;'>Fout bij registreren: ".esc_html($user_id->get_error_message())."</div>";
            } else {
                $usr = new WP_User($user_id);
                $usr->set_role('garage_manager');

                global $wpdb;
                $tb = $wpdb->prefix.'aml_garages';
                $wpdb->insert($tb, [
                    'manager_user_id'=> $user_id,
                    'garage_name'    => $garageName
                ]);

                // Auto inloggen
                wp_set_current_user($user_id);
                wp_set_auth_cookie($user_id,true);
                wp_safe_redirect(home_url('/garage'));
                exit;
            }
        }
    }

    ?>
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset="utf-8"/>
      <title>Garage Login / Registratie</title>
    </head>
    <body>
    <div class="aml-container">
      <h1>Garage Login</h1>
      <form method="post">
        <?php wp_nonce_field('aml_login_action','aml_login_nonce'); ?>
        <label>Gebruikersnaam</label>
        <input type="text" name="user_login" required/>
        <label>Wachtwoord</label>
        <input type="password" name="user_pass" required/>
        <button type="submit" name="aml_login_submit">Inloggen</button>
      </form>

      <hr/>
      <h2>Registreren als Nieuwe Garage</h2>
      <form method="post">
        <?php wp_nonce_field('aml_reg_action','aml_reg_nonce'); ?>
        <label>Gebruikersnaam</label>
        <input type="text" name="user_login_reg" required/>
        <label>E-mail</label>
        <input type="email" name="user_email_reg" required/>
        <label>Wachtwoord</label>
        <input type="password" name="user_pass_reg" required/>
        <label>Garage Naam</label>
        <input type="text" name="garage_name_reg" required/>
        <button type="submit" name="aml_register_submit">Registreren</button>
      </form>
    </div>
    </body>
    </html>
    <?php
    exit;
}

/*-------------------------------------------------------------------
   3. Garage Portal (/garage)
-------------------------------------------------------------------*/
function aml_render_garage_portal_page(){
    if(!is_user_logged_in() || (!current_user_can('garage_manager') && !current_user_can('administrator'))){
        wp_safe_redirect(home_url('/garage-login'));
        exit;
    }
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'garage_info';

    ?>
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset="utf-8"/>
      <title>Mijn Garage Beheer</title>
    </head>
    <body>
    <div class="aml-garage-portal">
      <div class="aml-garage-sidebar">
        <h2>Garage Beheer</h2>
        <ul>
          <li><a class="<?php echo ($tab=='garage_info'?'active':'');?>"
                 href="?aml_garage_portal=1&tab=garage_info">Garage Info</a></li>
          <li><a class="<?php echo ($tab=='customers'?'active':'');?>"
                 href="?aml_garage_portal=1&tab=customers">Klanten</a></li>
          <li><a class="<?php echo ($tab=='vehicles'?'active':'');?>"
                 href="?aml_garage_portal=1&tab=vehicles">Voertuigen</a></li>
          <li><a class="<?php echo ($tab=='messages'?'active':'');?>"
                 href="?aml_garage_portal=1&tab=messages">Berichten</a></li>
          <li><a class="<?php echo ($tab=='ai_info'?'active':'');?>"
                 href="?aml_garage_portal=1&tab=ai_info">AI Info</a></li>
          <li><a href="<?php echo wp_logout_url(home_url('/garage-login')); ?>">Uitloggen</a></li>
        </ul>
      </div>
      <div class="aml-garage-content">
        <?php
        switch($tab){
          case 'garage_info': aml_portal_garage_info();   break;
          case 'customers':   aml_portal_customers();     break;
          case 'vehicles':    aml_portal_vehicles();      break;
          case 'messages':    aml_portal_messages();      break;
          case 'ai_info':     aml_portal_ai_info();       break;
          default:            aml_portal_garage_info();   break;
        }
        ?>
      </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Hulpje: garage ophalen van huidige user
 */
function aml_get_current_user_garage(){
    global $wpdb;
    $tb  = $wpdb->prefix.'aml_garages';
    $uid = get_current_user_id();
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $tb WHERE manager_user_id=%d",$uid));
}

/*-------------------------------------------------------------------
   3a. Garage Info Tab
-------------------------------------------------------------------*/
function aml_portal_garage_info(){
    global $wpdb;
    $tb     = $wpdb->prefix.'aml_garages';
    $garage = aml_get_current_user_garage();

    if(isset($_POST['aml_save_garage_info'])){
        check_admin_referer('aml_save_garage_info_action','aml_save_garage_info_nonce');
        $gName   = sanitize_text_field($_POST['garage_name']);
        $addr    = sanitize_text_field($_POST['address']);
        $pc      = sanitize_text_field($_POST['postcode']);
        $city    = sanitize_text_field($_POST['city']);
        $phone   = aml_normalize_phone_number($_POST['phone']);
        $mPhone  = aml_normalize_phone_number($_POST['monteur_phone']);
        $loaners = isset($_POST['offers_loaner_cars']) ? 1 : 0;
        $cal     = esc_url_raw($_POST['calendly_link']);

        if(!$garage){
            $wpdb->insert($tb, [
                'manager_user_id'   => get_current_user_id(),
                'garage_name'       => $gName,
                'address'           => $addr,
                'postcode'          => $pc,
                'city'              => $city,
                'phone'             => $phone,
                'monteur_phone'     => $mPhone,
                'offers_loaner_cars'=> $loaners,
                'calendly_link'     => $cal
            ]);
            echo "<div class='success'>Garage-info aangemaakt.</div>";
        } else {
            $wpdb->update($tb, [
                'garage_name'       => $gName,
                'address'           => $addr,
                'postcode'          => $pc,
                'city'              => $city,
                'phone'             => $phone,
                'monteur_phone'     => $mPhone,
                'offers_loaner_cars'=> $loaners,
                'calendly_link'     => $cal
            ], ['garage_id'=>$garage->garage_id]);
            echo "<div class='success'>Garage-info bijgewerkt.</div>";
        }

        // Herlaad
        $garage = aml_get_current_user_garage();
    }
    ?>
    <h2>Garage Info</h2>
    <form method="post">
      <?php wp_nonce_field('aml_save_garage_info_action','aml_save_garage_info_nonce'); ?>
      <label>Garage Naam</label>
      <input type="text" name="garage_name" value="<?php echo esc_attr($garage->garage_name??'');?>" required/>

      <label>Adres</label>
      <input type="text" name="address" value="<?php echo esc_attr($garage->address??'');?>"/>

      <label>Postcode</label>
      <input type="text" name="postcode" value="<?php echo esc_attr($garage->postcode??'');?>"/>

      <label>Stad</label>
      <input type="text" name="city" value="<?php echo esc_attr($garage->city??'');?>"/>

      <label>Telefoon (Garage)</label>
      <input type="text" name="phone" value="<?php echo esc_attr($garage->phone??'');?>"/>

      <label>Telefoon (Monteur)</label>
      <input type="text" name="monteur_phone" value="<?php echo esc_attr($garage->monteur_phone??'');?>"/>

      <label>
        <input type="checkbox" name="offers_loaner_cars" <?php if(!empty($garage->offers_loaner_cars)) echo 'checked';?>/>
        Leenauto beschikbaar
      </label>
      <br/><br/>

      <label>Calendly link</label>
      <input type="text" name="calendly_link" value="<?php echo esc_attr($garage->calendly_link??'');?>"/>

      <hr/>
      <h3>Berichtsjablonen (optioneel)</h3>
      <label>APK-herinnering (sjabloon)</label>
      <textarea name="apk_template" rows="4" style="width:100%;"><?php echo esc_textarea($garage->apk_template??'');?></textarea>

      <label>Winterbanden (sjabloon)</label>
      <textarea name="winter_tires_template" rows="4" style="width:100%;"><?php echo esc_textarea($garage->winter_tires_template??'');?></textarea>

      <label>Zomerbanden (sjabloon)</label>
      <textarea name="summer_tires_template" rows="4" style="width:100%;"><?php echo esc_textarea($garage->summer_tires_template??'');?></textarea>

      <br/>
      <button type="submit" name="aml_save_garage_info">Opslaan</button>
    </form>
    <?php
}

/*-------------------------------------------------------------------
   3b. Klanten Tab
-------------------------------------------------------------------*/
function aml_portal_customers(){
    global $wpdb;
    $garage = aml_get_current_user_garage();
    if(!$garage){
        echo "<div class='error'>Geen garage-info.</div>";
        return;
    }
    $tb = $wpdb->prefix.'aml_customers';

    // Delete
    if(isset($_GET['delete_customer'])){
        $cid = intval($_GET['delete_customer']);
        $wpdb->delete($tb, [
            'customer_id'=> $cid,
            'garage_id'  => $garage->garage_id
        ]);
        echo "<div class='success'>Klant verwijderd.</div>";
    }

    // Save
    if(isset($_POST['aml_save_customer'])){
        check_admin_referer('aml_save_customer_action','aml_save_customer_nonce');
        $cid   = intval($_POST['customer_id']);
        $fname = sanitize_text_field($_POST['first_name']);
        $lname = sanitize_text_field($_POST['last_name']);
        $phone = aml_normalize_phone_number($_POST['phone']);
        $email = sanitize_email($_POST['email']);

        if($cid == 0){
            $wpdb->insert($tb, [
                'garage_id' => $garage->garage_id,
                'first_name'=> $fname,
                'last_name' => $lname,
                'phone'     => $phone,
                'email'     => $email
            ]);
            echo "<div class='success'>Klant toegevoegd.</div>";
        } else {
            $wpdb->update($tb, [
                'first_name'=> $fname,
                'last_name' => $lname,
                'phone'     => $phone,
                'email'     => $email
            ], [
                'customer_id'=> $cid,
                'garage_id'  => $garage->garage_id
            ]);
            echo "<div class='success'>Klant bijgewerkt.</div>";
        }
    }

    $edit_cust = null;
    if(isset($_GET['edit_customer'])){
        $cid = intval($_GET['edit_customer']);
        $edit_cust = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM $tb 
            WHERE customer_id=%d AND garage_id=%d
        ", $cid, $garage->garage_id));
    }

    $customers = $wpdb->get_results($wpdb->prepare("
        SELECT * FROM $tb
        WHERE garage_id=%d
        ORDER BY last_name, first_name
    ", $garage->garage_id));
    ?>
    <h2>Klanten</h2>
    <table>
      <thead>
        <tr><th>Naam</th><th>Telefoon</th><th>Email</th><th>Actie</th></tr>
      </thead>
      <tbody>
      <?php
      if($customers){
        foreach($customers as $c){
            echo "<tr>
                <td>".esc_html($c->first_name.' '.$c->last_name)."</td>
                <td>".esc_html($c->phone)."</td>
                <td>".esc_html($c->email)."</td>
                <td>
                  <a href='?aml_garage_portal=1&tab=customers&edit_customer=$c->customer_id'>Bewerken</a> |
                  <a href='?aml_garage_portal=1&tab=customers&delete_customer=$c->customer_id' onclick='return confirm(\"Zeker?\");'>Verwijderen</a>
                </td>
            </tr>";
        }
      }
      ?>
      </tbody>
    </table>

    <hr/>
    <h3><?php echo $edit_cust ? 'Klant bijwerken':'Klant toevoegen'; ?></h3>
    <form method="post">
      <?php wp_nonce_field('aml_save_customer_action','aml_save_customer_nonce'); ?>
      <input type="hidden" name="customer_id" value="<?php echo intval($edit_cust->customer_id??0);?>"/>
      <label>Voornaam</label>
      <input type="text" name="first_name" 
             value="<?php echo esc_attr($edit_cust->first_name??'');?>" required/>
      <label>Achternaam</label>
      <input type="text" name="last_name" 
             value="<?php echo esc_attr($edit_cust->last_name??'');?>" required/>
      <label>Telefoon</label>
      <input type="text" name="phone" 
             value="<?php echo esc_attr($edit_cust->phone??'');?>" required/>
      <label>E-mail</label>
      <input type="email" name="email" 
             value="<?php echo esc_attr($edit_cust->email??'');?>"/>
      <button type="submit" name="aml_save_customer">Opslaan</button>
    </form>
    <?php
}

/*-------------------------------------------------------------------
   3c. Voertuigen Tab
-------------------------------------------------------------------*/
function aml_portal_vehicles(){
    global $wpdb;
    $garage = aml_get_current_user_garage();
    if(!$garage){
        echo "<div class='error'>Geen garage-info.</div>";
        return;
    }
    $tb_cust = $wpdb->prefix.'aml_customers';
    $tb_veh  = $wpdb->prefix.'aml_vehicles';

    // Delete
    if(isset($_GET['delete_vehicle'])){
        $vid = intval($_GET['delete_vehicle']);
        $wpdb->delete($tb_veh, [
            'vehicle_id'=> $vid,
            'garage_id' => $garage->garage_id
        ]);
        echo "<div class='success'>Voertuig verwijderd.</div>";
    }

    // Save
    if(isset($_POST['aml_save_vehicle'])){
        check_admin_referer('aml_save_vehicle_action','aml_save_vehicle_nonce');
        $vid       = intval($_POST['vehicle_id']);
        $cust_id   = intval($_POST['customer_id']);
        $plate     = sanitize_text_field($_POST['license_plate']);
        $make      = sanitize_text_field($_POST['make']);
        $model     = sanitize_text_field($_POST['model']);
        $fuel      = sanitize_text_field($_POST['fuel_type']);
        $year      = intval($_POST['build_year']);
        $mileage   = intval($_POST['mileage']);
        $annual    = intval($_POST['annual_mileage']);
        $tire_type = sanitize_text_field($_POST['current_tire_type']);
        $tire_info = sanitize_text_field($_POST['tire_info']);
        $apk_due   = sanitize_text_field($_POST['apk_due_date'] ?? '');

        if($vid==0){
            $wpdb->insert($tb_veh, [
                'customer_id'      => $cust_id,
                'garage_id'        => $garage->garage_id,
                'license_plate'    => $plate,
                'make'             => $make,
                'model'            => $model,
                'fuel_type'        => $fuel,
                'build_year'       => $year,
                'mileage'          => $mileage,
                'annual_mileage'   => $annual,
                'current_tire_type'=> $tire_type,
                'tire_info'        => $tire_info,
                'apk_due_date'     => $apk_due ?: null
            ]);
            echo "<div class='success'>Voertuig toegevoegd.</div>";
        } else {
            $wpdb->update($tb_veh, [
                'customer_id'      => $cust_id,
                'license_plate'    => $plate,
                'make'             => $make,
                'model'            => $model,
                'fuel_type'        => $fuel,
                'build_year'       => $year,
                'mileage'          => $mileage,
                'annual_mileage'   => $annual,
                'current_tire_type'=> $tire_type,
                'tire_info'        => $tire_info,
                'apk_due_date'     => $apk_due ?: null
            ], [
                'vehicle_id'=> $vid,
                'garage_id' => $garage->garage_id
            ]);
            echo "<div class='success'>Voertuig bijgewerkt.</div>";
        }
    }

    $edit_veh = null;
    if(isset($_GET['edit_vehicle'])){
        $vid = intval($_GET['edit_vehicle']);
        $edit_veh = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM $tb_veh
            WHERE vehicle_id=%d AND garage_id=%d
        ", $vid, $garage->garage_id));
    }

    $vehicles = $wpdb->get_results($wpdb->prepare("
        SELECT v.*, c.first_name, c.last_name
        FROM $tb_veh v
        JOIN $tb_cust c ON v.customer_id=c.customer_id
        WHERE v.garage_id=%d
        ORDER BY v.vehicle_id DESC
    ", $garage->garage_id));

    $customers = $wpdb->get_results($wpdb->prepare("
        SELECT * FROM $tb_cust
        WHERE garage_id=%d
        ORDER BY last_name, first_name
    ", $garage->garage_id));

    // Voorbeeld-lijstje voor bandenprofiel
    $tireOptions = [];
    for($t=18;$t<=95;$t++){
        $val = ($t/10).' mm';
        $tireOptions[] = $val;
    }
    ?>
    <h2>Voertuigen</h2>
    <table>
      <thead>
        <tr>
          <th>Klant</th>
          <th>Kenteken</th>
          <th>Merk/Model</th>
          <th>Bouwjaar</th>
          <th>KM-stand</th>
          <th>Banden</th>
          <th>Actie</th>
        </tr>
      </thead>
      <tbody>
      <?php
      if($vehicles){
        foreach($vehicles as $v){
          $cust = esc_html($v->first_name.' '.$v->last_name);
          echo "<tr>
            <td>{$cust}</td>
            <td>".esc_html($v->license_plate)."</td>
            <td>".esc_html($v->make.' '.$v->model)."</td>
            <td>".esc_html($v->build_year)."</td>
            <td>".esc_html($v->mileage).' km'."</td>
            <td>".esc_html($v->current_tire_type.' / '.$v->tire_info)."</td>
            <td>
              <a href='?aml_garage_portal=1&tab=vehicles&edit_vehicle=$v->vehicle_id'>Bewerken</a> |
              <a href='?aml_garage_portal=1&tab=vehicles&delete_vehicle=$v->vehicle_id' onclick='return confirm(\"Zeker?\");'>Verwijderen</a>
            </td>
          </tr>";
        }
      }
      ?>
      </tbody>
    </table>
    <hr/>
    <h3><?php echo $edit_veh ? 'Voertuig bijwerken':'Voertuig toevoegen';?></h3>
    <form method="post" id="aml-vehicle-form">
      <?php wp_nonce_field('aml_save_vehicle_action','aml_save_vehicle_nonce'); ?>
      <input type="hidden" name="vehicle_id" value="<?php echo intval($edit_veh->vehicle_id??0);?>"/>

      <label>Klant</label>
      <select name="customer_id" required>
        <option value="">-- Kies Klant --</option>
        <?php
        foreach($customers as $c){
            $sel = ($edit_veh && $edit_veh->customer_id==$c->customer_id) ? 'selected' : '';
            echo "<option value='$c->customer_id' $sel>".esc_html($c->first_name.' '.$c->last_name)."</option>";
        }
        ?>
      </select>

      <label>Kenteken</label>
      <div style="display:flex; gap:10px;">
        <input type="text" name="license_plate" id="license_plate"
               value="<?php echo esc_attr($edit_veh->license_plate??'');?>" required style="flex:1;"/>
        <button type="button" id="btn-rdw-lookup">Zoek RDW</button>
      </div>

      <label>Merk</label>
      <input type="text" name="make" id="make" value="<?php echo esc_attr($edit_veh->make??''); ?>"/>

      <label>Model</label>
      <input type="text" name="model" id="model" value="<?php echo esc_attr($edit_veh->model??''); ?>"/>

      <label>Brandstof</label>
      <select name="fuel_type" id="fuel_type">
        <option value="benzine"    <?php if($edit_veh && $edit_veh->fuel_type=='benzine') echo 'selected';?>>Benzine</option>
        <option value="diesel"     <?php if($edit_veh && $edit_veh->fuel_type=='diesel') echo 'selected';?>>Diesel</option>
        <option value="elektrisch" <?php if($edit_veh && $edit_veh->fuel_type=='elektrisch') echo 'selected';?>>Elektrisch</option>
      </select>

      <label>Bouwjaar</label>
      <input type="number" name="build_year" id="build_year"
             value="<?php echo esc_attr($edit_veh->build_year??date('Y')); ?>"/>

      <label>Huidige km-stand</label>
      <input type="number" name="mileage" 
             value="<?php echo esc_attr($edit_veh->mileage??0); ?>"/>

      <label>Geschatte km/jaar</label>
      <input type="number" name="annual_mileage" 
             value="<?php echo esc_attr($edit_veh->annual_mileage??0); ?>"/>

      <label>Huidige banden</label>
      <select name="current_tire_type">
        <option value="summer" <?php if($edit_veh && $edit_veh->current_tire_type=='summer') echo 'selected';?>>Zomer</option>
        <option value="winter" <?php if($edit_veh && $edit_veh->current_tire_type=='winter') echo 'selected';?>>Winter</option>
      </select>

      <label>Banden info (profiel mm)</label>
      <select name="tire_info">
        <option value="">-- Kies profieldiepte --</option>
        <?php
        foreach($tireOptions as $opt){
          $sel = ($edit_veh && $edit_veh->tire_info==$opt) ? 'selected' : '';
          echo "<option value='$opt' $sel>$opt</option>";
        }
        ?>
      </select>

      <label>APK-vervaldatum</label>
      <input type="date" name="apk_due_date" id="apk_due_date"
             value="<?php echo esc_attr($edit_veh->apk_due_date??''); ?>"/>

      <button type="submit" name="aml_save_vehicle">Opslaan</button>
    </form>

    <script>
    document.addEventListener('DOMContentLoaded', function(){
       var btn = document.getElementById('btn-rdw-lookup');
       if(btn){
         btn.addEventListener('click', function(){
           var plate = document.getElementById('license_plate').value.trim();
           if(!plate){
             alert('Voer een kenteken in.');
             return;
           }
           var formData = new FormData();
           formData.append('action','aml_rdw_lookup');
           formData.append('plate', plate);

           fetch('<?php echo admin_url('admin-ajax.php'); ?>',{
             method:'POST',
             body: formData
           })
           .then(r=>r.json())
           .then(data=>{
             if(data.success){
               document.getElementById('make').value   = data.data.merk   || '';
               document.getElementById('model').value  = data.data.model  || '';
               let fuel='benzine';
               if(data.data.brandstof && data.data.brandstof.toLowerCase().includes('diesel')){
                 fuel='diesel';
               } else if(data.data.brandstof && data.data.brandstof.toLowerCase().includes('electr')){
                 fuel='elektrisch';
               }
               document.getElementById('fuel_type').value = fuel;
               if(data.data.bouwjaar){
                 document.getElementById('build_year').value = data.data.bouwjaar;
               }
               if(data.data.apk_date){
                 document.getElementById('apk_due_date').value = data.data.apk_date;
               }
               alert('RDW-gegevens gevonden! Vergeet niet op "Opslaan" te klikken.');
             } else {
               alert('Fout of geen RDW-gegevens: '+data.data.msg);
             }
           })
           .catch(err => alert('Fout: '+err));
         });
       }
    });
    </script>
    <?php
}

/*-------------------------------------------------------------------
   3d. Berichten Tab
-------------------------------------------------------------------*/
function aml_portal_messages(){
    global $wpdb;
    $garage = aml_get_current_user_garage();
    if(!$garage){
        echo "<div class='error'>Geen garage-info.</div>";
        return;
    }

    // Handmatig antwoord sturen
    if(isset($_POST['aml_reply_submit'])){
        check_admin_referer('aml_reply_action','aml_reply_nonce');
        $customer_id = intval($_POST['customer_id']);
        $reply       = sanitize_textarea_field($_POST['reply_message'] ?? '');
        aml_send_whatsapp_message($garage->garage_id, $customer_id, $reply);
        echo "<div class='success'>Bericht verzonden!</div>";
    }

    ?>
    <h2>WhatsApp Berichten</h2>
    <form method="post">
      <?php wp_nonce_field('aml_reply_action','aml_reply_nonce'); ?>
      <label>Klant selecteren</label>
      <select name="customer_id">
        <?php
        $custs = $wpdb->get_results($wpdb->prepare("
          SELECT * FROM {$wpdb->prefix}aml_customers
          WHERE garage_id=%d
          ORDER BY last_name, first_name
        ", $garage->garage_id));
        foreach($custs as $c){
            echo "<option value='$c->customer_id'>"
                 .esc_html($c->first_name.' '.$c->last_name.' ('.$c->phone.')')
                 ."</option>";
        }
        ?>
      </select>

      <label>Bericht typen</label>
      <textarea name="reply_message" rows="3"></textarea>
      <button type="submit" name="aml_reply_submit">Verstuur WhatsApp</button>
    </form>
    <hr/>
    <?php

    $tb_msg = $wpdb->prefix.'aml_messages';
    $tb_cus = $wpdb->prefix.'aml_customers';
    $tb_veh = $wpdb->prefix.'aml_vehicles';

    // Laatste 100 berichten
    $messages = $wpdb->get_results($wpdb->prepare("
        SELECT m.*, c.first_name, c.last_name, c.phone, v.license_plate
        FROM $tb_msg m
        LEFT JOIN $tb_cus c ON m.customer_id=c.customer_id
        LEFT JOIN $tb_veh v ON m.vehicle_id=v.vehicle_id
        WHERE m.garage_id=%d
        ORDER BY m.message_id DESC
        LIMIT 100
    ", $garage->garage_id));

    ?>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Richting</th>
          <th>Status</th>
          <th>Klant / Telefoon</th>
          <th>Kenteken</th>
          <th>Bericht</th>
          <th>Datum</th>
          <th>Foutmelding</th>
        </tr>
      </thead>
      <tbody>
      <?php
      if($messages){
        foreach($messages as $m){
            $klantInfo = esc_html($m->first_name." ".$m->last_name)."<br/>".esc_html($m->phone);
            echo "<tr>
              <td>{$m->message_id}</td>
              <td>{$m->direction}</td>
              <td>{$m->status}</td>
              <td>{$klantInfo}</td>
              <td>".esc_html($m->license_plate)."</td>
              <td>".nl2br(esc_html($m->message_content))."</td>
              <td>{$m->sent_date}</td>
              <td>".esc_html($m->fail_reason)."</td>
            </tr>";
        }
      }
      ?>
      </tbody>
    </table>
    <?php
}

/*-------------------------------------------------------------------
   3e. AI Info Tab
-------------------------------------------------------------------*/
function aml_portal_ai_info(){
    global $wpdb;
    $garage = aml_get_current_user_garage();
    if(!$garage){
        echo "<div class='error'>Geen garage-info.</div>";
        return;
    }
    $tb_gar      = $wpdb->prefix.'aml_garages';
    $tb_ai_lines = $wpdb->prefix.'aml_ai_lines';

    // Extra context
    if(isset($_POST['aml_save_ai_context'])){
        check_admin_referer('aml_save_ai_context_action','aml_save_ai_context_nonce');
        $context = sanitize_textarea_field($_POST['ai_extra_context'] ?? '');
        $wpdb->update($tb_gar, [
            'ai_extra_context'=> $context
        ], [
            'garage_id'=> $garage->garage_id
        ]);
        $garage->ai_extra_context = $context;
        echo "<div class='success'>AI-Extra Context opgeslagen.</div>";
    }

    // Verwijderen
    if(isset($_GET['delete_line'])){
        $line_id = intval($_GET['delete_line']);
        $wpdb->delete($tb_ai_lines, [
            'line_id'   => $line_id,
            'garage_id' => $garage->garage_id
        ]);
        echo "<div class='success'>AI-regel verwijderd.</div>";
    }

    // Toevoegen/Bewerken
    if(isset($_POST['aml_save_ai_line'])){
        check_admin_referer('aml_save_ai_line_action','aml_save_ai_line_nonce');
        $line_id    = intval($_POST['line_id']);
        $line_title = sanitize_text_field($_POST['line_title'] ?? '');
        $line_cont  = sanitize_textarea_field($_POST['line_content'] ?? '');

        if($line_id>0){
            $wpdb->update($tb_ai_lines, [
                'line_title'   => $line_title,
                'line_content' => $line_cont
            ], [
                'line_id'   => $line_id,
                'garage_id' => $garage->garage_id
            ]);
            echo "<div class='success'>AI-regel bijgewerkt.</div>";
        } else {
            $wpdb->insert($tb_ai_lines, [
                'garage_id'    => $garage->garage_id,
                'line_title'   => $line_title,
                'line_content' => $line_cont
            ]);
            echo "<div class='success'>AI-regel toegevoegd.</div>";
        }
    }

    $edit_line = null;
    if(isset($_GET['edit_line'])){
        $edit_line_id = intval($_GET['edit_line']);
        $edit_line = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM $tb_ai_lines
            WHERE line_id=%d AND garage_id=%d
        ", $edit_line_id, $garage->garage_id));
    }

    $ai_lines = $wpdb->get_results($wpdb->prepare("
        SELECT * FROM $tb_ai_lines
        WHERE garage_id=%d
        ORDER BY line_id DESC
    ", $garage->garage_id));

    ?>
    <h2>AI Info & Extra Context</h2>
    <form method="post">
      <?php wp_nonce_field('aml_save_ai_context_action','aml_save_ai_context_nonce'); ?>
      <label>Algemene extra AI-context (bijv. historie, prijsindicaties, specialisaties)</label>
      <textarea name="ai_extra_context" rows="5" style="width:100%;"><?php echo esc_textarea($garage->ai_extra_context??''); ?></textarea>
      <br/>
      <button type="submit" name="aml_save_ai_context">Opslaan</button>
    </form>

    <hr/>
    <h3>AI Regels / Informatie</h3>
    <p>Losse regels toevoegen, zoals prijzen, acties of andere details.</p>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Titel</th>
          <th>Inhoud</th>
          <th>Actie</th>
        </tr>
      </thead>
      <tbody>
      <?php if($ai_lines): ?>
        <?php foreach($ai_lines as $line): ?>
          <tr>
            <td><?php echo $line->line_id; ?></td>
            <td><?php echo esc_html($line->line_title); ?></td>
            <td><?php echo nl2br(esc_html($line->line_content)); ?></td>
            <td>
              <a href="?aml_garage_portal=1&tab=ai_info&edit_line=<?php echo $line->line_id; ?>">Bewerken</a> |
              <a href="?aml_garage_portal=1&tab=ai_info&delete_line=<?php echo $line->line_id; ?>" onclick="return confirm('Weet je het zeker?');">Verwijderen</a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="4">Nog geen extra AI-regels.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>

    <hr/>
    <h4><?php echo $edit_line ? 'AI-regel bewerken':'AI-regel toevoegen'; ?></h4>
    <form method="post">
      <?php wp_nonce_field('aml_save_ai_line_action','aml_save_ai_line_nonce'); ?>
      <input type="hidden" name="line_id" value="<?php echo intval($edit_line->line_id??0);?>"/>
      <label>Titel</label>
      <input type="text" name="line_title" value="<?php echo esc_attr($edit_line->line_title??'');?>" required/>
      <label>Inhoud</label>
      <textarea name="line_content" rows="4" style="width:100%;"><?php echo esc_textarea($edit_line->line_content??'');?></textarea>
      <br/>
      <button type="submit" name="aml_save_ai_line">Opslaan</button>
    </form>
    <?php
}