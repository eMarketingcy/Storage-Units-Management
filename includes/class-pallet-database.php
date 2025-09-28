<?php
/**
 * Pallet Database operations for Storage Unit Manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class SUM_Pallet_Database {

    public function __construct() {
        // Ensure schema & default pricing exist even if activation hook didn't run
        $this->create_tables();
    }

    public function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Pallets table
        $table_name = $wpdb->prefix . 'storage_pallets';
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            pallet_name varchar(100) NOT NULL,
            pallet_type varchar(10) DEFAULT 'EU',
            actual_height decimal(10,2) DEFAULT NULL,
            charged_height decimal(10,2) DEFAULT NULL,
            cubic_meters decimal(10,3) DEFAULT NULL,
            monthly_price decimal(10,2) DEFAULT NULL,
            period_from date DEFAULT NULL,
            period_until date DEFAULT NULL,
            payment_status varchar(20) DEFAULT 'paid',
            payment_token varchar(64) NOT NULL DEFAULT '',
            primary_contact_name varchar(200) DEFAULT '',
            primary_contact_phone varchar(50) DEFAULT '',
            primary_contact_whatsapp varchar(50) DEFAULT '',
            primary_contact_email varchar(200) DEFAULT '',
            secondary_contact_name varchar(200) DEFAULT '',
            secondary_contact_phone varchar(50) DEFAULT '',
            secondary_contact_whatsapp varchar(50) DEFAULT '',
            secondary_contact_email varchar(200) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY pallet_name (pallet_name),
            KEY idx_payment_token (payment_token)
        ) $charset_collate;";

        // Pallet settings table (height/price tiers)
        $pallet_settings_table = $wpdb->prefix . 'storage_pallet_settings';
        $pallet_settings_sql = "CREATE TABLE $pallet_settings_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            pallet_type varchar(10) NOT NULL,
            height decimal(10,2) NOT NULL,
            price decimal(10,2) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY type_height (pallet_type, height)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($pallet_settings_sql);

        $this->insert_default_pallet_pricing();
    }

    private function insert_default_pallet_pricing() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'storage_pallet_settings';

        $existing = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        if ($existing > 0) {
            return;
        }

        // EU Pallet pricing (1.20m x 0.80m base)
        $eu_prices = array(
            array('EU', 1.00, 30.00),
            array('EU', 1.20, 36.00),
            array('EU', 1.40, 42.00),
            array('EU', 1.60, 48.00),
            array('EU', 1.80, 54.00),
            array('EU', 2.00, 60.00)
        );

        // US Pallet pricing (1.22m x 1.02m base)
        $us_prices = array(
            array('US', 1.00, 35.00),
            array('US', 1.20, 42.00),
            array('US', 1.40, 49.00),
            array('US', 1.60, 56.00),
            array('US', 1.80, 63.00),
            array('US', 2.00, 70.00)
        );

        foreach (array_merge($eu_prices, $us_prices) as $row) {
            $wpdb->insert(
                $table_name,
                array(
                    'pallet_type' => $row[0],
                    'height'      => $row[1],
                    'price'       => $row[2]
                ),
                array('%s','%f','%f')
            );
        }
    }

    // ---------------- Query helpers ----------------

    public function get_pallets($filter = 'all') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'storage_pallets';

        $where_clause = '';
        if ($filter === 'past_due') {
            $where_clause = 'WHERE period_until < CURDATE()';
        } elseif ($filter === 'unpaid') {
            $where_clause = 'WHERE payment_status != "paid"';
        } elseif ($filter === 'eu') {
            $where_clause = 'WHERE pallet_type = "EU"';
        } elseif ($filter === 'us') {
            $where_clause = 'WHERE pallet_type = "US"';
        }

        return $wpdb->get_results("SELECT * FROM $table_name $where_clause ORDER BY pallet_name", ARRAY_A);
    }

    public function get_pallet($pallet_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'storage_pallets';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", (int)$pallet_id), ARRAY_A);
    }

    public function get_pallet_by_token($token) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'storage_pallets';
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE payment_token = %s LIMIT 1", $token),
            ARRAY_A
        );
    }

    public function get_pallet_settings() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'storage_pallet_settings';
        return $wpdb->get_results("SELECT * FROM $table_name ORDER BY pallet_type, height", ARRAY_A);
    }

    // --------------- Height / price logic ---------------

    public function get_height_tiers($type) {
        // If EU/US tiers diverge later, split by $type here.
        return array(1.00, 1.20, 1.40, 1.60, 1.80, 2.00);
    }

    public function compute_charged_height($actual_height, $type) {
        $actual = (float)$actual_height;
        $tiers  = $this->get_height_tiers($type);
        sort($tiers, SORT_NUMERIC);

        foreach ($tiers as $tier) {
            if ($actual <= (float)$tier + 1e-9) {
                return (float)$tier; // round up to first tier >= actual
            }
        }
        // If above all tiers, cap at the highest tier.
        return (float) end($tiers);
    }

    public function get_monthly_price_for($type, $charged_height) {
        global $wpdb;
        $table = $wpdb->prefix . 'storage_pallet_settings';

        // Normalize to avoid float equality issues
        $height_key = number_format((float)$charged_height, 2, '.', '');

        $price = $wpdb->get_var($wpdb->prepare(
            "SELECT price FROM $table WHERE pallet_type = %s AND height = %s LIMIT 1",
            $type,
            $height_key
        ));

        return ($price !== null) ? (float)$price : 0.0;
    }

    // Backward-compatible wrappers (keep your original names working)
    private function calculate_charged_height($actual_height) {
        // Old signature had no type; default EU to stay backward compatible
        return $this->compute_charged_height($actual_height, 'EU');
    }

    private function get_price_for_height($pallet_type, $height) {
        return $this->get_monthly_price_for($pallet_type, $height);
    }

    private function calculate_cubic_meters($pallet_type, $height) {
        if ($pallet_type === 'US') { // 1.22m x 1.02m
            return 1.22 * 1.02 * $height;
        }
        // EU: 1.20m x 0.80m
        return 1.20 * 0.80 * $height;
    }

    // ---------------- Save / Delete ----------------

    public function save_pallet($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'storage_pallets';

        $pallet_id   = isset($data['pallet_id']) ? (int)$data['pallet_id'] : 0;
        $pallet_type = isset($data['pallet_type']) ? sanitize_text_field($data['pallet_type']) : 'EU';
        $actual_h    = isset($data['actual_height']) ? (float)$data['actual_height'] : 0.0;

        // Always recompute based on current settings
        $charged_h     = $this->compute_charged_height($actual_h, $pallet_type);
        $monthly_price = $this->get_monthly_price_for($pallet_type, $charged_h);
        $cubic_meters  = $this->calculate_cubic_meters($pallet_type, $charged_h);

        $row = array(
            'pallet_name'               => sanitize_text_field($data['pallet_name']),
            'pallet_type'               => $pallet_type,
            'actual_height'             => $actual_h ?: null,
            'charged_height'            => $charged_h,
            'cubic_meters'              => $cubic_meters,
            'monthly_price'             => $monthly_price,
            'period_from'               => isset($data['period_from'])  ? (sanitize_text_field($data['period_from'])  ?: null) : null,
            'period_until'              => isset($data['period_until']) ? (sanitize_text_field($data['period_until']) ?: null) : null,
            'payment_status'            => isset($data['payment_status']) ? sanitize_text_field($data['payment_status']) : 'paid',
            'primary_contact_name'      => isset($data['primary_contact_name']) ? sanitize_text_field($data['primary_contact_name']) : '',
            'primary_contact_phone'     => isset($data['primary_contact_phone']) ? sanitize_text_field($data['primary_contact_phone']) : '',
            'primary_contact_whatsapp'  => isset($data['primary_contact_whatsapp']) ? sanitize_text_field($data['primary_contact_whatsapp']) : '',
            'primary_contact_email'     => isset($data['primary_contact_email']) ? sanitize_email($data['primary_contact_email']) : '',
            'secondary_contact_name'    => isset($data['secondary_contact_name']) ? sanitize_text_field($data['secondary_contact_name']) : '',
            'secondary_contact_phone'   => isset($data['secondary_contact_phone']) ? sanitize_text_field($data['secondary_contact_phone']) : '',
            'secondary_contact_whatsapp'=> isset($data['secondary_contact_whatsapp']) ? sanitize_text_field($data['secondary_contact_whatsapp']) : '',
            'secondary_contact_email'   => isset($data['secondary_contact_email']) ? sanitize_email($data['secondary_contact_email']) : '',
        );

        $format = array('%s','%s','%f','%f','%f','%f','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s');

        if ($pallet_id > 0) {
            $res = $wpdb->update($table_name, $row, array('id' => $pallet_id), $format, array('%d'));
            if ($res !== false) {
                $this->ensure_payment_token($pallet_id);
            }
            return $res;
        } else {
            $res = $wpdb->insert($table_name, $row, $format);
            if ($res !== false) {
                $this->ensure_payment_token((int)$wpdb->insert_id);
            }
            return $res;
        }
    }

    public function delete_pallet($pallet_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'storage_pallets';
        return $wpdb->delete($table_name, array('id' => (int)$pallet_id), array('%d'));
    }

    // ---------------- Payment token helpers ----------------

    public function ensure_payment_token($pallet_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'storage_pallets';

        $token = $wpdb->get_var($wpdb->prepare(
            "SELECT payment_token FROM $table_name WHERE id = %d",
            (int)$pallet_id
        ));
        if (!empty($token)) {
            return $token;
        }

        $token = wp_generate_password(32, false, false); // URL-safe
        $wpdb->update(
            $table_name,
            array('payment_token' => $token),
            array('id' => (int)$pallet_id),
            array('%s'),
            array('%d')
        );

        return $token;
    }

    public function get_payment_token($pallet_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'storage_pallets';
        return (string) $wpdb->get_var($wpdb->prepare(
            "SELECT payment_token FROM $table_name WHERE id = %d",
            (int)$pallet_id
        ));
    }

    // ---------------- Naming helpers (kept from your original) ----------------

    public function generate_pallet_name($customer_name) {
        $names = explode(' ', trim($customer_name));
        $initials = '';

        foreach ($names as $name) {
            if (!empty($name)) {
                $initials .= strtoupper(substr($name, 0, 1));
            }
        }

        if (strlen($initials) < 2) {
            $initials = str_pad($initials, 2, 'X');
        }

        $combinations = array();
        if (strlen($initials) >= 2) {
            $combinations[] = substr($initials, 0, 2);
        }
        if (strlen($initials) >= 3) {
            $combinations[] = substr($initials, 0, 3);
        }
        if (count($combinations) < 2 && !empty($names[0])) {
            $first_name = strtoupper($names[0]);
            if (strlen($initials) >= 2 && strlen($first_name) > 1) {
                $combinations[] = substr($initials, 0, 2) . substr($first_name, 1, 1);
            }
        }

        foreach ($combinations as $base) {
            for ($i = 1; $i <= 99; $i++) {
                $pallet_name = $base . $i;
                if (!$this->pallet_name_exists($pallet_name)) {
                    return $pallet_name;
                }
            }
        }

        for ($i = 1; $i <= 999; $i++) {
            $random_letters = chr(rand(65, 90)) . chr(rand(65, 90));
            $pallet_name = $random_letters . $i;
            if (!$this->pallet_name_exists($pallet_name)) {
                return $pallet_name;
            }
        }

        return 'PL' . time();
    }

    private function pallet_name_exists($pallet_name) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'storage_pallets';
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE pallet_name = %s",
            $pallet_name
        ));
        return $count > 0;
    }

    // ---------------- Reminders ----------------

    public function get_expiring_pallets($days) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'storage_pallets';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name 
             WHERE period_until = DATE_ADD(CURDATE(), INTERVAL %d DAY)
             AND primary_contact_email != ''",
            (int)$days
        ), ARRAY_A);
    }
}
