<?php
/**
 * Pallet PDF Invoice Generator for Storage Unit Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class SUM_Pallet_PDF_Generator {
    
    private $pallet_database;
    
    public function __construct($pallet_database) {
        $this->pallet_database = $pallet_database;
    }
    
    public function generate_invoice_pdf($pallet_data) {
        error_log("SUM Pallet PDF: Starting PDF generation for pallet {$pallet_data['id']}");
        
        $type          = $pallet_data['pallet_type'] ?? 'EU';
$actual_height = (float)($pallet_data['actual_height'] ?? 0);

// Always recompute using DB helpers
$charged_height = $this->pallet_database->compute_charged_height($actual_height, $type);
$monthly_price  = $this->pallet_database->get_monthly_price_for($type, $charged_height);

// keep the display consistent with what we’ll render in the spec table
$pallet_data['charged_height'] = $charged_height;
$pallet_data['cubic_meters']   = ($type === 'EU')
    ? 1.20 * 0.80 * $charged_height
    : 1.22 * 1.02 * $charged_height;


        // Calculate payment amount using billing calculator
        //$monthly_price = floatval($pallet_data['monthly_price'] ?: 30.00);
        
        // Use billing calculator for proper month calculation
        require_once SUM_PLUGIN_PATH . 'includes/class-rental-billing-calculator.php';
        
        $billing_result = null;
        $months_due = 1; // Default fallback
        $total_amount = $monthly_price;
        
        if (!empty($pallet_data['period_from']) && !empty($pallet_data['period_until'])) {
            try {
                $billing_result = calculate_billing_months(
                    $pallet_data['period_from'], 
                    $pallet_data['period_until'], 
                    ['monthly_price' => $monthly_price]
                );
                
                // Use occupied months for billing
                $months_due = $billing_result['occupied_months'];
                $total_amount = $monthly_price * $months_due;
                
            } catch (Exception $e) {
                error_log('SUM Pallet PDF: Billing calculator error: ' . $e->getMessage());
            }
        }
        
        // Try to load TCPDF
        $this->load_tcpdf();
        
        // Generate HTML content for PDF
        $html = $this->generate_invoice_html($pallet_data, $total_amount, $monthly_price, $months_due, $billing_result);
        
        // Create PDF file
        return $this->create_pdf_file($html, $pallet_data);
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
    
private function create_pdf_file($html, $pallet_data) {
    $upload_dir = wp_upload_dir();
    $pdf_dir = $upload_dir['basedir'] . '/invoices';
    if (!file_exists($pdf_dir)) { wp_mkdir_p($pdf_dir); }
    if (!is_writable($pdf_dir)) { @chmod($pdf_dir, 0755); }

    $pdf_filename = 'pallet-invoice-' . $pallet_data['pallet_name'] . '-' . date('Y-m-d-H-i-s') . '.pdf';
    $pdf_filepath = trailingslashit($pdf_dir) . $pdf_filename;

    if (function_exists('sum_load_dompdf') && sum_load_dompdf()) {
        $ok = $this->generate_dompdf($html, $pdf_filepath);
        if ($ok) return $pdf_filepath;
    }

    if (class_exists('TCPDF')) {
        $ok = $this->generate_tcpdf($html, $pallet_data, $pdf_filepath);
        if ($ok) return $ok;
    }

    return $this->generate_html_pdf($html, $pallet_data, $pdf_filepath);
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

    
    private function generate_tcpdf($html, $pallet_data, $pdf_filepath) {
        try {
            $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
            
            // Set document information
            $pdf->SetCreator('Storage Unit Manager - Pallet Storage');
            
            // Get company name from main database
            global $wpdb;
            $settings_table = $wpdb->prefix . 'storage_settings';
            $company_name = $wpdb->get_var($wpdb->prepare("SELECT setting_value FROM $settings_table WHERE setting_key = %s", 'company_name'));
            if (!$company_name) {
                $company_name = 'Self Storage Cyprus';
            }
            
            $pdf->SetAuthor($company_name);
            $pdf->SetTitle('Pallet Storage Invoice - ' . $pallet_data['pallet_name']);
            $pdf->SetSubject('Pallet Storage Invoice');
            
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
            
            // Print HTML content
            $pdf->writeHTML($html, true, false, true, false, '');
            
            // Save PDF to file
            $pdf->Output($pdf_filepath, 'F');
            
            // Verify file was created
            if (file_exists($pdf_filepath) && filesize($pdf_filepath) > 0) {
                return $pdf_filepath;
            } else {
                return false;
            }
            
        } catch (Exception $e) {
            error_log('SUM Pallet PDF: TCPDF generation error: ' . $e->getMessage());
            return $this->generate_html_pdf($html, $pallet_data, $pdf_filepath);
        }
    }
    
    private function generate_html_pdf($html, $pallet_data, $pdf_filepath) {
        // Create a complete HTML document
        $pdf_content = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Pallet Invoice - ' . esc_html($pallet_data['pallet_name']) . '</title>
    <style>
        body { margin: 0; padding: 20px; font-family: Arial, sans-serif; }
        @media print { body { margin: 0; } }
    </style>
</head>
<body>' . $html . '</body>
</html>';
        
        $write_result = file_put_contents($pdf_filepath, $pdf_content);
        
        if ($write_result !== false && file_exists($pdf_filepath) && filesize($pdf_filepath) > 0) {
            return $pdf_filepath;
        } else {
            return false;
        }
    }
    
    private function generate_invoice_html($pallet_data, $total_amount, $monthly_price, $months_due, $billing_result = null) {
        // Get settings from main database
        global $wpdb;
        $settings_table = $wpdb->prefix . 'storage_settings';
        
        $company_name = $wpdb->get_var($wpdb->prepare("SELECT setting_value FROM $settings_table WHERE setting_key = %s", 'company_name')) ?: 'Self Storage Cyprus';
        $company_address = $wpdb->get_var($wpdb->prepare("SELECT setting_value FROM $settings_table WHERE setting_key = %s", 'company_address')) ?: '';
        $company_phone = $wpdb->get_var($wpdb->prepare("SELECT setting_value FROM $settings_table WHERE setting_key = %s", 'company_phone')) ?: '';
        $company_email = $wpdb->get_var($wpdb->prepare("SELECT setting_value FROM $settings_table WHERE setting_key = %s", 'company_email')) ?: get_option('admin_email');
        $company_logo = $wpdb->get_var($wpdb->prepare("SELECT setting_value FROM $settings_table WHERE setting_key = %s", 'company_logo')) ?: '';
        $currency = $wpdb->get_var($wpdb->prepare("SELECT setting_value FROM $settings_table WHERE setting_key = %s", 'currency')) ?: 'EUR';
        $currency_symbol = $currency === 'USD' ? '$' : ($currency === 'GBP' ? '£' : '€');
        
        // VAT settings (read from storage_settings)
$vat_enabled_raw = $wpdb->get_var(
    $wpdb->prepare("SELECT setting_value FROM $settings_table WHERE setting_key = %s", 'vat_enabled')
);
$vat_enabled = ($vat_enabled_raw === '1');

$vat_rate = (float) ($wpdb->get_var(
    $wpdb->prepare("SELECT setting_value FROM $settings_table WHERE setting_key = %s", 'vat_rate')
) ?: 0);

$company_vat = (string) ($wpdb->get_var(
    $wpdb->prepare("SELECT setting_value FROM $settings_table WHERE setting_key = %s", 'company_vat')
) ?: '');

// totals
$subtotal    = (float) $total_amount;                           // ex VAT
$vat_amount  = $vat_enabled ? round($subtotal * ($vat_rate/100), 2) : 0.00;
$grand_total = round($subtotal + $vat_amount, 2);

        
        // Get pallet dimensions
        $pallet_dimensions = $this->get_pallet_dimensions($pallet_data['pallet_type']);
        
        $html = '
        <style>
            body { 
                font-family: Arial, sans-serif; 
                color: #333; 
                font-size: 12px;
                line-height: 1.4;
            }
            
            .header-table {
                width: 100%;
                margin-bottom: 30px;
                border-collapse: collapse;
            }
            
            .header-table td {
                vertical-align: top;
                padding: 10px;
            }
            
            .company-name {
                font-size: 24px;
                font-weight: bold;
                color: #2563eb;
                margin-bottom: 10px;
            }
            
            .company-details {
                color: #6b7280;
                font-size: 11px;
            }
            
            .invoice-title {
                font-size: 22px;
                font-weight: bold;
                color: #1a1a1a;
                text-align: right;
                margin-bottom: 25px;
            }
            
            .invoice-meta {
                background-color: #f8fafc;
                padding: 15px;
                border-left: 4px solid #2563eb;
                margin-bottom: 20px;
                font-size: 11px;
            }
            
            .section-title {
                font-size: 16px;
                font-weight: bold;
                color: #1a1a1a;
                text-transform: uppercase;
                margin-bottom: 5px;
                border-bottom: 2px solid #e5e7eb;
                padding-bottom: 5px;
            }
            
            .customer-info {
                background-color: #f8fafc;
                padding: 20px;
                border: 1px solid #e5e7eb;
                margin-bottom: 30px;
            }
            
            .customer-name {
                font-size: 18px;
                font-weight: bold;
                color: #1a1a1a;
                margin-bottom: 10px;
            }
            
            .pallet-details-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 30px;
                border: 1px solid #e5e7eb;
                vertical-align:middle;
            }
            
            .pallet-header {
                background-color: #f97316;
                color: white;
                padding: 20px;
                text-align: center;
                font-size: 18px;
                font-weight: bold;
            }
            
            .pallet-spec-cell {
                background-color: #f97316;
                color: white;
                padding: 15px;
                text-align: center;
                width: 20%;
                vertical-align: top;
            }
            
            .pallet-spec-label {
                font-size: 10px;
                text-transform: uppercase;
                opacity: 0.8;
                margin-bottom: 8px;
            }
            
            .pallet-spec-value {
                font-size: 13px;
                font-weight: bold;
                margin: 0;
            }
            
            .billing-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 30px;
                border: 1px solid #e5e7eb;
            }
            
            .billing-table th {
                background-color: #1e293b;
                color: white;
                padding: 15px;
                text-align: left;
                font-weight: bold;
                font-size: 12px;
                text-transform: uppercase;
            }
            
            .billing-table td {
                padding: 15px;
                border-bottom: 1px solid #f1f5f9;
            }
            
            .month-label {
                font-weight: bold;
                color: #1a1a1a;
            }
            
            .days-info {
                color: #6b7280;
                font-size: 11px;
            }
            
            .amount-cell {
                text-align: right;
                font-weight: bold;
            }
            
            .total-section {
                background-color: #10b981;
                color: white;
                padding: 25px;
                text-align: center;
                margin-bottom: 30px;
            }
            
            .total-label {
                font-size: 14px;
                text-transform: uppercase;
                margin-bottom: 10px;
            }
            
            .total-amount {
                font-size: 36px;
                font-weight: bold;
            }
            
            .payment-terms {
                background-color: #fef3c7;
                border: 1px solid #f59e0b;
                padding: 15px;
                margin-bottom: 25px;
            }
            
            .payment-terms-title {
                font-weight: bold;
                color: #92400e;
                margin-bottom: 8px;
            }
            
            .payment-terms-text {
                color: #78350f;
                font-size: 11px;
            }
            
            .invoice-footer {
                text-align: center;
                border-top: 2px solid #f1f5f9;
                padding-top: 20px;
                color: #6b7280;
                font-size: 11px;
            }
            
            .footer-highlight {
                color: #2563eb;
                font-weight: bold;
            }
        </style>
        
        <!-- Header Section -->
        <table class="header-table">
            <tr>
                <td width="50%">
                    ' . ($company_logo ? '<img src="' . $company_logo . '" style="max-height: 50px; margin-bottom: 10px;"><br>' : '') . '
                    <div class="company-name">' . esc_html($company_name) . '</div>
                    <div class="company-details">
                        ' . nl2br(esc_html($company_address)) . '<br>
                        ' . ($company_phone ? 'Phone: ' . esc_html($company_phone) . '<br>' : '') . '
                        Email: ' . esc_html($company_email) . '<br> VAT / Tax ID: ' . esc_html($company_vat)  .'
                    </div>
                </td>
                <td width="50%" style="text-align: right;">
                    <div class="invoice-title">PALLET INVOICE</div>
                    <div class="invoice-meta">
                        <strong>Invoice #:</strong> PAL-' . $pallet_data['id'] . '-' . date('Ymd') . '<br>
                        <strong>Date:</strong> ' . date('M d, Y') . '<br>
                        <strong>Due Date:</strong> ' . date('M d, Y', strtotime('+30 days')) . '
                    </div>
                </td>
            </tr>
        </table>
        
        <!-- Bill To Section -->
        <div class="section-title">Bill To</div>
        <div class="customer-info">
            <div class="customer-name">' . esc_html($pallet_data['primary_contact_name'] ?: 'N/A') . '</div>
            <div>Phone: ' . esc_html($pallet_data['primary_contact_phone'] ?: 'N/A') . '</div>
            <div>Email: ' .  esc_html($pallet_data['primary_contact_email'] ?: 'N/A') . '</div>
        </div>
        
        <!-- Pallet Details Section -->
        <div class="section-title">Pallet Storage Details</div>
        <table class="pallet-details-table">
            <tr>
                <td colspan="5" class="pallet-header">
                    Pallet ' . esc_html($pallet_data['pallet_name']) . '
                </td>
            </tr>
            <tr>
                <td class="pallet-spec-cell">
                    <div class="pallet-spec-label">TYPE</div>
                    <div class="pallet-spec-value">' . esc_html($pallet_data['pallet_type']) . ' Pallet</div>
                </td>
                <td class="pallet-spec-cell">
                    <div class="pallet-spec-label">DIMENSIONS</div>
                    <div class="pallet-spec-value">' . esc_html($pallet_dimensions) . '</div>
                </td>
                <td class="pallet-spec-cell">
                    <div class="pallet-spec-label">HEIGHT</div>
                    <div class="pallet-spec-value">' . esc_html($pallet_data['actual_height']) . 'm<br><small>(Charged: ' . esc_html($pallet_data['charged_height']) . 'm)</small></div>
                </td>
                <td class="pallet-spec-cell">
                    <div class="pallet-spec-label">VOLUME</div>
                    <div class="pallet-spec-value">' . number_format($pallet_data['cubic_meters'], 2) . ' m³</div>
                </td>
                <td class="pallet-spec-cell">
                    <div class="pallet-spec-label">MONTHLY RATE</div>
                    <div class="pallet-spec-value">' . $currency_symbol . number_format($monthly_price, 2) . '</div>
                </td>
            </tr>
        </table>
        
        <!-- Period -->
        <div class="section-title">Storage Period</div>
        <div style="background-color: #f97316; color: white; padding: 15px; margin-bottom: 30px; text-align: center; font-weight: bold;">
            ' . esc_html($pallet_data['period_from'] ?: 'N/A') . ' to ' . esc_html($pallet_data['period_until'] ?: 'N/A') . '
        </div>
        
        <!-- Billing Breakdown -->
        <div class="section-title">Billing Details</div>
        <table class="billing-table">
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Days</th>
                    <th>Rate</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                ' . ($billing_result ? $this->generate_billing_breakdown($billing_result, $monthly_price, $currency_symbol) : $this->generate_simple_billing_row($pallet_data, $months_due, $monthly_price, $currency_symbol)) . '
            </tbody>
        </table>
        
        <!-- Total Section -->
        <div class="total-section">
        <div class="section-title">Totals</div>
<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
  <tr>
    <td style="padding:8px;border:1px solid #e5e7eb;">Subtotal (ex VAT)</td>
    <td style="padding:8px;border:1px solid #e5e7eb;text-align:right;"><strong>' . $currency_symbol . number_format($subtotal,2) . ' </strong></td>
  </tr>
  <tr>
    <td style="padding:8px;border:1px solid #e5e7eb;">VAT ( '. number_format($vat_rate,2) . ' % )</td>
    <td style="padding:8px;border:1px solid #e5e7eb;text-align:right;"><strong> ' . $currency_symbol . number_format($vat_amount,2) .'</strong></td>
</tr>
  <tr>
    <td style="padding:12px;border:1px solid #e5e7eb;"><strong>Total (incl. VAT) </strong></td>
    <td style="padding:12px;border:1px solid #e5e7eb;text-align:right;"><strong>' . $currency_symbol . number_format($grand_total,2) .' 
            </strong></td>
  </tr>
</table>
        </div>
        
        <!-- Payment Terms -->
        <div class="payment-terms">
            <div class="payment-terms-title">Payment Terms</div>
            <div class="payment-terms-text">
                Payment is due within 30 days of invoice date. Late payments may result in service interruption and additional fees.
            </div>
        </div>
        
        <!-- Footer -->
        <div class="invoice-footer">
            <p>Thank you for choosing <span class="footer-highlight">' . esc_html($company_name) . '</span> Pallet Storage</p>
            <p>For questions about this invoice, please contact us at ' . esc_html($company_email) . '</p>
        </div>';
        
        return $html;
    }
    
    private function get_pallet_dimensions($pallet_type) {
        if ($pallet_type === 'US') {
            return '1.22m × 1.02m';
        } else {
            return '1.20m × 0.80m';
        }
    }
    
    private function generate_billing_breakdown($billing_result, $monthly_price, $currency_symbol) {
        $breakdown_html = '';
        
        foreach ($billing_result['months'] as $month) {
            if ($month['occupied_days'] > 0) {
                $month_amount = $monthly_price;
                
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
        
        return $breakdown_html;
    }
    
    private function generate_simple_billing_row($pallet_data, $months_due, $monthly_price, $currency_symbol) {
        $total_amount = $monthly_price * $months_due;
        
        return '
            <tr>
                <td>
                    <div class="month-label">Pallet Storage - ' . esc_html($pallet_data['pallet_name']) . '</div>
                </td>
                <td>
                    <div class="days-info">' . $months_due . ' month' . ($months_due > 1 ? 's' : '') . '</div>
                </td>
                <td>' . $currency_symbol . number_format($monthly_price, 2) . '</td>
                <td class="amount-cell">' . $currency_symbol . number_format($total_amount, 2) . '</td>
            </tr>';
    }
}