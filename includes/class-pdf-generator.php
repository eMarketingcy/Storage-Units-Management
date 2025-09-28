<?php
/**
 * PDF Invoice Generator for Storage Unit Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class SUM_PDF_Generator {
    private $pdf_engine = 'tcpdf'; // 'dompdf' or 'tcpdf'

private function load_pdf_engine() {
    if (class_exists('\Dompdf\Dompdf') || file_exists(SUM_DOMPDF_AUTO)) {
        if (!class_exists('\Dompdf\Dompdf')) {
            require_once SUM_DOMPDF_AUTO;
        }
        $this->pdf_engine = 'dompdf';
        return;
    }
    // fallback to TCPDF (your existing logic)
    $this->pdf_engine = 'tcpdf';
    $this->load_tcpdf();
}

    private $database;
    
   public function __construct($database = null) {
        $this->database = $database;
        // Fallback: If no database instance is provided, attempt to get the global one.
        if (!$this->database && class_exists('SUM_Customers_Module_CSSC')) {
            $this->database = SUM_Customers_Module_CSSC::get_db();
        }
        $this->load_pdf_engine();
    }
    
    // NEW FUNCTION: Handles the request from the AJAX handler (module.php)
    public function generate_invoice($customer_data, $unpaid_items) {
        if (empty($unpaid_items) || !$this->database) {
            return false;
        }

        // Ensure Billing Calculator is loaded, as the HTML generator might call it.
        if (!class_exists('SUM_Rental_Billing_Calculator')) {
            // Use the now-defined constant SUM_PLUGIN_PATH
            require_once SUM_PLUGIN_PATH . 'includes/class-rental-billing-calculator.php';
        }
        
        // 1. Calculate Grand Total
        $grand_total = 0;
        foreach ($unpaid_items as $item) {
            $grand_total += floatval($item['amount']);
        }
        
        // 2. Generate HTML (Multi-item)
        $html = $this->generate_multi_invoice_html($customer_data, $unpaid_items, $grand_total);
        
        // 3. Create PDF file
        $unit_data = [
            'id' => $customer_data['id'],
            'unit_name' => $customer_data['name'] . '-INV', 
            'primary_contact_name' => $customer_data['name'],
            'primary_contact_email' => $customer_data['full_email'],
            'primary_contact_phone' => $customer_data['full_phone']
        ];
        
        // Final sanity check before calling the PDF creation chain:
        // The load_pdf_engine() within the constructor or the first call to create_pdf_file() 
        // will handle loading TCPDF.
        
        // Use the dedicated PDF file creation method
        $pdf_url = $this->create_pdf_file($html, $unit_data);
        
        // For debugging 500 errors, log the outcome just before returning
        error_log('SUM PDF: Final invoice generation attempt result: ' . ($pdf_url ? 'SUCCESS' : 'FAILURE'));
        
        return $pdf_url ? wp_upload_dir()['baseurl'] . '/invoices/' . basename($pdf_url) : false;
    }
    
    
    public function generate_invoice_pdf($unit_data) {
        error_log("SUM PDF: Starting PDF generation for unit {$unit_data['id']}");
        error_log("SUM PDF: Unit data: " . print_r($unit_data, true));
        
        // Calculate payment amount using billing calculator
        $monthly_price = floatval($unit_data['monthly_price'] ?: $this->database->get_setting('default_unit_price', 100));
        
        // Use billing calculator for proper month calculation
        require_once SUM_PLUGIN_PATH . 'includes/class-rental-billing-calculator.php';
        
        $billing_result = null;
        $months_due = 1; // Default fallback
        $total_amount = $monthly_price;
        
        if ( ! class_exists('SUM_PDF_Generator') ) {
            throw new Exception('PDF generator class missing. Cannot proceed.');
        }
        
        $generator = new SUM_PDF_Generator($db); 
        
        if (!empty($unit_data['period_from']) && !empty($unit_data['period_until'])) {
            try {
                error_log("SUM PDF: Calculating billing for period {$unit_data['period_from']} to {$unit_data['period_until']}");
                
                $billing_result = calculate_billing_months(
                    $unit_data['period_from'], 
                    $unit_data['period_until'], 
                    ['monthly_price' => $monthly_price]
                );
                
                // Use occupied months for billing (lenient approach - any month touched)
                $months_due = $billing_result['occupied_months'];
                $total_amount = $monthly_price * $months_due;
                
                error_log("SUM PDF: Billing calculation result:");
                error_log("SUM PDF: - Full months: {$billing_result['full_months']}");
                error_log("SUM PDF: - Occupied months: {$months_due}");
                error_log("SUM PDF: - Monthly price: {$monthly_price}");
                error_log("SUM PDF: - Total amount: {$total_amount}");
                error_log("SUM PDF: - Months breakdown: " . print_r($billing_result['months'], true));
                
            } catch (Exception $e) {
                error_log('SUM PDF: Billing calculator error: ' . $e->getMessage());
                // Fallback to simple calculation
                $billing_result = null;
            }
        } else {
            error_log("SUM PDF: Missing period dates, using default 1 month");
        }
        
        // Try to load TCPDF
        $this->load_pdf_engine();
        
        // Generate HTML content for PDF
        $html = $this->generate_invoice_html($unit_data, $total_amount, $monthly_price, $months_due, $billing_result);
        
        // Create PDF file
        return $this->create_pdf_file($html, $unit_data);
    }
    
    private function load_tcpdf() {
        if (!class_exists('TCPDF')) {
            // Try to include TCPDF from common locations
            $tcpdf_paths = array(
                ABSPATH . 'wp-content/plugins/tcpdf/tcpdf.php',
                ABSPATH . 'wp-includes/tcpdf/tcpdf.php',
                SUM_PLUGIN_PATH . 'lib/tcpdf/tcpdf.php'
            );
            
            foreach ($tcpdf_paths as $path) {
                if (file_exists($path)) {
                    require_once($path);
                    break;
                }
            }
        }
    }
    
private function create_pdf_file($html, $unit_data) {
    $upload_dir = wp_upload_dir();
    $pdf_dir = $upload_dir['basedir'] . '/invoices';
    if (!file_exists($pdf_dir)) { wp_mkdir_p($pdf_dir); }
    if (!is_writable($pdf_dir)) { @chmod($pdf_dir, 0755); }

    $pdf_filename = 'invoice-' . $unit_data['unit_name'] . '-' . date('Y-m-d-H-i-s') . '.pdf';
    $pdf_filepath = trailingslashit($pdf_dir) . $pdf_filename;

    // 1) Dompdf
    if (function_exists('sum_load_dompdf') && sum_load_dompdf()) {
        $ok = $this->generate_dompdf($html, $pdf_filepath);
        if ($ok) return $pdf_filepath;
    }

    // 2) TCPDF (your existing fallback)
    if (class_exists('TCPDF')) {
        $ok = $this->generate_tcpdf($html, $unit_data, $pdf_filepath);
        if ($ok) return $ok;
    }

    // 3) HTML fallback
    return $this->generate_html_pdf($html, $unit_data, $pdf_filepath);
}

private function generate_dompdf($html, $pdf_filepath) {
    try {
        $opts = new \Dompdf\Options();
        $opts->set('isRemoteEnabled', true);
        $opts->set('defaultMediaType', 'print');
        $opts->set('isHtml5ParserEnabled', true);
        $opts->set('defaultFont', 'DejaVu Sans');

        $dompdf = new \Dompdf\Dompdf($opts);
        $dompdf->setPaper('A4', 'portrait');

        $html_doc = '<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
        $dompdf->loadHtml($html_doc);
        $dompdf->render();

        $output = $dompdf->output();
        return (false !== file_put_contents($pdf_filepath, $output));
    } catch (\Throwable $e) {
        error_log('SUM Dompdf error: ' . $e->getMessage());
        return false;
    }
}


    
    private function generate_tcpdf($html, $unit_data, $pdf_filepath) {
        try {
            error_log("SUM PDF: Starting TCPDF generation");
            
            $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
            
            // Set document information
            $pdf->SetCreator('Storage Unit Manager');
            $pdf->SetAuthor($this->database->get_setting('company_name', 'Self Storage Cyprus'));
            $pdf->SetTitle('Storage Unit Invoice - ' . $unit_data['unit_name']);
            $pdf->SetSubject('Storage Unit Invoice');
            
            // Remove default header/footer
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            
            // Set margins
            $pdf->SetMargins(15, 15, 15);
            $pdf->SetAutoPageBreak(TRUE, 15);
            
            // Add a page
            $pdf->AddPage();
            
            // Set font
            $pdf->SetFont('helvetica', '', 12);
            
            error_log("SUM PDF: Writing HTML to PDF");
            
            // Print HTML content
            $pdf->writeHTML($html, true, false, true, false, '');
            
            error_log("SUM PDF: Saving PDF to: {$pdf_filepath}");
            
            // Save PDF to file
            $pdf->Output($pdf_filepath, 'F');
            
            // Verify file was created
            if (file_exists($pdf_filepath)) {
                $file_size = filesize($pdf_filepath);
                error_log("SUM PDF: File created successfully, size: {$file_size} bytes");
                
                // Check if file has content
                if ($file_size > 0) {
                    return $pdf_filepath;
                } else {
                    error_log("SUM PDF: File created but is empty");
                    return false;
                }
            } else {
                error_log("SUM PDF: File was not created");
                return false;
            }
            
        } catch (Exception $e) {
            error_log('SUM PDF: TCPDF generation error: ' . $e->getMessage());
            return $this->generate_html_pdf($html, $unit_data, $pdf_filepath);
        }
    }
    
    private function generate_html_pdf($html, $unit_data, $pdf_filepath) {
        error_log("SUM PDF: Using HTML fallback method");
        
        // Create a complete HTML document
        $pdf_content = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice - ' . esc_html($unit_data['unit_name']) . '</title>
    <style>
        body { margin: 0; padding: 20px; font-family: Arial, sans-serif; }
        @media print { body { margin: 0; } }
    </style>
</head>
<body>' . $html . '</body>
</html>';
        
        $write_result = file_put_contents($pdf_filepath, $pdf_content);
        
        if ($write_result !== false) {
            error_log("SUM PDF: HTML fallback file created, size: {$write_result} bytes");
            
            if (file_exists($pdf_filepath) && filesize($pdf_filepath) > 0) {
                return $pdf_filepath;
            } else {
                error_log("SUM PDF: HTML fallback file verification failed");
                return false;
            }
        } else {
            error_log("SUM PDF: Failed to write HTML fallback file");
            return false;
        }
    }
    
    private function generate_invoice_html($unit_data, $total_amount, $monthly_price, $months_due, $billing_result = null) {
        $company_name = $this->database->get_setting('company_name', 'Self Storage Cyprus');
        $company_address = $this->database->get_setting('company_address', '');
        $company_phone = $this->database->get_setting('company_phone', '');
        $company_email = $this->database->get_setting('company_email', get_option('admin_email'));
        $company_logo = $this->database->get_setting('company_logo', '');
        $currency = $this->database->get_setting('currency', 'EUR');
        $currency_symbol = $currency === 'USD' ? '$' : ($currency === 'GBP' ? 'Â£' : 'â‚¬');
        
        $vat_enabled = ($this->database->get_setting('vat_enabled', '0') === '1');
         $vat_rate    = (float) $this->database->get_setting('vat_rate', '0');   // e.g. 19
      $company_vat = $this->database->get_setting('company_vat', '');         // your VAT / Tax ID
    
    // Subtotal is your computed total_amount (ex VAT)
    $subtotal    = (float) $total_amount;
    $vat_amount  = $vat_enabled ? round($subtotal * ($vat_rate / 100), 2) : 0.00;
    $grand_total = round($subtotal + $vat_amount, 2);
        
        error_log("SUM PDF HTML: Generating with {$months_due} months, total: {$total_amount}");
        // --- Minimalist Design Variables (Orange Accent) ---
    $accent_color = '#f97316'; // Orange for Pallets
    $light_bg = '#fff7ed';
    $border_color = '#e5e7eb';
        $html = '
        <style>
        /* MINIMALIST PALLET STYLES */
        body { font-family: Arial, sans-serif; color: #333; font-size: 11px; line-height: 1.4; }
        .accent-color { color: ' . $accent_color . '; }
        .bg-accent { background-color: ' . $accent_color . '; color: white; }
        .bg-light { background-color: ' . $light_bg . '; }
        
        .header-table { width: 100%; margin-bottom: 20px; border-collapse: collapse; }
        .header-table td { vertical-align: top; padding: 0; }
        
        .invoice-title { font-size: 28px; font-weight: bold; text-align: right; margin-bottom: 15px; }
        .invoice-meta { background-color: ' . $light_bg . '; padding: 10px; border-left: 4px solid ' . $accent_color . '; margin-top: 10px; font-size: 10px; }
        
        .section-title { font-size: 14px; font-weight: bold; margin-bottom: 10px; padding-bottom: 5px; border-bottom: 1px solid ' . $border_color . '; }
        .customer-info { margin-bottom: 20px; padding: 10px 0; font-size: 12px; }
        .customer-name { font-size: 16px; font-weight: bold; margin-bottom: 5px; }

        /* Pallet Details Bar */
        .pallet-details-bar { background-color: ' . $accent_color . '; color: white; padding: 15px; margin-bottom: 20px; }
        .pallet-name { font-size: 18px; font-weight: bold; margin: 0; }
        .pallet-specs { font-size: 11px; margin-top: 5px; }
        
        /* Billing Table */
        .billing-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .billing-table th { background-color: #f1f5f9; color: #333; padding: 10px; text-align: left; font-size: 11px; border-bottom: 1px solid ' . $border_color . '; }
        .billing-table td { padding: 10px; border-bottom: 1px dashed #eee; font-size: 11px; }
        .amount-cell { text-align: right; font-weight: bold; }
        
        /* Totals Block (Cleaned up) */
        .totals-block { width: 40%; float: right; margin-top: 10px; }
        .totals-table { width: 100%; border-collapse: collapse; text-align: right; }
        .totals-table td { padding: 5px 0; font-size: 11px; }
        .totals-table .total-row td { 
            border-top: 2px solid ' . $accent_color . '; 
            font-size: 14px; 
            font-weight: bold;
            padding-top: 8px; 
        }
        
        .invoice-footer { text-align: center; border-top: 1px solid ' . $border_color . '; padding-top: 15px; color: #6b7280; font-size: 10px; margin-top: 60px; }
    </style>
        
        <!-- Header Section -->
        <table class="header-table">
        <tr>
            <td width="50%">
                        ' . ($company_logo ? '<img src="' . $company_logo . '" style="max-height: 50px; margin-bottom: 10px;"><br>' : '') . '
                        <div class="company-name">' . esc_html($company_name) . '</div>
                        <div style="font-size: 10px; color: #6b7280;">
                            ' . nl2br(esc_html($company_address)) . '<br>
                            ' . ($company_phone ? 'Phone: ' . esc_html($company_phone) . '<br>' : '') . '
                            Email: ' . esc_html($company_email) . '
                        </div>
                    </td>
                    <td width="50%" style="text-align: right;">
                        <div class="invoice-title">UNIT INVOICE</div>
                        <div class="invoice-meta">
                            <strong>Invoice #:</strong> INV-' . $unit_data['id'] . '-' . date('Ymd') . '<br>
                            <strong>Date:</strong> ' . date('M d, Y') . '<br>
                            <strong>Due Date:</strong> ' . date('M d, Y', strtotime('+30 days')) . '
                        </div>
                    </td>
                </tr>
            </table>
        
        <!-- Bill To Section -->
        <div class="section-title">Bill To</div>
        <div class="customer-info">
            <div class="customer-name">' . esc_html($unit_data['primary_contact_name'] ?: 'N/A') . '</div>
            <div>Phone: ' . esc_html($unit_data['primary_contact_phone'] ?: 'N/A') . '</div>
            <div>Email: ' . esc_html($unit_data['primary_contact_email'] ?: 'N/A') . '</div>
        </div>
        
        <div class="pallet-details-bar bg-accent">
        <div class="pallet-name">Unit ' . esc_html($unit_data['unit_name']) . ' |
        <div class="pallet-specs">
                Size: ' . esc_html($unit_data['size'] ?: 'Standard') .  ' |
                </td>
                Area: ' . ($unit_data['sqm'] ? esc_html($unit_data['sqm']) . ' mÂ²' : 'N/A') . '</div>
                </td>
                Monthly Rate: ' . $currency_symbol . number_format($monthly_price, 2) . '
            </div>
        </div>
        <div class="section-title">Billing Details</div>
        <table class="billing-table">
            <thead>
                <tr>
                    <th style="width: 50%;">Description / Period</th>
                    <th style="width: 15%; text-align: center;">Qty (Months)</th>
                    <th style="width: 15%; text-align: right;">Rate</th>
                    <th style="width: 20%; text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                ' . ($billing_result ? $this->generate_modern_billing_breakdown($billing_result, $monthly_price, $currency_symbol) : $this->generate_simple_billing_row($unit_data, $months_due, $monthly_price, $currency_symbol)) . '
            </tbody>
        </table>
        
        <div class="totals-block">
        <table class="totals-table">
            <tr>
                <td>Subtotal (ex VAT):</td>
                <td><strong>' . $currency_symbol . number_format($subtotal,2) . ' </strong></td>
  </tr>
  <tr>
    <td>VAT ( '. number_format($vat_rate,2) . ' % )</td>
    <td><strong> ' . $currency_symbol . number_format($vat_amount,2) .'</strong></td>
</tr>
<tr class="total-row">
<td>Total (incl. VAT) </td>
    <td>' . $currency_symbol . number_format($grand_total,2) .' 
    </td>
    </tr>
</table>
</div>

<div style="clear: both;"></div>

        
<div class="invoice-footer">
            < <p>VAT / Tax ID: ' . esc_html($company_vat) . '</p>
            <p>Thank you for choosing <span class="accent-color" style="color: ' . $accent_color . ';">' . esc_html($company_name) . '</span> Pallet Storage. Payment is due within 30 days of invoice date.</p>
        </div>';
        
        return $html;
    }
    
     // NEW PRIVATE FUNCTION: Generates HTML for a multi-item invoice (copy of existing structure)
    private function generate_multi_invoice_html($customer_data, $unpaid_items, $grand_total) {
        // --- Settings Retrieval ---
        $company_name = $this->database->get_setting('company_name', 'Self Storage Cyprus');
        $company_address = $this->database->get_setting('company_address', '');
        $company_phone = $this->database->get_setting('company_phone', '');
        $company_email = $this->database->get_setting('company_email', get_option('admin_email'));
        $company_logo = $this->database->get_setting('company_logo', '');
        $currency = $this->database->get_setting('currency', 'EUR');
        $currency_symbol = $currency === 'USD' ? '$' : ($currency === 'GBP' ? '£' : '€');
        
        $vat_enabled = ($this->database->get_setting('vat_enabled', '0') === '1');
        $vat_rate    = (float) $this->database->get_setting('vat_rate', '0');   
        
        // --- Totals Calculation ---
        $subtotal_ex_vat = $grand_total; // Assuming items already include the full monthly price
        $vat_amount      = $vat_enabled ? round($subtotal_ex_vat * ($vat_rate / 100), 2) : 0.00;
        $grand_total_inc_vat = round($subtotal_ex_vat + $vat_amount, 2);
        
        // --- Billing Breakdown HTML ---
        $breakdown_html = '';
        foreach ($unpaid_items as $item) {
             // You'll need to adapt this part based on how much detail your unpaid_items array contains.
             // Assuming it contains: type, name, amount.
             $breakdown_html .= '
                <tr>
                    <td>
                        <div class="month-label">' . esc_html($item['type']) . ' Rental - ' . esc_html($item['name']) . '</div>
                    </td>
                    <td>
                        <div class="days-info">1 month due</div>
                    </td>
                    <td>' . $currency_symbol . number_format(floatval($item['amount']), 2) . '</td>
                    <td class="amount-cell">' . $currency_symbol . number_format(floatval($item['amount']), 2) . '</td>
                </tr>';
        }
        
        // --- Main HTML Structure (Adapted from generate_invoice_html) ---
        // (Copy/paste the entire existing HTML structure from generate_invoice_html below this line)
        
        $html = '
        <style>
            /* ... (Copy all the existing CSS here) ... */
        </style>
        
        <div class="header-section">
            <table class="header-table">
                <tr>
                    <td width="50%">
                        ' . ($company_logo ? '<img src="' . $company_logo . '" style="max-height: 50px; margin-bottom: 10px;"><br>' : '') . '
                        <div class="company-name">' . esc_html($company_name) . '</div>
                        <div class="company-details">
                            ' . nl2br(esc_html($company_address)) . '<br>
                            ' . ($company_phone ? 'Phone: ' . esc_html($company_phone) . '<br>' : '') . '
                            Email: ' . esc_html($company_email) . '
                        </div>
                    </td>
                    <td width="50%" style="text-align: right;">
                        <div class="invoice-title">INVOICE</div>
                        <div class="invoice-meta">
                            <strong>Invoice #:</strong> INV-' . $customer_data['id'] . '-' . date('Ymd') . '<br>
                            <strong>Date:</strong> ' . date('M d, Y') . '<br>
                            <strong>Due Date:</strong> ' . date('M d, Y', strtotime('+30 days')) . '
                        </div>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="section-title">Bill To</div>
        <div class="customer-info">
            <div class="customer-name">' . esc_html($customer_data['name'] ?: 'N/A') . '</div>
            <div>Phone: ' . esc_html($customer_data['full_phone'] ?: 'N/A') . '</div>
            <div>Email: ' . esc_html($customer_data['full_email'] ?: 'N/A') . '</div>
        </div>
        
        <div class="section-title">Billing Details (Unpaid Items)</div>
        <table class="billing-table">
            <thead>
                <tr>
                    <th>Item / Description</th>
                    <th>Period/Qty</th>
                    <th>Rate</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                ' . $breakdown_html . '
            </tbody>
        </table>
        
        <div class="total-section">
        <div class="section-title">Totals</div>
        <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
          <tr>
            <td style="padding:8px;border:1px solid #e5e7eb;">Subtotal (ex VAT)</td>
            <td style="padding:8px;border:1px solid #e5e7eb;text-align:right;"><strong>' . $currency_symbol . number_format($subtotal_ex_vat,2) . ' </strong></td>
          </tr>
          <tr>
            <td style="padding:8px;border:1px solid #e5e7eb;">VAT ( '. number_format($vat_rate,2) . ' % )</td>
            <td style="padding:8px;border:1px solid #e5e7eb;text-align:right;"><strong> ' . $currency_symbol . number_format($vat_amount,2) .'</strong></td>
        </tr>
          <tr>
            <td style="padding:12px;border:1px solid #e5e7eb;background:#f9fafb;"><strong>Total (incl. VAT) </strong></td>
            <td style="padding:12px;border:1px solid #e5e7eb;background:#f9fafb;text-align:right;"><strong>' . $currency_symbol . number_format($grand_total_inc_vat,2) .' 
                    </strong></td>
          </tr>
        </table>
        </div>
        
        <div class="payment-terms">
            <div class="payment-terms-title">Payment Terms</div>
            <div class="payment-terms-text">
                Payment is due within 30 days of invoice date. Late payments may result in service interruption and additional fees.
            </div>
        </div>
        
        <div class="invoice-footer">
            <p>Thank you for choosing <span class="footer-highlight">' . esc_html($company_name) . '</span></p>
            <p>For questions about this invoice, please contact us at ' . esc_html($company_email) . '</p>
        </div>';
        
        return $html;
    }
    
    private function generate_modern_billing_breakdown($billing_result, $monthly_price, $currency_symbol) {
        $breakdown_html = '';
        
        error_log("SUM PDF: Generating modern billing breakdown with " . count($billing_result['months']) . " months");
        
        foreach ($billing_result['months'] as $month) {
            if ($month['occupied_days'] > 0) {
                // For occupied months billing, we charge full monthly rate for any month touched
                $month_amount = $monthly_price;
                
                error_log("SUM PDF: Adding month {$month['label']} - {$month['occupied_days']}/{$month['days_in_month']} days - {$currency_symbol}{$month_amount}");
                
                $breakdown_html .= '
                    <tr>
                        <td>
                            <div class="month-label">' . esc_html($month['label']) . '</div>
                        </td>
                        <td>
                            <div class="days-info">' . $month['occupied_days'] . ' of ' . $month['days_in_month'] . ' days</div>
                        </td>
                        <td>' . $currency_symbol . number_format($month_amount, 2) . '</td>
                        <td class="amount-cell">' . $currency_symbol . number_format($month_amount, 2) . '</td>
                    </tr>';
            }
        }
        
        error_log("SUM PDF: Generated breakdown HTML: " . $breakdown_html);
        return $breakdown_html;
    }
    
    private function generate_simple_billing_row($unit_data, $months_due, $monthly_price, $currency_symbol) {
        $total_amount = $monthly_price * $months_due;
        
        error_log("SUM PDF: Generating simple billing row - {$months_due} months x {$currency_symbol}{$monthly_price} = {$currency_symbol}{$total_amount}");
        
        return '
            <tr>
                <td>
                    <div class="month-label">Storage Unit Rental - ' . esc_html($unit_data['unit_name']) . '</div>
                </td>
                <td>
                    <div class="days-info">' . $months_due . ' month' . ($months_due > 1 ? 's' : '') . '</div>
                </td>
                <td>' . $currency_symbol . number_format($monthly_price, 2) . '</td>
                <td class="amount-cell">' . $currency_symbol . number_format($total_amount, 2) . '</td>
            </tr>';
    }
}
