<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin-schermen (menu, instellingen, test, etc.)
 */

// Admin menu
add_action('admin_menu','aml_pro_admin_menu');
function aml_pro_admin_menu(){
    add_menu_page(
        'Auto Mechanic - Dashboard',
        'Auto Mechanic',
        'manage_options',
        'aml-pro-dashboard',
        'aml_pro_dashboard_page',
        'dashicons-car',
        66
    );

    add_submenu_page(
        'aml-pro-dashboard',
        'Instellingen',
        'Instellingen',
        'manage_options',
        'aml-pro-settings',
        'aml_pro_settings_page'
    );

    $testEn = get_option('aml_test_whatsapp_enabled','no');
    if($testEn==='yes'){
        add_submenu_page(
            'aml-pro-dashboard',
            'Test WhatsApp',
            'Test WhatsApp',
            'manage_options',
            'aml-test-whatsapp',
            'aml_pro_test_whatsapp_page'
        );
    }

    add_submenu_page(
        'aml-pro-dashboard',
        'Test Interval Cron',
        'Test Interval Cron',
        'manage_options',
        'aml-test-interval-cron',
        'aml_test_interval_cron_page'
    );
}

/**
 * Hoofd Admin-pagina
 */
function aml_pro_dashboard_page(){
    ?>
    <div class="wrap">
      <h1>Auto Mechanic - Dashboard</h1>
      <p>Welkom bij de “Auto Mechanic” plugin. Gebruik de sub-menu’s voor de plugininstellingen, testfuncties of andere opties.</p>
      <hr/>
      <p><strong>In deze plugin:</strong></p>
      <ul style="list-style:disc; padding-left:20px;">
        <li>RDW-koppeling voor kentekengegevens.</li>
        <li>WhatsApp-berichten (Twilio) voor inkomend en uitgaand verkeer.</li>
        <li>AI-ondersteuning (OpenAI) voor automatische antwoorden.</li>
        <li>APK-herinneringen en bandenwisselnotificaties (cron).</li>
        <li>Monteur-flow op basis van monteur-telefoonnummer.</li>
      </ul>
      <p>Ga naar <em>Instellingen</em> om je API-keys en voorkeuren in te stellen.</p>
    </div>
    <?php
}

/**
 * Instellingen-pagina
 */
function aml_pro_settings_page(){
    if(!current_user_can('manage_options')) return;

    if(isset($_POST['aml_save_settings']) && check_admin_referer('aml_pro_settings_action','aml_pro_settings_nonce')){
        update_option('aml_apk_reminder_days',         sanitize_text_field($_POST['aml_apk_reminder_days']));
        update_option('aml_weather_api_key',           sanitize_text_field($_POST['aml_weather_api_key']));
        update_option('aml_twilio_sid',                sanitize_text_field($_POST['aml_twilio_sid']));
        update_option('aml_twilio_token',              sanitize_text_field($_POST['aml_twilio_token']));
        update_option('aml_twilio_whatsapp_number',    sanitize_text_field($_POST['aml_twilio_whatsapp_number']));
        update_option('aml_rdw_app_token',             sanitize_text_field($_POST['aml_rdw_app_token']));

        $test_en = (!empty($_POST['aml_test_whatsapp_enabled']) && $_POST['aml_test_whatsapp_enabled']=='yes') ? 'yes' : 'no';
        update_option('aml_test_whatsapp_enabled', $test_en);

        $method = in_array($_POST['aml_notification_method'], ['whatsapp','email','both']) 
                  ? $_POST['aml_notification_method'] 
                  : 'whatsapp';
        update_option('aml_notification_method', $method);

        update_option('aml_openai_api_key', sanitize_text_field($_POST['aml_openai_api_key'] ?? ''));

        echo '<div class="notice notice-success"><p>Instellingen opgeslagen.</p></div>';
    }

    $apk_days = get_option('aml_apk_reminder_days','30');
    $weather  = get_option('aml_weather_api_key','');
    $sid      = get_option('aml_twilio_sid','');
    $token    = get_option('aml_twilio_token','');
    $fromNr   = get_option('aml_twilio_whatsapp_number','');
    $rdwToken = get_option('aml_rdw_app_token','');
    $testEn   = get_option('aml_test_whatsapp_enabled','no');
    $method   = get_option('aml_notification_method','whatsapp');
    $openAi   = get_option('aml_openai_api_key','');

    ?>
    <div class="wrap">
      <h1>Auto Mechanic - Instellingen</h1>
      <form method="post">
        <?php wp_nonce_field('aml_pro_settings_action','aml_pro_settings_nonce'); ?>
        <table class="form-table">
          <tr>
            <th>Dagen voor APK-herinnering</th>
            <td><input type="number" name="aml_apk_reminder_days" value="<?php echo esc_attr($apk_days); ?>"/></td>
          </tr>
          <tr>
            <th>OpenWeatherMap API Key</th>
            <td><input type="text" name="aml_weather_api_key" value="<?php echo esc_attr($weather); ?>" size="60"/></td>
          </tr>
          <tr>
            <th>Twilio SID</th>
            <td><input type="text" name="aml_twilio_sid" value="<?php echo esc_attr($sid); ?>" size="60"/></td>
          </tr>
          <tr>
            <th>Twilio Token</th>
            <td><input type="text" name="aml_twilio_token" value="<?php echo esc_attr($token); ?>" size="60"/></td>
          </tr>
          <tr>
            <th>Twilio WhatsApp nummer</th>
            <td><input type="text" name="aml_twilio_whatsapp_number" value="<?php echo esc_attr($fromNr); ?>" size="60"/></td>
          </tr>
          <tr>
            <th>RDW App Token <small>(Socrata)</small></th>
            <td>
              <input type="text" name="aml_rdw_app_token" value="<?php echo esc_attr($rdwToken); ?>" size="60"/>
              <p class="description">Voor kenteken-lookup via opendata.rdw.nl.</p>
            </td>
          </tr>
          <tr>
            <th>Test WhatsApp?</th>
            <td>
              <label>
                <input type="checkbox" name="aml_test_whatsapp_enabled" value="yes" <?php checked($testEn,'yes'); ?> />
                Inschakelen
              </label>
            </td>
          </tr>
          <tr>
            <th>Methode voor berichtversturing</th>
            <td>
              <select name="aml_notification_method">
                <option value="whatsapp" <?php selected($method,'whatsapp');?>>Alleen WhatsApp</option>
                <option value="email"    <?php selected($method,'email');?>>Alleen e-mail</option>
                <option value="both"     <?php selected($method,'both');?>>Beide</option>
              </select>
            </td>
          </tr>
          <tr>
            <th>OpenAI API Key (optioneel)</th>
            <td>
              <input type="text" name="aml_openai_api_key" value="<?php echo esc_attr($openAi); ?>" size="60"/>
              <p class="description">Vul een OpenAI-sleutel in als je AI-automatisering wilt gebruiken. (Bijv. GPT-4 Vision)</p>
            </td>
          </tr>
        </table>
        <p><button type="submit" name="aml_save_settings" class="button button-primary">Opslaan</button></p>
      </form>
    </div>
    <?php
}

/**
 * Test WhatsApp pagina (indien aml_test_whatsapp_enabled = yes)
 */
function aml_pro_test_whatsapp_page(){
    if(!current_user_can('manage_options')) return;

    $testEn = get_option('aml_test_whatsapp_enabled','no');
    if($testEn !== 'yes'){
        echo "<div class='wrap'><h1>Test WhatsApp uitgeschakeld</h1>";
        echo "<p>Je hebt de testmodus niet ingeschakeld. Ga naar 'Auto Mechanic' > 'Instellingen' en vink 'Test WhatsApp' aan.</p>";
        echo "</div>";
        return;
    }

    if(isset($_POST['aml_do_test_whatsapp']) && check_admin_referer('aml_do_test_whatsapp_action','aml_do_test_whatsapp_nonce')){
        $phone   = sanitize_text_field($_POST['test_phone'] ?? '');
        $message = sanitize_text_field($_POST['test_message'] ?? '');
        if(!$phone || !$message){
            echo '<div class="notice notice-error"><p>Telefoonnummer en bericht zijn vereist.</p></div>';
        } else {
            $res = aml_whatsapp_send_message_to_phone($phone, $message);
            if($res === true){
                echo '<div class="notice notice-success"><p>Testbericht succesvol verzonden naar '.esc_html($phone).'</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Fout bij versturen: '.esc_html($res).'</p></div>';
            }
        }
    }

    ?>
    <div class="wrap">
      <h1>Test WhatsApp Versturen</h1>
      <form method="post">
        <?php wp_nonce_field('aml_do_test_whatsapp_action','aml_do_test_whatsapp_nonce'); ?>
        <table class="form-table">
          <tr>
            <th>Telefoonnummer</th>
            <td><input type="text" name="test_phone" placeholder="+31612345678" required/></td>
          </tr>
          <tr>
            <th>Bericht</th>
            <td><textarea name="test_message" rows="4" cols="50" placeholder="Hoi, testbericht!"></textarea></td>
          </tr>
        </table>
        <p><button type="submit" name="aml_do_test_whatsapp" class="button button-primary">Verstuur testbericht</button></p>
      </form>
    </div>
    <?php
}

/**
 * Test Interval Cron pagina
 */
function aml_test_interval_cron_page(){
    if(!current_user_can('manage_options')) return;

    if(isset($_POST['aml_force_cron']) && check_admin_referer('aml_force_cron_action','aml_force_cron_nonce')){
        aml_run_daily_tasks(); // uit api.php
        echo "<div class='notice notice-success'><p>Cron taken nu handmatig uitgevoerd. Check debug.log / error_log!</p></div>";
    }
    ?>
    <div class="wrap">
      <h1>Test Interval Cron</h1>
      <p>Hier kun je handmatig de cron-taken starten (zoals APK, bandenwissel e.d.).</p>
      <form method="post">
        <?php wp_nonce_field('aml_force_cron_action','aml_force_cron_nonce'); ?>
        <button class="button button-primary" type="submit" name="aml_force_cron">Cron taken nu uitvoeren</button>
      </form>
    </div>
    <?php
}