<?php
/**
 * Pallet Email handling for Storage Unit Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class SUM_Pallet_Email_Handler {
    
    private $pallet_database;
    private $pdf_generator;
    
    public function __construct($pallet_database) {
        $this->pallet_database = $pallet_database;
        
        // Include PDF generator
        require_once SUM_PLUGIN_PATH . 'includes/class-pallet-pdf-generator.php';
        $this->pdf_generator = new SUM_Pallet_PDF_Generator($pallet_database);
    }
    
    public function init() {
        // Cron hooks for pallet reminders
        add_action('sum_daily_email_check', array($this, 'check_expiring_pallets'));
    }
    
    public function check_expiring_pallets() {
        // Get main database for settings
        global $wpdb;
        $settings_table = $wpdb->prefix . 'storage_settings';
        $email_enabled = $wpdb->get_var($wpdb->prepare("SELECT setting_value FROM $settings_table WHERE setting_key = %s", 'email_enabled'));
        
        if ($email_enabled !== '1') {
            return;
        }
        
        // Check for pallets expiring in 15 days
        $pallets_15_days = $this->pallet_database->get_expiring_pallets(15);
        foreach ($pallets_15_days as $pallet) {
            $this->send_reminder_email($pallet, 15);
        }
        
        // Check for pallets expiring in 5 days
        $pallets_5_days = $this->pallet_database->get_expiring_pallets(5);
        foreach ($pallets_5_days as $pallet) {
            $this->send_reminder_email($pallet, 5);
        }
    }
    
    public function send_reminder_email($pallet, $days) {
        $customer_email = $pallet['primary_contact_email'];
        if (empty($customer_email)) {
            return false;
        }
        
        // Get settings from main database
        global $wpdb;
        $settings_table = $wpdb->prefix . 'storage_settings';
        
        $subject_key = $days === 15 ? 'email_subject_15' : 'email_subject_5';
        $subject = $wpdb->get_var($wpdb->prepare("SELECT setting_value FROM $settings_table WHERE setting_key = %s", $subject_key));
        if (!$subject) {
            $subject = "Pallet Storage Reminder - $days Days Until Expiration";
        }
        
        $body_template = $wpdb->get_var($wpdb->prepare("SELECT setting_value FROM $settings_table WHERE setting_key = %s", 'reminder_email_body'));
        if (!$body_template) {
            $body_template = $this->get_default_reminder_template();
        }
        
        // Replace placeholders
        $placeholders = array(
            '{customer_name}' => $pallet['primary_contact_name'],
            '{unit_name}' => $pallet['pallet_name'],
            '{unit_size}' => $pallet['pallet_type'] . ' Pallet (' . $pallet['charged_height'] . 'm)',
            '{period_until}' => $pallet['period_until'],
            '{days_remaining}' => $days,
            '{company_name}' => $wpdb->get_var($wpdb->prepare("SELECT setting_value FROM $settings_table WHERE setting_key = %s", 'company_name')) ?: 'Self Storage Cyprus'
        );
        
        $body = str_replace(array_keys($placeholders), array_values($placeholders), $body_template);
        
        // Send email
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $sent = wp_mail($customer_email, $subject, $body, $headers);
        
        // Also send to admin
        $admin_email = $wpdb->get_var($wpdb->prepare("SELECT setting_value FROM $settings_table WHERE setting_key = %s", 'admin_email'));
        if (!$admin_email) {
            $admin_email = get_option('admin_email');
        }
        
        if ($admin_email && $admin_email !== $customer_email) {
            wp_mail($admin_email, '[Admin] ' . $subject, $body, $headers);
        }
        
        return $sent;
    }
    
public function send_invoice_email($pallet) {
    $customer_email = isset($pallet['primary_contact_email']) ? $pallet['primary_contact_email'] : '';
    if (empty($customer_email)) {
        return false;
    }

    // --- Pallet ID from the passed record (this was missing) ---
    $pallet_id = isset($pallet['id']) ? (int)$pallet['id'] : 0;
    if ($pallet_id <= 0) {
        error_log('SUM Pallet Email: Missing or invalid pallet_id in send_invoice_email()');
        return false;
    }

    // --- Payment page URL (prefer saved page, fallback to slug) ---
    $pay_page_id = (int) get_option('sum_payment_page_id');
    $payment_page_url = $pay_page_id ? get_permalink($pay_page_id) : home_url('/storage-payment/');

    // --- Use a persistent DB token; optionally mirror to transient for legacy links ---
    // (ensure_payment_token should create one if missing and return it)
    $token = $this->pallet_database->ensure_payment_token($pallet_id);

    // Optional (keeps older validator happy if it still checks transients too):
    set_transient("sum_pallet_payment_token_{$pallet_id}", $token, 25 * DAY_IN_SECONDS);

    // --- Build the working link ---
    $payment_link = add_query_arg(array(
        'pallet_id' => $pallet_id,
        'token'     => $token,
    ), $payment_page_url);

    // Get settings from main database
    global $wpdb;
    $settings_table = $wpdb->prefix . 'storage_settings';

    $subject = $wpdb->get_var($wpdb->prepare(
        "SELECT setting_value FROM $settings_table WHERE setting_key = %s", 'invoice_email_subject'
    ));
    if (!$subject) {
        $subject = 'Pallet Storage Invoice';
    }

    $body_template = $wpdb->get_var($wpdb->prepare(
        "SELECT setting_value FROM $settings_table WHERE setting_key = %s", 'invoice_email_body'
    ));
    if (!$body_template) {
        $body_template = $this->get_default_invoice_template();
    }

    // Calculate payment amount
    $monthly_price = floatval(!empty($pallet['monthly_price']) ? $pallet['monthly_price'] : 30.00);

    // Use billing calculator for proper month calculation
    if (!function_exists('calculate_billing_months')) {
        require_once SUM_PLUGIN_PATH . 'includes/class-rental-billing-calculator.php';
    }

    $months_due = 1; // default
    $payment_amount = $monthly_price;

    if (!empty($pallet['period_from']) && !empty($pallet['period_until']) && function_exists('calculate_billing_months')) {
        try {
            $billing_result = calculate_billing_months(
                $pallet['period_from'],
                $pallet['period_until'],
                array('monthly_price' => $monthly_price)
            );
            $months_due     = isset($billing_result['occupied_months']) ? (int)$billing_result['occupied_months'] : 1;
            if ($months_due < 1) $months_due = 1;
            $payment_amount = $monthly_price * $months_due;
        } catch (Exception $e) {
            error_log('SUM Pallet Email: Billing calculator error: ' . $e->getMessage());
        }
    }

    // Company name
    $company_name = $wpdb->get_var($wpdb->prepare(
        "SELECT setting_value FROM $settings_table WHERE setting_key = %s", 'company_name'
    ));
    if (!$company_name) $company_name = 'Self Storage Cyprus';

    // Replace placeholders
    $placeholders = array(
        '{customer_name}'  => isset($pallet['primary_contact_name']) ? $pallet['primary_contact_name'] : '',
        '{unit_name}'      => isset($pallet['pallet_name']) ? $pallet['pallet_name'] : '',
        '{unit_size}'      => (isset($pallet['pallet_type']) ? $pallet['pallet_type'] : '') . ' Pallet (' . (isset($pallet['charged_height']) ? $pallet['charged_height'] : '') . 'm)',
        '{monthly_price}'  => number_format($monthly_price, 2),
        '{payment_amount}' => number_format($payment_amount, 2),
        '{payment_link}'   => $payment_link,
        '{period_from}'    => isset($pallet['period_from']) ? $pallet['period_from'] : '',
        '{period_until}'   => isset($pallet['period_until']) ? $pallet['period_until'] : '',
        '{payment_status}' => isset($pallet['payment_status']) ? ucfirst($pallet['payment_status']) : 'Pending',
        '{company_name}'   => $company_name,
    );

    $body = str_replace(array_keys($placeholders), array_values($placeholders), $body_template);

    // Generate PDF invoice (optional)
    $pdf_content = $this->pdf_generator->generate_invoice_pdf($pallet);
    $attachments = array();
    if ($pdf_content && file_exists($pdf_content)) {
        $attachments[] = $pdf_content;
    }

    // Send email
    $headers = array('Content-Type: text/html; charset=UTF-8');
    $sent = wp_mail($customer_email, $subject, $body, $headers, $attachments);

    // Also send to admin
    $admin_email = $wpdb->get_var($wpdb->prepare(
        "SELECT setting_value FROM $settings_table WHERE setting_key = %s", 'admin_email'
    ));
    if (!$admin_email) {
        $admin_email = get_option('admin_email');
    }
    if ($admin_email && $admin_email !== $customer_email) {
        wp_mail($admin_email, '[Admin] ' . $subject, $body, $headers, $attachments);
    }

    // Clean temporary PDF(s)
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
        return '<h2>Pallet Storage Expiration Reminder</h2>
<p>Dear {customer_name},</p>
<p>This is a reminder that your pallet storage <strong>{unit_name}</strong> will expire soon.</p>
<p><strong>Pallet Details:</strong></p>
<ul>
    <li>Pallet: {unit_name}</li>
    <li>Type: {unit_size}</li>
    <li>Expiration Date: {period_until}</li>
    <li>Days Remaining: {days_remaining}</li>
</ul>
<p>Please contact us to renew your pallet storage rental.</p>
<p>Best regards,<br>{company_name} Team</p>';
    }
    
    private function get_default_invoice_template() {
        return '<h2>Invoice for Pallet Storage {unit_name}</h2>
<p>Dear {customer_name},</p>
<p>Please find your invoice for pallet storage <strong>{unit_name}</strong>.</p>
<p><strong>Payment Required:</strong> €{payment_amount}</p>
<p><a href="{payment_link}" style="background: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Pay Now</a></p>
<p><strong>Pallet Details:</strong></p>
<ul>
    <li>Pallet: {unit_name}</li>
    <li>Type: {unit_size}</li>
    <li>Monthly Price: €{monthly_price}</li>
    <li>Period: {period_from} - {period_until}</li>
    <li>Status: {payment_status}</li>
</ul>
<p>Thank you for choosing {company_name}.</p>
<p>Best regards,<br>{company_name} Team</p>';
    }
}