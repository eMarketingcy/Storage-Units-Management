<?php
/**
 * Payment handling for Storage Unit Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class SUM_Payment_Handler {
    
    private $database;
    private $pallet_db; // NEW
    
    public function __construct($database, $pallet_database = null) {
        $this->database = $database;

        // Ensure pallet DB is available
        if ($pallet_database) {
            $this->pallet_db = $pallet_database;
        } else {
            if (!class_exists('SUM_Pallet_Database') && defined('SUM_PLUGIN_PATH')) {
                @require_once SUM_PLUGIN_PATH . 'includes/class-pallet-database.php';
            }
            $this->pallet_db = class_exists('SUM_Pallet_Database') ? new SUM_Pallet_Database() : null;
        }
    }
    
    public function init() {
        // Payment AJAX handlers
        add_action('wp_ajax_sum_process_stripe_payment', array($this, 'process_stripe_payment'));
        add_action('wp_ajax_nopriv_sum_process_stripe_payment', array($this, 'process_stripe_payment'));
        
        // Payment shortcode
        add_shortcode('storage_payment_form', array($this, 'payment_form_shortcode'));
        // Public PDF generator for payment page
        add_action('wp_ajax_sum_generate_invoice_pdf', array($this, 'ajax_generate_invoice_pdf'));
        add_action('wp_ajax_nopriv_sum_generate_invoice_pdf', array($this, 'ajax_generate_invoice_pdf'));
        // Debug: Log that handlers are registered
        error_log('SUM: Payment AJAX handlers registered');
    }
    
public function payment_form_shortcode($atts) {
    // 1. INPUT READING & INITIAL VALIDATION
    $unit_id   = isset($_GET['unit_id'])   ? absint($_GET['unit_id'])   : 0;
    $pallet_id = isset($_GET['pallet_id']) ? absint($_GET['pallet_id']) : 0;

    $token_raw = isset($_GET['token']) ? wp_unslash($_GET['token']) : '';
    $token     = preg_replace('/[^A-Za-z0-9]/', '', $token_raw);

    $is_pallet = (!$unit_id && $pallet_id);
    $entity_id = $is_pallet ? $pallet_id : $unit_id;

    if (!$entity_id || !$token) {
        return '<div class="sum-error">Invalid payment link. Missing entity ID or token.</div>';
    }

    // 2. TOKEN VERIFICATION & DATA LOADING
    $match = false;
    $db = $is_pallet ? $this->pallet_db : $this->database;
    $method_name = $is_pallet ? 'get_payment_token' : 'get_unit_payment_token';
    $transient_name = $is_pallet ? "sum_pallet_payment_token_{$entity_id}" : "sum_payment_token_{$entity_id}";
    
    if ($is_pallet && !$this->pallet_db) {
         return '<div class="sum-error">Invalid payment link (Pallet module missing).</div>';
    }

    $db_token = method_exists($db, $method_name) ? (string) $db->{$method_name}($entity_id) : '';
    $tx_token = (string) get_transient($transient_name);
    
    if ($db_token) {
        $match = function_exists('hash_equals') ? hash_equals($db_token, $token) : ($db_token === $token);
    }
    if (!$match && $tx_token) {
        $match = function_exists('hash_equals') ? hash_equals($tx_token, $token) : ($tx_token === $token);
    }

    if (!$match) {
        return '<div class="sum-error">Invalid or expired payment link. Please request a new invoice.</div>';
    }

    $row = $is_pallet ? $this->pallet_db->get_pallet($entity_id) : $this->database->get_unit($entity_id);
    if (!$row) {
        return '<div class="sum-error">' . ($is_pallet ? 'Pallet' : 'Unit') . ' not found.</div>';
    }
    
    // Normalize unit/pallet data
    $unit = array(
        'unit_name'            => isset($row['unit_name']) ? $row['unit_name'] : (isset($row['pallet_name']) ? $row['pallet_name'] : ''),
        'monthly_price'        => isset($row['monthly_price']) ? $row['monthly_price'] : '',
        'period_from'          => isset($row['period_from']) ? $row['period_from'] : '',
        'period_until'         => isset($row['period_until']) ? $row['period_until'] : '',
        'primary_contact_name' => isset($row['primary_contact_name']) ? $row['primary_contact_name'] : '',
        'id'                   => $entity_id,
        'payment_token'        => $token,
        'is_pallet'            => $is_pallet,
    );

    $entity_title  = $is_pallet ? 'Pallet Storage Invoice' : 'Storage Unit Invoice';
    $display_label = $is_pallet ? 'Pallet' : 'Unit';

    // 3. CALCULATION LOGIC
    $default_price  = floatval($this->database->get_setting('default_unit_price', 100));
    $monthly_price  = floatval($unit['monthly_price'] ? $unit['monthly_price'] : $default_price);
    $payment_amount = $monthly_price;
    
    if (!empty($unit['period_from']) && !empty($unit['period_until'])) {
        if (!function_exists('calculate_billing_months')) {
            @require_once SUM_PLUGIN_PATH . 'includes/class-rental-billing-calculator.php';
        }
        if (function_exists('calculate_billing_months')) {
            try {
                $calc   = calculate_billing_months($unit['period_from'], $unit['period_until'], array('monthly_price' => $monthly_price));
                $months = isset($calc['occupied_months']) ? intval($calc['occupied_months']) : 1;
                if ($months < 1) { $months = 1; }
                $payment_amount = $monthly_price * $months;
            } catch (Exception $e) { /* Fallback to default */ }
        }
    }

    // VAT calculation
    $vat_enabled = $this->database->get_setting('vat_enabled','0') === '1';
    $vat_rate    = floatval( $this->database->get_setting('vat_rate','19') );
    $subtotal    = round( floatval($payment_amount), 2 );
    $vat_amount  = $vat_enabled ? round($subtotal * ($vat_rate/100), 2) : 0.00;
    $total_due   = round($subtotal + $vat_amount, 2);

    // Stripe key check
    $stripe_enabled         = $this->database->get_setting('stripe_enabled', '0');
    $stripe_publishable_key = $this->database->get_setting('stripe_publishable_key', '');
    if (!$stripe_enabled || !$stripe_publishable_key) {
        return '<div class="sum-error">Payment system is not configured. Please contact support.</div>';
    }


    // 4. PREPARE DATA ARRAY (The Presenter's output)
    $data = array(
        'unit'                   => array_map('esc_html', $unit),
        'entity_title'           => esc_html($entity_title),
        'display_label'          => esc_html($display_label),
        'monthly_price'          => number_format($monthly_price, 2),
        'subtotal'               => number_format($subtotal, 2),
        'vat_rate'               => $vat_enabled ? number_format($vat_rate, 2) : '0',
        'vat_amount'             => number_format($vat_amount, 2),
        'total_due'              => number_format($total_due, 2),
        'total_due_raw'          => $total_due, // Raw float for JS calc
        'is_pallet'              => $is_pallet,
        'stripe_publishable_key' => $stripe_publishable_key,
        // Company contact info
        'company_email'          => esc_html($this->database->get_setting('company_email', get_option('admin_email'))),
        'company_phone'          => esc_html($this->database->get_setting('company_phone', '')),
        // AJAX/Security info
        'payment_nonce'          => wp_create_nonce('sum_payment_nonce'),
        'ajax_url'               => admin_url('admin-ajax.php'),
    );

    // 5. TEMPLATE LOADING (Theme Override Implementation)
    $template_name = 'payment-form-template.php';
    
    // Check for theme override (theme-or-child-theme/storage-unit-manager/payment-form-template.php)
    $template_path = locate_template("storage-unit-manager/{$template_name}");

    if (empty($template_path)) {
        // Fallback to the plugin's default template
        $template_path = SUM_PLUGIN_PATH . 'templates/' . $template_name;
    }

    if (!file_exists($template_path)) {
        return '<div class="sum-error">Error: Payment template file not found at path: ' . esc_html($template_path) . '</div>';
    }
    
    // Extract variables into the local scope for template access (best practice for simple view inclusion)
    extract($data);
    
    ob_start();
    include $template_path; // Load the View (HTML/CSS/JS)
    return ob_get_clean();
}    
public function process_stripe_payment() {
    // Nonce (PHP 5.6 compatible)
    $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
    if (!wp_verify_nonce($nonce, 'sum_payment_nonce')) {
        wp_send_json_error('Security check failed');
        return;
    }

    // Inputs
    $stripe_token  = isset($_POST['stripe_token'])  ? sanitize_text_field($_POST['stripe_token'])  : '';
    $unit_id       = isset($_POST['unit_id'])       ? absint($_POST['unit_id'])       : 0;
    $pallet_id     = isset($_POST['pallet_id'])     ? absint($_POST['pallet_id'])     : 0;
    $payment_token = isset($_POST['payment_token']) ? preg_replace('/[^A-Za-z0-9]/', '', (string)$_POST['payment_token']) : '';
    $amount        = isset($_POST['amount'])        ? intval($_POST['amount'])        : 0; // cents

    // Determine entity type
    $is_pallet = (!$unit_id && $pallet_id);
    $entity_id = $is_pallet ? $pallet_id : $unit_id;

    // Validate
    if (empty($stripe_token) || empty($entity_id) || empty($payment_token) || $amount <= 0) {
        $missing = array();
        if (empty($stripe_token))  $missing[] = 'stripe_token';
        if (empty($entity_id))     $missing[] = $is_pallet ? 'pallet_id' : 'unit_id';
        if (empty($payment_token)) $missing[] = 'payment_token';
        if ($amount <= 0)          $missing[] = 'amount';
        wp_send_json_error('Missing required payment information: ' . implode(', ', $missing));
        return;
    }

    // Verify token (DB token primary; transient fallback)
    if ($is_pallet) {
        if (!$this->pallet_db) { wp_send_json_error('Invalid payment token'); return; }
        $db_token = method_exists($this->pallet_db, 'get_payment_token') ? (string)$this->pallet_db->get_payment_token($entity_id) : '';
        $tx_token = (string) get_transient("sum_pallet_payment_token_{$entity_id}");
        $match = false;
        if ($db_token) {
            $match = function_exists('hash_equals') ? hash_equals($db_token, $payment_token) : ($db_token === $payment_token);
        }
        if (!$match && $tx_token) {
            $match = function_exists('hash_equals') ? hash_equals($tx_token, $payment_token) : ($tx_token === $payment_token);
        }
        if (!$match) { wp_send_json_error('Invalid payment token'); return; }
    } else {
        $db_token = method_exists($this->database, 'get_unit_payment_token') ? (string)$this->database->get_unit_payment_token($entity_id) : '';
        $tx_token = (string) get_transient("sum_payment_token_{$entity_id}");
        $match = false;
        if ($db_token) {
            $match = function_exists('hash_equals') ? hash_equals($db_token, $payment_token) : ($db_token === $payment_token);
        }
        if (!$match && $tx_token) {
            $match = function_exists('hash_equals') ? hash_equals($tx_token, $payment_token) : ($tx_token === $payment_token);
        }
        if (!$match) { wp_send_json_error('Invalid payment token'); return; }
    }

    // Load record (for metadata)
    if ($is_pallet) {
        $rec = $this->pallet_db->get_pallet($entity_id);
        if (!$rec) { wp_send_json_error('Pallet not found'); return; }
        $meta_name = isset($rec['pallet_name']) ? $rec['pallet_name'] : '';
        $customer  = isset($rec['primary_contact_name']) ? $rec['primary_contact_name'] : '';
    } else {
        $rec = $this->database->get_unit($entity_id);
        if (!$rec) { wp_send_json_error('Unit not found'); return; }
        $meta_name = isset($rec['unit_name']) ? $rec['unit_name'] : '';
        $customer  = isset($rec['primary_contact_name']) ? $rec['primary_contact_name'] : '';
    }

    // Stripe secret
    $secret = $this->database->get_setting('stripe_secret_key', '');
    if (!$secret) { wp_send_json_error('Payment system not configured'); return; }

    // Build charge
    $charge = array(
        'amount'      => $amount, // cents
        'currency'    => strtolower($this->database->get_setting('currency', 'EUR')),
        'source'      => $stripe_token,
        'description' => ($is_pallet ? 'Pallet Storage Payment - ' : 'Storage Unit Payment - ') . $meta_name,
        'metadata'    => $is_pallet
            ? array('pallet_id' => $entity_id, 'name' => $meta_name, 'customer_name' => $customer)
            : array('unit_id'   => $entity_id, 'unit_name' => $meta_name, 'customer_name' => $customer),
    );

    // Call Stripe
    $resp = wp_remote_post('https://api.stripe.com/v1/charges', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $secret,
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ),
        'body'    => http_build_query($charge),
        'timeout' => 30,
    ));

    if (is_wp_error($resp)) { wp_send_json_error('Payment processing failed'); return; }
    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    if ($code !== 200) {
        $err = json_decode($body, true);
        wp_send_json_error(isset($err['error']['message']) ? $err['error']['message'] : 'Payment failed'); return;
    }

    $result = json_decode($body, true);
    if (!$result || !isset($result['id']) || (!isset($result['status']) || $result['status'] !== 'succeeded')) {
        wp_send_json_error('Payment was not successful'); return;
    }

    // Mark as paid, clean transients, rotate DB token
    global $wpdb;
    if ($is_pallet) {
        $update_result = $wpdb->update(
            $wpdb->prefix . 'storage_pallets',
            array('payment_status' => 'paid'),
            array('id' => $entity_id),
            array('%s'),
            array('%d')
        );
        delete_transient("sum_pallet_payment_token_{$entity_id}");
        // Rotate token (if column exists)
        $wpdb->update(
            $wpdb->prefix . 'storage_pallets',
            array('payment_token' => wp_generate_password(32, false, false)),
            array('id' => $entity_id),
            array('%s'),
            array('%d')
        );
    } else {
        $update_result = $this->database->update_unit_payment_details($entity_id, 'paid');
        delete_transient("sum_payment_token_{$entity_id}");
        // Rotate token defensively (works if column exists)
        //$wpdb->query(
          //  $wpdb->prepare(
            //    "UPDATE {$wpdb->prefix}storage_units SET payment_token = %s //WHERE id = %d",
                //wp_generate_password(32, false, false),
                //$entity_id
            //)
        //);
    }

    if ($update_result === false) {
        wp_send_json_error('Payment processed but failed to update records'); return;
    }

    wp_send_json_success('Payment processed successfully');
}

public function ajax_generate_invoice_pdf() {
    // Nonce
    $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
    if (!wp_verify_nonce($nonce, 'sum_payment_nonce')) {
        wp_send_json_error('Security check failed'); return;
    }

    // Inputs
    $unit_id       = isset($_POST['unit_id'])   ? absint($_POST['unit_id'])   : 0;
    $pallet_id     = isset($_POST['pallet_id']) ? absint($_POST['pallet_id']) : 0;
    $payment_token = isset($_POST['payment_token']) ? preg_replace('/[^A-Za-z0-9]/', '', (string)$_POST['payment_token']) : '';

    $is_pallet = (!$unit_id && $pallet_id);
    $entity_id = $is_pallet ? $pallet_id : $unit_id;

    if (!$entity_id || !$payment_token) {
        wp_send_json_error('Missing entity id or token'); return;
    }

    // Verify token (same logic as payment)
    if ($is_pallet) {
        if (!$this->pallet_db) { wp_send_json_error('Invalid payment link'); return; }
        $db_token = method_exists($this->pallet_db, 'get_payment_token') ? (string)$this->pallet_db->get_payment_token($entity_id) : '';
        $tx_token = (string) get_transient("sum_pallet_payment_token_{$entity_id}");
        $match = false;
        if ($db_token) { $match = function_exists('hash_equals') ? hash_equals($db_token, $payment_token) : ($db_token === $payment_token); }
        if (!$match && $tx_token) { $match = function_exists('hash_equals') ? hash_equals($tx_token, $payment_token) : ($tx_token === $payment_token); }
        if (!$match) { wp_send_json_error('Invalid or expired payment link'); return; }

        // Load record
        $rec = $this->pallet_db->get_pallet($entity_id);
        if (!$rec) { wp_send_json_error('Pallet not found'); return; }

        // Generate PDF
        if (!class_exists('SUM_Pallet_PDF_Generator') && defined('SUM_PLUGIN_PATH')) {
            @require_once SUM_PLUGIN_PATH . 'includes/class-pallet-pdf-generator.php';
        }
        if (!class_exists('SUM_Pallet_PDF_Generator')) { wp_send_json_error('PDF generator not available'); return; }
        $gen = new SUM_Pallet_PDF_Generator($this->pallet_db);
        $pdf_path = $gen->generate_invoice_pdf($rec);
    } else {
        $db_token = method_exists($this->database, 'get_unit_payment_token') ? (string)$this->database->get_unit_payment_token($entity_id) : '';
        $tx_token = (string) get_transient("sum_payment_token_{$entity_id}");
        $match = false;
        if ($db_token) { $match = function_exists('hash_equals') ? hash_equals($db_token, $payment_token) : ($db_token === $payment_token); }
        if (!$match && $tx_token) { $match = function_exists('hash_equals') ? hash_equals($tx_token, $payment_token) : ($tx_token === $payment_token); }
        if (!$match) { wp_send_json_error('Invalid or expired payment link'); return; }

        // Load record
        $rec = $this->database->get_unit($entity_id);
        if (!$rec) { wp_send_json_error('Unit not found'); return; }

        // Generate PDF
        if (!class_exists('SUM_PDF_Generator') && defined('SUM_PLUGIN_PATH')) {
            @require_once SUM_PLUGIN_PATH . 'includes/class-pdf-generator.php';
        }
        if (!class_exists('SUM_PDF_Generator')) { wp_send_json_error('PDF generator not available'); return; }
        $gen = new SUM_PDF_Generator($this->database);
        $pdf_path = $gen->generate_invoice_pdf($rec);
    }

    if (!$pdf_path || !file_exists($pdf_path)) {
        wp_send_json_error('Failed to generate PDF'); return;
    }

    // Build URL from path
    $uploads = wp_upload_dir();
    $baseurl = isset($uploads['baseurl']) ? $uploads['baseurl'] : '';
    $basedir = isset($uploads['basedir']) ? $uploads['basedir'] : '';
    $download_url = $baseurl && $basedir ? str_replace($basedir, $baseurl, $pdf_path) : '';

    if (!$download_url) {
        wp_send_json_error('Could not expose PDF URL'); return;
    }

    $resp = array(
        'download_url' => $download_url,
        'filename'     => basename($pdf_path),
        'file_size'    => @filesize($pdf_path),
    );
    wp_send_json_success($resp);
}

}