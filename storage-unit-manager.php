<?php
/**
 * Plugin Name: Storage Unit Manager
 * Plugin URI: https://selfstorage.cy
 * Description: Comprehensive storage unit management system with frontend access, email automation, bulk operations, and advanced filtering.
 * Version: 3.1.2
 * Author: eMarketing Cyprus
 * Author URI: https://selfstorage.cy
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: storage-unit-manager
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SUM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SUM_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SUM_VERSION', '3.1.2');

// --- START: PDF Dependency Management Functions ---

// Define constants once (Assuming SUM_PLUGIN_PATH is defined elsewhere, like the main plugin file)
if (defined('SUM_PLUGIN_PATH')) {
    define('SUM_LIB_PATH', trailingslashit(SUM_PLUGIN_PATH . 'lib'));
    define('SUM_DOMPDF_DIR', trailingslashit(SUM_LIB_PATH . 'dompdf'));
}

/**
 * Try to load Dompdf if present on disk.
 * @return bool true when Dompdf is ready to use
 */
function sum_load_dompdf() {
    if (!defined('SUM_DOMPDF_DIR')) {
        return false;
    }
    if (class_exists('\Dompdf\Dompdf')) {
        return true;
    }
    
    // Attempt to find the autoloader from either bundled or composer paths
    $autoloads = array(
        SUM_DOMPDF_DIR . 'autoload.inc.php',          // Standard Dompdf zip autoloader
        SUM_DOMPDF_DIR . 'vendor/autoload.php',       // Composer path if user installed via Composer
    );
    
    foreach ($autoloads as $file) {
        if (is_readable($file)) {
            require_once $file;
            // Check for the class after inclusion
            return class_exists('\Dompdf\Dompdf');
        }
    }
    return false;
}

/**
 * Ensure Dompdf exists; if not, download and unzip it.
 * Uses WP core downloading & unzip APIs.
 */
function sum_ensure_dompdf_installed() {
    // If already loaded, done
    if (function_exists('sum_load_dompdf') && sum_load_dompdf()) {
        return true;
    }

    // WP FS tools
    if ( ! function_exists('download_url') ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if ( ! function_exists('unzip_file') ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }
    if ( ! function_exists('WP_Filesystem') ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    WP_Filesystem();
    global $wp_filesystem;

    // AJAX can’t show the FTP creds UI. If method isn’t 'direct', bail with a useful error.
    if ( ! $wp_filesystem || ( isset($wp_filesystem->method) && $wp_filesystem->method !== 'direct' ) ) {
        return new WP_Error(
            'fs_method',
            'WordPress cannot write files directly (FS_METHOD is not "direct"). ' .
            'Either set define("FS_METHOD","direct") in wp-config.php (if safe), or create ' .
            'the folder '/**/ . trailingslashit(SUM_LIB_PATH) . 'dompdf' . ' manually by uploading Dompdf.'
        );
    }

    // Ensure lib/ exists
    if ( ! is_dir(SUM_LIB_PATH) && ! wp_mkdir_p(SUM_LIB_PATH) ) {
        return new WP_Error('mkdir', 'Cannot create library folder: ' . SUM_LIB_PATH);
    }

    // Download dompdf zip
    $zip_url = 'https://github.com/dompdf/dompdf/releases/download/v2.0.4/dompdf_2_0_4.zip';
    $tmp = download_url($zip_url, 300);
    if (is_wp_error($tmp)) {
        return new WP_Error('download', 'Download failed: ' . $tmp->get_error_message());
    }

    // Unzip into lib/
    $unzipped = unzip_file($tmp, SUM_LIB_PATH);
    @unlink($tmp);
    if (is_wp_error($unzipped)) {
        return new WP_Error('unzip', 'Unzip failed: ' . $unzipped->get_error_message());
    }

    // Find the extracted folder (GitHub zips sometimes use dompdf-2.0.4)
    $candidates = array('dompdf', 'dompdf-2.0.4', 'dompdf_2_0_4');
    $found = '';
    foreach ($candidates as $c) {
        if (is_dir(SUM_LIB_PATH . $c)) { $found = SUM_LIB_PATH . $c; break; }
    }
    if (!$found) {
        return new WP_Error('notfound', 'Unzipped folder not found in ' . SUM_LIB_PATH);
    }

    // Rename to lib/dompdf if needed
    if (basename($found) !== 'dompdf') {
        // If an old dompdf exists, try to remove/rename it first
        if (is_dir(SUM_DOMPDF_DIR)) {
            // Try to remove leftover dir
            // (If removal fails due to perms, the rename below will fail and we’ll surface that.)
            @rmdir(SUM_DOMPDF_DIR);
        }
        if (!@rename($found, SUM_DOMPDF_DIR)) {
            return new WP_Error('rename', 'Failed to rename ' . $found . ' to ' . SUM_DOMPDF_DIR);
        }
    }

    // Try to load it now
    if (!sum_load_dompdf()) {
        return new WP_Error('autoload', 'Dompdf autoloader not found after install.');
    }

    return true;
}

// Put this in a central place (e.g., your main plugin file or a utils file)
if (!defined('SUM_DOMPDF_URL')) {
    define('SUM_DOMPDF_URL', 'https://github.com/dompdf/dompdf/releases/download/v3.1.2/dompdf_3-1-2.zip');
}

function sum_install_dompdf() {
    if (!current_user_can('manage_options')) {
        return new WP_Error('cap', 'No permission');
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    WP_Filesystem();

    // 1) Download
    $zip = download_url(SUM_DOMPDF_URL, 30);
    if (is_wp_error($zip)) {
        error_log('SUM Dompdf: download failed - ' . $zip->get_error_message());
        return $zip;
    }

    // 2) Unzip to uploads/sum
    $uploads = wp_upload_dir();
    $base    = trailingslashit($uploads['basedir']) . 'sum';
    wp_mkdir_p($base);

    $unzipped = unzip_file($zip, $base);
    @unlink($zip);
    if (is_wp_error($unzipped)) {
        error_log('SUM Dompdf: unzip failed - ' . $unzipped->get_error_message());
        return $unzipped;
    }

    // 3) Find the extracted dompdf folder (dompdf, dompdf-3.1.2, dompdf_3-1-2, etc)
    $candidates = array(
        $base . '/dompdf',
        $base . '/dompdf-3.1.2',
        $base . '/dompdf_3-1-2',
    );
    foreach (glob($base . '/dompdf*', GLOB_ONLYDIR) ?: array() as $g) {
        if (!in_array($g, $candidates, true)) $candidates[] = $g;
    }

    $found = '';
    foreach ($candidates as $dir) {
        if (file_exists($dir . '/autoload.inc.php')) { $found = $dir; break; }
    }
    if (!$found) {
        error_log('SUM Dompdf: autoload.inc.php not found in any extracted folder');
        return new WP_Error('missing', 'Could not locate Dompdf autoload file after extraction.');
    }

    // 4) Normalize to uploads/sum/dompdf
    $target = $base . '/dompdf';
    if ($found !== $target) {
        // Remove old target if partially installed
        if (is_dir($target)) {
            sum_rrmdir($target);
        }

        // Try rename; if it fails, copy
        if (!@rename($found, $target)) {
            $copy_res = sum_copy_dir($found, $target);
            if (is_wp_error($copy_res)) {
                error_log('SUM Dompdf: copy_dir failed - ' . $copy_res->get_error_message());
                return new WP_Error('finalize', 'Could not finalize Dompdf install. Check permissions.');
            }
            // remove the leftover source folder
            sum_rrmdir($found);
        }
    }

    // 5) Final sanity check
    if (!file_exists($target . '/autoload.inc.php')) {
        return new WP_Error('finalize', 'Could not finalize Dompdf install. Check permissions.');
    }

    return true;
}

// Minimal recursive remove (uses WP_Filesystem or native)
function sum_rrmdir($dir) {
    if (!is_dir($dir)) return;
    $items = array_diff(scandir($dir), array('.','..'));
    foreach ($items as $item) {
        $path = $dir . '/' . $item;
        if (is_dir($path)) sum_rrmdir($path); else @unlink($path);
    }
    @rmdir($dir);
}

// copy_dir wrapper that always includes the WP file functions
function sum_copy_dir($src, $dst) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    WP_Filesystem();
    return copy_dir($src, $dst);
}


// Hook the installer to run on plugin activation
register_activation_hook(__FILE__, function () {
    // Best-effort install during activation.
    sum_ensure_dompdf_installed();
});

// Also try to load on every request before AJAX handlers run (cheap after first success)
add_action('init', 'sum_load_dompdf', 0);

// --- END: PDF Dependency Management Functions ---

// Include required files
require_once SUM_PLUGIN_PATH . 'includes/class-database.php';
require_once SUM_PLUGIN_PATH . 'includes/class-ajax-handlers.php';
require_once SUM_PLUGIN_PATH . 'includes/class-payment-handler.php';
require_once SUM_PLUGIN_PATH . 'includes/class-email-handler.php';
require_once SUM_PLUGIN_PATH . 'includes/class-pdf-generator.php';
require_once SUM_PLUGIN_PATH . 'includes/class-pallet-database.php';
require_once SUM_PLUGIN_PATH . 'includes/class-pallet-ajax-handlers.php';
require_once SUM_PLUGIN_PATH . 'includes/class-pallet-email-handler.php';

// === PDF libs paths ===
// Where Dompdf will live: wp-content/plugins/storage-unit-manager/lib/dompdf/
if (!defined('SUM_VENDOR_PATH')) define('SUM_VENDOR_PATH', plugin_dir_path(__FILE__) . 'lib/');
if (!defined('SUM_DOMPDF_DIR'))  define('SUM_DOMPDF_DIR',  SUM_VENDOR_PATH . 'dompdf/');
if (!defined('SUM_DOMPDF_AUTO')) define('SUM_DOMPDF_AUTO', SUM_DOMPDF_DIR . 'autoload.inc.php');

if (!function_exists('sum_load_dompdf')) {
function sum_load_dompdf() {
    static $ok = null;
    if ($ok !== null) return $ok;
    
    // 1. Primary Check: Class already loaded
    if (class_exists('\\Dompdf\\Dompdf')) return $ok = true;

    // 2. Build paths to check. Priority is key!
    $paths = array();

    // 🏆 Priority 1: The correct installation path inside the plugin (SUM_DOMPDF_DIR)
    if (defined('SUM_DOMPDF_DIR')) {
         // This is the file shown in your screenshot (dompdf/autoload.inc.php)
         $paths[] = SUM_DOMPDF_DIR . 'autoload.inc.php'; 
    }

    // Priority 2: Fallback to checking the uploads directory (for older/alternate installs)
    $uploads = wp_upload_dir();
    $base    = trailingslashit($uploads['basedir']) . 'sum';
    $paths[] = $base . '/dompdf/autoload.inc.php'; 
    
    // Fallback 3: Check plugin's vendor path (if installed via Composer locally)
    $paths[] = plugin_dir_path(__FILE__) . 'vendor/dompdf/autoload.inc.php';

    // 4. Loop and load
    foreach ($paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            if (class_exists('\\Dompdf\\Dompdf')) return $ok = true;
        }
    }
    
    return $ok = false;
}
}
// Customers module
//require_once SUM_PLUGIN_PATH . 'includes/class-customer-database.php';
//require_once SUM_PLUGIN_PATH . 'includes/class-customer-admin.php';

//$sum_customer_db = new SUM_Customer_Database();
//add_action('admin_init', array($sum_customer_db, 'maybe_install_or_update'));

//$sum_customer_admin = new SUM_Customer_Admin($sum_customer_db);
//$sum_customer_admin->init();

// Optional modules loader (safe if folder/file is missing)
// storage-unit-manager.php (core plugin)
$customers_module = SUM_PLUGIN_PATH . 'modules/customers/module.php';
if ( file_exists($customers_module) ) {
    include_once $customers_module;
    if ( class_exists('SUM_Customers_Module') ) {
        SUM_Customers_Module::boot(); // module self-registers everything
    }
}


class StorageUnitManager {
    
    private $database;
    private $ajax_handlers;
    private $payment_handler;
    private $email_handler;
    private $pallet_database;
    private $pallet_ajax_handlers;
    private $pallet_email_handler;
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        // Initialize components
        $this->database = new SUM_Database();
        $this->ajax_handlers = new SUM_Ajax_Handlers($this->database);
        $this->payment_handler = new SUM_Payment_Handler($this->database);
        $this->email_handler = new SUM_Email_Handler($this->database);
        $this->pallet_database = new SUM_Pallet_Database();
        $this->pallet_ajax_handlers = new SUM_Pallet_Ajax_Handlers($this->pallet_database);
        $this->pallet_email_handler = new SUM_Pallet_Email_Handler($this->pallet_database);
        
        // Create database tables
        $this->database->create_tables();
        $this->pallet_database->create_tables();
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Enqueue scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'frontend_enqueue_scripts'));
        
        // Initialize AJAX handlers
        $this->ajax_handlers->init();
        $this->pallet_ajax_handlers->init();
        
        // Initialize payment handlers
        $this->payment_handler->init();
        
        // Initialize email handlers
        $this->email_handler->init();
        $this->pallet_email_handler->init();
        
        // Shortcodes
        add_shortcode('storage_units_frontend', array($this, 'frontend_shortcode'));
        add_shortcode('storage_pallets_frontend', array($this, 'pallet_frontend_shortcode'));
        add_shortcode('storage_pallets_frontend', array($this, 'pallet_frontend_shortcode'));
        
        // Create user role
        $this->create_storage_manager_role();
    }
    
    public function activate() {
        $this->database = new SUM_Database();
        $this->database->create_tables();
        $this->pallet_database = new SUM_Pallet_Database();
        $this->pallet_database->create_tables();
        $this->create_storage_manager_role();
        $this->create_frontend_page();
        $this->create_pallet_frontend_page();
        $this->create_pallet_frontend_page();
        
        // Schedule daily email check
        if (!wp_next_scheduled('sum_daily_email_check')) {
            wp_schedule_event(time(), 'daily', 'sum_daily_email_check');
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        // Clear scheduled hook
        wp_clear_scheduled_hook('sum_daily_email_check');
        
        // Remove user role
        remove_role('storage_manager');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    public function create_storage_manager_role() {
        add_role('storage_manager', 'Storage Manager', array(
            'read' => true,
            'edit_posts' => false,
            'delete_posts' => false,
            'publish_posts' => false,
            'upload_files' => false,
        ));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Storage Units',
            'Storage Units',
            'manage_options',
            'storage-units',
            array($this, 'admin_page'),
            'dashicons-building',
            30
        );
        
        add_submenu_page(
            'storage-units',
            'Settings',
            'Settings',
            'manage_options',
            'storage-units-settings',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'storage-units',
            'Email Settings',
            'Email Settings',
            'manage_options',
            'storage-units-email',
            array($this, 'email_settings_page')
        );
        
        add_submenu_page(
            'storage-units',
            'Payment Settings',
            'Payment Settings',
            'manage_options',
            'storage-units-payment',
            array($this, 'payment_settings_page')
        );
        
        add_submenu_page(
            'storage-units',
            'Pallet Storage',
            'Pallet Storage',
            'manage_options',
            'storage-pallets',
            array($this, 'pallet_page')
        );
        
        add_submenu_page(
            'storage-units',
            'Pallet Settings',
            'Pallet Settings',
            'manage_options',
            'storage-pallet-settings',
            array($this, 'pallet_settings_page')
        );
    }
    
    public function admin_enqueue_scripts($hook) {
        if (strpos($hook, 'storage-units') !== false && strpos($hook, 'storage-pallets') === false && strpos($hook, 'storage-pallet') === false) {
            wp_enqueue_style('sum-admin-css', SUM_PLUGIN_URL . 'assets/admin.css', array(), SUM_VERSION);
            wp_enqueue_script('sum-admin-js', SUM_PLUGIN_URL . 'assets/admin.js', array('jquery'), SUM_VERSION, true);
            
            wp_localize_script('sum-admin-js', 'sum_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('sum_nonce')
            ));
        }
        
        if (strpos($hook, 'storage-pallets') !== false && strpos($hook, 'storage-pallet-settings') === false) {
            wp_enqueue_style('sum-admin-css', SUM_PLUGIN_URL . 'assets/admin.css', array(), SUM_VERSION);
            wp_enqueue_script('sum-pallet-admin-js', SUM_PLUGIN_URL . 'assets/pallet-admin.js', array('jquery'), SUM_VERSION, true);
            
            wp_localize_script('sum-pallet-admin-js', 'sum_pallet_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('sum_nonce')
            ));
        }
        
        // Only load basic admin CSS for settings pages
        if (strpos($hook, 'storage-units-settings') !== false || 
            strpos($hook, 'storage-units-email') !== false || 
            strpos($hook, 'storage-units-payment') !== false || 
            strpos($hook, 'storage-pallet-settings') !== false) {
            wp_enqueue_style('sum-admin-css', SUM_PLUGIN_URL . 'assets/admin.css', array(), SUM_VERSION);
        }
    }
    
public function frontend_enqueue_scripts() {
    // Check if we are on a page, and get its content
    if (!is_page() || !get_post()) {
        return;
    }
    $post_content = get_post()->post_content;

    // --- A. Enqueue for general Storage Units frontend ---
    if (has_shortcode($post_content, 'storage_units_frontend')) {
        wp_enqueue_style('sum-frontend-css', SUM_PLUGIN_URL . 'assets/frontend.css', array(), SUM_VERSION);
        //wp_enqueue_script('sum-frontend-js', SUM_PLUGIN_URL . 'assets/frontend.js', array('jquery'), SUM_VERSION, true);
         // 1. UI Utilities (Dependencies: jquery)
    wp_enqueue_script( 'sum-frontend-ui', plugins_url( 'assets/sum-frontend-ui.js', __FILE__ ), array( 'jquery' ), '1.0', true );

    // 2. Rendering & Filtering (Dependencies: sum-frontend-ui)
    wp_enqueue_script( 'sum-frontend-render', plugins_url( 'assets/sum-frontend-init.js', __FILE__ ), array( 'sum-frontend-ui' ), '1.0', true );

    // 3. Main Logic (Dependencies: sum-frontend-render)
    wp_enqueue_script( 'sum-frontend-js', plugins_url( 'assets/sum-frontend-core.js', __FILE__ ), array( 'sum-frontend-render' ), '1.0', true );
        
        wp_localize_script('sum-frontend-js', 'sum_frontend_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sum_frontend_nonce')
        ));
    }
    
    // --- B. Enqueue for Pallet Storage frontend ---
    if (has_shortcode($post_content, 'storage_pallets_frontend')) {
        wp_enqueue_style('sum-pallet-frontend-css', SUM_PLUGIN_URL . 'assets/pallet-frontend.css', array(), SUM_VERSION);
        wp_enqueue_script('sum-pallet-frontend-js', SUM_PLUGIN_URL . 'assets/pallet-frontend.js', array('jquery'), SUM_VERSION, true);
        
        wp_localize_script('sum-pallet-frontend-js', 'sum_pallet_frontend_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sum_frontend_nonce')
        ));
    }
    
    // --- C. FIX: Enqueue for Payment Form Page (storage_payment_form) ---
    // The payment form relies on the general frontend styles for its container/card layout.
    if (has_shortcode($post_content, 'storage_payment_form')) {
        // Load main CSS for card layout and general styling
        wp_enqueue_style('sum-frontend-css', SUM_PLUGIN_URL . 'assets/frontend.css', array(), SUM_VERSION); 
        
        // Stripe JS library is already correctly enqueued, but we ensure it happens here:
        wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', array(), null, true);

        // We don't need the general 'sum-frontend-js' but the payment logic script (which might be the same file or inline in the template).
        // Since the current JS is inline in the template, we only need the CSS and Stripe JS.
    }
}    
    public function admin_page() {
        include SUM_PLUGIN_PATH . 'templates/admin-page.php';
    }
    
    public function settings_page() {
    if ( ! current_user_can('manage_options') ) {
        return;
    }

    // Make the DB layer available to the template:
    $database = $this->database;

    // Now include your template
    include SUM_PLUGIN_PATH . 'templates/settings-page.php';
}
    
    public function email_settings_page() {
        include SUM_PLUGIN_PATH . 'templates/email-settings-page.php';
    }
    
    public function payment_settings_page() {
        include SUM_PLUGIN_PATH . 'templates/payment-settings-page.php';
    }
    
    public function pallet_page() {
        include SUM_PLUGIN_PATH . 'templates/pallet-admin-page.php';
    }
    
    public function pallet_settings_page() {
        include SUM_PLUGIN_PATH . 'templates/pallet-settings-page.php';
    }
    
    public function frontend_shortcode($atts) {
        ob_start();
        include SUM_PLUGIN_PATH . 'templates/frontend-page.php';
        return ob_get_clean();
    }
    
    public function pallet_frontend_shortcode($atts) {
        ob_start();
        include SUM_PLUGIN_PATH . 'templates/pallet-frontend-page.php';
        return ob_get_clean();
    }
    
    public function create_frontend_page() {
        // Check if page already exists
        $existing_page = get_page_by_path('storage-units-manager');
        if ($existing_page) {
            return $existing_page->ID;
        }
        
        // Create new page
        $page_data = array(
            'post_title' => 'Storage Units Manager',
            'post_content' => '[storage_units_frontend]',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'storage-units-manager'
        );
        
        return wp_insert_post($page_data);
    }
    
    public function create_pallet_frontend_page() {
        // Check if page already exists
        $existing_page = get_page_by_path('storage-pallets-manager');
        if ($existing_page) {
            return $existing_page->ID;
        }
        
        // Create new page
        $page_data = array(
            'post_title' => 'Storage Pallets Manager',
            'post_content' => '[storage_pallets_frontend]',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'storage-pallets-manager'
        );
        
        return wp_insert_post($page_data);
    }
    
    // Helper method to get settings (used by templates)
    public function get_setting($key, $default = '') {
        return $this->database->get_setting($key, $default);
    }
    
    // Helper method to get pallet settings
    public function get_pallet_settings() {
        return $this->pallet_database->get_pallet_settings();
    }
}

// Initialize the plugin
new StorageUnitManager();