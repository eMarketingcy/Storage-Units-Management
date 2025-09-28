<?php
// Make $database available inside the template.
if ( ! isset($database) || ! is_object($database) ) {
    if ( isset($this) && isset($this->database) ) {
        $database = $this->database;
    } else {
        if ( ! class_exists('SUM_Database') && defined('SUM_PLUGIN_PATH') ) {
            @require_once SUM_PLUGIN_PATH . 'includes/class-database.php';
        }
        if ( class_exists('SUM_Database') ) {
            $database = new SUM_Database();
        }
    }
}
?>

<div class="wrap">
    <h1>Storage Unit Manager Settings</h1>
    <?php
// Handle settings save
if ( isset($_POST['sum_settings_submit']) && check_admin_referer('sum_settings_nonce') ) {
    $database->update_setting('vat_enabled', !empty($_POST['sum_settings']['vat_enabled']) ? '1' : '0');
    $database->update_setting('vat_rate',    isset($_POST['sum_settings']['vat_rate']) ? (string) floatval($_POST['sum_settings']['vat_rate']) : '0');
    $database->update_setting('company_vat', isset($_POST['sum_settings']['company_vat']) ? sanitize_text_field($_POST['sum_settings']['company_vat']) : '');
    echo '<div class="updated"><p>Settings saved.</p></div>';
}
?>

    <form id="settings-form">
        <?php wp_nonce_field('sum_settings_nonce'); ?>
        <table class="form-table">
            <tr>
                <th scope="row">Frontend Access</th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text">Frontend Access Settings</legend>
                        <label for="allowed-roles">
                            <strong>Allowed User Roles</strong><br>
                            <input type="text" id="allowed-roles" name="allowed_roles" class="regular-text" 
                                   value="<?php echo esc_attr($this->get_setting('allowed_roles', 'administrator,storage_manager')); ?>">
                        </label>
                        <p class="description">Comma-separated list of user roles that can access the frontend (e.g., administrator,storage_manager). Users must be logged in to WordPress.</p>
                    </fieldset>
                </td>
            </tr>
            
            <tr>
                <th scope="row">Email Notifications</th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text">Email Notification Settings</legend>
                        <label for="email-enabled">
                            <input type="checkbox" id="email-enabled" name="email_enabled" value="1" 
                                   <?php checked($this->get_setting('email_enabled', '1'), '1'); ?>>
                            <strong>Enable Email Notifications</strong>
                        </label>
                        <p class="description">Send automatic email reminders before storage unit expiration.</p>
                        
                        <br><br>
                        
                        <label for="admin-email">
                            <strong>Admin Email</strong><br>
                            <input type="email" id="admin-email" name="admin_email" class="regular-text" 
                                   value="<?php echo esc_attr($this->get_setting('admin_email', get_option('admin_email'))); ?>">
                        </label>
                        <p class="description">Email address to receive admin notifications.</p>
                        
                        <br><br>
                        
                        <label for="email-subject-15">
                            <strong>15-Day Reminder Subject</strong><br>
                            <input type="text" id="email-subject-15" name="email_subject_15" class="regular-text" 
                                   value="<?php echo esc_attr($this->get_setting('email_subject_15', 'Storage Unit Reminder - 15 Days Until Expiration')); ?>">
                        </label>
                        
                        <br><br>
                        
                        <label for="email-subject-5">
                            <strong>5-Day Reminder Subject</strong><br>
                            <input type="text" id="email-subject-5" name="email_subject_5" class="regular-text" 
                                   value="<?php echo esc_attr($this->get_setting('email_subject_5', 'Storage Unit Reminder - 5 Days Until Expiration')); ?>">
                        </label>
                    </fieldset>
                </td>
            </tr>
            <tr>
  <th scope="row"><label for="sum_vat_enabled">Enable VAT</label></th>
  <td>
    <input type="checkbox" id="sum_vat_enabled" name="sum_settings[vat_enabled]" value="1"
      <?php checked( $database->get_setting('vat_enabled','0'), '1' ); ?> />
    <p class="description">Show VAT on invoices/payment page and add it to totals.</p>
  </td>
</tr>

<tr>
  <th scope="row"><label for="sum_vat_rate">VAT rate (%)</label></th>
  <td>
    <input type="number" step="0.01" min="0" id="sum_vat_rate" class="small-text"
      name="sum_settings[vat_rate]"
      value="<?php echo esc_attr( $database->get_setting('vat_rate','19') ); ?>" />
    <p class="description">Example: 19 for 19%</p>
  </td>
</tr>

<tr>
  <th scope="row"><label for="sum_company_vat">Company VAT / Tax ID</label></th>
  <td>
    <input type="text" id="sum_company_vat" class="regular-text"
      name="sum_settings[company_vat]"
      value="<?php echo esc_attr( $database->get_setting('company_vat','') ); ?>" />
  </td>
</tr>
<tr>
    <td>
        <p>
  <button type="button" class="button" id="sum-install-dompdf">Install Dompdf</button>
  <span class="description">Installs Dompdf into the plugin’s <code>lib/dompdf/</code> folder.</span>
</p>
<script>
jQuery(function($){
  $('#sum-install-dompdf').on('click', function(){
    if(!confirm('Install Dompdf now?')) return;
    $.post(ajaxurl, {
      action: 'sum_install_dompdf',
      nonce: '<?php echo wp_create_nonce('sum_nonce'); ?>'
    }, function(resp){
      alert(resp.success ? 'Dompdf installed.' : ('Error: ' + resp.data));
      location.reload();
    });
  });
});
</script>

    </td>
</tr>

        </table>
        
        <p class="submit">
            <button type="submit" class="button-primary">Save Settings</button>
        </p>
    </form>
    
    <div class="sum-info-box">
        <h3>Frontend Access Information</h3>
        <p><strong>Frontend Page URL:</strong> <a href="<?php echo home_url('/storage-units-manager/'); ?>" target="_blank"><?php echo home_url('/storage-units-manager/'); ?></a></p>
        <p>A page called "Storage Units Manager" has been automatically created with the shortcode [storage_units_frontend]. Users must be logged in to WordPress and have the appropriate role permissions to access the frontend interface.</p>
        
        <p>
            <button type="button" id="create-frontend-page" class="button button-secondary">
                Recreate Frontend Page
            </button>
            <span class="description">Click this if the frontend page is not working or missing.</span>
        </p>
    </div>
    
    <div class="sum-info-box">
        <h3>Email Notifications</h3>
        <p>The system automatically sends email reminders:</p>
        <ul>
            <li>15 days before unit expiration</li>
            <li>5 days before unit expiration</li>
        </ul>
        <p>Emails are sent to both the customer and the admin email address. The system uses WordPress cron to check daily for units approaching expiration.</p>
    </div>
    
    <div class="sum-info-box">
        <h3>User Role Management</h3>
        <p>The plugin creates a custom "Storage Manager" role. You can assign this role to users who should have access to the frontend interface.</p>
        <p>To assign roles:</p>
        <ol>
            <li>Go to Users > All Users in WordPress admin</li>
            <li>Edit a user</li>
            <li>Change their role to "Storage Manager" or ensure they have "Administrator" role</li>
        </ol>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    $('#settings-form').on('submit', function(e) {
        e.preventDefault();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: $(this).serialize() + '&action=sum_save_settings&nonce=' + '<?php echo wp_create_nonce('sum_nonce'); ?>',
            success: function(response) {
                if (response.success) {
                    alert('Settings saved successfully!');
                } else {
                    alert('Error saving settings: ' + response.data);
                }
            },
            error: function() {
                alert('Failed to save settings');
            }
        });
    });
    
    $('#create-frontend-page').on('click', function() {
        if (!confirm('This will recreate the frontend page. Continue?')) {
            return;
        }
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sum_create_frontend_page',
                nonce: '<?php echo wp_create_nonce('sum_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    alert('Success: ' + response.data);
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                alert('Failed to create frontend page');
            }
        });
    });
});
</script>

<style>
.sum-info-box {
    background: #fff;
    border: 1px solid #ddd;
    border-left: 4px solid #0073aa;
    padding: 15px;
    margin: 20px 0;
    border-radius: 4px;
}

.sum-info-box h3 {
    margin-top: 0;
    color: #333;
}

.sum-info-box ul {
    margin: 10px 0;
    padding-left: 20px;
}
</style>