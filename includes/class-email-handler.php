<?php
/**
 * Email handling for Storage Unit Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class SUM_Email_Handler {
    
    private $database;
    private $pdf_generator;
    
    public function __construct($database) {
        $this->database = $database;
        
        // Include PDF generator
        require_once SUM_PLUGIN_PATH . 'includes/class-pdf-generator.php';
        $this->pdf_generator = new SUM_PDF_Generator($database);
        
        // Register the logo shortcode handler
        add_shortcode('sum_logo', array($this, 'render_logo_shortcode'));
    }
    
    public function init() {
        // Cron hooks
        add_action('sum_daily_email_check', array($this, 'check_expiring_units'));
    }
    
    public function check_expiring_units() {
        if ($this->database->get_setting('email_enabled', '1') !== '1') {
            return;
        }
        
        // Check for units expiring in 15 days
        $units_15_days = $this->database->get_expiring_units(15);
        foreach ($units_15_days as $unit) {
            $this->send_reminder_email($unit, 15);
        }
        
        // Check for units expiring in 5 days
        $units_5_days = $this->database->get_expiring_units(5);
        foreach ($units_5_days as $unit) {
            $this->send_reminder_email($unit, 5);
        }
    }
    
    /**
     * Renders the [sum_logo] shortcode into an HTML img tag.
     * Uses inline styles for maximum email compatibility.
     * @return string The HTML for the logo or company name.
     */
    public function render_logo_shortcode($atts, $content = null) {
        $logo_url = $this->database->get_setting('company_logo', '');
        $company_name = $this->database->get_setting('company_name', 'Your Company Name');
        
        if (empty($logo_url)) {
            // Fallback: return company name as styled text if no logo is uploaded
            return '<span style="font-size: 24px; font-weight: bold; color: #333333;">' . esc_html($company_name) . '</span>';
        }

        // Return the image tag with inline styling
        return '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($company_name) . '" width="150" style="max-width: 150px; height: auto; border: 0; display: block; margin: 0 auto;">';
    }

    
    public function send_reminder_email($unit, $days) {
        $customer_email = $unit['primary_contact_email'];
        if (empty($customer_email)) {
            return false;
        }
        
        $subject_key = $days === 15 ? 'email_subject_15' : 'email_subject_5';
        $subject = $this->database->get_setting($subject_key, "Storage Unit Reminder - $days Days Until Expiration");
        
        $body_template = $this->database->get_setting('reminder_email_body', $this->get_default_reminder_template());
        
        // Replace placeholders
        $placeholders = array(
            '{customer_name}' => $unit['primary_contact_name'],
            '{unit_name}' => $unit['unit_name'],
            '{unit_size}' => $unit['size'],
            '{period_until}' => $unit['period_until'],
            '{days_remaining}' => $days,
            '{company_name}' => $this->database->get_setting('company_name', 'Self Storage Cyprus'),
            // --- NEW PLACEHOLDERS ---
            '{company_address}' => $this->database->get_setting('company_address', ''),
            '{company_phone}' => $this->database->get_setting('company_phone', ''),
            '{company_email}' => $this->database->get_setting('company_email', get_option('admin_email'))
            // --------------------------
        );
        
        $body = str_replace(array_keys($placeholders), array_values($placeholders), $body_template);
        $body = do_shortcode($body); // Process shortcodes (like [sum_logo])
        
        // Send email
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $sent = wp_mail($customer_email, $subject, $body, $headers);
        
        // Also send to admin
        $admin_email = $this->database->get_setting('admin_email', get_option('admin_email'));
        if ($admin_email && $admin_email !== $customer_email) {
            wp_mail($admin_email, '[Admin] ' . $subject, $body, $headers);
        }
        
        return $sent;
    }
    
public function send_invoice_email($unit) {
    $customer_email = isset($unit['primary_contact_email']) ? $unit['primary_contact_email'] : '';
    if (empty($customer_email)) {
        return false;
    }

    // --- Unit ID from the passed record (this was missing) ---
    $unit_id = isset($unit['id']) ? (int)$unit['id'] : 0;
    if ($unit_id <= 0) {
        error_log('SUM Unit Email: Missing or invalid unit_id in send_invoice_email()');
        return false;
    }

    // --- Payment page URL (prefer saved page, fallback to slug) ---
    $pay_page_id = (int) get_option('sum_payment_page_id');
    $pay_url = $pay_page_id ? get_permalink($pay_page_id) : home_url('/storage-payment/');

    // --- Use a persistent DB token; optionally mirror to transient for legacy links ---
    if (!method_exists($this->database, 'ensure_unit_payment_token')) {
        error_log('SUM Unit Email: ensure_unit_payment_token() not found on database class');
        return false;
    }
    $token = $this->database->ensure_unit_payment_token($unit_id);

    // Optional: keep legacy transient valid (25 days) for older payment pages
    set_transient("sum_payment_token_{$unit_id}", $token, 25 * DAY_IN_SECONDS);

    // --- Build the working link ---
    $payment_link = add_query_arg(array(
        'unit_id' => $unit_id,
        'token'   => $token,
    ), $pay_url);

    error_log("SUM: Payment link generated (unit {$unit_id}): {$payment_link}");

    $subject = $this->database->get_setting('invoice_email_subject', 'Storage Unit Invoice');
    $body_template = $this->database->get_setting('invoice_email_body', $this->get_default_invoice_template());

    // Calculate payment amount
    $monthly_price = floatval(!empty($unit['monthly_price']) ? $unit['monthly_price'] : $this->database->get_setting('default_unit_price', 100));

    if (!function_exists('calculate_billing_months')) {
        require_once SUM_PLUGIN_PATH . 'includes/class-rental-billing-calculator.php';
    }

    $months_due = 1; // default
    $payment_amount = $monthly_price;

    if (!empty($unit['period_from']) && !empty($unit['period_until']) && function_exists('calculate_billing_months')) {
        try {
            $billing_result = calculate_billing_months(
                $unit['period_from'],
                $unit['period_until'],
                array('monthly_price' => $monthly_price)
            );
            $months_due     = isset($billing_result['occupied_months']) ? (int)$billing_result['occupied_months'] : 1;
            if ($months_due < 1) $months_due = 1;
            $payment_amount = $monthly_price * $months_due;

            error_log("SUM Unit Email: Billing {$unit['period_from']} → {$unit['period_until']}, months={$months_due}, amount={$payment_amount}");
        } catch (Exception $e) {
            error_log('SUM Unit Email: Billing calculator error: ' . $e->getMessage());
        }
    } else {
        error_log("SUM Unit Email: Missing period dates for unit {$unit_id}, using default 1 month");
    }

    // Replace placeholders
    $placeholders = array(
        '{customer_name}'  => isset($unit['primary_contact_name']) ? $unit['primary_contact_name'] : '',
        '{unit_name}'      => isset($unit['unit_name']) ? $unit['unit_name'] : '',
        '{unit_size}'      => isset($unit['size']) ? $unit['size'] : '',
        '{monthly_price}'  => number_format($monthly_price, 2),
        '{payment_amount}' => number_format($payment_amount, 2),
        '{payment_link}'   => $payment_link,
        '{period_from}'    => isset($unit['period_from']) ? $unit['period_from'] : '',
        '{period_until}'   => isset($unit['period_until']) ? $unit['period_until'] : '',
        '{payment_status}' => isset($unit['payment_status']) ? ucfirst($unit['payment_status']) : 'Pending',
        '{company_name}'   => $this->database->get_setting('company_name', 'Self Storage Cyprus'),
        // --- NEW PLACEHOLDERS ---
        '{company_address}' => $this->database->get_setting('company_address', ''),
        '{company_phone}' => $this->database->get_setting('company_phone', ''),
        '{company_email}' => $this->database->get_setting('company_email', get_option('admin_email'))
        // --------------------------
    );

    $body = str_replace(array_keys($placeholders), array_values($placeholders), $body_template);
    $body = do_shortcode($body);

    // Generate PDF invoice
    $pdf_content = $this->pdf_generator->generate_invoice_pdf($unit);
    $attachments = array();
    if ($pdf_content && file_exists($pdf_content)) {
        $attachments[] = $pdf_content;
    }

    // Send email
    $headers = array('Content-Type: text/html; charset=UTF-8');
    $sent = wp_mail($customer_email, $subject, $body, $headers, $attachments);

    // Also send to admin
    $admin_email = $this->database->get_setting('admin_email', get_option('admin_email'));
    if ($admin_email && $admin_email !== $customer_email) {
        wp_mail($admin_email, '[Admin] ' . $subject, $body, $headers, $attachments);
    }

    // Clean up temporary PDF file(s) safely (only inside uploads)
    if (!empty($attachments)) {
        $uploads = wp_upload_dir();
        $basedir = isset($uploads['basedir']) ? $uploads['basedir'] : '';
        foreach ($attachments as $attachment) {
            if ($basedir && file_exists($attachment) && strpos($attachment, $basedir) !== false) {
                @unlink($attachment);
            }
        }
    }

    return $sent;
}
    
    private function get_default_reminder_template() {
        // MODERN REMINDER TEMPLATE - Updated with #f97316
        return '<div style="max-width: 600px; margin: 0 auto; padding: 20px; font-family: Arial, sans-serif; background-color: #ffffff; border: 1px solid #e0e0e0; border-radius: 8px;">
            
            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 20px;">
                <tr>
                    <td style="text-align: center; background-color: #f7f7f7; padding: 15px 0; border-radius: 6px;">
                        [sum_logo]
                    </td>
                </tr>
            </table>

            <h2 style="margin-top: 0; margin-bottom: 20px; font-size: 24px; color: #f97316; border-bottom: 2px solid #f97316; padding-bottom: 10px;">
                Storage Unit Expiration Reminder
            </h2>
            <p style="margin: 0 0 25px 0; font-size: 16px; line-height: 24px; color: #333333;">
                Dear <strong>{customer_name}</strong>,
            </p>
            <p style="margin: 0 0 30px 0; font-size: 16px; line-height: 24px; color: #555555;">
                This is a reminder that your storage unit <strong>{unit_name}</strong> will expire in **{days_remaining} days**.
            </p>
            
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
                        <td style="color: #555555; padding: 5px 0; border-top: 1px solid #dddddd;">**Expiration Date:**</td>
                        <td style="font-weight: bold; color: #f97316; text-align: right; padding: 5px 0; border-top: 1px solid #dddddd;">**{period_until}**</td>
                    </tr>
                </table>
            </div>

            <p style="margin: 0 0 25px 0; font-size: 16px; line-height: 24px; color: #555555;">
                Please contact us immediately to renew your storage unit rental and ensure continued access.
            </p>

            <p style="margin-top: 25px; margin-bottom: 0; font-size: 16px; line-height: 24px; color: #555555;">
                Best regards,<br>
                The <strong>{company_name}</strong> Team
            </p>

            <p style="margin-top: 40px; border-top: 1px solid #e0e0e0; padding-top: 15px; font-size: 12px; color: #999999; text-align: center;">
                Contact Us: {company_address} | Tel: {company_phone} | Email: {company_email}
                <br>
                This is an automated reminder email.
            </p>

        </div>';
    }
    
    private function get_default_invoice_template() {
        // MODERN INVOICE TEMPLATE - Updated with #f97316
        return '<div style="max-width: 600px; margin: 0 auto; padding: 20px; font-family: Arial, sans-serif; background-color: #ffffff; border: 1px solid #e0e0e0; border-radius: 8px;">

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
    }
}