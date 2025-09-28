<?php
/**
 * Customers → Invoice Handler (clean)
 *
 * REST: POST /wp-json/sum/v1/invoice  body: { "customer_id": <int> }
 * Returns: { pdf_url, pdf_path, invoice_no }
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SUM_Customer_Invoice_Handler_CSSC' ) ) :

class SUM_Customer_Invoice_Handler_CSSC {

    /* ====================== Boot ====================== */

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
    }

    public static function register_rest_routes() {
        register_rest_route(
            'sum/v1',
            '/invoice',
            array(
                'methods'             => 'POST',
                'callback'            => array( __CLASS__, 'rest_generate_invoice' ),
                'permission_callback' => array( __CLASS__, 'rest_permission_check' ),
                'args'                => array(
                    'customer_id' => array( 'required' => true, 'type' => 'integer' ),
                ),
            )
        );
    }

    public static function rest_permission_check( WP_REST_Request $r ) {
        return is_user_logged_in() && current_user_can( 'manage_options' );
    }

    public static function rest_generate_invoice( WP_REST_Request $r ) {
        try {
            $customer_id = absint( $r->get_param( 'customer_id' ) );
            if ( ! $customer_id ) {
                return new WP_REST_Response(
                    array( 'code' => 'sum_invalid_customer', 'message' => 'Missing or invalid customer_id.' ),
                    400
                );
            }
            $result = self::generate( $customer_id );
            return new WP_REST_Response(
                array(
                    'pdf_url'    => $result['pdf_url'],
                    'pdf_path'   => $result['pdf_path'],
                    'invoice_no' => $result['invoice_no'],
                ),
                200
            );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response(
                array(
                    'code'    => 'sum_fatal',
                    'message' => 'PHP FATAL: ' . $e->getMessage(),
                    'file'    => __FILE__,
                    'line'    => __LINE__,
                    'trace'   => wp_debug_backtrace_summary(),
                ),
                500
            );
        }
    }

    /* ====================== Public: Generate ====================== */

    /**
     * Create consolidated PDF invoice for a customer and return URL/path/number.
     */
    public static function generate( $customer_id ) {
        $customer_id = absint( $customer_id );
        if ( ! $customer_id ) throw new \RuntimeException( 'Invalid customer ID.' );

        // Load customer (prefer your own loader if it exists).
        $customer = null;
        if ( method_exists( __CLASS__, 'get_customer_by_id' ) ) {
            $customer = self::get_customer_by_id( $customer_id );
        } elseif ( function_exists( 'sum_get_customer_by_id' ) ) {
            $customer = sum_get_customer_by_id( $customer_id );
        }
        if ( ! is_array( $customer ) || empty( $customer['id'] ) ) {
            $customer = array( 'id' => $customer_id, 'name' => 'Customer-' . $customer_id );
        }
        $customer_name = trim( (string) ( $customer['name'] ?? '' ) ) ?: ( 'Customer-' . $customer_id );

        // Invoice number (prefer your own implementation).
        if ( method_exists( __CLASS__, 'get_next_invoice_number' ) ) {
            $invoice_no = (string) self::get_next_invoice_number( $customer_id, $customer );
        } elseif ( function_exists( 'sum_get_next_invoice_number' ) ) {
            $invoice_no = (string) sum_get_next_invoice_number( $customer_id, $customer );
        } else {
            $invoice_no = 'INV-' . gmdate( 'Ymd-His' );
        }

        // Build HTML (uses storage_settings for company info).
        $html = (string) self::build_invoice_html( $customer, $invoice_no );
        if ( '' === trim( $html ) ) throw new \RuntimeException( 'Empty HTML. Cannot generate PDF.' );

        // Resolve /uploads/invoices (filesystem + URL).
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) throw new \RuntimeException( 'Upload directory error: ' . $uploads['error'] );
        $invoices_dir = trailingslashit( $uploads['basedir'] ) . 'invoices';
        $invoices_url = trailingslashit( $uploads['baseurl'] ) . 'invoices';
        if ( ! wp_mkdir_p( $invoices_dir ) ) throw new \RuntimeException( 'Cannot create: ' . $invoices_dir );

        // Safe filename.
        $base = sprintf(
            'invoice-%s-%s-%s.pdf',
            sanitize_file_name( $customer_name ),
            sanitize_file_name( $invoice_no ),
            gmdate( 'Y-m-d-H-i-s' )
        );
        $pdf_fs_path = trailingslashit( $invoices_dir ) . $base;
        $pdf_url     = trailingslashit( $invoices_url ) . rawurlencode( $base );

        // Render (Dompdf first, TCPDF fallback).
        $made = false; $last_err = null;

        $dompdf_tmp  = trailingslashit( $uploads['basedir'] ) . 'dompdf-temp';
        $dompdf_font = trailingslashit( $uploads['basedir'] ) . 'dompdf-fonts';
        @wp_mkdir_p( $dompdf_tmp ); @wp_mkdir_p( $dompdf_font );

        if ( class_exists( '\Dompdf\Dompdf' ) ) {
            try {
                $opt = new \Dompdf\Options();
                $opt->set( 'isRemoteEnabled', true );
                $opt->set( 'tempDir',  $dompdf_tmp );
                $opt->set( 'fontDir',  $dompdf_font );
                $opt->set( 'fontCache',$dompdf_font );
                $pdf = new \Dompdf\Dompdf( $opt );
                $pdf->loadHtml( $html );
                $pdf->setPaper( 'A4', 'portrait' );
                $pdf->render();
                $out = $pdf->output();
                $bytes = file_put_contents( $pdf_fs_path, $out );
                $made = ( $bytes !== false && $bytes > 0 );
            } catch ( \Throwable $e ) { $last_err = 'DOMPDF: ' . $e->getMessage(); }
        }

        if ( ! $made && class_exists( '\TCPDF' ) ) {
            try {
                $pdf = new \TCPDF( PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false );
                $pdf->SetCreator( 'Storage Unit Manager' );
                $pdf->SetAuthor( get_bloginfo( 'name' ) );
                $pdf->SetTitle( 'Invoice ' . $invoice_no );
                $pdf->SetMargins( 10, 10, 10 );
                $pdf->AddPage();
                $pdf->writeHTML( $html, true, false, true, false, '' );
                $pdf->Output( $pdf_fs_path, 'F' );
                $made = ( file_exists( $pdf_fs_path ) && filesize( $pdf_fs_path ) > 0 );
            } catch ( \Throwable $e ) { $last_err = ( $last_err ? $last_err.' | ' : '' ) . 'TCPDF: ' . $e->getMessage(); }
        }

        if ( ! $made || ! file_exists( $pdf_fs_path ) || filesize( $pdf_fs_path ) === 0 ) {
            @file_put_contents( trailingslashit( $invoices_dir ) . '__last_invoice.html', $html );
            throw new \RuntimeException( 'PDF not created: ' . $pdf_fs_path . ( $last_err ? ' | ' . $last_err : '' ) );
        }

        return array(
            'pdf_url'    => $pdf_url,
            'pdf_path'   => $pdf_fs_path,
            'invoice_no' => $invoice_no,
        );
    }

    /* ====================== HTML Builder ====================== */

    protected static function build_invoice_html( array $customer, string $invoice_no ) {
        $customer_id   = absint( $customer['id'] );
        $customer_name = trim( (string) ( $customer['name'] ?? '' ) ) ?: ( 'Customer-' . $customer_id );

        // Company profile from custom table wp_{prefix}_storage_settings.
        $cfg          = self::get_storage_settings_map();
        $company_name = (string) ( $cfg['company_name']  ?? get_bloginfo( 'name' ) );
        $company_addr = (string) ( $cfg['company_address'] ?? '' ); // raw; we nl2br+escape below
        $company_tel  = (string) ( $cfg['company_phone'] ?? '' );
        $company_mail = (string) ( $cfg['company_email'] ?? get_bloginfo( 'admin_email' ) );
        $company_logo = (string) ( $cfg['company_logo']  ?? '' ); // URL
        $vat_percent  = (float)  ( $cfg['vat_rate']      ?? 0 );
        $currency     = (string) ( $cfg['currency']      ?? 'EUR' );
        $symbol       = self::currency_symbol( $currency );

        // Items: prefer your main PDF classes; fallback to legacy helpers.
        $items = self::get_unpaid_line_items_for_customer( $customer_id );

        // Group and totals.
        $grouped = array( 'unit' => array(), 'pallet' => array(), 'other' => array() );
        $subtotal = 0.0;
        foreach ( $items as $row ) {
            $t = ( $row['type'] === 'pallet' || $row['type'] === 'unit' ) ? $row['type'] : 'other';
            $grouped[ $t ][] = $row;
            $subtotal += (float) $row['subtotal'];
        }
        $subtotal    = round( $subtotal, 2 );
        $vat_amount  = $vat_percent > 0 ? round( $subtotal * ( $vat_percent / 100 ), 2 ) : 0.0;
        $grand_total = round( $subtotal + $vat_amount, 2 );
        $invoice_date = date_i18n( 'Y-m-d' );

        // Safe address with preserved line breaks.
        $company_addr_html = nl2br( esc_html( $company_addr ) );
        $logo_url          = esc_url( $company_logo );

        ob_start(); ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Invoice <?php echo esc_html( $invoice_no ); ?></title>
<style>
    * { box-sizing: border-box; }
    body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size: 12px; color: #111; margin: 0; padding: 0; }
    .wrap { padding: 24px; }
    .header { display: table; width: 100%; }
    .header .left, .header .right { display: table-cell; vertical-align: top; }
    .header .right { text-align: right; }
    .logo { height: 50px; margin-bottom: 8px; }
    h1 { font-size: 20px; margin: 0 0 8px; }
    .muted { color: #666; }
    .small { font-size: 11px; }
    .mt-12 { margin-top: 12px; }
    .mt-16 { margin-top: 16px; }
    .box { border: 1px solid #ddd; padding: 12px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 8px; border-bottom: 1px solid #eee; vertical-align: top; }
    th { background: #f7f7f7; text-align: left; font-weight: bold; }
    .text-right { text-align: right; }
    .text-center { text-align: center; }
    .tr-total td { border-top: 2px solid #333; }
    .section-title { font-size: 14px; font-weight: bold; margin: 16px 0 8px; }
</style>
</head>
<body>
<div class="wrap">

    <div class="header">
        <div class="left">
            <?php if ( $logo_url ) : ?>
                <img class="logo" src="<?php echo $logo_url; ?>" alt="Logo">
            <?php endif; ?>
            <div><strong><?php echo esc_html( $company_name ); ?></strong></div>
            <?php if ( $company_addr_html ) : ?>
                <div class="muted small"><?php echo $company_addr_html; ?></div>
            <?php endif; ?>
            <?php if ( $company_tel ) : ?>
                <div class="small">Tel: <?php echo esc_html( $company_tel ); ?></div>
            <?php endif; ?>
            <?php if ( $company_mail ) : ?>
                <div class="small">Email: <?php echo esc_html( $company_mail ); ?></div>
            <?php endif; ?>
        </div>
        <div class="right">
            <h1>Invoice</h1>
            <div><strong>No:</strong> <?php echo esc_html( $invoice_no ); ?></div>
            <div><strong>Date:</strong> <?php echo esc_html( $invoice_date ); ?></div>
            <div><strong>Customer:</strong> <?php echo esc_html( $customer_name ); ?></div>
        </div>
    </div>

    <div class="mt-16 box">
        <?php if ( empty( $items ) ) : ?>
            <div class="muted">No unpaid items found for this customer.</div>
        <?php else : ?>

            <?php
            $section = function( $title, $rows ) use ( $symbol ) {
                if ( empty( $rows ) ) return; ?>
                <div class="section-title"><?php echo esc_html( $title ); ?></div>
                <table>
                    <thead>
                        <tr>
                            <th style="width:38%;">Description</th>
                            <th style="width:18%;">Period</th>
                            <th class="text-center" style="width:10%;">Months</th>
                            <th class="text-center" style="width:10%;">Qty</th>
                            <th class="text-right" style="width:12%;">Unit Price</th>
                            <th class="text-right" style="width:12%;">Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $r ) :
                            $desc   = trim( $r['name'] ) !== '' ? $r['name'] : ucfirst( $r['type'] );
                            $period = ( $r['period_from'] || $r['period_to'] )
                                ? trim( $r['period_from'] . ' – ' . $r['period_to'] ) : '';
                            $months = ( $r['months'] !== null && $r['months'] > 0 ) ? $r['months'] : '';
                            $qty    = $r['qty'] ?: 1;
                            $price  = number_format( (float) $r['price'], 2 );
                            $line   = number_format( (float) $r['subtotal'], 2 ); ?>
                            <tr>
                                <td>
                                    <?php echo esc_html( $desc ); ?>
                                    <?php if ( ! empty( $r['notes'] ) ) : ?>
                                        <div class="muted small"><?php echo esc_html( $r['notes'] ); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $period ); ?></td>
                                <td class="text-center"><?php echo $months !== '' ? esc_html( (string) $months ) : '—'; ?></td>
                                <td class="text-center"><?php echo esc_html( (string) $qty ); ?></td>
                                <td class="text-right"><?php echo $symbol . $price; ?></td>
                                <td class="text-right"><?php echo $symbol . $line; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php };

            $section( 'Units',   $grouped['unit'] );
            $section( 'Pallets', $grouped['pallet'] );
            if ( ! empty( $grouped['other'] ) ) $section( 'Other', $grouped['other'] );
            ?>

            <table class="mt-16">
                <tbody>
                    <tr>
                        <td class="text-right"><strong>Subtotal</strong></td>
                        <td class="text-right" style="width:140px;"><?php echo $symbol . number_format( $subtotal, 2 ); ?></td>
                    </tr>
                    <?php if ( $vat_percent > 0 ) : ?>
                        <tr>
                            <td class="text-right"><strong>VAT (<?php echo esc_html( (string) $vat_percent ); ?>%)</strong></td>
                            <td class="text-right"><?php echo $symbol . number_format( $vat_amount, 2 ); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr class="tr-total">
                        <td class="text-right"><strong>Total Due</strong></td>
                        <td class="text-right"><strong><?php echo $symbol . number_format( $grand_total, 2 ); ?></strong></td>
                    </tr>
                </tbody>
            </table>

            <div class="mt-12 small muted">Please settle the outstanding amount at your earliest convenience.</div>

        <?php endif; ?>
    </div>

</div>
</body>
</html>
<?php
        return (string) ob_get_clean();
    }

    /* ====================== Data Sources ====================== */

    /**
     * Read wp_{prefix}_storage_settings into a [key => value] map.
     */
    protected static function get_storage_settings_map() {
        global $wpdb;
        $table = $wpdb->prefix . 'storage_settings';
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
        if ( $exists !== $table ) return array();
        $rows = $wpdb->get_results( "SELECT setting_key, setting_value FROM {$table}", ARRAY_A );
        if ( empty( $rows ) ) return array();
        $out = array();
        foreach ( $rows as $r ) {
            $out[ $r['setting_key'] ] = (string) ( $r['setting_value'] ?? '' );
        }
        return $out;
    }

    /**
     * Merge unpaid items from your canonical PDF classes first,
     * then fall back to legacy helpers if present.
     *
     * Normalized row: type, name, period_from, period_to, months|null, qty, price, subtotal, notes
     */
    protected static function get_unpaid_line_items_for_customer( $customer_id ) {
        $customer_id = absint( $customer_id );
        if ( ! $customer_id ) return array();

        $raw = array();

        // Prefer main PDF class.
        if ( class_exists( 'SUM_PDF_Generator' ) ) {
            $C = 'SUM_PDF_Generator';
            if ( method_exists( $C, 'get_unpaid_items_for_customer' ) ) {
                $raw = (array) call_user_func( array( $C, 'get_unpaid_items_for_customer' ), $customer_id );
            } else {
                $u = method_exists( $C, 'get_unpaid_units_for_customer' )   ? (array) call_user_func( array( $C, 'get_unpaid_units_for_customer' ), $customer_id )   : array();
                $p = method_exists( $C, 'get_unpaid_pallets_for_customer' ) ? (array) call_user_func( array( $C, 'get_unpaid_pallets_for_customer' ), $customer_id ) : array();
                foreach ( $u as $it ) { $it['_source_type'] = 'unit';   $raw[] = $it; }
                foreach ( $p as $it ) { $it['_source_type'] = 'pallet'; $raw[] = $it; }
            }
        }

        // Pallets class as additional source if needed.
        if ( empty( $raw ) && class_exists( 'SUM_Pallets_PDF' ) ) {
            $P = 'SUM_Pallets_PDF';
            if ( method_exists( $P, 'get_unpaid_pallets_for_customer' ) ) {
                $p = (array) call_user_func( array( $P, 'get_unpaid_pallets_for_customer' ), $customer_id );
                foreach ( $p as $it ) { $it['_source_type'] = 'pallet'; $raw[] = $it; }
            }
            if ( method_exists( $P, 'get_unpaid_units_for_customer' ) ) {
                $u = (array) call_user_func( array( $P, 'get_unpaid_units_for_customer' ), $customer_id );
                foreach ( $u as $it ) { $it['_source_type'] = 'unit'; $raw[] = $it; }
            }
        }

        // Legacy fallbacks.
        if ( empty( $raw ) ) {
            if ( method_exists( __CLASS__, 'get_unpaid_invoices_for_customer' ) ) {
                $raw = (array) self::get_unpaid_invoices_for_customer( $customer_id );
            } elseif ( function_exists( 'sum_get_unpaid_invoices_for_customer' ) ) {
                $raw = (array) sum_get_unpaid_invoices_for_customer( $customer_id );
            }
        }

        if ( empty( $raw ) ) return array();

        // Normalize.
        $out = array();
        foreach ( $raw as $it ) {
            $type = 'unit';
            if ( isset( $it['type'] ) && in_array( $it['type'], array( 'unit','pallet' ), true ) ) {
                $type = $it['type'];
            } elseif ( ! empty( $it['_source_type'] ) && in_array( $it['_source_type'], array( 'unit','pallet' ), true ) ) {
                $type = $it['_source_type'];
            } elseif ( ! empty( $it['category'] ) && $it['category'] === 'pallet' ) {
                $type = 'pallet';
            }

            $name =
                ( isset( $it['name'] ) ? $it['name'] : '' ) ?:
                ( isset( $it['unit_name'] ) ? $it['unit_name'] : '' ) ?:
                ( isset( $it['pallet_name'] ) ? $it['pallet_name'] : '' ) ?:
                ( isset( $it['title'] ) ? $it['title'] : '' ) ?:
                ucfirst( $type );

            $from = ''; $to = '';
            foreach ( array( 'period_from','from','date_from','start','start_date' ) as $k ) {
                if ( ! empty( $it[ $k ] ) ) { $from = (string) $it[ $k ]; break; }
            }
            foreach ( array( 'period_to','to','date_to','end','end_date' ) as $k ) {
                if ( ! empty( $it[ $k ] ) ) { $to = (string) $it[ $k ]; break; }
            }

            $months = null;
            if ( isset( $it['months'] ) && $it['months'] !== '' ) $months = (float) $it['months'];
            elseif ( isset( $it['billing_months'] ) && $it['billing_months'] !== '' ) $months = (float) $it['billing_months'];

            $qty = 1.0;
            if ( isset( $it['qty'] ) && $it['qty'] !== '' ) $qty = (float) $it['qty'];
            elseif ( isset( $it['quantity'] ) && $it['quantity'] !== '' ) $qty = (float) $it['quantity'];

            $price = 0.0;
            foreach ( array( 'price','monthly_price','unit_price','amount_per_month' ) as $k ) {
                if ( isset( $it[ $k ] ) && $it[ $k ] !== '' ) { $price = (float) $it[ $k ]; break; }
            }

            $subtotal = null;
            foreach ( array( 'subtotal','line_total','total' ) as $k ) {
                if ( isset( $it[ $k ] ) && $it[ $k ] !== '' ) { $subtotal = (float) $it[ $k ]; break; }
            }
            if ( $subtotal === null ) {
                $factor   = ( $months !== null && $months > 0 ) ? $months : 1.0;
                $subtotal = round( $qty * $price * $factor, 2 );
            }

            $notes = '';
            foreach ( array( 'notes','note','comment','description' ) as $k ) {
                if ( ! empty( $it[ $k ] ) ) { $notes = (string) $it[ $k ]; break; }
            }

            $out[] = array(
                'type'        => $type,
                'name'        => (string) $name,
                'period_from' => (string) $from,
                'period_to'   => (string) $to,
                'months'      => $months,
                'qty'         => $qty,
                'price'       => $price,
                'subtotal'    => $subtotal,
                'notes'       => $notes,
            );
        }
        return $out;
    }

    /* ====================== Small Helpers ====================== */

    protected static function currency_symbol( $code ) {
        $code = strtoupper( (string) $code );
        switch ( $code ) {
            case 'EUR': return '€';
            case 'USD': return '$';
            case 'GBP': return '£';
            default:    return ''; // let numbers show without symbol if unknown
        }
    }

    // Minimal fallback loader; prefer your real implementation if available.
    protected static function get_customer_by_id( $customer_id ) {
        return array( 'id' => absint( $customer_id ), 'name' => 'Customer-' . absint( $customer_id ) );
    }
}

endif;

SUM_Customer_Invoice_Handler_CSSC::init();
