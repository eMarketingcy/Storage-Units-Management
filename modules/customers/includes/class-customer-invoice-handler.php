<?php
/**
 * Customers → Invoice Handler (deterministic identity + price fallback)
 *
 * REST: POST /wp-json/sum/v1/invoice
 * Body: { "customer_id": <int> }
 * Returns: { pdf_url, pdf_path, invoice_no }
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SUM_Customer_Invoice_Handler_CSSC' ) ) :

class SUM_Customer_Invoice_Handler_CSSC {

    /* ============================================================
     * Boot
     * ============================================================
     */

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

    /* ============================================================
     * Generate
     * ============================================================
     */

    public static function generate( $customer_id ) {
        $customer_id = absint( $customer_id );
        if ( ! $customer_id ) throw new \RuntimeException( 'Invalid customer ID.' );

        $customer = self::get_customer_by_id( $customer_id );
        if ( empty( $customer['id'] ) ) {
            $customer = array( 'id' => $customer_id, 'name' => 'Customer-' . $customer_id, 'email' => '' );
        }

        // Invoice no
        if ( method_exists( __CLASS__, 'get_next_invoice_number' ) ) {
            $invoice_no = (string) self::get_next_invoice_number( $customer_id, $customer );
        } elseif ( function_exists( 'sum_get_next_invoice_number' ) ) {
            $invoice_no = (string) sum_get_next_invoice_number( $customer_id, $customer );
        } else {
            $invoice_no = 'INV-' . gmdate( 'Ymd-His' );
        }

        // Build HTML
        $html = (string) self::build_invoice_html( $customer, $invoice_no );
        if ( '' === trim( $html ) ) throw new \RuntimeException( 'Empty HTML. Cannot generate PDF.' );

        // Uploads /invoices
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) throw new \RuntimeException( 'Upload directory error: ' . $uploads['error'] );
        $invoices_dir = trailingslashit( $uploads['basedir'] ) . 'invoices';
        $invoices_url = trailingslashit( $uploads['baseurl'] ) . 'invoices';
        if ( ! wp_mkdir_p( $invoices_dir ) ) throw new \RuntimeException( 'Cannot create: ' . $invoices_dir );

        $filename = sprintf(
            'invoice-%s-%s-%s.pdf',
            sanitize_file_name( $customer['name'] ?: ('Customer-' . $customer['id']) ),
            sanitize_file_name( $invoice_no ),
            gmdate( 'Y-m-d-H-i-s' )
        );
        $pdf_fs_path = trailingslashit( $invoices_dir ) . $filename;
        $pdf_url     = trailingslashit( $invoices_url ) . rawurlencode( $filename );

        // Render PDF (DOMPDF first, TCPDF fallback)
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

                $out   = $pdf->output();
                $bytes = file_put_contents( $pdf_fs_path, $out );
                $made  = ( $bytes !== false && $bytes > 0 );
            } catch ( \Throwable $e ) {
                $last_err = 'DOMPDF: ' . $e->getMessage();
            }
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
            } catch ( \Throwable $e ) {
                $last_err = ( $last_err ? $last_err . ' | ' : '' ) . 'TCPDF: ' . $e->getMessage();
            }
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
  
  
  /**
 * Normalize email for comparisons.
 */
protected static function normalize_email( $email ) {
    $email = trim( (string) $email );
    $email = strtolower( $email );
    return $email;
}

/**
 * Column exists in table? (fast)
 */
protected static function table_has_column( $table, $column ) {
    global $wpdb;
    $column = sanitize_key( $column );
    $row = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE '{$column}'", ARRAY_A );
    return ! empty( $row );
}

    /* ============================================================
     * HTML Builder
     * ============================================================
     */

    protected static function build_invoice_html( array $customer, string $invoice_no ) {
        $customer_id   = absint( $customer['id'] );
        $customer_name = trim( (string) ( $customer['name']  ?? '' ) ) ?: ( 'Customer-' . $customer_id );

        // Company profile from custom table
        $cfg          = self::get_storage_settings_map();
        $company_name = (string) ( $cfg['company_name']    ?? get_bloginfo( 'name' ) );
        $company_addr = (string) ( $cfg['company_address'] ?? '' );
        $company_tel  = (string) ( $cfg['company_phone']   ?? '' );
        $company_mail = (string) ( $cfg['company_email']   ?? get_bloginfo( 'admin_email' ) );
        $company_logo = (string) ( $cfg['company_logo']    ?? '' ); // URL
        $vat_percent  = (float)  ( $cfg['vat_rate']        ?? 0 );
        $currency     = (string) ( $cfg['currency']        ?? 'EUR' );
        $symbol       = self::currency_symbol( $currency );

        // Unpaid items for THIS customer
        $items = self::get_unpaid_line_items_for_customer( $customer );

        // Totals
        $grouped   = array( 'unit' => array(), 'pallet' => array(), 'other' => array() );
        $subtotal  = 0.0;
        foreach ( $items as $row ) {
            $t = ( $row['type'] === 'pallet' || $row['type'] === 'unit' ) ? $row['type'] : 'other';
            $grouped[ $t ][] = $row;
            $subtotal += (float) $row['subtotal'];
        }
        $subtotal    = round( $subtotal, 2 );
        $vat_amount  = $vat_percent > 0 ? round( $subtotal * ( $vat_percent / 100 ), 2 ) : 0.0;
        $grand_total = round( $subtotal + $vat_amount, 2 );
        $invoice_date = date_i18n( 'Y-m-d' );

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
            $symbol_local = $symbol;
            $section = function( $title, $rows ) use ( $symbol_local ) {
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
                            $period = ( $r['period_from'] || $r['period_to'] ) ? trim( $r['period_from'] . ' – ' . $r['period_to'] ) : '';
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
                                <td class="text-right"><?php echo $symbol_local . $price; ?></td>
                                <td class="text-right"><?php echo $symbol_local . $line; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php };

            $section( 'Units',   array_values( array_filter( $items, fn($i)=>$i['type']==='unit' ) ) );
            $section( 'Pallets', array_values( array_filter( $items, fn($i)=>$i['type']==='pallet' ) ) );
            ?>

            <?php
            // Totals we computed above:
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

    /* ============================================================
     * Data loading
     * ============================================================
     */

    protected static function get_storage_settings_map() {
        global $wpdb;
        $table = $wpdb->prefix . 'storage_settings';
        if ( ! self::table_exists( $table ) ) return array();
        $rows = $wpdb->get_results( "SELECT setting_key, setting_value FROM {$table}", ARRAY_A );
        $out  = array();
        foreach ( (array) $rows as $r ) {
            $out[ $r['setting_key'] ] = (string) ( $r['setting_value'] ?? '' );
        }
        return $out;
    }

    
    /**
     * STRICT: Only include items that belong to this customer by:
     *  1) customer_id (if the table has it), else
     *  2) primary_contact_email (EXACT, case-insensitive).
     * No secondary contacts. No name matching.
     */
    protected static function get_unpaid_line_items_for_customer( array $customer, array $cfg = array() ) {
        global $wpdb;
    
        $cid        = absint( $customer['id'] ?? 0 );
        $email_raw  = (string) ( $customer['email'] ?? '' );
        $email_norm = self::normalize_email( $email_raw ); // lowercased + trimmed
        $items      = array();
    
        /* ================= Units ================= */
        $unit_table = $wpdb->prefix . 'storage_units';
        if ( self::table_exists( $unit_table ) ) {
            $has_status = self::table_has_column( $unit_table, 'payment_status' );
            $has_cid    = self::table_has_column( $unit_table, 'customer_id' );
            $has_pmail  = self::table_has_column( $unit_table, 'primary_contact_email' );
    
            $where  = array();
            $params = array();
    
            // Must be unpaid
            if ( $has_status ) {
                $where[]  = "`payment_status` = %s";
                $params[] = 'unpaid';
            } else {
                // Without a status column, we refuse to include anything to avoid cross-customer leakage
                $where[] = '1=0';
            }
    
            // Identity: customer_id OR primary_contact_email (EXACT, case-insensitive)
            if ( $has_cid && $cid > 0 ) {
                $where[]  = "`customer_id` = %d";
                $params[] = $cid;
            } elseif ( $has_pmail && $email_norm !== '' ) {
                // compare LOWER(email) = lower(value)
                $where[]  = "LOWER(`primary_contact_email`) = %s";
                $params[] = $email_norm;
            } else {
                // No safe identity available → block
                $where[] = '1=0';
            }
    
            $sql       = "SELECT * FROM {$unit_table} WHERE " . implode( ' AND ', $where );
            $unit_rows = self::db_select( $sql, $params );
    
            foreach ( (array) $unit_rows as $r ) {
                $from   = self::safe_date( $r[ self::first_present( array_keys( $r ), array( 'period_from','from_date','start_date','start' ) ) ] ?? '' );
                $to     = self::safe_date( $r[ self::first_present( array_keys( $r ), array( 'period_until','to_date','end_date','end' ) ) ] ?? '' );
                $months = self::compute_occupied_months( $from, $to );
    
                // Price: use known column if present else fallback to "last numeric" cell
                $price_key = self::first_present( array_keys( $r ), array( 'monthly_price','price','price_per_month','monthly_cost','total_price','amount_per_month' ) );
                $price     = $price_key ? self::to_float( $r[ $price_key ] ) : 0.0;
                if ( $price <= 0 ) $price = self::row_last_numeric( $r );
    
                // Description bits (best-effort)
                $bits = array();
                foreach ( array( 'unit_name','unit','code','unit_code' ) as $k ) if ( ! empty( $r[ $k ] ) ) { $bits[] = (string) $r[ $k ]; break; }
                foreach ( array( 'size','dimensions','dimension' ) as $k ) if ( ! empty( $r[ $k ] ) ) { $bits[] = (string) $r[ $k ]; break; }
                foreach ( array( 'sqm','m2','square_meters','cubic' ) as $k ) if ( isset( $r[ $k ] ) && $r[ $k ] !== '' ) { $bits[] = $r[ $k ] . ' m²'; break; }
                foreach ( array( 'website_name','ref_code','reference','booking_code' ) as $k ) if ( ! empty( $r[ $k ] ) ) { $bits[] = (string) $r[ $k ]; break; }
    
                $qty  = 1.0;
                $line = round( $price * max( 1, $months ?: 1 ) * $qty, 2 );
    
                $items[] = array(
                    'type'        => 'unit',
                    'name'        => implode( ' • ', array_filter( $bits ) ),
                    'period_from' => $from,
                    'period_to'   => $to,
                    'months'      => $months,
                    'qty'         => $qty,
                    'price'       => $price,
                    'subtotal'    => $line,
                    'notes'       => '',
                );
            }
        }
    
        /* ================= Pallets ================= */
        $pal_table = $wpdb->prefix . 'storage_pallets';
        if ( self::table_exists( $pal_table ) ) {
            $has_status = self::table_has_column( $pal_table, 'payment_status' );
            $has_cid    = self::table_has_column( $pal_table, 'customer_id' );
            $has_pmail  = self::table_has_column( $pal_table, 'primary_contact_email' );
    
            $where  = array();
            $params = array();
    
            if ( $has_status ) {
                $where[]  = "`payment_status` = %s";
                $params[] = 'unpaid';
            } else {
                $where[] = '1=0';
            }
    
            if ( $has_cid && $cid > 0 ) {
                $where[]  = "`customer_id` = %d";
                $params[] = $cid;
            } elseif ( $has_pmail && $email_norm !== '' ) {
                $where[]  = "LOWER(`primary_contact_email`) = %s";
                $params[] = $email_norm;
            } else {
                $where[] = '1=0';
            }
    
            $sql       = "SELECT * FROM {$pal_table} WHERE " . implode( ' AND ', $where );
            $pal_rows  = self::db_select( $sql, $params );
    
            foreach ( (array) $pal_rows as $r ) {
                $from   = self::safe_date( $r[ self::first_present( array_keys( $r ), array( 'period_from','from_date','start_date','start' ) ) ] ?? '' );
                $to     = self::safe_date( $r[ self::first_present( array_keys( $r ), array( 'period_until','to_date','end_date','end' ) ) ] ?? '' );
                $months = self::compute_occupied_months( $from, $to );
    
                $price_key = self::first_present( array_keys( $r ), array( 'monthly_price','price','price_per_month','monthly_cost','total_price','amount_per_month' ) );
                $price     = $price_key ? self::to_float( $r[ $price_key ] ) : 0.0;
                if ( $price <= 0 ) $price = self::row_last_numeric( $r );
    
                $name = '';
                foreach ( array( 'pallet_name','name','code' ) as $k ) if ( ! empty( $r[ $k ] ) ) { $name = (string) $r[ $k ]; break; }
                $type = '';
                foreach ( array( 'pallet_type','type' ) as $k ) if ( ! empty( $r[ $k ] ) ) { $type = (string) $r[ $k ]; break; }
                $hTxt = '';
                foreach ( array( 'charged_height','height_charge','height' ) as $k ) if ( array_key_exists( $k, $r ) ) { $hTxt = 'H: ' . $r[ $k ]; break; }
    
                $desc = trim( implode( ' • ', array_filter( array( $name, $type, $hTxt ) ) ) );
                $qty  = 1.0;
                $line = round( $price * max( 1, $months ?: 1 ) * $qty, 2 );
    
                $items[] = array(
                    'type'        => 'pallet',
                    'name'        => $desc !== '' ? $desc : 'Pallet',
                    'period_from' => $from,
                    'period_to'   => $to,
                    'months'      => $months,
                    'qty'         => $qty,
                    'price'       => $price,
                    'subtotal'    => $line,
                    'notes'       => '',
                );
            }
        }
    
        return $items;
    }

  
    /**
     * Build WHERE + params from available identity columns and the customer's identity set.
     * STRICT mode:
     *  - uses customer_id if present
     *  - else primary_contact_email (EXACT), no secondary
     *  - else primary_contact_name (EXACT), no LIKE
     *  - never runs a global query (falls back to 1=0)
     */
    protected static function build_where_for_identity( $table, array $cols, $cid, array $iden, array $map, array $opts = array() ) {
        $use_secondary = (bool) ( $opts['use_secondary'] ?? false );   // <-- default false
        $allow_like    = (bool) ( $opts['allow_like']    ?? false );   // <-- default false
    
        $where  = array();
        $params = array();
    
        // payment_status = 'unpaid' must exist
        if ( ! empty( $map['payment_status'] ) && in_array( $map['payment_status'], $cols, true ) ) {
            $where[]  = "`{$map['payment_status']}` = %s";
            $params[] = 'unpaid';
        } else {
            // No status column -> never return anything (safer)
            $where[] = '1=0';
            return array( implode(' AND ', $where), $params );
        }
    
        // 1) customer_id wins if present
        if ( ! empty( $map['customer_id'] ) && in_array( $map['customer_id'], $cols, true ) && $cid > 0 ) {
            $where[]  = "`{$map['customer_id']}` = %d";
            $params[] = $cid;
            return array( implode(' AND ', $where), $params );
        }
    
        // 2) primary_contact_email (EXACT). We DO NOT use secondary email unless explicitly enabled.
        $email_vals = array_unique( array_filter( (array) ($iden['emails'] ?? array()), 'strlen' ) );
        if ( ! empty( $map['p_email'] ) && in_array( $map['p_email'], $cols, true ) && ! empty( $email_vals ) ) {
            $or = array();
            foreach ( $email_vals as $e ) {
                $or[]    = "`{$map['p_email']}` = %s";
                $params[]= $e;
            }
            $where[] = '( ' . implode( ' OR ', $or ) . ' )';
            return array( implode(' AND ', $where), $params );
        }
    
        // (optional) secondary email – OFF by default
        if ( $use_secondary && ! empty( $map['s_email'] ) && in_array( $map['s_email'], $cols, true ) && ! empty( $email_vals ) ) {
            $or = array();
            foreach ( $email_vals as $e ) {
                $or[]    = "`{$map['s_email']}` = %s";
                $params[]= $e;
            }
            $where[] = '( ' . implode( ' OR ', $or ) . ' )';
            return array( implode( ' AND ', $where ), $params );
        }
    
        // 3) primary_contact_name (EXACT). No LIKE by default.
        $name_vals = array_unique( array_filter( (array) ($iden['names'] ?? array()), 'strlen' ) );
        if ( ! empty( $map['p_name'] ) && in_array( $map['p_name'], $cols, true ) && ! empty( $name_vals ) ) {
            $or = array();
            foreach ( $name_vals as $n ) {
                if ( $allow_like ) {
                    $like    = '%' . self::db()->esc_like( $n ) . '%';
                    $or[]    = "(`{$map['p_name']}` = %s OR `{$map['p_name']}` LIKE %s)";
                    $params[]= $n; $params[] = $like;
                } else {
                    $or[]    = "`{$map['p_name']}` = %s";
                    $params[]= $n;
                }
            }
            $where[] = '( ' . implode( ' OR ', $or ) . ' )';
            return array( implode(' AND ', $where), $params );
        }
    
        // (optional) secondary name – OFF by default
        if ( $use_secondary && ! empty( $map['s_name'] ) && in_array( $map['s_name'], $cols, true ) && ! empty( $name_vals ) ) {
            $or = array();
            foreach ( $name_vals as $n ) {
                if ( $allow_like ) {
                    $like    = '%' . self::db()->esc_like( $n ) . '%';
                    $or[]    = "(`{$map['s_name']}` = %s OR `{$map['s_name']}` LIKE %s)";
                    $params[]= $n; $params[] = $like;
                } else {
                    $or[]    = "`{$map['s_name']}` = %s";
                    $params[]= $n;
                }
            }
            $where[] = '( ' . implode( ' OR ', $or ) . ' )';
            return array( implode(' AND ', $where), $params );
        }
    
        // Nothing strong enough → return none (prevents cross-customer bleed)
        $where[] = '1=0';
        return array( implode( ' AND ', $where ), $params );
    }

    /* ============================================================
     * Customer identity
     * ============================================================
     */

    /**
     * Build an identity set (emails, names, phones, uuids) for a given customer_id from
     * the storage_customers table (or fallbacks).
     */
    protected static function build_customer_identity( $customer_id ) {
        $out = array( 'emails' => array(), 'names' => array(), 'phones' => array(), 'uuids' => array() );

        $c = self::get_customer_row( $customer_id );
        if ( ! $c ) return $out;

        $push = function(&$arr,$v){
            $v = trim((string)$v);
            if ( $v !== '' && ! in_array($v,$arr,true) ) $arr[] = $v;
        };

        // emails
        foreach ( array('email','customer_email','primary_contact_email','contact_email','secondary_contact_email','alt_email') as $k ) {
            if ( isset($c[$k]) ) $push($out['emails'], $c[$k]);
        }
        // names
        foreach ( array('full_name','name','customer_name','primary_contact_name','secondary_contact_name') as $k ) {
            if ( isset($c[$k]) ) $push($out['names'], $c[$k]);
        }
        // phones
        foreach ( array('phone','mobile','primary_contact_phone','secondary_contact_phone') as $k ) {
            if ( isset($c[$k]) ) $push($out['phones'], preg_replace('/\s+/', '', (string)$c[$k]) );
        }
        // uuids/tokens
        foreach ( array('uuid','customer_uuid','customer_token','customer_hash','customer_ref','customer_reference','public_id') as $k ) {
            if ( isset($c[$k]) ) $push($out['uuids'], $c[$k]);
        }

        self::log_debug('[IDENTITY] for customer '.$customer_id.' => '.json_encode($out));
        return $out;
    }

    protected static function get_customer_row( $customer_id ) {
        global $wpdb;
        $customer_id = absint( $customer_id );

        $candidates = array(
            $wpdb->prefix . 'storage_customers',
            $wpdb->prefix . 'sum_customers_cssc',
            $wpdb->prefix . 'customers',
        );
        foreach ( $candidates as $table ) {
            if ( ! self::table_exists( $table ) ) continue;
            $row = $wpdb->get_row(
                $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $customer_id ),
                ARRAY_A
            );
            if ( $row ) return $row;
        }
        return null;
    }

    protected static function get_customer_by_id( $customer_id ) {
        $row = self::get_customer_row( $customer_id );
        if ( $row ) {
            $name = '';
            foreach ( array('full_name','name','customer_name','primary_contact_name') as $k ) {
                if ( ! empty( $row[$k] ) ) { $name = (string)$row[$k]; break; }
            }
            $email = '';
            foreach ( array('email','customer_email','primary_contact_email','contact_email') as $k ) {
                if ( ! empty( $row[$k] ) ) { $email = (string)$row[$k]; break; }
            }
            return array(
                'id'    => absint($customer_id),
                'name'  => $name ?: ('Customer-' . absint($customer_id)),
                'email' => $email
            );
        }
        return array( 'id' => absint($customer_id), 'name' => 'Customer-' . absint($customer_id), 'email' => '' );
    }

    /* ============================================================
     * DB / Utils
     * ============================================================
     */

    protected static function db() { global $wpdb; return $wpdb; }

    protected static function table_exists( $table ) {
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
        return ( $exists === $table );
    }

    protected static function list_columns( $table ) {
        global $wpdb;
        $rows = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
        return array_map( fn($r)=>$r['Field'], (array)$rows );
    }

    protected static function first_present( array $columns, array $candidates ) {
        foreach ( $candidates as $c ) if ( in_array( $c, $columns, true ) ) return $c;
        return null;
    }

    protected static function db_select( $sql, array $params = array(), $output = ARRAY_A ) {
        global $wpdb;

        if ( empty( $params ) ) {
            if ( preg_match( '/(?<!%)%(?:s|d|f)/', $sql ) ) {
                throw new \RuntimeException( 'SQL has placeholders but no params were provided.' );
            }
            return $wpdb->get_results( $sql, $output );
        }

        $placeholders = preg_match_all( '/(?<!%)%(?:s|d|f)/', $sql );
        if ( $placeholders !== count( $params ) ) {
            throw new \RuntimeException( 'Placeholder/param count mismatch for SQL: ' . $sql );
        }

        $prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $params ) );
        return $wpdb->get_results( $prepared, $output );
    }

    protected static function safe_date( $val ) {
        $val = trim( (string) $val );
        if ( $val === '' || $val === '0000-00-00' ) return '';
        return substr( $val, 0, 10 );
    }

    protected static function compute_occupied_months( $from, $to ) {
        if ( $from === '' || $to === '' ) return null;
        try { $d1 = new \DateTimeImmutable( $from ); $d2 = new \DateTimeImmutable( $to ); }
        catch ( \Throwable $e ) { return null; }
        if ( $d2 < $d1 ) return null;
        $m1 = (int)$d1->format('n'); $y1 = (int)$d1->format('Y');
        $m2 = (int)$d2->format('n'); $y2 = (int)$d2->format('Y');
        return (($y2-$y1)*12)+($m2-$m1)+1;
    }

    protected static function to_float( $v ) {
        if ( is_numeric( $v ) ) return (float)$v;
        $v = str_replace(array('€','£','$',','), '', (string)$v);
        return is_numeric($v) ? (float)$v : 0.0;
    }

    /**
     * When price column is unknown/empty, pick the **last numeric** cell in the row.
     */
    protected static function row_last_numeric( array $row ) {
        $last = 0.0;
        foreach ( $row as $val ) {
            $n = self::to_float( $val );
            if ( $n > 0 ) $last = $n;
        }
        return $last;
    }

    protected static function currency_symbol( $code ) {
        $code = strtoupper( (string) $code );
        switch ( $code ) {
            case 'EUR': return '€';
            case 'USD': return '$';
            case 'GBP': return '£';
            default:    return '';
        }
    }

    /**
     * Debug log (respects WP_DEBUG).
     */
    protected static function log_debug( $msg ) {
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log('[SUM Invoice] ' . $msg);
        }
    }
}

endif;

SUM_Customer_Invoice_Handler_CSSC::init();
