<?php
/**
 * Pallet AJAX handlers for Storage Unit Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class SUM_Pallet_Ajax_Handlers {
    
    private $pallet_database;
    
    public function __construct($pallet_database) {
        $this->pallet_database = $pallet_database;
    }
    
    public function init() {
        // Admin AJAX handlers
        add_action('wp_ajax_sum_get_pallets', array($this, 'get_pallets'));
        add_action('wp_ajax_sum_save_pallet', array($this, 'save_pallet'));
        add_action('wp_ajax_sum_delete_pallet', array($this, 'delete_pallet'));
        add_action('wp_ajax_sum_generate_pallet_name', array($this, 'generate_pallet_name'));
        add_action('wp_ajax_sum_save_pallet_settings', array($this, 'save_pallet_settings'));
        add_action('wp_ajax_sum_send_pallet_invoice', array($this, 'send_pallet_invoice'));
        add_action('wp_ajax_sum_regenerate_pallet_pdf', array($this, 'regenerate_pallet_pdf'));
        
        // Frontend AJAX handlers
        add_action('wp_ajax_sum_get_pallets_frontend', array($this, 'get_pallets_frontend'));
        add_action('wp_ajax_sum_save_pallet_frontend', array($this, 'save_pallet_frontend'));
        add_action('wp_ajax_sum_delete_pallet_frontend', array($this, 'delete_pallet_frontend'));
        add_action('wp_ajax_sum_generate_pallet_name_frontend', array($this, 'generate_pallet_name_frontend'));
        add_action('wp_ajax_sum_send_pallet_invoice_frontend', array($this, 'send_pallet_invoice_frontend'));
        add_action('wp_ajax_sum_regenerate_pallet_pdf_frontend', array($this, 'regenerate_pallet_pdf_frontend'));
    }
    
    public function get_pallets() {
        if (!check_ajax_referer('sum_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $filter = sanitize_text_field($_POST['filter'] ?? 'all');
        $pallets = $this->pallet_database->get_pallets($filter);
        
        wp_send_json_success($pallets);
    }
    
/**
 * AJAX: Create/Update a pallet row.
 * Expects POST fields:
 *  - id (optional, numeric)
 *  - pallet_name
 *  - pallet_type ('EU'|'US')
 *  - actual_height (float)
 *  - period_from (Y-m-d or anything parseable)
 *  - period_until (Y-m-d or anything parseable)
 *  - payment_status (paid|unpaid|overdue, etc.)
 *  - primary_contact_* / secondary_contact_* fields
 *  - nonce (AJAX nonce)
 */
public function save_pallet() {
    // Require login (frontend or admin). Adjust capability if you want.
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array('message' => 'Not authorized'), 401 );
    }

    // Nonce: change 'sum_pallet_save' to your action if different.
    if ( ! isset($_POST['nonce']) || ! wp_verify_nonce( $_POST['nonce'], 'sum_pallet_save' ) ) {
        wp_send_json_error( array('message' => 'Invalid nonce'), 403 );
    }

    // Sanitize inputs
    $id           = isset($_POST['id']) ? absint($_POST['id']) : 0;
    $pallet_name  = isset($_POST['pallet_name']) ? sanitize_text_field($_POST['pallet_name']) : '';
    $pallet_type  = isset($_POST['pallet_type']) ? strtoupper(sanitize_text_field($_POST['pallet_type'])) : 'EU';
    $pallet_type  = in_array($pallet_type, array('EU','US'), true) ? $pallet_type : 'EU';

    $actual_height = isset($_POST['actual_height']) ? floatval($_POST['actual_height']) : 0.0;
    if ($actual_height < 0) { $actual_height = 0.0; }

    // Dates -> Y-m-d or NULL
    $period_from  = $this->normalize_date( $_POST['period_from'] ?? '' );
    $period_until = $this->normalize_date( $_POST['period_until'] ?? '' );

    // Payment status (allow list or fallback)
    $payment_status = isset($_POST['payment_status']) ? sanitize_text_field($_POST['payment_status']) : 'paid';
    $allowed_status = apply_filters('sum_allowed_payment_statuses', array('paid','unpaid','overdue','pending'));
    if ( ! in_array($payment_status, $allowed_status, true) ) {
        $payment_status = 'paid';
    }

    // Contacts
    $primary_contact_name      = sanitize_text_field($_POST['primary_contact_name']      ?? '');
    $primary_contact_phone     = sanitize_text_field($_POST['primary_contact_phone']     ?? '');
    $primary_contact_whatsapp  = sanitize_text_field($_POST['primary_contact_whatsapp']  ?? '');
    $primary_contact_email     = sanitize_email($_POST['primary_contact_email']          ?? '');

    $secondary_contact_name     = sanitize_text_field($_POST['secondary_contact_name']     ?? '');
    $secondary_contact_phone    = sanitize_text_field($_POST['secondary_contact_phone']    ?? '');
    $secondary_contact_whatsapp = sanitize_text_field($_POST['secondary_contact_whatsapp'] ?? '');
    $secondary_contact_email    = sanitize_email($_POST['secondary_contact_email']         ?? '');

    // Basic validation
    if ($pallet_name === '') {
        wp_send_json_error( array('message' => 'Pallet name is required'), 422 );
    }

    // === Canonical server-side calculations ===
    // Round UP to next tier (e.g., 1.25 -> 1.40)
    $charged_height = $this->pallet_database->compute_charged_height($actual_height, $pallet_type);

    // Lookup monthly price for (type, charged_height) from settings table
    $monthly_price  = $this->pallet_database->get_monthly_price_for($pallet_type, $charged_height);

    // Compute cubic meters using CHARGED height
    if ($pallet_type === 'EU') {
        $length = 1.20; $width = 0.80;
    } else {
        $length = 1.22; $width = 1.02;
    }
    $cubic_meters = $length * $width * (float)$charged_height;

    // Prepare DB write
    global $wpdb;
    $table = $wpdb->prefix . 'storage_pallets';

    $data = array(
        'pallet_name'              => $pallet_name,
        'pallet_type'              => $pallet_type,
        'actual_height'            => $actual_height,
        'charged_height'           => $charged_height,
        'cubic_meters'             => $cubic_meters,
        'monthly_price'            => $monthly_price,
        'period_from'              => $period_from,
        'period_until'             => $period_until,
        'payment_status'           => $payment_status,
        'primary_contact_name'     => $primary_contact_name,
        'primary_contact_phone'    => $primary_contact_phone,
        'primary_contact_whatsapp' => $primary_contact_whatsapp,
        'primary_contact_email'    => $primary_contact_email,
        'secondary_contact_name'   => $secondary_contact_name,
        'secondary_contact_phone'  => $secondary_contact_phone,
        'secondary_contact_whatsapp'=> $secondary_contact_whatsapp,
        'secondary_contact_email'  => $secondary_contact_email,
    );

    $format = array(
        '%s','%s','%f','%f','%f','%f','%s','%s','%s',
        '%s','%s','%s','%s','%s','%s','%s','%s'
    );

    // Optional: wrap in a transaction if InnoDB
    $wpdb->query('START TRANSACTION');

    if ($id > 0) {
        $where  = array('id' => $id);
        $w_fmt  = array('%d');
        $done   = $wpdb->update($table, $data, $where, $format, $w_fmt);
        if ($done === false) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error( array('message' => 'Database update failed', 'error' => $wpdb->last_error), 500 );
        }
        $pallet_id = $id;
    } else {
        $done = $wpdb->insert($table, $data, $format);
        if ($done === false) {
            $wpdb->query('ROLLBACK');

            // Unique pallet_name collision? Report clearly.
            $msg = 'Database insert failed';
            if (strpos(strtolower($wpdb->last_error), 'duplicate') !== false) {
                $msg .= ': pallet name already exists';
            }
            wp_send_json_error( array('message' => $msg, 'error' => $wpdb->last_error), 500 );
        }
        $pallet_id = (int)$wpdb->insert_id;
    }

    $wpdb->query('COMMIT');

    // Return normalized, display-ready payload
    $payload = array(
        'id'              => $pallet_id,
        'pallet_name'     => $pallet_name,
        'pallet_type'     => $pallet_type,
        'actual_height'   => (float)$actual_height,
        'charged_height'  => (float)$charged_height, // e.g., 1.40 for 1.25 actual
        'cubic_meters'    => (float)round($cubic_meters, 3),
        'monthly_price'   => (float)round($monthly_price, 2),
        'period_from'     => $period_from,
        'period_until'    => $period_until,
        'payment_status'  => $payment_status,
        'primary_contact' => array(
            'name'      => $primary_contact_name,
            'phone'     => $primary_contact_phone,
            'whatsapp'  => $primary_contact_whatsapp,
            'email'     => $primary_contact_email,
        ),
        'secondary_contact' => array(
            'name'      => $secondary_contact_name,
            'phone'     => $secondary_contact_phone,
            'whatsapp'  => $secondary_contact_whatsapp,
            'email'     => $secondary_contact_email,
        ),
        // For UI convenience (strings)
        'display' => array(
            'charged_height' => number_format((float)$charged_height, 2) . ' m',
            'cubic_meters'   => number_format((float)$cubic_meters, 3) . ' m³',
            'monthly_price'  => number_format((float)$monthly_price, 2),
        ),
        'message' => ($id > 0) ? 'Pallet updated' : 'Pallet created',
    );

    wp_send_json_success($payload, 200);
}

/**
 * Normalize any date-ish input to 'Y-m-d' or return null if empty/invalid.
 * Keep it inside the same class for reuse.
 */
private function normalize_date($value) {
    $value = is_string($value) ? trim($value) : '';
    if ($value === '') {
        return null;
    }
    try {
        $dt = new DateTime($value);
        return $dt->format('Y-m-d');
    } catch (Exception $e) {
        return null;
    }
}
    
    public function delete_pallet() {
        if (!check_ajax_referer('sum_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $pallet_id = intval($_POST['pallet_id']);
        
        if ($pallet_id <= 0) {
            wp_send_json_error('Invalid pallet ID');
            return;
        }
        
        $result = $this->pallet_database->delete_pallet($pallet_id);
        
        if ($result !== false) {
            wp_send_json_success('Pallet deleted successfully');
        } else {
            wp_send_json_error('Failed to delete pallet');
        }
    }
    
    public function generate_pallet_name() {
        if (!check_ajax_referer('sum_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $customer_name = sanitize_text_field($_POST['customer_name'] ?? '');
        
        if (empty($customer_name)) {
            wp_send_json_error('Customer name is required');
            return;
        }
        
        $pallet_name = $this->pallet_database->generate_pallet_name($customer_name);
        
        wp_send_json_success($pallet_name);
    }
    
    public function save_pallet_settings() {
        if (!check_ajax_referer('sum_nonce', 'nonce', false)) {
            error_log('SUM Pallet Settings AJAX: Invalid nonce');
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            error_log('SUM Pallet Settings AJAX: Insufficient permissions');
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        error_log('SUM Pallet Settings AJAX: Raw POST data: ' . print_r($_POST, true));
        
        // Get settings data - try multiple approaches
        $settings_json = '';
        if (isset($_POST['settings'])) {
            $settings_json = stripslashes($_POST['settings']);
        } else {
            error_log('SUM Pallet Settings AJAX: No settings field in POST data');
            wp_send_json_error('No settings data received');
            return;
        }
        
        error_log('SUM Pallet Settings AJAX: Settings JSON: ' . $settings_json);
        
        $settings = json_decode($settings_json, true);
        $json_error = json_last_error();
        
        if ($json_error !== JSON_ERROR_NONE) {
            error_log('SUM Pallet Settings AJAX: JSON decode error: ' . json_last_error_msg());
            error_log('SUM Pallet Settings AJAX: Raw JSON: ' . $settings_json);
            wp_send_json_error('Invalid JSON data: ' . json_last_error_msg());
            return;
        }
        
        if (!is_array($settings) || empty($settings)) {
            error_log('SUM Pallet Settings AJAX: Settings is not array or empty: ' . print_r($settings, true));
            wp_send_json_error('Invalid or empty settings data');
            return;
        }
        
        error_log('SUM Pallet Settings AJAX: Parsed settings: ' . print_r($settings, true));
        error_log('SUM Pallet Settings AJAX: Settings count: ' . count($settings));
        
        $result = $this->pallet_database->save_pallet_settings($settings);
        
        if ($result) {
            error_log('SUM Pallet Settings AJAX: Save successful');
            wp_send_json_success('Pallet settings saved successfully');
        } else {
            error_log('SUM Pallet Settings AJAX: Save failed');
            wp_send_json_error('Failed to save pallet settings');
        }
    }
    
    public function send_pallet_invoice() {
        if (!check_ajax_referer('sum_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $pallet_id = intval($_POST['pallet_id']);
        $pallet = $this->pallet_database->get_pallet($pallet_id);
        
        if (!$pallet) {
            wp_send_json_error('Pallet not found');
            return;
        }
        
        // Initialize email handler and send invoice
        require_once SUM_PLUGIN_PATH . 'includes/class-pallet-email-handler.php';
        $email_handler = new SUM_Pallet_Email_Handler($this->pallet_database);
        $result = $email_handler->send_invoice_email($pallet);
        
        if ($result) {
            wp_send_json_success('Invoice sent successfully');
        } else {
            wp_send_json_error('Failed to send invoice');
        }
    }
    
    public function regenerate_pallet_pdf() {
        if (!check_ajax_referer('sum_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $pallet_id = intval($_POST['pallet_id']);
        $pallet = $this->pallet_database->get_pallet($pallet_id);
        
        if (!$pallet) {
            wp_send_json_error('Pallet not found');
            return;
        }
        
        // Generate PDF
        require_once SUM_PLUGIN_PATH . 'includes/class-pallet-pdf-generator.php';
        $pdf_generator = new SUM_Pallet_PDF_Generator($this->pallet_database);
        $pdf_path = $pdf_generator->generate_invoice_pdf($pallet);
        
        if ($pdf_path && file_exists($pdf_path)) {
            // Get the URL for download
            $upload_dir = wp_upload_dir();
            $pdf_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $pdf_path);
            
            wp_send_json_success(array(
                'message' => 'PDF regenerated successfully',
                'download_url' => $pdf_url,
                'filename' => basename($pdf_path),
                'file_size' => filesize($pdf_path)
            ));
        } else {
            wp_send_json_error('Failed to generate PDF');
        }
    }
    
    // Frontend handlers (similar to admin but with different permission checks)
    public function get_pallets_frontend() {
        if (!check_ajax_referer('sum_frontend_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!$this->check_frontend_access()) {
            wp_send_json_error('Access denied');
            return;
        }
        
        $filter = sanitize_text_field($_POST['filter'] ?? 'all');
        $pallets = $this->pallet_database->get_pallets($filter);
        
        wp_send_json_success($pallets);
    }
    
    public function save_pallet_frontend() {
        if (!check_ajax_referer('sum_frontend_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!$this->check_frontend_access()) {
            wp_send_json_error('Access denied');
            return;
        }
        
        if (empty($_POST['pallet_name'])) {
            wp_send_json_error('Pallet name is required');
            return;
        }
        
        $result = $this->pallet_database->save_pallet($_POST);
        
        if ($result !== false) {
            $message = intval($_POST['pallet_id'] ?? 0) > 0 ? 'Pallet updated successfully' : 'Pallet created successfully';
            wp_send_json_success($message);
        } else {
            wp_send_json_error('Failed to save pallet');
        }
    }
    
    public function delete_pallet_frontend() {
        if (!check_ajax_referer('sum_frontend_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!$this->check_frontend_access()) {
            wp_send_json_error('Access denied');
            return;
        }
        
        $pallet_id = intval($_POST['pallet_id']);
        
        if ($pallet_id <= 0) {
            wp_send_json_error('Invalid pallet ID');
            return;
        }
        
        $result = $this->pallet_database->delete_pallet($pallet_id);
        
        if ($result !== false) {
            wp_send_json_success('Pallet deleted successfully');
        } else {
            wp_send_json_error('Failed to delete pallet');
        }
    }
    
    public function generate_pallet_name_frontend() {
        if (!check_ajax_referer('sum_frontend_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!$this->check_frontend_access()) {
            wp_send_json_error('Access denied');
            return;
        }
        
        $customer_name = sanitize_text_field($_POST['customer_name'] ?? '');
        
        if (empty($customer_name)) {
            wp_send_json_error('Customer name is required');
            return;
        }
        
        $pallet_name = $this->pallet_database->generate_pallet_name($customer_name);
        
        wp_send_json_success($pallet_name);
    }
    
    public function send_pallet_invoice_frontend() {
        if (!check_ajax_referer('sum_frontend_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!$this->check_frontend_access()) {
            wp_send_json_error('Access denied');
            return;
        }
        
        $pallet_id = intval($_POST['pallet_id']);
        $pallet = $this->pallet_database->get_pallet($pallet_id);
        
        if (!$pallet) {
            wp_send_json_error('Pallet not found');
            return;
        }
        
        // Initialize email handler and send invoice
        require_once SUM_PLUGIN_PATH . 'includes/class-pallet-email-handler.php';
        $email_handler = new SUM_Pallet_Email_Handler($this->pallet_database);
        $result = $email_handler->send_invoice_email($pallet);
        
        if ($result) {
            wp_send_json_success('Invoice sent successfully');
        } else {
            wp_send_json_error('Failed to send invoice');
        }
    }
    
    public function regenerate_pallet_pdf_frontend() {
        if (!check_ajax_referer('sum_frontend_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!$this->check_frontend_access()) {
            wp_send_json_error('Access denied');
            return;
        }
        
        $pallet_id = intval($_POST['pallet_id']);
        $pallet = $this->pallet_database->get_pallet($pallet_id);
        
        if (!$pallet) {
            wp_send_json_error('Pallet not found');
            return;
        }
        
        // Generate PDF
        require_once SUM_PLUGIN_PATH . 'includes/class-pallet-pdf-generator.php';
        $pdf_generator = new SUM_Pallet_PDF_Generator($this->pallet_database);
        $pdf_path = $pdf_generator->generate_invoice_pdf($pallet);
        
        if ($pdf_path && file_exists($pdf_path)) {
            // Get the URL for download
            $upload_dir = wp_upload_dir();
            $pdf_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $pdf_path);
            
            wp_send_json_success(array(
                'message' => 'PDF regenerated successfully',
                'download_url' => $pdf_url,
                'filename' => basename($pdf_path),
                'file_size' => filesize($pdf_path)
            ));
        } else {
            wp_send_json_error('Failed to generate PDF');
        }
    }
    
    private function check_frontend_access() {
        if (!is_user_logged_in()) {
            return false;
        }
        
        $current_user = wp_get_current_user();
        
        // Get allowed roles from main database
        global $wpdb;
        $settings_table = $wpdb->prefix . 'storage_settings';
        $allowed_roles = $wpdb->get_var($wpdb->prepare("SELECT setting_value FROM $settings_table WHERE setting_key = %s", 'allowed_roles'));
        
        if (!$allowed_roles) {
            $allowed_roles = 'administrator,storage_manager';
        }
        
        $allowed_roles = explode(',', $allowed_roles);
        $allowed_roles = array_map('trim', $allowed_roles);
        
        foreach ($allowed_roles as $role) {
            if (in_array($role, $current_user->roles)) {
                return true;
            }
        }
        
        return false;
    }
}