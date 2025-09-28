<?php
/**
 * PDF Generator for Customer Invoices
 * Supports both DomPDF and TCPDF libraries
 */
if (!defined('ABSPATH')) exit;

class SUM_PDF_Generator {
    
    private $main_db;
    private $pdf_library = 'dompdf'; // Default to dompdf, fallback to tcpdf
    
    public function __construct($main_db) {
        $this->main_db = $main_db;
        $this->initialize_pdf_library();
    }
    
    /**
     * Initialize PDF library (DomPDF first, TCPDF as fallback)
     */
    private function initialize_pdf_library() {
        // Try DomPDF first
        if ($this->initialize_dompdf()) {
            $this->pdf_library = 'dompdf';
            return;
        }
        
        // Fallback to TCPDF
        if ($this->initialize_tcpdf()) {
            $this->pdf_library = 'tcpdf';
            return;
        }
        
        throw new Exception('Neither DomPDF nor TCPDF libraries could be initialized. Please check if the libraries are installed in the lib/ directory.');
    }
    
    /**
     * Initialize DomPDF library
     */
    private function initialize_dompdf() {
        try {
            // Check if DomPDF autoloader exists
            $dompdf_autoloader = defined('SUM_DOMPDF_AUTO') ? SUM_DOMPDF_AUTO : null;
            
            if (!$dompdf_autoloader || !file_exists($dompdf_autoloader)) {
                error_log('DomPDF autoloader not found at: ' . ($dompdf_autoloader ?: 'undefined path'));
                return false;
            }
            
            require_once $dompdf_autoloader;
            
            if (!class_exists('Dompdf\Dompdf')) {
                error_log('DomPDF class not available after loading autoloader');
                return false;
            }
            
            return true;
        } catch (Exception $e) {
            error_log('DomPDF initialization failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Initialize TCPDF library
     */
    private function initialize_tcpdf() {
        try {
            $tcpdf_path = defined('K_PATH_MAIN') ? K_PATH_MAIN : null;
            
            if (!$tcpdf_path || !file_exists($tcpdf_path . 'tcpdf.php')) {
                error_log('TCPDF not found at: ' . ($tcpdf_path ? $tcpdf_path . 'tcpdf.php' : 'undefined path'));
                return false;
            }
            
            require_once $tcpdf_path . 'tcpdf.php';
            
            if (!class_exists('TCPDF')) {
                error_log('TCPDF class not available after loading');
                return false;
            }
            
            return true;
        } catch (Exception $e) {
            error_log('TCPDF initialization failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generate invoice PDF
     */

    public function generate_invoice($customer_data, $unpaid_items) {
        if (empty($unpaid_items) || !$this->main_db) { // Using $this->main_db now
            return false;
        }

        // 1. Calculate Grand Total
        $grand_total = 0;
        foreach ($unpaid_items as $item) {
            $grand_total += floatval($item['amount']);
        }
        
        // 2. Generate HTML (Multi-item)
        $html = $this->generate_multi_invoice_html($customer_data, $unpaid_items, $grand_total);
        
        // 3. Prepare File Paths
        $upload_dir = wp_upload_dir();
        $pdf_dir = $upload_dir['basedir'] . '/invoices';
        if (!file_exists($pdf_dir)) { wp_mkdir_p($pdf_dir); }
        if (!is_writable($pdf_dir)) { @chmod($pdf_dir, 0755); } // Ensure permissions are right
        
        $filename_base = sanitize_file_name($customer_data['name'] . '-INV-' . $customer_data['id']);
        $pdf_filename  = $filename_base . '-' . date('Ymd-His') . '.pdf';
        $pdf_filepath  = trailingslashit($pdf_dir) . $pdf_filename;

        // 4. CRITICAL: Increase timeout and memory for the PDF process
        if (function_exists('set_time_limit')) { set_time_limit(60); }
        if (function_exists('ini_set')) { ini_set('memory_limit', '512M'); } 

        // 5. Try to Create PDF file using the initialized library
        $success = false;

        if ($this->pdf_library === 'dompdf' && class_exists('\Dompdf\Dompdf')) {
            $success = $this->generate_dompdf($html, $pdf_filepath);
        } elseif ($this->pdf_library === 'tcpdf' && class_exists('TCPDF')) {
             // If TCPDF is the fallback engine
             $success = $this->generate_tcpdf($html, $customer_data, $pdf_filepath);
        } else {
             throw new Exception("Neither DomPDF nor TCPDF is available or initialized.");
        }

        // 6. Final Verification (This is what triggers your error message)
        if ($success && file_exists($pdf_filepath) && filesize($pdf_filepath) > 0) {
            // Success: Return the local path
            return $pdf_filepath;
        } else {
             // Failure: Throw an exception with the specific path that failed.
             throw new Exception("PDF file was not created at expected path: " . $pdf_filepath . ". Check directory permissions and available disk space.");
        }
    }
        
    /**
     * Generate PDF using DomPDF
     */
private function generate_with_dompdf($html, $filepath) {
    // Use Dompdf 1.x/2.x canonical option names
    // Build writable caches inside uploads/
    $uploads      = wp_upload_dir();
    $temp_dir     = trailingslashit($uploads['basedir']) . 'dompdf-temp';
    $font_cache   = trailingslashit($uploads['basedir']) . 'dompdf-fonts';
    $chroot       = untrailingslashit($uploads['basedir']); // limit FS access to uploads

    if ( ! file_exists($temp_dir) )  wp_mkdir_p($temp_dir);
    if ( ! file_exists($font_cache) ) wp_mkdir_p($font_cache);

    // (Optional) dump HTML for debugging when WP_DEBUG is on
    if ( defined('WP_DEBUG') && WP_DEBUG ) {
        @file_put_contents(trailingslashit($uploads['basedir']).'invoices/__last_invoice.html', $html);
    }

    try {
        $options = new \Dompdf\Options();
        // Correct option names:
        $options->set('isRemoteEnabled', true);         // allow http(s) images/logos if any
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        // Writable caches
        $options->set('tempDir',   $temp_dir);
        $options->set('fontCache', $font_cache);
        // Constrain filesystem access (inside uploads)
        $options->set('chroot', $chroot);
        // Optionally pick a sane default font for Unicode
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        // If your $html is UTF-8, pass the encoding param:
        $dompdf->loadHtml($html, 'UTF-8');

        @set_time_limit(90);
        $dompdf->render();

        $output = $dompdf->output();
        if ($output === '' || $output === null) {
            throw new \Exception('DomPDF generated empty output. Check HTML, fonts, and temp/cache permissions.');
        }

        $bytes = @file_put_contents($filepath, $output);
        if ($bytes === false || $bytes === 0) {
            throw new \Exception('Failed to write PDF file to disk: ' . $filepath);
        }
    } catch (\Throwable $e) {
        throw new \Exception('DomPDF generation failed: ' . $e->getMessage());
    }
}
    
    /**
     * Generate PDF using TCPDF
     */
    private function generate_with_tcpdf($html, $filepath) {
        try {
            $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
            
            // Set document information
            $pdf->SetCreator('Storage Management System');
            $pdf->SetAuthor('Storage Management System');
            $pdf->SetTitle('Customer Invoice');
            
            // Remove default header/footer
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            
            // Set margins
            $pdf->SetMargins(15, 15, 15);
            $pdf->SetAutoPageBreak(TRUE, 15);
            
            // Add a page
            $pdf->AddPage();
            
            // Write HTML content
            $pdf->writeHTML($html, true, false, true, false, '');
            
            // Output to file
            $result = $pdf->Output($filepath, 'F');
            if (!$result) {
                throw new Exception('TCPDF failed to save file to: ' . $filepath);
            }
            
        } catch (Exception $e) {
            throw new Exception('TCPDF generation failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate HTML content for invoice
     */
    private function generate_invoice_html($customer, $unpaid_items) {
        $company_name = $this->get_setting('company_name', 'Storage Management System');
        $company_address = $this->get_setting('company_address', '');
        $company_phone = $this->get_setting('company_phone', '');
        $company_email = $this->get_setting('company_email', '');
        
        $invoice_date = date('F j, Y');
        $invoice_number = 'INV-' . date('Y-m-d-H-i-s');
        
        // Calculate totals
        $subtotal = 0;
        foreach ($unpaid_items as $item) {
            $subtotal += floatval($item['amount']);
        }
        
        $tax_rate = floatval($this->get_setting('tax_rate', 0));
        $tax_amount = $subtotal * ($tax_rate / 100);
        $total = $subtotal + $tax_amount;
        
        // Build items HTML
        $items_html = '';
        foreach ($unpaid_items as $item) {
            $amount = number_format(floatval($item['amount']), 2);
            $items_html .= "
                <tr>
                    <td style='padding: 10px; border-bottom: 1px solid #ddd;'>" . esc_html($item['type']) . "</td>
                    <td style='padding: 10px; border-bottom: 1px solid #ddd;'>" . esc_html($item['name']) . "</td>
                    <td style='padding: 10px; border-bottom: 1px solid #ddd;'>" . esc_html($item['status']) . "</td>
                    <td style='padding: 10px; border-bottom: 1px solid #ddd; text-align: right;'>€{$amount}</td>
                </tr>
            ";
        }
        
        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Invoice</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 20px; color: #333; }
                .header { text-align: center; margin-bottom: 30px; }
                .company-info { margin-bottom: 20px; }
                .invoice-info { margin-bottom: 30px; }
                .customer-info { margin-bottom: 30px; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                th { background-color: #f5f5f5; padding: 12px; text-align: left; border-bottom: 2px solid #ddd; }
                td { padding: 10px; border-bottom: 1px solid #ddd; }
                .totals { text-align: right; margin-top: 20px; }
                .total-row { font-weight: bold; font-size: 1.2em; }
                .footer { margin-top: 40px; text-align: center; font-size: 0.9em; color: #666; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>INVOICE</h1>
            </div>
            
            <div class='company-info'>
                <h2>" . esc_html($company_name) . "</h2>
                " . ($company_address ? "<p>" . nl2br(esc_html($company_address)) . "</p>" : "") . "
                " . ($company_phone ? "<p>Phone: " . esc_html($company_phone) . "</p>" : "") . "
                " . ($company_email ? "<p>Email: " . esc_html($company_email) . "</p>" : "") . "
            </div>
            
            <div class='invoice-info'>
                <p><strong>Invoice Number:</strong> {$invoice_number}</p>
                <p><strong>Invoice Date:</strong> {$invoice_date}</p>
            </div>
            
            <div class='customer-info'>
                <h3>Bill To:</h3>
                <p><strong>" . esc_html($customer['name'] ?? 'N/A') . "</strong></p>
                " . (!empty($customer['email']) ? "<p>Email: " . esc_html($customer['email']) . "</p>" : "") . "
                " . (!empty($customer['phone']) ? "<p>Phone: " . esc_html($customer['phone']) . "</p>" : "") . "
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Item</th>
                        <th>Status</th>
                        <th style='text-align: right;'>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    {$items_html}
                </tbody>
            </table>
            
            <div class='totals'>
                <p>Subtotal: €" . number_format($subtotal, 2) . "</p>
                " . ($tax_rate > 0 ? "<p>Tax ({$tax_rate}%): €" . number_format($tax_amount, 2) . "</p>" : "") . "
                <p class='total-row'>Total: €" . number_format($total, 2) . "</p>
            </div>
            
            <div class='footer'>
                <p>Thank you for your business!</p>
                <p>Generated on " . date('F j, Y \a\t g:i A') . "</p>
            </div>
        </body>
        </html>
        ";
        
        return $html;
    }
    
    /**
     * Get setting from main database
     */
    private function get_setting($key, $default = '') {
        if ($this->main_db && method_exists($this->main_db, 'get_setting')) {
            return $this->main_db->get_setting($key, $default);
        }
        return $default;
    }
}
?>