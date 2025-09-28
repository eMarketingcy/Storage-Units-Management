<?php
// Check if user is logged in and has proper role
if (!is_user_logged_in()) {
    echo '<div class="sum-pallet-login-required">';
    echo '<div class="sum-pallet-login-card">';
    echo '<div class="sum-pallet-login-icon">🔐</div>';
    echo '<h2>Login Required</h2>';
    echo '<p>You must be logged in to access the Pallet Storage Manager.</p>';
    echo '<a href="' . wp_login_url(get_permalink()) . '" class="sum-pallet-btn sum-pallet-btn-primary">Login</a>';
    echo '</div>';
    echo '</div>';
    return;
}

$current_user = wp_get_current_user();
$allowed_roles = explode(',', $this->get_setting('allowed_roles', 'administrator,storage_manager'));
$allowed_roles = array_map('trim', $allowed_roles);

$user_has_access = false;
foreach ($allowed_roles as $role) {
    if (in_array($role, $current_user->roles)) {
        $user_has_access = true;
        break;
    }
}

if (!$user_has_access) {
    echo '<div class="sum-pallet-access-denied">';
    echo '<div class="sum-pallet-access-card">';
    echo '<div class="sum-pallet-access-icon">⚠️</div>';
    echo '<h2>Access Denied</h2>';
    echo '<p>You do not have permission to access the Pallet Storage Manager.</p>';
    echo '<p class="sum-pallet-roles">Required roles: ' . implode(', ', $allowed_roles) . '</p>';
    echo '<p class="sum-pallet-roles">Your roles: ' . implode(', ', $current_user->roles) . '</p>';
    echo '</div>';
    echo '</div>';
    return;
}
?>

<div id="sum-pallet-frontend-container">
    <!-- Main Interface -->
    <div id="sum-pallet-frontend-main" class="sum-pallet-frontend-main">
        <!-- Header -->
        <div class="sum-pallet-header">
            <div class="sum-pallet-header-content">
                <div class="sum-pallet-title-section">
                    <div class="sum-pallet-icon">🟠</div>
                    <div class="sum-pallet-title-text">
                        <h1>Pallet Storage</h1>
                        <p>Manage your pallet storage efficiently</p>
                    </div>
                </div>
                <div class="sum-pallet-user-section">
                    <div class="sum-pallet-user-info">
                        <span class="sum-pallet-user-name"><?php echo esc_html($current_user->display_name); ?></span>
                        <span class="sum-pallet-user-role"><?php echo esc_html(implode(', ', $current_user->roles)); ?></span>
                    </div>
                    <a href="<?php echo wp_logout_url(get_permalink()); ?>" class="sum-pallet-logout-btn">
                        <span class="sum-pallet-logout-icon">🚪</span>
                        Logout
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Navigation Links -->
        <div class="sum-pallet-navigation">
            <a href="<?php echo home_url('/storage-units-manager/'); ?>" class="sum-pallet-nav-item">
                <span class="sum-pallet-nav-icon">📦</span>
                <span>Storage Units</span>
            </a>
            <div class="sum-pallet-nav-item sum-pallet-nav-active">
                <span class="sum-pallet-nav-icon">🟠</span>
                <span>Pallet Storage</span>
            </div>
            <a href="<?php echo home_url('/storage-customers/'); ?>" class="sum-pallet-nav-item">
                <span class="sum-pallet-nav-icon">👪</span>
                <span>Custommers</span>
            </a>
        </div>
        
        <!-- Stats Cards -->
        <div class="sum-pallet-stats-grid">
            <div class="sum-pallet-stat-card sum-pallet-stat-total">
                <div class="sum-pallet-stat-icon">📦</div>
                <div class="sum-pallet-stat-content">
                    <div class="sum-pallet-stat-value" id="frontend-total-pallets">0</div>
                    <div class="sum-pallet-stat-label">Total Pallets</div>
                </div>
            </div>
            <div class="sum-pallet-stat-card sum-pallet-stat-unpaid">
                <div class="sum-pallet-stat-icon">⚠️</div>
                <div class="sum-pallet-stat-content">
                    <div class="sum-pallet-stat-value" id="frontend-unpaid-pallets">0</div>
                    <div class="sum-pallet-stat-label">Unpaid</div>
                </div>
            </div>
            <div class="sum-pallet-stat-card sum-pallet-stat-eu">
                <div class="sum-pallet-stat-icon">🇪🇺</div>
                <div class="sum-pallet-stat-content">
                    <div class="sum-pallet-stat-value" id="frontend-eu-pallets">0</div>
                    <div class="sum-pallet-stat-label">EU Pallets</div>
                </div>
            </div>
            <div class="sum-pallet-stat-card sum-pallet-stat-us">
                <div class="sum-pallet-stat-icon">🇺🇸</div>
                <div class="sum-pallet-stat-content">
                    <div class="sum-pallet-stat-value" id="frontend-us-pallets">0</div>
                    <div class="sum-pallet-stat-label">US Pallets</div>
                </div>
            </div>
        </div>
        
        <!-- Controls -->
        <div class="sum-pallet-controls">
            <div class="sum-pallet-search-section">
                <div class="sum-pallet-search-wrapper">
                    <span class="sum-pallet-search-icon">🔍</span>
                    <input type="text" id="frontend-search-pallets" placeholder="Search pallets..." class="sum-pallet-search-input">
                </div>
                <select id="frontend-filter-status" class="sum-pallet-filter-select">
                    <option value="all">All Pallets</option>
                    <option value="eu">EU Pallets</option>
                    <option value="us">US Pallets</option>
                    <option value="past_due">Past Due</option>
                    <option value="unpaid">Unpaid</option>
                </select>
            </div>
            <div class="sum-pallet-action-section">
                <button type="button" class="sum-pallet-btn sum-pallet-btn-primary" id="frontend-add-pallet-btn">
                    <span class="sum-pallet-btn-icon">➕</span>
                    Add Pallet
                </button>
            </div>
        </div>
        
        <!-- Pallets Grid -->
        <div id="frontend-pallets-grid" class="sum-pallet-grid">
            <!-- Pallets will be loaded here -->
        </div>
        
        <!-- No Pallets Message -->
        <div id="frontend-no-pallets" class="sum-pallet-empty-state" style="display: none;">
            <div class="sum-pallet-empty-icon">🟠</div>
            <h3>No pallets found</h3>
            <p>No pallets match your current search criteria.</p>
            <button type="button" class="sum-pallet-btn sum-pallet-btn-primary" id="frontend-add-first-pallet-btn">
                <span class="sum-pallet-btn-icon">➕</span>
                Add Your First Pallet
            </button>
        </div>
    </div>
</div>

<!-- Pallet Modal -->
<div id="frontend-pallet-modal" class="sum-pallet-modal" style="display: none;">
    <div class="sum-pallet-modal-overlay"></div>
    <div class="sum-pallet-modal-content">
        <div class="sum-pallet-modal-header">
            <h2 id="frontend-pallet-modal-title">Add New Pallet Storage</h2>
            <button type="button" class="sum-pallet-modal-close">✕</button>
        </div>
        
        <form id="frontend-pallet-form" class="sum-pallet-form">
            <input type="hidden" id="frontend-pallet-id" name="pallet_id">
            
            <div class="sum-pallet-form-section">
                <h3>📦 Pallet Information</h3>
                <div class="sum-pallet-form-grid">
                    <div class="sum-pallet-form-group">
                        <label for="frontend-pallet-name">Pallet Name</label>
                        <input type="text" id="frontend-pallet-name" name="pallet_name" required placeholder="Auto-generated">
                        <small>Will be generated from customer name</small>
                    </div>
                    
                    <div class="sum-pallet-form-group">
                        <label for="frontend-pallet-type">Pallet Type</label>
                        <select id="frontend-pallet-type" name="pallet_type" required>
                            <option value="EU">🇪🇺 EU Pallet (1.20m × 0.80m)</option>
                            <option value="US">🇺🇸 US Pallet (1.22m × 1.02m)</option>
                        </select>
                    </div>
                    
                    <div class="sum-pallet-form-group">
                        <label for="frontend-actual-height">Actual Height (m)</label>
                        <input type="number" id="frontend-actual-height" name="actual_height" step="0.01" min="0" max="2" required placeholder="1.15">
                        <small>Price calculated based on tier (e.g., 1.15m → 1.20m tier)</small>
                    </div>
                </div>
            </div>
            
            <div class="sum-pallet-form-section">
                <h3>📅 Storage Period</h3>
                <div class="sum-pallet-form-grid">
                    <div class="sum-pallet-form-group">
                        <label for="frontend-period-from">Period From</label>
                        <input type="date" id="frontend-period-from" name="period_from">
                    </div>
                    
                    <div class="sum-pallet-form-group">
                        <label for="frontend-period-until">Period Until</label>
                        <input type="date" id="frontend-period-until" name="period_until">
                    </div>
                    
                    <div class="sum-pallet-form-group">
                        <label for="frontend-payment-status">Payment Status</label>
                        <select id="frontend-payment-status" name="payment_status">
                            <option value="paid">✅ Paid</option>
                            <option value="unpaid">⏳ Unpaid</option>
                            <option value="overdue">⚠️ Overdue</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="sum-pallet-form-section">
                <h3>👤 Primary Contact</h3>
                <div class="sum-pallet-form-grid">
                    <div class="sum-pallet-form-group">
                        <label for="frontend-primary-name">Full Name</label>
                        <input type="text" id="frontend-primary-name" name="primary_contact_name" required placeholder="John Doe">
                    </div>
                    
                    <div class="sum-pallet-form-group">
                        <label for="frontend-primary-phone">Phone</label>
                        <input type="tel" id="frontend-primary-phone" name="primary_contact_phone" placeholder="+357 99 123456">
                    </div>
                    
                    <div class="sum-pallet-form-group">
                        <label for="frontend-primary-whatsapp">WhatsApp</label>
                        <input type="tel" id="frontend-primary-whatsapp" name="primary_contact_whatsapp" placeholder="+357 99 123456">
                    </div>
                    
                    <div class="sum-pallet-form-group">
                        <label for="frontend-primary-email">Email</label>
                        <input type="email" id="frontend-primary-email" name="primary_contact_email" placeholder="john@example.com">
                    </div>
                </div>
            </div>
            
            <div class="sum-pallet-form-section">
                <div class="sum-pallet-secondary-toggle">
                    <label class="sum-pallet-checkbox-label">
                        <input type="checkbox" id="frontend-has-secondary-contact">
                        <span class="sum-pallet-checkbox-custom"></span>
                        Add Secondary Contact
                    </label>
                </div>
                
                <div id="frontend-secondary-contact-section" class="sum-pallet-secondary-section" style="display: none;">
                    <h3>👥 Secondary Contact</h3>
                    <div class="sum-pallet-form-grid">
                        <div class="sum-pallet-form-group">
                            <label for="frontend-secondary-name">Full Name</label>
                            <input type="text" id="frontend-secondary-name" name="secondary_contact_name" placeholder="Jane Doe">
                        </div>
                        
                        <div class="sum-pallet-form-group">
                            <label for="frontend-secondary-phone">Phone</label>
                            <input type="tel" id="frontend-secondary-phone" name="secondary_contact_phone" placeholder="+357 99 123456">
                        </div>
                        
                        <div class="sum-pallet-form-group">
                            <label for="frontend-secondary-whatsapp">WhatsApp</label>
                            <input type="tel" id="frontend-secondary-whatsapp" name="secondary_contact_whatsapp" placeholder="+357 99 123456">
                        </div>
                        
                        <div class="sum-pallet-form-group">
                            <label for="frontend-secondary-email">Email</label>
                            <input type="email" id="frontend-secondary-email" name="secondary_contact_email" placeholder="jane@example.com">
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="sum-pallet-form-actions">
                <button type="button" class="sum-pallet-btn sum-pallet-btn-secondary" id="frontend-pallet-cancel-btn">Cancel</button>
                <button type="submit" class="sum-pallet-btn sum-pallet-btn-primary" id="frontend-pallet-save-btn">
                    <span class="sum-pallet-btn-icon">💾</span>
                    Save Pallet
                </button>
            </div>
        </form>
    </div>
</div>