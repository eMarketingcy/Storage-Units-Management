<div class="wrap">
    <h1>Email & Invoice Settings</h1>
    
    <form id="email-settings-form" enctype="multipart/form-data">
        <h2>Company Information</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Company Name</th>
                <td>
                    <input type="text" id="company-name" name="company_name" class="regular-text" 
                           value="<?php echo esc_attr($this->get_setting('company_name', 'Self Storage Cyprus')); ?>">
                </td>
            </tr>
            
            <tr>
                <th scope="row">Company Logo</th>
                <td>
                    <input type="file" id="company-logo" name="company_logo" accept="image/*">
                    <?php $logo_url = $this->get_setting('company_logo', ''); ?>
                    <?php if ($logo_url): ?>
                        <br><br>
                        <img src="<?php echo esc_url($logo_url); ?>" style="max-width: 200px; height: auto;">
                        <p class="description">Current logo</p>
                    <?php endif; ?>
                    <p class="description">Upload a logo for invoices and emails (recommended size: 200x100px)</p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">Company Address</th>
                <td>
                    <textarea id="company-address" name="company_address" rows="4" class="large-text"><?php echo esc_textarea($this->get_setting('company_address', '')); ?></textarea>
                    <p class="description">Full company address for invoices</p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">Company Phone</th>
                <td>
                    <input type="text" id="company-phone" name="company_phone" class="regular-text" 
                           value="<?php echo esc_attr($this->get_setting('company_phone', '')); ?>">
                </td>
            </tr>
            
            <tr>
                <th scope="row">Company Email</th>
                <td>
                    <input type="email" id="company-email" name="company_email" class="regular-text" 
                           value="<?php echo esc_attr($this->get_setting('company_email', get_option('admin_email'))); ?>">
                </td>
            </tr>
            
            <tr>
                <th scope="row">Company Website</th>
                <td>
                    <input type="url" id="company-website" name="company_website" class="regular-text" 
                           value="<?php echo esc_attr($this->get_setting('company_website', home_url())); ?>">
                </td>
            </tr>
        </table>
        
        <h2>Invoice Email Settings</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Invoice Email Subject</th>
                <td>
                    <input type="text" id="invoice-email-subject" name="invoice_email_subject" class="large-text" 
                           value="<?php echo esc_attr($this->get_setting('invoice_email_subject', 'Storage Unit Invoice')); ?>">
                </td>
            </tr>
            
            <tr>
                <th scope="row">Invoice Email Body</th>
                <td>
                    <?php
                    // MODERN INVOICE TEMPLATE (for admin editor default)
                    $default_body = '<div style="max-width: 600px; margin: 0 auto; padding: 20px; font-family: Arial, sans-serif; background-color: #ffffff; border: 1px solid #e0e0e0; border-radius: 8px;">

    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 20px;">
        <tr>
            <td style="text-align: center; background-color: #f7f7f7; padding: 15px 0; border-radius: 6px;">
                [sum_logo]
            </td>
        </tr>
    </table>

    <h2 style="margin-top: 0; margin-bottom: 20px; font-size: 24px; color: #f97316; border-bottom: 2px solid #f97316; padding-bottom: 10px;">
        Invoice for Unit: {unit_name}
    </h2>

    <p style="margin: 0 0 25px 0; font-size: 16px; line-height: 24px; color: #333333;">
        Dear <strong>{customer_name}</strong>,
    </p>

    <p style="margin: 0 0 30px 0; font-size: 16px; line-height: 24px; color: #555555;">
        Please find your invoice below. The official PDF is attached.
    </p>

    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 30px; border-collapse: collapse;">
        <tr>
            <td style="background-color: #f7f7f7; padding: 15px 20px; border-radius: 6px;">
                <p style="margin: 0; font-size: 18px; color: #555555;"><strong>Payment Due:</strong></p>
                <p style="margin: 5px 0 0 0; font-size: 28px; font-weight: bold; color: #10b981;">
                    €{payment_amount}
                </p>
            </td>
        </tr>
    </table>

    <div style="background-color: #f9f9f9; border: 1px solid #eeeeee; padding: 20px; border-radius: 8px; margin-bottom: 30px;">
        <p style="margin-top: 0; margin-bottom: 15px; font-size: 18px; font-weight: bold; color: #333333;">Unit Details</p>
        
        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse; font-size: 14px;">
            <tr>
                <td style="color: #555555; padding: 5px 0; width: 50%;">Unit:</td>
                <td style="font-weight: bold; color: #333333; text-align: right; padding: 5px 0; width: 50%;">{unit_name}</td>
            </tr>
            <tr>
                <td style="color: #555555; padding: 5px 0;">Size:</td>
                <td style="font-weight: bold; color: #333333; text-align: right; padding: 5px 0;">{unit_size}</td>
            </tr>
            <tr>
                <td style="color: #555555; padding: 5px 0;">Monthly Price:</td>
                <td style="font-weight: bold; color: #333333; text-align: right; padding: 5px 0;">€{monthly_price}</td>
            </tr>
            <tr>
                <td style="color: #555555; padding: 5px 0;">Billing Period:</td>
                <td style="font-weight: bold; color: #333333; text-align: right; padding: 5px 0;">{period_from} - {period_until}</td>
            </tr>
            <tr>
                <td style="color: #555555; padding: 5px 0; border-top: 1px solid #dddddd;"><strong>Status:</strong></td>
                <td style="font-weight: bold; color: #f97316; text-align: right; padding: 5px 0; border-top: 1px solid #dddddd;"><strong>{payment_status}</strong></td>
            </tr>
        </table>
    </div>

    <table width="100%" cellpadding="0" cellspacing="0" style="text-align: center; margin-bottom: 30px;">
        <tr>
            <td align="center" style="padding: 10px 0;">
                <a href="{payment_link}" target="_blank" style="padding: 12px 20px; border-radius: 6px; display: inline-block; background-color: #f97316; color: #ffffff; text-decoration: none; font-weight: bold; font-size: 16px; border: 1px solid #f97316;">
                    PAY INVOICE NOW
                </a>
            </td>
        </tr>
    </table>

    <p style="margin: 0 0 25px 0; font-size: 16px; line-height: 24px; color: #555555;">
        Thank you for choosing <strong>{company_name}</strong>. We appreciate your business.
    </p>

    <p style="margin-top: 25px; margin-bottom: 0; font-size: 16px; line-height: 24px; color: #555555;">
        Best regards,<br>
        The <strong>{company_name}</strong> Team
    </p>
    
    <p style="margin-top: 40px; border-top: 1px solid #e0e0e0; padding-top: 15px; font-size: 12px; color: #999999; text-align: center;">
        Contact Us: {company_address} | Tel: {company_phone} | Email: {company_email}
        <br>
        &copy; 2024 <strong>{company_name}</strong>.
    </p>

</div>';
                    
                    wp_editor(
                        $this->get_setting('invoice_email_body', $default_body),
                        'invoice_email_body',
                        array(
                            'textarea_name' => 'invoice_email_body',
                            'textarea_rows' => 15,
                            'media_buttons' => false,
                            'teeny' => true
                        )
                    );
                    ?>
                    <p class="description">
                        Available placeholders: {customer_name}, {unit_name}, {unit_size}, {monthly_price}, {payment_amount}, {payment_link}, {period_from}, {period_until}, {payment_status}, {company_name}, **{company_address}**, **{company_phone}**, **{company_email}**, **[sum_logo]**
                    </p>
                </td>
            </tr>
        </table>
        
        <h2>Reminder Email Settings</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Reminder Email Body</th>
                <td>
                    <?php
                    $default_reminder = '<h2>Storage Unit Expiration Reminder</h2>
<p>Dear {customer_name},</p>
<p>This is a reminder that your storage unit <strong>{unit_name}</strong> will expire soon.</p>
<p><strong>Unit Details:</strong></p>
<ul>
    <li>Unit: {unit_name}</li>
    <li>Size: {unit_size}</li>
    <li>Expiration Date: {period_until}</li>
    <li>Days Remaining: {days_remaining}</li>
</ul>
<p>Please contact us to renew your storage unit rental.</p>
<p>Best regards,<br>{company_name} Team</p>';
                    
                    wp_editor(
                        $this->get_setting('reminder_email_body', $default_reminder),
                        'reminder_email_body',
                        array(
                            'textarea_name' => 'reminder_email_body',
                            'textarea_rows' => 15,
                            'media_buttons' => false,
                            'teeny' => true
                        )
                    );
                    ?>
                    <p class="description">
                        Available placeholders: {customer_name}, {unit_name}, {unit_size}, {period_until}, {days_remaining}, {company_name}, **{company_address}**, **{company_phone}**, **{company_email}**, **[sum_logo]**
                    </p>
                </td>
            </tr>
        </table>
        
        <p class="submit">
            <button type="submit" class="button-primary">Save Email Settings</button>
        </p>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    $('#email-settings-form').on('submit', function(e) {
        e.preventDefault();
        
        var formData = new FormData(this);
        formData.append('action', 'sum_save_email_settings');
        formData.append('nonce', '<?php echo wp_create_nonce('sum_nonce'); ?>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    alert('Email settings saved successfully!');
                    location.reload();
                } else {
                    alert('Error saving settings: ' + response.data);
                }
            },
            error: function() {
                alert('Failed to save email settings');
            }
        });
    });
});
</script>

<style>
.form-table th {
    width: 200px;
}
</style>