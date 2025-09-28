<?php
/**
 * Customer Invoice and PDF Generation Handler (CSSC)
 * Handles both legacy AJAX and modern REST endpoints for invoice generation.
 */
if (!defined('ABSPATH')) exit;

class SUM_Customer_Invoice_Handler_CSSC {

    // --- ENDPOINTS ---
    
    // Legacy AJAX Endpoint (for wp_ajax_sum_customers_frontend_generate_invoice)
    public static function ajax_generate_invoice() {
        try {
            // Check legacy nonce and permissions
            check_ajax_referer('sum_customers_frontend_nonce', 'nonce');
            if ( ! is_user_logged_in() || !current_user_can('manage_options')) {
                wp_send_json_error(['message' => 'Authorization failed.'], 403);
            }
            
            $customer_id = isset($_POST['customer_id']) ? absint($_POST['customer_id']) : 0;
            $result = self::generate($customer_id);
            wp_send_json_success($result);
        } catch (Throwable $e) {
            error_log('[SUM Invoice AJAX/Legacy Fatal] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            wp_send_json_error(['message' => 'Server error (Legacy AJAX). Check debug log.'], 500);
        }
    }

    public static function rest_generate_invoice( WP_REST_Request $req ) {
        try {
            // ... (rest of the code remains the same)
            $customer_id = absint( $req->get_param('customer_id') );
            $result = self::generate($customer_id);
            
            return new WP_REST_Response($result, 200); 
        } catch (Throwable $e) {
            
            // ==========================================================
            // >>> CRITICAL DEBUG FIX: Output full error details to frontend <<<
            // This will give us the exact missing file or class name.
            // ==========================================================
            
            error_log('[SUM Invoice REST/Fatal] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            
            // Send the precise error message, file, and line number to the frontend.
            return new WP_REST_Response([
                'code'    => 'sum_fatal',
                'message' => 'PHP FATAL: ' . $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ], 500);
            
            // ==========================================================
        }
    }

    // --- SHARED CORE GENERATION LOGIC ---

    protected static function generate($customer_id) {
        if ( ! $customer_id ) {
            throw new Exception('Missing customer ID.');
        }
        if ( ! class_exists('SUM_Customers_Module_CSSC') ) {
            throw new Exception('Customers module core not loaded.');
        }
        
        $main_db = SUM_Customers_Module_CSSC::get_main_db();
        $cust_db = SUM_Customers_Module_CSSC::get_db();

        // Fetch customer data using the dedicated method from the customer DB
        $customer = $cust_db->get_customer_by_id_cssc($customer_id); 
        if ( ! $customer ) {
            throw new Exception('Customer not found (ID: ' . $customer_id . ').');
        }
        
        // ✅ FIX: Use $cust_db instead of undefined $db variable
        $unpaid_items = $cust_db->get_unpaid_invoices_for_customer($customer);
        if ( empty($unpaid_items) ) {
            return ['message' => 'No unpaid items to invoice.'];
        }

        // --- PDF Generator Inclusion ---
        $pdf_generator_path = defined('SUM_PLUGIN_PATH') ? SUM_PLUGIN_PATH . 'includes/class-pdf-generator.php' : null;
        
        // Enhanced PDF generator loading with better error handling
        if ($pdf_generator_path && file_exists($pdf_generator_path) && !class_exists('SUM_PDF_Generator')) {
            require_once $pdf_generator_path;
        } elseif (!$pdf_generator_path) {
            throw new Exception('SUM_PLUGIN_PATH constant not defined. Cannot locate PDF generator.');
        } elseif (!file_exists($pdf_generator_path)) {
            throw new Exception('PDF generator file not found at: ' . $pdf_generator_path);
        }

        if ( ! class_exists('SUM_PDF_Generator') ) {
            throw new Exception('PDF generator class (SUM_PDF_Generator) could not be loaded. Check if the file exists and is properly formatted.');
        }

        // Enhanced PDF generation with better error handling
        try {
            $generator = new SUM_PDF_Generator($main_db);  
            $pdfPath = $generator->generate_invoice($customer, $unpaid_items);
        } catch (Exception $e) {
            // Log the full error for debugging
            error_log('[SUM PDF Generation Error] ' . $e->getMessage());
            throw new Exception('PDF Generator failed: ' . $e->getMessage());
        }

        // Validate PDF generation result
        if (empty($pdfPath)) {
            throw new Exception('PDF Generator returned empty path.');
        }
        
        if (!file_exists($pdfPath)) {
            throw new Exception('PDF file was not created at expected path: ' . $pdfPath . '. Check directory permissions and available disk space.');
        }
        
        // Check if file has content (not empty)
        $fileSize = filesize($pdfPath);
        if ($fileSize === 0) {
            // Delete the empty file
            @unlink($pdfPath);
            throw new Exception('PDF file was created but is empty (0 bytes). This usually indicates an issue with the PDF library or HTML content. Check error logs for details.');
        }
        
        if ($fileSize === false) {
            throw new Exception('Cannot read PDF file size. File may be corrupted: ' . $pdfPath);
        }

        // Convert path to URL
        $uploads  = wp_upload_dir();
        
        // Check if uploads directory is accessible
        if (!$uploads || !empty($uploads['error'])) {
            throw new Exception('WordPress uploads directory error: ' . ($uploads['error'] ?? 'Unknown error'));
        }
        
        $base_dir = trailingslashit($uploads['basedir']);
        $base_url = trailingslashit($uploads['baseurl']);

        if ( strpos( $pdfPath, $base_dir ) !== 0 ) {
             throw new Exception('PDF file path is outside WordPress uploads directory. Expected: ' . $base_dir . ', Got: ' . $pdfPath);
        }

        $rel    = ltrim(str_replace($base_dir, '', $pdfPath), '/');
        $pdfUrl = $base_url . $rel;
        
        // Final verification that the URL is accessible
        if (!filter_var($pdfUrl, FILTER_VALIDATE_URL)) {
            throw new Exception('Generated PDF URL is invalid: ' . $pdfUrl);
        }

        return [
            'pdf_url' => esc_url_raw($pdfUrl),
            'message' => 'Invoice generated successfully.',
            'debug_info' => [
                'pdf_path' => $pdfPath,
                'file_size' => $fileSize,
                'uploads_dir' => $base_dir,
                'pdf_url' => $pdfUrl,
                'pdf_library' => $this->pdf_library ?? 'unknown'
            ]
        ];
    }
}