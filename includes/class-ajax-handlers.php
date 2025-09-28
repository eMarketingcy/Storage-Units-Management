<?php
/**
 * AJAX handlers for Storage Unit Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class SUM_Ajax_Handlers {
    
    private $database;
    
    public function __construct($database) {
        $this->database = $database;
    }
    
    public function init() {
        // Admin AJAX handlers
        add_action('wp_ajax_sum_get_units', array($this, 'get_units'));
        add_action('wp_ajax_sum_save_unit', array($this, 'save_unit'));
        add_action('wp_ajax_sum_delete_unit', array($this, 'delete_unit'));
        add_action('wp_ajax_sum_toggle_occupancy', array($this, 'toggle_occupancy'));
        add_action('wp_ajax_sum_bulk_add_units', array($this, 'bulk_add_units'));
        add_action('wp_ajax_sum_save_settings', array($this, 'save_settings'));
        add_action('wp_ajax_sum_create_frontend_page', array($this, 'create_frontend_page'));
        add_action('wp_ajax_sum_save_email_settings', array($this, 'save_email_settings'));
        add_action('wp_ajax_sum_save_payment_settings', array($this, 'save_payment_settings'));
        add_action('wp_ajax_sum_create_payment_page', array($this, 'create_payment_page'));
        add_action('wp_ajax_sum_send_manual_invoice', array($this, 'send_manual_invoice'));
        add_action('wp_ajax_sum_regenerate_pdf', array($this, 'regenerate_pdf'));
        
        // Frontend AJAX handlers
        add_action('wp_ajax_sum_get_units_frontend', array($this, 'get_units_frontend'));
        add_action('wp_ajax_sum_save_unit_frontend', array($this, 'save_unit_frontend'));
        add_action('wp_ajax_sum_delete_unit_frontend', array($this, 'delete_unit_frontend'));
        add_action('wp_ajax_sum_toggle_occupancy_frontend', array($this, 'toggle_occupancy_frontend'));
        add_action('wp_ajax_sum_bulk_add_units_frontend', array($this, 'bulk_add_units_frontend'));
        add_action('wp_ajax_sum_send_manual_invoice_frontend', array($this, 'send_manual_invoice_frontend'));
        add_action('wp_ajax_sum_regenerate_pdf_frontend', array($this, 'regenerate_pdf_frontend'));
        add_action('wp_ajax_sum_install_dompdf', array($this, 'install_dompdf'));

    }
    
public function install_dompdf() {
    if (!check_ajax_referer('sum_nonce', 'nonce', false)) {
        wp_send_json_error('Invalid nonce'); return;
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions'); return;
    }

    // Already installed?
    if (file_exists(SUM_DOMPDF_AUTO)) {
        wp_send_json_success('Dompdf already installed'); return;
    }

    // Make sure WP_Filesystem is ready
    if (!function_exists('request_filesystem_credentials')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    WP_Filesystem();

    // Pick a specific, known-good release to avoid 404s
    $version   = '3.1.2';
    $zip_url   = "https://github.com/dompdf/dompdf/archive/refs/tags/v{$version}/dompdf_{$version}.zip";
    $tmp_file  = download_url($zip_url);

    if (is_wp_error($tmp_file)) {
        wp_send_json_error('Download failed: ' . $tmp_file->get_error_message()); return;
    }

    // Ensure lib/ exists
    if (!wp_mkdir_p(SUM_VENDOR_PATH)) {
        @unlink($tmp_file);
        wp_send_json_error('Could not create lib/ directory'); return;
    }

    // Unzip into lib/
    if (!function_exists('unzip_file')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    $result = unzip_file($tmp_file, SUM_VENDOR_PATH);
    @unlink($tmp_file);

    if (is_wp_error($result)) {
        wp_send_json_error('Unzip failed: ' . $result->get_error_message()); return;
    }

    // Depending on the zip structure, Dompdf may be in lib/dompdf-*; normalize to lib/dompdf/
    $expected_auto = SUM_VENDOR_PATH . 'dompdf/autoload.inc.php';
    if (!file_exists($expected_auto)) {
        // Find the extracted folder that contains autoload.inc.php
        foreach (glob(SUM_VENDOR_PATH . 'dompdf*', GLOB_ONLYDIR) as $dir) {
            if (file_exists($dir . '/autoload.inc.php')) {
                // Rename to dompdf/
                @rename($dir, SUM_DOMPDF_DIR);
                break;
            }
        }
    }

    if (!file_exists(SUM_DOMPDF_AUTO)) {
        wp_send_json_error('Could not finalize Dompdf install. Check permissions.'); return;
    }

    wp_send_json_success('Dompdf installed');
}

    
    public function get_units() {
        if (!check_ajax_referer('sum_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $filter = sanitize_text_field($_POST['filter'] ?? 'all');
        $units = $this->database->get_units($filter);
        
        wp_send_json_success($units);
    }
    
    public function save_unit() {
        if (!check_ajax_referer('sum_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        if (empty($_POST['unit_name'])) {
            wp_send_json_error('Unit name is required');
            return;
        }
        
        $result = $this->database->save_unit($_POST);
        
        if ($result !== false) {
            $message = intval($_POST['unit_id'] ?? 0) > 0 ? 'Unit updated successfully' : 'Unit created successfully';
            wp_send_json_success($message);
        } else {
            wp_send_json_error('Failed to save unit');
        }
    }
    
    public function delete_unit() {
        if (!check_ajax_referer('sum_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $unit_id = intval($_POST['unit_id']);
        
        if ($unit_id <= 0) {
            wp_send_json_error('Invalid unit ID');
            return;
        }
        
        $result = $this->database->delete_unit($unit_id);
        
        if ($result !== false) {
            wp_send_json_success('Unit deleted successfully');
        } else {
            wp_send_json_error('Failed to delete unit');
        }
    }
    
    public function toggle_occupancy() {
        if (!check_ajax_referer('sum_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $unit_id = intval($_POST['unit_id']);
        
        if ($unit_id <= 0) {
            wp_send_json_error('Invalid unit ID');
            return;
        }
        
        $result = $this->database->toggle_occupancy($unit_id);
        
        if ($result !== false) {
            wp_send_json_success('Occupancy status updated');
        } else {
            wp_send_json_error('Failed to update occupancy status');
        }
    }
    
    public function bulk_add_units() {
        if (!check_ajax_referer('sum_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $prefix = sanitize_text_field($_POST['prefix']);
        $start_number = intval($_POST['start_number']);
        $end_number = intval($_POST['end_number']);
        
        if (empty($prefix) || $start_number <= 0 || $end_number <= 0 || $start_number > $end_number) {
            wp_send_json_error('Invalid parameters');
            return;
        }
        
        $result = $this->database->bulk_add_units($prefix, $start_number, $end_number, $_POST);
        
        if ($result['created'] > 0) {
            $message = "Successfully created {$result['created']} units";
            if (!empty($result['errors'])) {
                $message .= ". Errors: " . implode(', ', $result['errors']);
            }
            wp_send_json_success($message);
        } else {
            wp_send_json_error('Failed to create any units');
        }
    }
    
public function save_settings() {
    if (!check_ajax_referer('sum_nonce', 'nonce', false)) {
        wp_send_json_error('Invalid nonce');
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
        return;
    }

    // existing general settings
    $settings = array(
        'allowed_roles'   => sanitize_text_field($_POST['allowed_roles'] ?? 'administrator,storage_manager'),
        'email_enabled'   => isset($_POST['email_enabled']) ? '1' : '0',
        'admin_email'     => sanitize_email($_POST['admin_email'] ?? get_option('admin_email')),
        'email_subject_15'=> sanitize_text_field($_POST['email_subject_15'] ?? 'Storage Unit Reminder - 15 Days Until Expiration'),
        'email_subject_5' => sanitize_text_field($_POST['email_subject_5'] ?? 'Storage Unit Reminder - 5 Days Until Expiration'),
    );

    foreach ($settings as $key => $value) {
        $this->database->save_setting($key, $value);
    }

    // 🔽 ADD THIS: save VAT group coming from sum_settings[...]
    $vat_enabled = (isset($_POST['sum_settings']['vat_enabled']) && $_POST['sum_settings']['vat_enabled'] == '1') ? '1' : '0';
    $vat_rate    = isset($_POST['sum_settings']['vat_rate']) ? number_format((float)$_POST['sum_settings']['vat_rate'], 2, '.', '') : '0.00';
    $company_vat = isset($_POST['sum_settings']['company_vat']) ? sanitize_text_field($_POST['sum_settings']['company_vat']) : '';

    $this->database->save_setting('vat_enabled', $vat_enabled);
    $this->database->save_setting('vat_rate',    $vat_rate);
    $this->database->save_setting('company_vat', $company_vat);
    // 🔼 END ADD

    wp_send_json_success('Settings saved successfully');
}

public function update_setting($key, $value) {
    return $this->save_setting($key, $value);
}
    
    public function save_email_settings() {
        if (!check_ajax_referer('sum_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        // Handle file upload for company logo
        if (!empty($_FILES['company_logo']['name'])) {
            $uploaded_file = wp_handle_upload($_FILES['company_logo'], array('test_form' => false));
            if (!isset($uploaded_file['error'])) {
                $this->database->save_setting('company_logo', $uploaded_file['url']);
            }
        }
        
        $settings = array(
            'company_name' => sanitize_text_field($_POST['company_name'] ?? 'Self Storage Cyprus'),
            'company_address' => sanitize_textarea_field($_POST['company_address'] ?? ''),
            'company_phone' => sanitize_text_field($_POST['company_phone'] ?? ''),
            'company_email' => sanitize_email($_POST['company_email'] ?? get_option('admin_email')),
            'company_website' => esc_url_raw($_POST['company_website'] ?? home_url()),
            'invoice_email_subject' => sanitize_text_field($_POST['invoice_email_subject'] ?? 'Storage Unit Invoice'),
            'invoice_email_body' => wp_kses_post($_POST['invoice_email_body'] ?? ''),
            'reminder_email_body' => wp_kses_post($_POST['reminder_email_body'] ?? '')
        );
        
        foreach ($settings as $key => $value) {
            $this->database->save_setting($key, $value);
        }
        
        wp_send_json_success('Email settings saved successfully');
    }
    
    public function save_payment_settings() {
        if (!check_ajax_referer('sum_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $settings = array(
            'stripe_enabled' => sanitize_text_field($_POST['stripe_enabled'] ?? '0'),
            'stripe_publishable_key' => sanitize_text_field($_POST['stripe_publishable_key'] ?? ''),
            'stripe_secret_key' => sanitize_text_field($_POST['stripe_secret_key'] ?? ''),
            'woocommerce_integration' => sanitize_text_field($_POST['woocommerce_integration'] ?? '0'),
            'default_unit_price' => floatval($_POST['default_unit_price'] ?? 100.00),
            'currency' => sanitize_text_field($_POST['currency'] ?? 'EUR')
        );
        
        foreach ($settings as $key => $value) {
            $this->database->save_setting($key, $value);
        }
        
        wp_send_json_success('Payment settings saved successfully');
    }
    
    public function create_frontend_page() {
        if (!check_ajax_referer('sum_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        // Check if page already exists
        $existing_page = get_page_by_path('storage-units-manager');
        if ($existing_page) {
            wp_delete_post($existing_page->ID, true);
        }
        
        // Create new page
        $page_data = array(
            'post_title' => 'Storage Units Manager',
            'post_content' => '[storage_units_frontend]',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'storage-units-manager'
        );
        
        $page_id = wp_insert_post($page_data);
        
        if ($page_id) {
            wp_send_json_success('Frontend page created successfully');
        } else {
            wp_send_json_error('Failed to create frontend page');
        }
    }
    
    public function create_payment_page() {
        if (!check_ajax_referer('sum_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        // Check if page already exists
        $existing_page = get_page_by_path('storage-payment');
        if ($existing_page) {
            wp_delete_post($existing_page->ID, true);
        }
        
        // Create new page
        $page_data = array(
            'post_title' => 'Storage Payment',
            'post_content' => '[storage_payment_form]',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'storage-payment'
        );
        
        $page_id = wp_insert_post($page_data);
        
        if ($page_id) {
            wp_send_json_success('Payment page created successfully');
        } else {
            wp_send_json_error('Failed to create payment page');
        }
    }
    
    public function send_manual_invoice() {
        if (!check_ajax_referer('sum_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $unit_id = intval($_POST['unit_id']);
        $unit = $this->database->get_unit($unit_id);
        
        if (!$unit) {
            wp_send_json_error('Unit not found');
            return;
        }
        
        // Debug: Log invoice sending
        error_log("SUM: Sending manual invoice for unit {$unit_id}");
        
        // Initialize email handler and send invoice
        require_once SUM_PLUGIN_PATH . 'includes/class-email-handler.php';
        $email_handler = new SUM_Email_Handler($this->database);
        $result = $email_handler->send_invoice_email($unit);
        
        if ($result) {
            error_log("SUM: Invoice sent successfully");
            wp_send_json_success('Invoice sent successfully');
        } else {
            error_log("SUM: Failed to send invoice");
            wp_send_json_error('Failed to send invoice');
        }
    }
    
    public function regenerate_pdf() {
        if (!check_ajax_referer('sum_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $unit_id = intval($_POST['unit_id']);
        $unit = $this->database->get_unit($unit_id);
        
        if (!$unit) {
            wp_send_json_error('Unit not found');
            return;
        }
        
        error_log("SUM: Starting PDF regeneration for unit {$unit_id}");
        
        // Generate PDF
        require_once SUM_PLUGIN_PATH . 'includes/class-pdf-generator.php';
        $pdf_generator = new SUM_PDF_Generator($this->database);
        $pdf_path = $pdf_generator->generate_invoice_pdf($unit);
        
        error_log("SUM: PDF generation result: " . ($pdf_path ? $pdf_path : 'FAILED'));
        
        if ($pdf_path && file_exists($pdf_path)) {
            // Get the URL for download
            $upload_dir = wp_upload_dir();
            $pdf_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $pdf_path);
            
            error_log("SUM: PDF file exists at: {$pdf_path}");
            error_log("SUM: PDF URL: {$pdf_url}");
            error_log("SUM: File size: " . filesize($pdf_path) . " bytes");
            
            wp_send_json_success(array(
                'message' => 'PDF regenerated successfully',
                'download_url' => $pdf_url,
                'filename' => basename($pdf_path),
                'file_size' => filesize($pdf_path)
            ));
        } else {
            error_log("SUM: PDF file does not exist or generation failed");
            wp_send_json_error('Failed to generate PDF');
        }
    }
    
    // Frontend AJAX handlers (with different permission checks)
    public function get_units_frontend() {
        if (!check_ajax_referer('sum_frontend_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!$this->check_frontend_access()) {
            wp_send_json_error('Access denied');
            return;
        }
        
        $filter = sanitize_text_field($_POST['filter'] ?? 'all');
        $units = $this->database->get_units($filter);
        
        wp_send_json_success($units);
    }
    
    public function save_unit_frontend() {
        if (!check_ajax_referer('sum_frontend_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!$this->check_frontend_access()) {
            wp_send_json_error('Access denied');
            return;
        }
        
        if (empty($_POST['unit_name'])) {
            wp_send_json_error('Unit name is required');
            return;
        }
        
        $result = $this->database->save_unit($_POST);
        
        if ($result !== false) {
            $message = intval($_POST['unit_id'] ?? 0) > 0 ? 'Unit updated successfully' : 'Unit created successfully';
            wp_send_json_success($message);
        } else {
            wp_send_json_error('Failed to save unit');
        }
    }
    
    public function delete_unit_frontend() {
        if (!check_ajax_referer('sum_frontend_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!$this->check_frontend_access()) {
            wp_send_json_error('Access denied');
            return;
        }
        
        $unit_id = intval($_POST['unit_id']);
        
        if ($unit_id <= 0) {
            wp_send_json_error('Invalid unit ID');
            return;
        }
        
        $result = $this->database->delete_unit($unit_id);
        
        if ($result !== false) {
            wp_send_json_success('Unit deleted successfully');
        } else {
            wp_send_json_error('Failed to delete unit');
        }
    }
    
    public function toggle_occupancy_frontend() {
        if (!check_ajax_referer('sum_frontend_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!$this->check_frontend_access()) {
            wp_send_json_error('Access denied');
            return;
        }
        
        $unit_id = intval($_POST['unit_id']);
        
        if ($unit_id <= 0) {
            wp_send_json_error('Invalid unit ID');
            return;
        }
        
        $result = $this->database->toggle_occupancy($unit_id);
        
        if ($result !== false) {
            wp_send_json_success('Occupancy status updated');
        } else {
            wp_send_json_error('Failed to update occupancy status');
        }
    }
    
    public function bulk_add_units_frontend() {
        if (!check_ajax_referer('sum_frontend_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!$this->check_frontend_access()) {
            wp_send_json_error('Access denied');
            return;
        }
        
        $prefix = sanitize_text_field($_POST['prefix']);
        $start_number = intval($_POST['start_number']);
        $end_number = intval($_POST['end_number']);
        
        if (empty($prefix) || $start_number <= 0 || $end_number <= 0 || $start_number > $end_number) {
            wp_send_json_error('Invalid parameters');
            return;
        }
        
        $result = $this->database->bulk_add_units($prefix, $start_number, $end_number, $_POST);
        
        if ($result['created'] > 0) {
            $message = "Successfully created {$result['created']} units";
            if (!empty($result['errors'])) {
                $message .= ". Errors: " . implode(', ', $result['errors']);
            }
            wp_send_json_success($message);
        } else {
            wp_send_json_error('Failed to create any units');
        }
    }
    
    public function send_manual_invoice_frontend() {
        if (!check_ajax_referer('sum_frontend_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!$this->check_frontend_access()) {
            wp_send_json_error('Access denied');
            return;
        }
        
        $unit_id = intval($_POST['unit_id']);
        $unit = $this->database->get_unit($unit_id);
        
        if (!$unit) {
            wp_send_json_error('Unit not found');
            return;
        }
        
        // Debug: Log frontend invoice sending
        error_log("SUM: Sending frontend invoice for unit {$unit_id}");
        
        // Initialize email handler and send invoice
        require_once SUM_PLUGIN_PATH . 'includes/class-email-handler.php';
        $email_handler = new SUM_Email_Handler($this->database);
        $result = $email_handler->send_invoice_email($unit);
        
        if ($result) {
            error_log("SUM: Frontend invoice sent successfully");
            wp_send_json_success('Invoice sent successfully');
        } else {
            error_log("SUM: Failed to send frontend invoice");
            wp_send_json_error('Failed to send invoice');
        }
    }
    
    public function regenerate_pdf_frontend() {
        if (!check_ajax_referer('sum_frontend_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!$this->check_frontend_access()) {
            wp_send_json_error('Access denied');
            return;
        }
        
        $unit_id = intval($_POST['unit_id']);
        $unit = $this->database->get_unit($unit_id);
        
        if (!$unit) {
            wp_send_json_error('Unit not found');
            return;
        }
        
        error_log("SUM Frontend: Starting PDF regeneration for unit {$unit_id}");
        
        // Generate PDF
        require_once SUM_PLUGIN_PATH . 'includes/class-pdf-generator.php';
        $pdf_generator = new SUM_PDF_Generator($this->database);
        $pdf_path = $pdf_generator->generate_invoice_pdf($unit);
        
        error_log("SUM Frontend: PDF generation result: " . ($pdf_path ? $pdf_path : 'FAILED'));
        
        if ($pdf_path && file_exists($pdf_path)) {
            // Get the URL for download
            $upload_dir = wp_upload_dir();
            $pdf_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $pdf_path);
            
            error_log("SUM Frontend: PDF file exists at: {$pdf_path}");
            error_log("SUM Frontend: PDF URL: {$pdf_url}");
            error_log("SUM Frontend: File size: " . filesize($pdf_path) . " bytes");
            
            wp_send_json_success(array(
                'message' => 'PDF regenerated successfully',
                'download_url' => $pdf_url,
                'filename' => basename($pdf_path),
                'file_size' => filesize($pdf_path)
            ));
        } else {
            error_log("SUM Frontend: PDF file does not exist or generation failed");
            wp_send_json_error('Failed to generate PDF');
        }
    }
    
    private function check_frontend_access() {
        if (!is_user_logged_in()) {
            return false;
        }
        
        $current_user = wp_get_current_user();
        $allowed_roles = explode(',', $this->database->get_setting('allowed_roles', 'administrator,storage_manager'));
        $allowed_roles = array_map('trim', $allowed_roles);
        
        foreach ($allowed_roles as $role) {
            if (in_array($role, $current_user->roles)) {
                return true;
            }
        }
        
        return false;
    }
}