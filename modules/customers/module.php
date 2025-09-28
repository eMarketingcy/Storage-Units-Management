<?php
/**
 * Customers module (CSSC)
 */
if (!defined('ABSPATH')) exit;

// The SUM_CUSTOMERS_DIR constant is assumed to be defined by the main plugin file
if (!defined('SUM_CUSTOMERS_DIR')) {
    define('SUM_CUSTOMERS_DIR', __DIR__);
}

// Core Includes
require_once SUM_CUSTOMERS_DIR . '/includes/class-customers-database.php';
// Handler is included AFTER the main class definition (at the end) to ensure load order for get_db()

final class SUM_Customers_Module_CSSC {
    
    // Hold the database instance
    private static $db;
    
    public static function boot() {
        // Database setup and upgrade on plugins_loaded
        add_action('plugins_loaded', [__CLASS__, 'setup_database']);
        
        // --- Constant Definitions ---
        // Derive SUM_PLUGIN_PATH (one directory up from 'modules/customers')
        if (!defined('SUM_PLUGIN_PATH')) {
            define('SUM_PLUGIN_PATH', trailingslashit(dirname(SUM_CUSTOMERS_DIR)));
        }
        if (!defined('SUM_DOMPDF_AUTO')) {
            define('SUM_DOMPDF_AUTO', SUM_PLUGIN_PATH . 'lib/dompdf/vendor/autoload.inc.php');
        }
        if (!defined('K_PATH_MAIN')) {
            define('K_PATH_MAIN', SUM_PLUGIN_PATH . 'lib/tcpdf/');
        }
        // --------------------------

        // REST API Registration (Needed for stable invoice generation)
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']); 

        if (is_admin()) {
            add_action('admin_menu', [__CLASS__, 'menu_cssc']);
            
            // Register Admin AJAX actions
            add_action('wp_ajax_sum_customers_cssc_get',  [__CLASS__, 'ajax_get_cssc']);
            add_action('wp_ajax_sum_customers_cssc_sync', [__CLASS__, 'ajax_sync_cssc']);
            add_action('wp_ajax_sum_customers_get_available_units', [__CLASS__, 'ajax_get_available_units']);
            add_action('wp_ajax_sum_customers_get_available_pallets', [__CLASS__, 'ajax_get_available_pallets']);
            add_action('wp_ajax_sum_customers_assign_unit', [__CLASS__, 'ajax_assign_unit']);
            add_action('wp_ajax_sum_customers_assign_pallet', [__CLASS__, 'ajax_assign_pallet']);
            
            // Enqueue admin styles and register settings
            add_action('admin_enqueue_scripts', [__CLASS__, 'admin_enqueue_scripts']);
            add_action('admin_init', [__CLASS__, 'register_settings_cssc']);
        }

        // Frontend shortcode
        add_shortcode('sum_customers_frontend_cssc', [__CLASS__, 'shortcode_frontend_cssc']);
        
        // Frontend AJAX (Data retrieval actions) � LOGGED-IN users
add_action('wp_ajax_sum_customers_frontend_get_cssc', [__CLASS__, 'ajax_frontend_get_cssc']);
add_action('wp_ajax_sum_customers_frontend_get_single_cssc', [__CLASS__, 'ajax_frontend_get_single_cssc']);
add_action('wp_ajax_sum_customers_frontend_generate_invoice', ['SUM_Customer_Invoice_Handler_CSSC', 'ajax_generate_invoice']);

// Keep your existing nopriv hooks for visitors, if needed:
add_action('wp_ajax_nopriv_sum_customers_frontend_get_cssc', [__CLASS__, 'ajax_frontend_get_cssc']);
add_action('wp_ajax_nopriv_sum_customers_frontend_get_single_cssc', [__CLASS__, 'ajax_frontend_get_single_cssc']);
add_action('wp_ajax_nopriv_sum_customers_frontend_generate_invoice', ['SUM_Customer_Invoice_Handler_CSSC', 'ajax_generate_invoice']);

        
        // Enqueue frontend scripts
        add_action('wp_enqueue_scripts', [__CLASS__, 'frontend_enqueue_scripts']);
    }

    public static function get_db() {
        if (!self::$db) {
            self::$db = new SUM_Customers_Database_CSSC();
        }
        return self::$db;
    }
    
    // NEW HELPER: Get the general database/settings object
    public static function get_main_db() {
        if (class_exists('SUM_Database')) {
            return new SUM_Database(); // Assuming this holds get_setting()
        }
        return self::get_db(); // Fallback to customer DB if main DB is missing
    }
    
    public static function register_rest_routes() {
    register_rest_route('sum/v1', '/invoice', [
        'methods'             => 'POST',
        'callback'            => ['SUM_Customer_Invoice_Handler_CSSC', 'rest_generate_invoice'],
        'permission_callback' => function($request){
            // adjust if non-admin staff should use this:
            return current_user_can('manage_options');
        },
        'args' => [
            'customer_id' => ['required' => true, 'type' => 'integer'],
        ],
    ]);
}

    public static function setup_database() {
        $db = self::get_db();
        // 1. Ensure tables are created (dbDelta handles both create and alter)
        $db->create_tables(); 
        // 2. Ensure all columns and indexes from the latest schema are present
        $db->maybe_upgrade_cssc();
    }
    
    // Renamed to match the suggested structure/parent page (assuming 'storage-units' is the parent slug)
    public static function menu_cssc() {
        add_menu_page( // Changed to add_menu_page to retain the top-level menu item
            'Customers', 'Customers', 'manage_options',
            'sum_customers_cssc', [__CLASS__, 'render_page_cssc'],
            'dashicons-groups', 58
        );
        
        // If you intended to make it a submenu of 'storage-units', use this instead:
        /*
        add_submenu_page(
            'storage-units', // Replace with your actual parent slug if needed
            'Customers', 'Customers', 'manage_options',
            'sum_customers_cssc', [__CLASS__, 'render_page_cssc']
        );
        */
    }
    
    public static function register_settings_cssc() {
        // Dummy setting registration as suggested (adjust if real settings are needed)
        register_setting('sum_customers_cssc', 'sum_customers_enabled_cssc');
    }

    public static function admin_enqueue_scripts($hook) {
        if (strpos($hook, 'sum_customers_cssc') !== false) {
            wp_enqueue_style('sum-customers-admin', plugin_dir_url(__FILE__) . 'assets/customers-admin.css', [], '1.0.0');
        }
    }
    

// Update enqueue_scripts to localize REST parameters
    public static function frontend_enqueue_scripts() {
        global $post;
        if (is_page() && $post && has_shortcode($post->post_content, 'sum_customers_frontend_cssc')) {
            wp_enqueue_style('sum-customers-frontend', plugin_dir_url(__FILE__) . 'assets/customers-frontend.css', [], '1.0.0');
            wp_enqueue_script('sum-customers-frontend', plugin_dir_url(__FILE__) . 'assets/customers-frontend.js', ['jquery'], '1.0.0', true);
            
            // FIX: Localize REST parameters for frontend
            wp_localize_script('sum-customers-frontend', 'sum_customers_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'), // Keep for legacy AJAX
                'nonce' => wp_create_nonce('sum_customers_frontend_nonce'), // Keep for legacy AJAX
                'rest_url'      => esc_url_raw( rest_url('sum/v1/invoice') ),
    'wp_rest_nonce' => wp_create_nonce('wp_rest'),
            ]);
        }
    }
    public static function render_page_cssc() {
        // This is your admin UI template. The JS inside needs to be updated.
        $nonce = wp_create_nonce('sum_customers_nonce'); // Use a generic nonce name for the admin area
        ?>
        <div class="wrap">
            <h1>
                <span class="dashicons dashicons-groups" style="margin-right: 10px;"></span>
                Customers Management
            </h1>
            
            <div class="sum-customers-header">
                <div class="sum-customers-actions">
                    <button class="button button-primary" id="cssc-sync">
                        <span class="dashicons dashicons-update"></span>
                        Sync from Storage Data
                    </button>
                    <input type="text" id="cssc-search" placeholder="Search customers..." style="margin-left: 10px; width: 250px;">
                    <button class="button" id="cssc-search-btn">Search</button>
                </div>
                <div id="cssc-status" class="sum-status-message"></div>
            </div>

            <div class="sum-customers-stats" id="cssc-stats" style="display: none;">
                <div class="sum-stat-card">
                    <h3>Total Customers</h3>
                    <span class="sum-stat-number" id="total-customers">0</span>
                </div>
                <div class="sum-stat-card">
                    <h3>Active Rentals</h3>
                    <span class="sum-stat-number" id="active-rentals">0</span>
                </div>
                <div class="sum-stat-card">
                    <h3>Past Customers</h3>
                    <span class="sum-stat-number" id="past-customers">0</span>
                </div>
            </div>

            <div class="sum-customers-table-container">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th class="manage-column">Customer Name</th>
                            <th class="manage-column">Contact Info</th>
                            <th class="manage-column">Current Rentals</th>
                            <th class="manage-column">Past Rentals</th>
                            <th class="manage-column">Status</th>
                        </tr>
                    </thead>
                    <tbody id="cssc-tbody">
                        <tr id="cssc-loading">
                            <td colspan="5" class="sum-loading">
                                <span class="spinner is-active"></span>
                                Loading customers...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div id="cssc-empty" class="sum-empty-state" style="display: none;">
                <div class="sum-empty-icon">
                    <span class="dashicons dashicons-groups"></span>
                </div>
                <h3>No Customers Found</h3>
                <p>Click "Sync from Storage Data" to import customer data from your storage units and pallets.</p>
            </div>
        </div>

        <script>
        (function($){
            // Use the updated nonce and AJAX actions
            const nonce = '<?php echo esc_js($nonce); ?>';
            const ajaxGetAction = 'sum_customers_cssc_get';
            const ajaxSyncAction = 'sum_customers_cssc_sync';
            let currentCustomers = [];

            function updateStats(customers) {
                const totalCustomers = customers.length;
                let activeRentals = 0;
                let pastCustomers = 0;
                
                // The status column now correctly reflects the customer status
                customers.forEach(c => {
                    if (c.status === 'active') activeRentals++;
                    if (c.status === 'past') pastCustomers++;
                });

                $('#total-customers').text(totalCustomers);
                $('#active-rentals').text(activeRentals);
                $('#past-customers').text(pastCustomers);
                $('#cssc-stats').show();
            }

            function renderRows(customers) {
                const $tbody = $('#cssc-tbody');
                $tbody.empty();
                
                if (!customers || customers.length === 0) {
                    $('#cssc-empty').show();
                    $('#cssc-stats').hide();
                    return;
                }
                
                $('#cssc-empty').hide();
                updateStats(customers);

                customers.forEach(customer => {
                    const contactInfo = [];
                    // Note: In the new DB structure, phone/email might be normalized or empty, 
                    // so we display the best available data.
                    if (customer.email) contactInfo.push(`📧 ${escapeHtml(customer.email)}`);
                    if (customer.phone) contactInfo.push(`📞 ${escapeHtml(customer.phone)}`);
                    // If whatsapp is different and available, show it
                    if (customer.whatsapp && customer.whatsapp !== customer.phone) {
                         contactInfo.push(`🟢 ${escapeHtml(customer.whatsapp)} (WhatsApp)`);
                    }
                    
                    // Current/Past rentals are now comma-separated strings from the DB, 
                    // but get_customers_cssc converts them to arrays for the UI.
                    
                    const renderList = (rentals) => {
                      if (!rentals || rentals.length === 0) return '';
                      const typeClass = rentals[0].toLowerCase().includes('unit') ? 'sum-rental-unit' : 'sum-rental-pallet';
                      return rentals.map(item => `<span class="sum-rental-item ${typeClass}">${escapeHtml(item)}</span>`).join('');
                    };

                    const currentRentals = (customer.current_units || []).concat(customer.current_pallets || []);
                    const pastRentals    = (customer.past_units || []).concat(customer.past_pallets || []);
                    
                    const hasActive = currentRentals.length > 0;
                    const status = customer.status === 'active' ? 
                        '<span class="sum-status-active">Active</span>' : 
                        '<span class="sum-status-past">Past Customer</span>';

                    $tbody.append(`
                        <tr>
                            <td><strong>${escapeHtml(customer.name || 'N/A')}</strong></td>
                            <td class="sum-contact-info">${contactInfo.join('<br>')}</td>
                            <td class="sum-rental-list">${renderList(customer.current_units)} ${renderList(customer.current_pallets)}</td>
                            <td class="sum-rental-list">${renderList(customer.past_units)} ${renderList(customer.past_pallets)}</td>
                            <td>${status}</td>
                        </tr>
                    `);
                });
            }

            function showStatus(message, type = 'info') {
                const $status = $('#cssc-status');
                $status.removeClass('sum-status-success sum-status-error')
                       .addClass(`sum-status-${type}`)
                       .text(message);
                
                if (type !== 'info') {
                    setTimeout(() => $status.text(''), 5000);
                }
            }

            function escapeHtml(str) {
                return String(str).replace(/[&<>"']/g, m => ({
                    '&': '&amp;', '<': '&lt;', '>': '&gt;', 
                    '"': '&quot;', "'": '&#039;'
                }[m]));
            }

            function loadCustomers(search = '') {
                // Show loading spinner
                $('#cssc-tbody').html('<tr id="cssc-loading"><td colspan="5" class="sum-loading"><span class="spinner is-active"></span>Loading customers...</td></tr>');
                
                $.post(ajaxurl, {
                    action: ajaxGetAction, // Use the new action name
                    nonce: nonce,
                    search: search
                }, function(resp) {
                    if (resp && resp.success) {
                        currentCustomers = resp.data;
                        renderRows(currentCustomers);
                        showStatus(`Loaded ${currentCustomers.length} customers`, 'success');
                    } else {
                        showStatus('Failed to load customers', 'error');
                        $('#cssc-empty').show();
                    }
                }).fail(() => {
                    showStatus('Failed to load customers', 'error');
                    $('#cssc-empty').show();
                });
            }

            // Event handlers
            $('#cssc-sync').on('click', function() {
                const $btn = $(this);
                $btn.prop('disabled', true).find('.dashicons').addClass('spin');
                showStatus('Syncing customer data...', 'info');
                
                $.post(ajaxurl, {
                    action: ajaxSyncAction, // Use the new action name
                    nonce: nonce
                }, function(resp) {
                    if (resp && resp.success) {
                        showStatus(`Sync completed: ${resp.data.inserted} imported, ${resp.data.updated} updated.`, 'success');
                        loadCustomers();
                    } else {
                        showStatus('Sync failed', 'error');
                    }
                }).fail(() => {
                    showStatus('Sync failed', 'error');
                }).always(() => {
                    $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
                });
            });

            $('#cssc-search-btn, #cssc-search').on('click keypress', function(e) {
                if (e.type === 'click' || e.which === 13) {
                    e.preventDefault();
                    const search = $('#cssc-search').val().trim();
                    loadCustomers(search);
                }
            });

            // Load initial data
            loadCustomers();

        })(jQuery);
        </script>

        <?php
    }

    // ----- AJAX Handlers -----

    public static function ajax_get_cssc() {
        // Use check_ajax_referer for consistency and simplicity
        check_ajax_referer('sum_customers_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('no_perms');

        $db = self::get_db();
        // The new get_customers_cssc can also handle the 'status' filter now, though not used here
        $rows = $db->get_customers_cssc([
            'search' => sanitize_text_field($_POST['search'] ?? ''),
            'limit'  => 500,
            'offset' => 0,
        ]);
        wp_send_json_success($rows);
    }

    public static function ajax_sync_cssc() {
        // Use check_ajax_referer for consistency and simplicity
        check_ajax_referer('sum_customers_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('no_perms');

        // This is the CRUCIAL part: Fetch all units and pallets from their respective databases
        // You need to ensure SUM_Database and SUM_Pallet_Database classes exist and 
        // have the get_units('all') and get_pallets('all') methods, or adjust the calls.
        
        $units = [];
        if (class_exists('SUM_Database')) {
            $units = (new SUM_Database())->get_units('all');
        } else {
             // Fallback to direct query if you can't instantiate the class, but this is less ideal
             global $wpdb;
             $tblUnits   = $wpdb->prefix . 'storage_units';
             $units = $wpdb->get_results("SELECT * FROM {$tblUnits}", ARRAY_A)   ?: [];
        }
        
        $pallets = [];
        if (class_exists('SUM_Pallet_Database')) {
            $pallets = (new SUM_Pallet_Database())->get_pallets('all');
        } else {
             // Fallback to direct query
             global $wpdb;
             $tblPallets = $wpdb->prefix . 'storage_pallets';
             $pallets = $wpdb->get_results("SELECT * FROM {$tblPallets}", ARRAY_A) ?: [];
        }

        $db = self::get_db();
        // Call the new sync method which accepts both arrays
        $stats = $db->sync_from_sources($units, $pallets);
        
        wp_send_json_success($stats);
    }

public static function ajax_frontend_get_cssc() {
        // Use check_ajax_referer for consistency and simplicity.
        // The check_ajax_referer function WILL terminate the script with a 400 error 
        // if the nonce is invalid or missing, which is the exact behavior you are seeing.
        check_ajax_referer('sum_customers_frontend_nonce', 'nonce');
        
        // Ensure the user is logged in, otherwise security is bypassed.
        if (!is_user_logged_in()) {
            wp_send_json_error('no_perms', 403);
            return;
        }

        // FIX: Directly instantiate the DB class needed to guarantee the object is not null.
        $db = new SUM_Customers_Database_CSSC();
        
        $rows = $db->get_customers_cssc([
            'search' => sanitize_text_field($_POST['search'] ?? ''),
            'limit'  => 100,
            'offset' => 0,
        ]);
        
        // Remove sensitive data for frontend
        foreach ($rows as &$row) {
            // Partially hide email for privacy
            $row['email_display'] = preg_replace('/(.{2}).*@/', '$1***@', $row['email']);
            // Partially hide phone for privacy
            $row['phone_display'] = preg_replace('/(\d{3})(\d+)(\d{3})/', '$1***$3', $row['phone']);
            // Remove raw data fields
            unset($row['email']);
            unset($row['phone']);
            unset($row['whatsapp']);
            unset($row['fingerprint']);
            unset($row['sources']);
        }
        
        wp_send_json_success($rows);
    }    
public static function ajax_frontend_get_single_cssc() {
        // 1. Security Check: Verify nonce and user login
        if (!is_user_logged_in() || !wp_verify_nonce($_POST['nonce'] ?? '', 'sum_customers_frontend_nonce')) {
            wp_send_json_error('auth');
            return;
        }

        // 2. Input Validation
        $customer_id = intval($_POST['customer_id'] ?? 0);
        if ($customer_id <= 0) {
            wp_send_json_error('invalid_id');
            return;
        }
        
        $db = new SUM_Customers_Database_CSSC();

        // 3. Fetch Customer Data
        $customer = $db->get_customer_by_id_cssc($customer_id);

        if (!$customer) {
            wp_send_json_error('not_found');
            return;
        }
        
        // FIX: Calculate unpaid invoices (This is where the original crash occurred)
        $customer['unpaid_invoices'] = $db->get_unpaid_invoices_for_customer($customer);
        
        // 4. PREPARE DATA FOR MODAL
        $customer['full_email'] = $customer['email'] ?? '';
        $customer['full_phone'] = $customer['phone'] ?? '';
        
        // Remove fields used for internal lookups
        unset($customer['fingerprint']);
        unset($customer['sources']);
        unset($customer['email']);
        unset($customer['phone']);


        // 5. Send Success Response
        wp_send_json_success($customer);
    }
    
    // Keep asset assignment/availability AJAX as they were
    public static function ajax_get_available_units() {
        if (!current_user_can('manage_options')) wp_send_json_error('cap');
        check_ajax_referer('sum_customers_nonce', 'nonce');

        $db = self::get_db();
        $units = $db->get_available_units();
        wp_send_json_success($units);
    }

    public static function ajax_get_available_pallets() {
        if (!current_user_can('manage_options')) wp_send_json_error('cap');
        check_ajax_referer('sum_customers_nonce', 'nonce');

        $db = self::get_db();
        $pallets = $db->get_available_pallets();
        wp_send_json_success($pallets);
    }

    public static function ajax_assign_unit() {
        if (!current_user_can('manage_options')) wp_send_json_error('cap');
        check_ajax_referer('sum_customers_nonce', 'nonce');

        $customer_id = intval($_POST['customer_id']);
        $unit_id = intval($_POST['unit_id']);

        $db = self::get_db();
        $result = $db->assign_unit_to_customer($customer_id, $unit_id);

        if ($result) {
            wp_send_json_success('Unit assigned successfully');
        } else {
            wp_send_json_error('Failed to assign unit');
        }
    }

    public static function ajax_assign_pallet() {
        if (!current_user_can('manage_options')) wp_send_json_error('cap');
        check_ajax_referer('sum_customers_nonce', 'nonce');

        $customer_id = intval($_POST['customer_id']);
        $pallet_id = intval($_POST['pallet_id']);

        $db = self::get_db();
        $result = $db->assign_pallet_to_customer($customer_id, $pallet_id);

        if ($result) {
            wp_send_json_success('Pallet assigned successfully');
        } else {
            wp_send_json_error('Failed to assign pallet');
        }
    }

    // Keep shortcode handler as it was
    public static function shortcode_frontend_cssc($atts) {
        if (!is_user_logged_in()) {
            return '<div class="sum-login-required">Please log in to view customer information.</div>';
        }
        
        ob_start(); ?>
        <div id="sum-customers-frontend">
            <div class="sum-frontend-header">
                <h2>
                    <span class="dashicons dashicons-groups"></span>
                    Customer Directory
                </h2>
                <div class="sum-frontend-search">
                    <input type="text" id="frontend-search" placeholder="Search customers...">
                    <button class="button" id="frontend-search-btn">Search</button>
                </div>
            </div>
<!-- Navigation Links -->
        <div class="sum-frontend-navigation">
            <div class="sum-frontend-nav-item sum-frontend-nav-active">
                <span class="sum-frontend-nav-icon">👪</span>
                <span>Custommers</span>
            </div>
            
            <a href="<?php echo home_url('/storage-units-manager/'); ?>" class="sum-frontend-nav-item">
                <span class="sum-frontend-nav-icon">📦</span>
                <span>Storage Units</span>
            </a>
            
            <a href="<?php echo home_url('/storage-pallets-manager/'); ?>" class="sum-frontend-nav-item">
                <span class="sum-frontend-nav-icon">�</span>
                <span>Pallet Storage</span>
            </a>
        </div>
            <div class="sum-frontend-filters">
                <button class="filter-btn active" data-filter="all">All Customers</button>
                <button class="filter-btn" data-filter="active">Active Rentals</button>
                <button class="filter-btn" data-filter="past">Past Customers</button>
                <button class="filter-btn" data-filter="unpaid">Unpaid Invoices</button>
                <div class="sum-view-options">
            <button class="sum-view-toggle-btn active" data-view="grid">
                <span class="dashicons dashicons-grid-view"></span>
            </button>
            <button class="sum-view-toggle-btn" data-view="list">
                <span class="dashicons dashicons-list-view"></span>
            </button>
        </div>
            </div>

            <div id="frontend-loading" class="sum-loading">
                <span class="spinner is-active"></span>
                Loading customers...
            </div>

            <div id="frontend-customers" class="sum-customers-grid"></div>

            <div id="frontend-empty" class="sum-empty-state" style="display: none;">
                <div class="sum-empty-icon">
                    <span class="dashicons dashicons-groups"></span>
                </div>
                <h3>No Customers Found</h3>
                <p>No customer records match your current filter.</p>
            </div>
        </div>
        <?php 
        // Start of Modal HTML Structure
        ?>
        <div id="sum-customer-modal-overlay" class="sum-modal-overlay">
            <div id="sum-customer-details-modal" class="sum-modal-content">
                <div class="sum-modal-header">
                    <h3 id="modal-customer-name">Customer Details</h3>
                    <button class="sum-modal-close" id="modal-close-btn">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
                <div class="sum-modal-body" id="modal-details-body">
                    <div class="sum-loading-modal">
                        <span class="spinner is-active"></span> Loading data...
                    </div>
                    </div>
                <div class="sum-modal-footer">
                    <button class="button button-secondary sum-modal-close">Close</button>
                </div>
            </div>
        </div>
        <?php
        // End of Modal HTML Structure
        return ob_get_clean();
    }
}

// 2. Load Invoice Handler AFTER the main class definition (Fixing load order)
require_once SUM_CUSTOMERS_DIR . '/includes/class-customer-invoice-handler.php';

SUM_Customers_Module_CSSC::boot();