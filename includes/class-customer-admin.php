<?php
if ( ! defined('ABSPATH') ) exit;

class SUM_Customer_Admin {

    /** @var SUM_Customer_Database */
    protected $db;

    public function __construct($customer_db) {
        $this->db = $customer_db;
    }

    public function init() {
        add_action('admin_menu', array($this, 'register_menu'));
    }

    public function register_menu() {
        add_menu_page(
            'Customers', 'Customers', 'manage_options', 'sum-customers',
            array($this, 'render_customers_list'),
            'dashicons-groups', 55
        );
        add_submenu_page(
            'sum-customers', 'All Customers', 'All Customers', 'manage_options', 'sum-customers',
            array($this, 'render_customers_list')
        );
        add_submenu_page(
            'sum-customers', 'Customer Detail', 'Customer Detail', 'manage_options', 'sum-customer-detail',
            array($this, 'render_customer_detail')
        );
    }

    public function render_customers_list() {
        if ( ! current_user_can('manage_options') ) return;
        $q = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $rows = $q ? $this->db->search_customers($q, 200) : $this->db->search_customers('', 200);
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Customers</h1>
            <form method="get" style="margin-top:10px;">
                <input type="hidden" name="page" value="sum-customers">
                <input type="search" name="s" value="<?php echo esc_attr($q); ?>" placeholder="Search by name, email, phone" class="regular-text">
                <button class="button">Search</button>
            </form>
            <table class="widefat striped" style="margin-top:15px;">
                <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Active</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo esc_html($r['name']); ?></td>
                        <td><?php echo esc_html($r['email']); ?></td>
                        <td><?php echo esc_html($r['phone']); ?></td>
                        <td><?php echo $r['is_active'] ? 'Yes' : 'No'; ?></td>
                        <td><a class="button" href="<?php echo esc_url( admin_url('admin.php?page=sum-customer-detail&customer_id=' . intval($r['id'])) ); ?>">View</a></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5">No customers found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_customer_detail() {
        if ( ! current_user_can('manage_options') ) return;
        $id = isset($_GET['customer_id']) ? absint($_GET['customer_id']) : 0;
        if (!$id) { echo '<div class="notice notice-error"><p>Missing customer_id.</p></div>'; return; }

        $c = $this->db->get_customer($id);
        if (!$c) { echo '<div class="notice notice-error"><p>Customer not found.</p></div>'; return; }

        $active    = $this->db->list_assignments($id, true);
        $history   = $this->db->list_assignments($id, false);
        $invoices  = $this->db->list_invoices($id);
        $payments  = $this->db->list_payments($id);
        $balance   = $this->db->get_open_balance($id);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($c['name']); ?></h1>
            <p><strong>Email:</strong> <?php echo esc_html($c['email']); ?> &nbsp;
               <strong>Phone:</strong> <?php echo esc_html($c['phone']); ?> &nbsp;
               <strong>VAT:</strong> <?php echo esc_html($c['vat_number']); ?></p>
            <p><strong>Open Balance:</strong> €<?php echo number_format($balance, 2); ?></p>

            <h2>Active Rentals</h2>
            <?php $this->table_assignments($active); ?>

            <h2>History (Units & Pallets)</h2>
            <?php $this->table_assignments($history); ?>

            <h2>Invoices</h2>
            <?php $this->table_invoices($invoices); ?>

            <h2>Payments</h2>
            <?php $this->table_payments($payments); ?>
        </div>
        <?php
    }

    protected function table_assignments($rows) {
        ?>
        <table class="widefat striped">
            <thead><tr>
                <th>Type</th><th>Name</th><th>Period</th><th>Monthly</th><th>Status</th>
            </tr></thead>
            <tbody>
            <?php if ($rows): foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo esc_html(ucfirst($r['entity_type'])); ?></td>
                    <td><?php echo esc_html($r['entity_name']); ?></td>
                    <td><?php echo esc_html($r['period_from']); ?> → <?php echo esc_html($r['period_until']); ?></td>
                    <td>€<?php echo number_format($r['monthly_price'], 2); ?></td>
                    <td><?php echo esc_html($r['status']); ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="5">No records.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    protected function table_invoices($rows) {
        ?>
        <table class="widefat striped">
            <thead><tr>
                <th>No.</th><th>Type</th><th>Name</th><th>Period</th><th>Status</th><th>Total</th>
            </tr></thead>
            <tbody>
            <?php if ($rows): foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo esc_html($r['invoice_no']); ?></td>
                    <td><?php echo esc_html(ucfirst($r['entity_type'])); ?></td>
                    <td><?php echo esc_html($r['entity_name']); ?></td>
                    <td><?php echo esc_html($r['period_from']); ?> → <?php echo esc_html($r['period_until']); ?></td>
                    <td><?php echo esc_html($r['status']); ?></td>
                    <td>€<?php echo number_format($r['total'], 2); ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="6">No invoices.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    protected function table_payments($rows) {
        ?>
        <table class="widefat striped">
            <thead><tr>
                <th>Date</th><th>Type</th><th>Name</th><th>Method</th><th>Amount</th>
            </tr></thead>
            <tbody>
            <?php if ($rows): foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo esc_html($r['paid_at']); ?></td>
                    <td><?php echo esc_html(ucfirst($r['entity_type'])); ?></td>
                    <td><?php echo esc_html($r['entity_name']); ?></td>
                    <td><?php echo esc_html($r['method']); ?></td>
                    <td>€<?php echo number_format($r['amount'], 2); ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="5">No payments.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
}
