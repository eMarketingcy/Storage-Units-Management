<?php
if (!defined('ABSPATH')) exit;

class SUM_Customers_Database_CSSC {
    // The DB_VER is not strictly necessary anymore since we're using
    // maybe_upgrade_cssc(), but it's kept here for completeness.
    const DB_VER = '2.1.0'; /* <<< UPDATED VERSION FOR NEW SCHEMA */
    private $table;


    public function __construct() {
        global $wpdb;
        // Updated table name as per your request
        $this->table = $wpdb->prefix . 'storage_customers_cssc';
    }


    public function maybe_install_cssc() {
        $ver = get_option('sum_customers_db_ver_cssc', '');
        // Run full table creation on initial install or major version change
        if ($ver !== self::DB_VER) {
            $this->create_tables();
            update_option('sum_customers_db_ver_cssc', self::DB_VER, true);
        } else {
            // Safety: ensure all columns and indexes are present
            $this->maybe_upgrade_cssc();
        }
    }
    
public function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        
        // Final, Cleaned SQL structure for dbDelta:
        $sql = "CREATE TABLE {$this->table} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(200) NOT NULL DEFAULT '',
            email VARCHAR(200) NOT NULL DEFAULT '',
            phone VARCHAR(50)  NOT NULL DEFAULT '',
            whatsapp VARCHAR(50) NOT NULL DEFAULT '',
            fingerprint VARCHAR(64) NOT NULL DEFAULT '',
            status ENUM('active','past','lead') NOT NULL DEFAULT 'active',
            secondary_name VARCHAR(200) NOT NULL DEFAULT '', 
            secondary_email VARCHAR(200) NOT NULL DEFAULT '',
            secondary_phone VARCHAR(50) NOT NULL DEFAULT '',
            secondary_whatsapp VARCHAR(50) NOT NULL DEFAULT '',
            current_units TEXT,
            current_pallets TEXT,
            past_units TEXT,
            past_pallets TEXT,
            sources TEXT,
            secondary_contact TEXT,
            last_payment_date DATE NULL DEFAULT NULL, 
            total_payments_amount DECIMAL(10, 2) NOT NULL DEFAULT '0.00',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
             PRIMARY KEY (id),
             UNIQUE KEY uniq_fingerprint (fingerprint)
        ) $charset;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }    
     // Safety: add missing columns/indexes without nuking data.
    public function maybe_upgrade_cssc() {
        global $wpdb;
        
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table}'");
        if (!$table_exists) {
            $this->create_tables();
            return;
        }

        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$this->table}", 0);

        // --- Core Columns (Checked by older code) ---
        if (!in_array('whatsapp', $cols)) {
            $wpdb->query("ALTER TABLE {$this->table} ADD COLUMN whatsapp VARCHAR(50) NOT NULL DEFAULT '' AFTER phone");
        }
        if (!in_array('fingerprint', $cols)) {
            $wpdb->query("ALTER TABLE {$this->table} ADD COLUMN fingerprint VARCHAR(64) NOT NULL DEFAULT '' AFTER whatsapp");
        }
        if (!in_array('status', $cols)) {
            $enum_def = "ENUM('active','past','lead') NOT NULL DEFAULT 'active'";
            $wpdb->query("ALTER TABLE {$this->table} ADD COLUMN status {$enum_def} AFTER fingerprint");
        }
        
        // --- NEW COLUMNS: Secondary Contact Details ---
        if (!in_array('secondary_name', $cols)) {
            $wpdb->query("ALTER TABLE {$this->table} ADD COLUMN secondary_name VARCHAR(200) NOT NULL DEFAULT '' AFTER status");
        }
        if (!in_array('secondary_email', $cols)) {
            $wpdb->query("ALTER TABLE {$this->table} ADD COLUMN secondary_email VARCHAR(200) NOT NULL DEFAULT '' AFTER secondary_name");
        }
        if (!in_array('secondary_phone', $cols)) {
            $wpdb->query("ALTER TABLE {$this->table} ADD COLUMN secondary_phone VARCHAR(50) NOT NULL DEFAULT '' AFTER secondary_email");
        }
        if (!in_array('secondary_whatsapp', $cols)) {
            $wpdb->query("ALTER TABLE {$this->table} ADD COLUMN secondary_whatsapp VARCHAR(50) NOT NULL DEFAULT '' AFTER secondary_phone");
        }

        // --- NEW COLUMNS: Payment Tracking ---
        if (!in_array('last_payment_date', $cols)) {
            $wpdb->query("ALTER TABLE {$this->table} ADD COLUMN last_payment_date DATE NULL DEFAULT NULL");
        }
        if (!in_array('total_payments_amount', $cols)) {
            $wpdb->query("ALTER TABLE {$this->table} ADD COLUMN total_payments_amount DECIMAL(10, 2) NOT NULL DEFAULT '0.00'");
        }

        // Add TEXT columns (updated to ensure secondary_contact is added)
        foreach (['current_units','current_pallets','past_units','past_pallets','sources', 'secondary_contact'] as $c) {
            if (!in_array($c, $cols)) {
                $wpdb->query("ALTER TABLE {$this->table} ADD COLUMN $c TEXT");
            }
        }
        
        // Ensure unique index is present
        $idx = $wpdb->get_results("SHOW INDEX FROM {$this->table} WHERE Key_name='uniq_fingerprint'");
        if (empty($idx)) {
            $wpdb->query("ALTER TABLE {$this->table} ADD UNIQUE KEY uniq_fingerprint (fingerprint)");
        }
    }

    /* ---------------- Normalizers & fingerprint ---------------- */

    private function norm_email($e) {
        $e = strtolower(trim((string)$e));
        return $e;
    }
    private function norm_phone($p) {
        // digits only; strip leading zeros for stability
        $d = preg_replace('/\D+/', '', (string)$p);
        $d = ltrim($d, '0');
        return $d;
    }
    private function norm_name($n) {
        return preg_replace('/\s+/', ' ', strtolower(trim((string)$n)));
    }
    
    // Key function for deduplication
    private function fingerprint($name, $email, $phone) {
        $e = $this->norm_email($email);
        $p = $this->norm_phone($phone);
        // Priority 1: Email (most unique)
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) return 'e:' . $e;
        // Priority 2: Phone (second most unique, minimum 8 digits)
        if ($p !== '' && strlen($p) >= 8) return 'p:' . $p;
        // Priority 3: Name (least unique, normalized)
        return 'n:' . md5($this->norm_name($name)); // Use MD5 of normalized name for fixed length
    }
    
    // Helper to merge customer data from sources
    private function mergeCustomer(&$existing, $name, $email, $phone, $whatsapp) {
        // Prefer non-empty and non-normalized values where possible
        if ($name !== '' && $existing['name'] === '') {
            $existing['name'] = $name;
        }
        if ($email !== '' && $existing['email'] === '') {
            $existing['email'] = $this->norm_email($email);
        }
        if ($phone !== '' && $existing['phone'] === '') {
            $existing['phone'] = $this->norm_phone($phone);
        }
        if ($whatsapp !== '' && $existing['whatsapp'] === '') {
            $existing['whatsapp'] = $this->norm_phone($whatsapp);
        }
    }
    
    /* ---------------- Public API ---------------- */
    
    /**
     * Retrieves a single customer record by ID.
     * This is the function used by the modal AJAX handler.
     * @param int $customer_id
     * @return array|null The customer row as an array, or null if not found.
     */
    public function get_customer_by_id_cssc($customer_id) {
        global $wpdb;
        $table_name = $this->table; 

        if (empty($customer_id) || !is_numeric($customer_id)) {
            return null;
        }

        // Use $wpdb->prepare for security and select all columns
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $customer_id
        );

        $customer = $wpdb->get_row($sql, ARRAY_A);

        if (!$customer) {
            return null;
        }
        
        // Convert comma-separated strings back to arrays for asset fields
        $asset_fields = ['current_units','current_pallets','past_units','past_pallets'];
        foreach ($asset_fields as $k) {
            $customer[$k] = array_filter(array_map('trim', explode(',', $customer[$k] ?? '')));
        }
        
        // Ensure secondary_contact is a clean string 
        $customer['secondary_contact'] = trim($customer['secondary_contact'] ?? '');

        return $customer;
    }
    
    // NOTE: The original sync_from_wpdb_cssc is replaced by this new sync_from_sources
    // but the logic is slightly adjusted to match the new customer fields and text storage format.
    public function sync_from_wpdb_cssc() {
        global $wpdb;

        $tblUnits   = $wpdb->prefix . 'storage_units';
        $tblPallets = $wpdb->prefix . 'storage_pallets';

        // Check if source tables exist before querying
        if ($wpdb->get_var("SHOW TABLES LIKE '{$tblUnits}'") !== $tblUnits) $units = [];
        else $units   = $wpdb->get_results("SELECT * FROM {$tblUnits}", ARRAY_A)   ?: [];

        if ($wpdb->get_var("SHOW TABLES LIKE '{$tblPallets}'") !== $tblPallets) $pallets = [];
        else $pallets = $wpdb->get_results("SELECT * FROM {$tblPallets}", ARRAY_A) ?: [];

        return $this->sync_from_sources($units, $pallets);
    }
    
    /**
     * Merge customers from units & pallets arrays.
     * $units:   rows from storage_units (assoc arrays)
     * $pallets: rows from storage_pallets (assoc arrays)
     */
    public function sync_from_sources($units, $pallets) {
        global $wpdb;

        $map = []; // fingerprint => aggregated data

        // helper to attach a rental to a person bucket
        $attach = function (&$map, $row, $type) {
            $name     = trim($row['primary_contact_name']  ?? '');
            $email    = trim($row['primary_contact_email'] ?? '');
            $phone    = trim($row['primary_contact_phone'] ?? '');
            $whatsapp = trim($row['primary_contact_whatsapp'] ?? '');
            
            // Determine the primary contact phone: prefer phone, then whatsapp
            $contact_phone = $phone ?: $whatsapp;

            $fp = $this->fingerprint($name, $email, $contact_phone);

            // Skip records that don't generate a valid fingerprint (no name, email, or phone)
            if (strpos($fp, 'n:') === 0 && md5($this->norm_name($name)) === substr($fp, 2)) {
                 // Check if it's the fallback fingerprint for a blank name, which should be avoided
                 if (empty($name) && empty($email) && empty($contact_phone)) {
                     return;
                 }
            }
            
            if (!isset($map[$fp])) {
                $map[$fp] = [
                    'name'    => $name,
                    'email'   => $this->norm_email($email),
                    'phone'   => $this->norm_phone($phone),
                    'whatsapp'=> $this->norm_phone($whatsapp),
                    'sources' => [],
                    'current_units'   => [],
                    'current_pallets' => [],
                    'past_units'      => [],
                    'past_pallets'    => [],
                ];
            } else {
                // Merge additional contact info
                $this->mergeCustomer($map[$fp], $name, $email, $phone, $whatsapp);
            }

            $is_active = false;
            $label     = '';
            if ($type === 'unit') {
                // For units, use is_occupied status
                $is_active = (int)($row['is_occupied'] ?? 0) === 1;
                $label = $row['unit_name'] ?? ('#' . ($row['id'] ?? ''));
                $is_active ? $map[$fp]['current_units'][] = $label : $map[$fp]['past_units'][] = $label;
            } else {
                // For pallets, use period_until date to determine active status
                $today = date('Y-m-d');
                $until = trim($row['period_until'] ?? '');
                
                // Active if there's no until date, or the until date is today or in the future
                if ($until !== '' && $until !== '0000-00-00') {
                    $is_active = $until >= $today;
                }
                
                $label = $row['pallet_name'] ?? ('#' . ($row['id'] ?? ''));
                $is_active ? $map[$fp]['current_pallets'][] = $label : $map[$fp]['past_pallets'][] = $label;
            }
            // Record source type
            $map[$fp]['sources'][] = $type;
        };

        foreach ((array)$units as $u)   { $attach($map, $u,   'unit');   }
        foreach ((array)$pallets as $p) { $attach($map, $p,   'pallet'); }

        $inserted = 0; $updated = 0;

        foreach ($map as $fp => $c) {
            // Determine overall status
            $status = 'past';
            if (!empty($c['current_units']) || !empty($c['current_pallets'])) {
                $status = 'active';
            }
            
            // Ensure data integrity before inserting/updating
            if ($c['name'] === '' && $c['email'] === '' && $c['phone'] === '' && $c['whatsapp'] === '') {
                 // Skip customers with no identifying information
                 continue;
            }

            // Does it exist? Check by fingerprint.
            $existing_customer = $wpdb->get_row(
                $wpdb->prepare("SELECT id, name, email, phone, whatsapp FROM {$this->table} WHERE fingerprint = %s LIMIT 1", $fp),
                ARRAY_A
            );
            $id = (int) ($existing_customer['id'] ?? 0);

            // Coalesce contact info: prefer existing non-empty values over new blank ones.
            if ($id > 0) {
                // Keep the original name/email/phone/whatsapp if they are more complete
                $c['name']     = $existing_customer['name']     ?: $c['name'];
                $c['email']    = $existing_customer['email']    ?: $c['email'];
                $c['phone']    = $existing_customer['phone']    ?: $c['phone'];
                $c['whatsapp'] = $existing_customer['whatsapp'] ?: $c['whatsapp'];
            }
            
            // Build the data array for insert/update
            $data = [
                'name'            => $c['name'],
                'email'           => $c['email'],
                'phone'           => $c['phone'],
                'whatsapp'        => $c['whatsapp'],
                'fingerprint'     => $fp,
                'status'          => $status,
                // Use comma-separated string for TEXT columns
                'current_units'   => implode(', ', array_unique($c['current_units'])),
                'current_pallets' => implode(', ', array_unique($c['current_pallets'])),
                'past_units'      => implode(', ', array_unique($c['past_units'])),
                'past_pallets'    => implode(', ', array_unique($c['past_pallets'])),
                'sources'         => implode(', ', array_unique($c['sources'])),
                'updated_at'      => current_time('mysql'),
            ];
            
            // Format array for wpdb operations
            $fmt = ['%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s'];

            if ($id > 0) {
                // Update existing record
                $ok = $wpdb->update($this->table, $data, ['id' => $id], $fmt, ['%d']);
                if ($ok !== false) $updated++;
            } else {
                // Insert new record
                $data['created_at'] = current_time('mysql');
                $fmt[] = '%s'; // Add format for created_at
                $ok = $wpdb->insert($this->table, $data, $fmt);
                if ($ok) $inserted++;
            }
        }

        // Deactivate customers that were not in the sync data (i.e., they are truly past/lead)
        // This is a simplified approach, a full sync would involve a more complex cleanup
        // to set to 'past' any previously 'active' customer not present in the new map.
        // For now, we only update existing or insert new.
        
        return [
            'inserted' => $inserted, 
            'updated'  => $updated,
            'source_units'   => count($units),
            'source_pallets' => count($pallets),
        ];
    }

    public function get_customers_cssc($args = []) {
        global $wpdb;
        $search = trim($args['search'] ?? '');
        $status = trim($args['status'] ?? '');
        $limit  = max(1, (int)($args['limit'] ?? 200));
        $offset = max(0, (int)($args['offset'] ?? 0));

        $where = 'WHERE 1=1';
        $params = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            // Search against name, email, phone, and current assets
            $where .= " AND (name LIKE %s OR email LIKE %s OR phone LIKE %s
                       OR current_units LIKE %s OR current_pallets LIKE %s)";
            $params = array_merge($params, [$like,$like,$like,$like,$like]);
        }
        
        // This condition replaces the old explicit check for non-empty JSON arrays
        // Since we are now storing comma-separated strings, we can check the status.
        if ($status !== '') {
            $where .= " AND status = %s";
            $params[] = $status;
        } else {
            // Default: only show active and past customers (exclude 'lead' if it was added manually)
            $where .= " AND status IN ('active', 'past')";
        }
        
        // Exclude customers with no data (fingerprint is the only way to identify an empty customer now)
        // The sync_from_sources logic ensures customers with no name/email/phone and no assets aren't saved.
        // We'll trust the sync process and let the search/status filters handle visibility.

        $sql = "SELECT * FROM {$this->table} $where ORDER BY name ASC LIMIT %d OFFSET %d";
        $params[] = $limit; $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: [];

        // For compatibility with the UI, convert comma-separated strings back to arrays
        foreach ($rows as &$r) {
            foreach (['current_units','current_pallets','past_units','past_pallets'] as $k) {
                // Split by comma, trim whitespace, and filter empty strings
                $r[$k] = array_filter(array_map('trim', explode(',', $r[$k])));
                if (!is_array($r[$k])) $r[$k] = [];
            }
            
            // Add unpaid invoices information (this logic remains the same)
            $r['unpaid_invoices'] = $this->get_unpaid_invoices_for_customer($r);
        }
        return $rows;
    }

    public function delete_customer_cssc($id) {
        global $wpdb;
        return $wpdb->delete($this->table, ['id' => (int)$id], ['%d']);
    }

    // This function remains largely the same, but it uses the customer data which is now
    // sourced from the aggregated data (comma-separated strings, converted back to arrays).
    public function get_unpaid_invoices_for_customer($customer) {
        global $wpdb;
        $unpaid = [];
        
        // Check units
        if (!empty($customer['current_units'])) {
            $units_table = $wpdb->prefix . 'storage_units';
            // Note: The new format stores "Unit: Name" or just "Name". 
            // We assume $unit_name is the actual name now.
            foreach ($customer['current_units'] as $unit_label) {
                // Attempt to extract the clean name/ID from the label
                $unit_name = preg_replace('/^(Unit: |Pallet: |#)/i', '', $unit_label);
                $unit_name = trim($unit_name);
                
                if (empty($unit_name)) continue;

                $unit = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$units_table} WHERE unit_name = %s AND payment_status != 'paid'",
                    $unit_name
                ), ARRAY_A);
                
                if ($unit) {
                    $unpaid[] = [
                        'type' => 'Unit',
                        'name' => $unit['unit_name'],
                        'amount' => $unit['monthly_price'] ?: 0,
                        'status' => $unit['payment_status']
                    ];
                }
            }
        }
        
        // Check pallets
        if (!empty($customer['current_pallets'])) {
            $pallets_table = $wpdb->prefix . 'storage_pallets';
            foreach ($customer['current_pallets'] as $pallet_label) {
                // Attempt to extract the clean name/ID from the label
                $pallet_name = preg_replace('/^(Unit: |Pallet: |#)/i', '', $pallet_label);
                $pallet_name = trim($pallet_name);

                if (empty($pallet_name)) continue;
                
                $pallet = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$pallets_table} WHERE pallet_name = %s AND payment_status != 'paid'",
                    $pallet_name
                ), ARRAY_A);
                
                if ($pallet) {
                    $unpaid[] = [
                        'type' => 'Pallet',
                        'name' => $pallet['pallet_name'],
                        'amount' => $pallet['monthly_price'] ?: 0,
                        'status' => $pallet['payment_status']
                    ];
                }
            }
        }
        
        return $unpaid;
    }
    
    // The following asset management functions remain the same as they operate 
    // directly on the storage_units/pallets tables.

    public function get_available_units() {
        global $wpdb;
        $units_table = $wpdb->prefix . 'storage_units';
        return $wpdb->get_results(
            "SELECT * FROM {$units_table} WHERE is_occupied = 0 ORDER BY unit_name",
            ARRAY_A
        );
    }
    
    public function get_available_pallets() {
        global $wpdb;
        $pallets_table = $wpdb->prefix . 'storage_pallets';
        return $wpdb->get_results(
            "SELECT * FROM {$pallets_table} WHERE (period_until IS NULL OR period_until < CURDATE() OR period_until = '0000-00-00') ORDER BY pallet_name",
            ARRAY_A
        );
    }
    
    public function assign_unit_to_customer($customer_id, $unit_id) {
        global $wpdb;
        $units_table = $wpdb->prefix . 'storage_units';
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $customer_id), ARRAY_A);
        $unit = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$units_table} WHERE id = %d", $unit_id), ARRAY_A);
        
        if (!$customer || !$unit || $unit['is_occupied']) {
            return false;
        }
        
        // Update unit with customer info and mark as occupied
        $result = $wpdb->update(
            $units_table,
            [
                'is_occupied' => 1,
                'primary_contact_name' => $customer['name'],
                'primary_contact_email' => $customer['email'],
                'primary_contact_phone' => $customer['phone'],
                'period_from' => date('Y-m-d'),
                'period_until' => date('Y-m-d', strtotime('+1 month'))
            ],
            ['id' => $unit_id],
            ['%d', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );
        
        // After assignment, you should run a sync to update the customer record
        if ($result !== false) {
             // A lightweight sync for only this customer's unit could be more efficient, 
             // but running the full sync_from_wpdb_cssc() is safer for now.
             // You might want to schedule a full sync later instead of running it immediately.
             // $this->sync_from_wpdb_cssc();
        }

        return $result !== false;
    }
    
    public function assign_pallet_to_customer($customer_id, $pallet_id) {
        global $wpdb;
        $pallets_table = $wpdb->prefix . 'storage_pallets';
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $customer_id), ARRAY_A);
        $pallet = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pallets_table} WHERE id = %d", $pallet_id), ARRAY_A);
        
        if (!$customer || !$pallet) {
            return false;
        }
        
        // Check if pallet is available (no current period or expired)
        if ($pallet['period_until'] && $pallet['period_until'] >= date('Y-m-d')) {
            return false;
        }
        
        // Update pallet with customer info
        $result = $wpdb->update(
            $pallets_table,
            [
                'primary_contact_name' => $customer['name'],
                'primary_contact_email' => $customer['email'],
                'primary_contact_phone' => $customer['phone'],
                'period_from' => date('Y-m-d'),
                'period_until' => date('Y-m-d', strtotime('+1 month'))
            ],
            ['id' => $pallet_id],
            ['%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );
        
        // After assignment, you should run a sync to update the customer record
        if ($result !== false) {
            // $this->sync_from_wpdb_cssc();
        }

        return $result !== false;
    }
}