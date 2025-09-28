<?php
if (!defined('ABSPATH')) exit;

class SUM_Customers_Database {

    public function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table   = $wpdb->prefix . 'storage_customers';

        $sql = "CREATE TABLE $table (
            id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
            display_name VARCHAR(200) NOT NULL,
            email VARCHAR(200) DEFAULT '',
            email_norm VARCHAR(200) DEFAULT '',
            phone VARCHAR(50) DEFAULT '',
            phone_norm VARCHAR(50) DEFAULT '',
            whatsapp VARCHAR(50) DEFAULT '',
            address TEXT,
            notes TEXT,
            status VARCHAR(20) DEFAULT 'prospective', /* prospective | active | past */
            entity_type VARCHAR(20) DEFAULT '',        /* unit | pallet */
            entity_id MEDIUMINT(9) DEFAULT NULL,
            contact_role VARCHAR(20) DEFAULT 'primary',/* primary | secondary */
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_email (email_norm),
            KEY idx_phone (phone_norm),
            KEY idx_status (status),
            KEY idx_entity (entity_type, entity_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /* ---------- Normalizers ---------- */

    public function normalize_email($e) {
        $e = trim(strtolower((string)$e));
        return $e;
    }
    public function normalize_phone($p) {
        // keep digits only for matching
        $p = preg_replace('/\D+/', '', (string)$p);
        return $p;
    }

    /* ---------- Upsert from Unit/Pallet ---------- */

    public function upsert_from_unit($unit, $role = 'primary') {
        if (!$unit) return false;

        $name     = (string)($role === 'secondary' ? ($unit['secondary_contact_name'] ?? '') : ($unit['primary_contact_name'] ?? ''));
        $email    = (string)($role === 'secondary' ? ($unit['secondary_contact_email'] ?? '') : ($unit['primary_contact_email'] ?? ''));
        $phone    = (string)($role === 'secondary' ? ($unit['secondary_contact_phone'] ?? '') : ($unit['primary_contact_phone'] ?? ''));
        $whatsapp = (string)($role === 'secondary' ? ($unit['secondary_contact_whatsapp'] ?? '') : ($unit['primary_contact_whatsapp'] ?? ''));

        $status = 'prospective';
        $today  = date('Y-m-d');
        if (!empty($unit['period_until'])) {
            $status = ($unit['period_until'] >= $today) ? 'active' : 'past';
        } elseif (!empty($unit['period_from'])) {
            $status = 'active';
        }

        return $this->upsert_customer(array(
            'display_name' => $name,
            'email'        => $email,
            'phone'        => $phone,
            'whatsapp'     => $whatsapp,
            'status'       => $status,
            'entity_type'  => 'unit',
            'entity_id'    => (int)($unit['id'] ?? 0),
            'contact_role' => $role
        ));
    }

    public function upsert_from_pallet($pallet, $role = 'primary') {
        if (!$pallet) return false;

        $name     = (string)($role === 'secondary' ? ($pallet['secondary_contact_name'] ?? '') : ($pallet['primary_contact_name'] ?? ''));
        $email    = (string)($role === 'secondary' ? ($pallet['secondary_contact_email'] ?? '') : ($pallet['primary_contact_email'] ?? ''));
        $phone    = (string)($role === 'secondary' ? ($pallet['secondary_contact_phone'] ?? '') : ($pallet['primary_contact_phone'] ?? ''));
        $whatsapp = (string)($role === 'secondary' ? ($pallet['secondary_contact_whatsapp'] ?? '') : ($pallet['primary_contact_whatsapp'] ?? ''));

        $status = 'prospective';
        $today  = date('Y-m-d');
        if (!empty($pallet['period_until'])) {
            $status = ($pallet['period_until'] >= $today) ? 'active' : 'past';
        } elseif (!empty($pallet['period_from'])) {
            $status = 'active';
        }

        return $this->upsert_customer(array(
            'display_name' => $name,
            'email'        => $email,
            'phone'        => $phone,
            'whatsapp'     => $whatsapp,
            'status'       => $status,
            'entity_type'  => 'pallet',
            'entity_id'    => (int)($pallet['id'] ?? 0),
            'contact_role' => $role
        ));
    }

    /* ---------- Core Upsert & CRUD ---------- */

    public function upsert_customer($c) {
        global $wpdb;
        $table = $wpdb->prefix . 'storage_customers';

        $name       = sanitize_text_field($c['display_name'] ?? '');
        $email      = sanitize_email($c['email'] ?? '');
        $phone      = sanitize_text_field($c['phone'] ?? '');
        $whatsapp   = sanitize_text_field($c['whatsapp'] ?? '');
        $address    = sanitize_textarea_field($c['address'] ?? '');
        $notes      = sanitize_textarea_field($c['notes'] ?? '');
        $status     = sanitize_text_field($c['status'] ?? 'prospective');
        $etype      = sanitize_text_field($c['entity_type'] ?? '');
        $eid        = (int)($c['entity_id'] ?? 0);
        $role       = sanitize_text_field($c['contact_role'] ?? 'primary');

        $email_norm = $this->normalize_email($email);
        $phone_norm = $this->normalize_phone($phone);

        // Dedup: prefer email, else phone
        $existing = null;
        if ($email_norm) {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE email_norm = %s LIMIT 1", $email_norm), ARRAY_A);
        }
        if (!$existing && $phone_norm) {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE phone_norm = %s LIMIT 1", $phone_norm), ARRAY_A);
        }

        $row = array(
            'display_name' => $name ?: ($existing['display_name'] ?? ''),
            'email'        => $email ?: ($existing['email'] ?? ''),
            'email_norm'   => $email_norm ?: ($existing['email_norm'] ?? ''),
            'phone'        => $phone ?: ($existing['phone'] ?? ''),
            'phone_norm'   => $phone_norm ?: ($existing['phone_norm'] ?? ''),
            'whatsapp'     => $whatsapp ?: ($existing['whatsapp'] ?? ''),
            'address'      => $address ?: ($existing['address'] ?? ''),
            'notes'        => $notes ?: ($existing['notes'] ?? ''),
            'status'       => $status ?: ($existing['status'] ?? 'prospective'),
            'entity_type'  => $etype,
            'entity_id'    => $eid,
            'contact_role' => $role,
            'is_active'    => 1
        );
        $fmt = array('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%d');

        if ($existing) {
            return $wpdb->update($table, $row, array('id' => (int)$existing['id']), $fmt, array('%d'));
        } else {
            return $wpdb->insert($table, $row, $fmt);
        }
    }

    public function get_customers($args = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'storage_customers';

        $where = array();
        $params = array();

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $params[] = $args['status'];
        }
        if (!empty($args['search'])) {
            $s = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(display_name LIKE %s OR email LIKE %s OR phone LIKE %s)';
            array_push($params, $s, $s, $s);
        }

        $sql = "SELECT * FROM $table";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY display_name ASC LIMIT 500';

        return $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A)
                       : $wpdb->get_results($sql, ARRAY_A);
    }

    public function save_customer($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'storage_customers';

        $id = (int)($data['id'] ?? 0);
        $row = array(
            'display_name' => sanitize_text_field($data['display_name'] ?? ''),
            'email'        => sanitize_email($data['email'] ?? ''),
            'email_norm'   => $this->normalize_email($data['email'] ?? ''),
            'phone'        => sanitize_text_field($data['phone'] ?? ''),
            'phone_norm'   => $this->normalize_phone($data['phone'] ?? ''),
            'whatsapp'     => sanitize_text_field($data['whatsapp'] ?? ''),
            'address'      => sanitize_textarea_field($data['address'] ?? ''),
            'notes'        => sanitize_textarea_field($data['notes'] ?? ''),
            'status'       => sanitize_text_field($data['status'] ?? 'prospective'),
            'is_active'    => !empty($data['is_active']) ? 1 : 0
        );
        $fmt = array('%s','%s','%s','%s','%s','%s','%s','%s','%s','%d');

        if ($id > 0) {
            return $wpdb->update($table, $row, array('id' => $id), $fmt, array('%d'));
        } else {
            return $wpdb->insert($table, $row, $fmt);
        }
    }

    public function delete_customer($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'storage_customers';
        return $wpdb->delete($table, array('id' => (int)$id), array('%d'));
    }

    /* ---------- Bulk sync ---------- */

    public function sync_all($units_db, $pallets_db) {
        // Units
        if ($units_db && method_exists($units_db, 'get_units')) {
            $units = $units_db->get_units('all');
            foreach ($units as $u) {
                $this->upsert_from_unit($u, 'primary');
                if (!empty($u['secondary_contact_name']) || !empty($u['secondary_contact_email']) || !empty($u['secondary_contact_phone'])) {
                    $this->upsert_from_unit($u, 'secondary');
                }
            }
        }
        // Pallets
        if ($pallets_db && method_exists($pallets_db, 'get_pallets')) {
            $pallets = $pallets_db->get_pallets('all');
            foreach ($pallets as $p) {
                $this->upsert_from_pallet($p, 'primary');
                if (!empty($p['secondary_contact_name']) || !empty($p['secondary_contact_email']) || !empty($p['secondary_contact_phone'])) {
                    $this->upsert_from_pallet($p, 'secondary');
                }
            }
        }
        return true;
    }
}
