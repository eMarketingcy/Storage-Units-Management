<?php
// Check if user is logged in and has proper role
if (!is_user_logged_in()) {
    echo '<div class="sum-frontend-login-required">';
    echo '<div class="sum-frontend-login-card">';
    echo '<div class="sum-frontend-login-icon">🔐</div>';
    echo '<h2>Login Required</h2>';
    echo '<p>You must be logged in to access the Storage Unit Manager.</p>';
    echo '<a href="' . wp_login_url(get_permalink()) . '" class="sum-frontend-btn sum-frontend-btn-primary">Login</a>';
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
    echo '<div class="sum-frontend-access-denied">';
    echo '<div class="sum-frontend-access-card">';
    echo '<div class="sum-frontend-access-icon">⚠️</div>';
    echo '<h2>Access Denied</h2>';
    echo '<p>You do not have permission to access the Storage Unit Manager.</p>';
    echo '<p class="sum-frontend-roles">Required roles: ' . implode(', ', $allowed_roles) . '</p>';
    echo '<p class="sum-frontend-roles">Your roles: ' . implode(', ', $current_user->roles) . '</p>';
    echo '</div>';
    echo '</div>';
    return;
}
?>

<div id="sum-frontend-container">
    <div id="sum-frontend-main" class="sum-frontend-main">
        <div class="sum-frontend-header">
            <div class="sum-frontend-header-content">
                <div class="sum-frontend-title-section">
                    <div class="sum-frontend-icon">📦</div>
                    <div class="sum-frontend-title-text">
                        <h1>Storage Unit Manager</h1>
                        <p>Manage your storage units efficiently</p>
                    </div>
                </div>
                <div class="sum-frontend-user-section">
                    <div class="sum-frontend-user-info">
                        <span class="sum-frontend-user-name"><?php echo esc_html($current_user->display_name); ?></span>
                        <span class="sum-frontend-user-role"><?php echo esc_html(implode(', ', $current_user->roles)); ?></span>
                    </div>
                    <a href="<?php echo wp_logout_url(get_permalink()); ?>" class="sum-frontend-logout-btn">
                        <span class="sum-frontend-logout-icon">🚪</span>
                        Logout
                    </a>
                </div>
            </div>
        </div>
        
        <div class="sum-frontend-navigation">
            <div class="sum-frontend-nav-item sum-frontend-nav-active">
                <span class="sum-frontend-nav-icon">📦</span>
                <span>Storage Units</span>
            </div>
            <a href="<?php echo home_url('/storage-pallets-manager/'); ?>" class="sum-frontend-nav-item">
                <span class="sum-frontend-nav-icon">🟠</span>
                <span>Pallet Storage</span>
            </a>
            <a href="<?php echo home_url('/storage-customers/'); ?>" class="sum-frontend-nav-item">
                <span class="sum-frontend-nav-icon">👪</span>
                <span>Custommers</span>
            </a>
        </div>
        
        <div class="sum-frontend-stats-grid">
            <div class="sum-frontend-stat-card sum-frontend-stat-total">
                <div class="sum-frontend-stat-icon">📦</div>
                <div class="sum-frontend-stat-content">
                    <div class="sum-frontend-stat-value" id="frontend-total-units">0</div>
                    <div class="sum-frontend-stat-label">Total Units</div>
                </div>
            </div>
            <div class="sum-frontend-stat-card sum-frontend-stat-occupied">
                <div class="sum-frontend-stat-icon">🔴</div>
                <div class="sum-frontend-stat-content">
                    <div class="sum-frontend-stat-value" id="frontend-occupied-units">0</div>
                    <div class="sum-frontend-stat-label">Occupied</div>
                </div>
            </div>
            <div class="sum-frontend-stat-card sum-frontend-stat-available">
                <div class="sum-frontend-stat-icon">🟢</div>
                <div class="sum-frontend-stat-content">
                    <div class="sum-frontend-stat-value" id="frontend-available-units">0</div>
                    <div class="sum-frontend-stat-label">Available</div>
                </div>
            </div>
            <div class="sum-frontend-stat-card sum-frontend-stat-unpaid">
                <div class="sum-frontend-stat-icon">⚠️</div>
                <div class="sum-frontend-stat-content">
                    <div class="sum-frontend-stat-value" id="frontend-unpaid-units">0</div>
                    <div class="sum-frontend-stat-label">Unpaid</div>
                </div>
            </div>
        </div>
        
        <div class="sum-frontend-controls">
            <div class="sum-frontend-search-section">
                <div class="sum-frontend-search-wrapper">
                    <span class="sum-frontend-search-icon">🔍</span>
                    <input type="text" id="frontend-search-units" placeholder="Search units..." class="sum-frontend-search-input">
                </div>
                <select id="frontend-filter-status" class="sum-frontend-filter-select">
                    <option value="all">All Units</option>
                    <option value="occupied">Occupied</option>
                    <option value="available">Available</option>
                    <option value="past_due">Past Due</option>
                    <option value="unpaid">Unpaid</option>
                </select>
            </div>
            
            <div class="sum-frontend-action-group">
                <div class="sum-frontend-view-toggle">
                    <button type="button" class="sum-frontend-btn sum-frontend-btn-secondary sum-view-grid active" id="frontend-view-grid" title="Grid View">
                        <span class="sum-frontend-btn-icon">▦</span>
                    </button>
                    <button type="button" class="sum-frontend-btn sum-frontend-btn-secondary sum-view-list" id="frontend-view-list" title="List View">
                        <span class="sum-frontend-btn-icon">📋</span>
                    </button>
                </div>
                <button type="button" class="sum-frontend-btn sum-frontend-btn-secondary" id="frontend-bulk-add-btn">
                    <span class="sum-frontend-btn-icon">📦</span>
                    Bulk Add
                </button>
                <button type="button" class="sum-frontend-btn sum-frontend-btn-primary" id="frontend-add-unit-btn">
                    <span class="sum-frontend-btn-icon">➕</span>
                    Add Unit
                </button>
            </div>
        </div>
        
        <div id="frontend-units-view-wrapper" class="sum-frontend-view-wrapper sum-view-mode-grid">
            
            <div id="frontend-units-grid" class="sum-frontend-grid">
                </div>
            
            <div id="frontend-units-table" class="sum-frontend-table-container">
                <table class="sum-frontend-table">
                    <thead>
                        <tr>
                            <th style="width: 5%;">ID</th>
                            <th style="width: 15%;">Unit Name</th>
                            <th style="width: 10%;">Size / SQM</th>
                            <th style="width: 15%;">Customer</th>
                            <th style="width: 15%;">Period Until</th>
                            <th style="width: 10%;">Price (€)</th>
                            <th style="width: 15%;">Status</th>
                            <th style="width: 15%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="frontend-units-table-body">
                        </tbody>
                </table>
            </div>

        </div>
        
        <div id="frontend-no-units" class="sum-frontend-empty-state" style="display: none;">
            <div class="sum-frontend-empty-icon">📦</div>
            <h3>No units found</h3>
            <p>No units match your current search criteria.</p>
            <button type="button" class="sum-frontend-btn sum-frontend-btn-primary" id="frontend-add-first-unit-btn">
                <span class="sum-frontend-btn-icon">➕</span>
                Add Your First Unit
            </button>
        </div>
    </div>
</div>

<div id="frontend-unit-modal" class="sum-frontend-modal" style="display: none;">
    <div class="sum-frontend-modal-overlay"></div>
    <div class="sum-frontend-modal-content">
        <div class="sum-frontend-modal-header">
            <h2 id="frontend-modal-title">Add New Storage Unit</h2>
            <button type="button" class="sum-frontend-modal-close">✕</button>
        </div>
        
        <form id="frontend-unit-form" class="sum-frontend-form">
            <input type="hidden" id="frontend-unit-id" name="unit_id">
            
            <div class="sum-frontend-form-section">
                <h3>📦 Unit Information</h3>
                <div class="sum-frontend-form-grid">
                    <div class="sum-frontend-form-group">
                        <label for="frontend-unit-name">Unit Name</label>
                        <input type="text" id="frontend-unit-name" name="unit_name" required placeholder="e.g., A1, B2, D2">
                    </div>
                    
                    <div class="sum-frontend-form-group">
                        <label for="frontend-size">Size</label>
                        <input type="text" id="frontend-size" name="size" placeholder="e.g., Small, Medium, Large">
                    </div>
                    
                    <div class="sum-frontend-form-group">
                        <label for="frontend-sqm">Square Meters</label>
                        <input type="number" id="frontend-sqm" name="sqm" step="0.1" placeholder="e.g., 10.5">
                    </div>
                    
                    <div class="sum-frontend-form-group">
                        <label for="frontend-monthly-price">Monthly Price (€)</label>
                        <input type="number" id="frontend-monthly-price" name="monthly_price" step="0.01" min="0" placeholder="e.g., 100.00">
                    </div>
                    
                    <div class="sum-frontend-form-group">
                        <label for="frontend-website-name">Website Name</label>
                        <input type="text" id="frontend-website-name" name="website_name" placeholder="Name as shown on website">
                    </div>
                </div>
            </div>
            
            <div class="sum-frontend-form-section">
                <div class="sum-frontend-occupancy-toggle">
                    <label class="sum-frontend-checkbox-label">
                        <input type="checkbox" id="frontend-is-occupied" name="is_occupied">
                        <span class="sum-frontend-checkbox-custom"></span>
                        Unit Occupied
                    </label>
                </div>
                
                <div id="frontend-occupancy-details" class="sum-frontend-occupancy-section" style="display: none;">
                    <h3>📅 Occupancy Details</h3>
                    <div class="sum-frontend-form-grid">
                        <div class="sum-frontend-form-group">
                            <label for="frontend-period-from">Period From</label>
                            <input type="date" id="frontend-period-from" name="period_from">
                        </div>
                        
                        <div class="sum-frontend-form-group">
                            <label for="frontend-period-until">Period Until</label>
                            <input type="date" id="frontend-period-until" name="period_until">
                        </div>
                        
                        <div class="sum-frontend-form-group">
                            <label for="frontend-payment-status">Payment Status</label>
                            <select id="frontend-payment-status" name="payment_status">
                                <option value="paid">✅ Paid</option>
                                <option value="unpaid">⏳ Unpaid</option>
                                <option value="overdue">⚠️ Overdue</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="sum-frontend-form-section">
                        <h3>👤 Primary Contact</h3>
                        <div class="sum-frontend-form-grid">
                            <div class="sum-frontend-form-group">
                                <label for="frontend-primary-name">Full Name</label>
                                <input type="text" id="frontend-primary-name" name="primary_contact_name" placeholder="John Doe">
                            </div>
                            
                            <div class="sum-frontend-form-group">
                                <label for="frontend-primary-phone">Phone</label>
                                <input type="tel" id="frontend-primary-phone" name="primary_contact_phone" placeholder="+357 99 123456">
                            </div>
                            
                            <div class="sum-frontend-form-group">
                                <label for="frontend-primary-whatsapp">WhatsApp</label>
                                <input type="tel" id="frontend-primary-whatsapp" name="primary_contact_whatsapp" placeholder="+357 99 123456">
                            </div>
                            
                            <div class="sum-frontend-form-group">
                                <label for="frontend-primary-email">Email</label>
                                <input type="email" id="frontend-primary-email" name="primary_contact_email" placeholder="john@example.com">
                            </div>
                        </div>
                    </div>
                    
                    <div class="sum-frontend-form-section">
                        <div class="sum-frontend-secondary-toggle">
                            <label class="sum-frontend-checkbox-label">
                                <input type="checkbox" id="frontend-has-secondary-contact">
                                <span class="sum-frontend-checkbox-custom"></span>
                                Add Secondary Contact
                            </label>
                        </div>
                        
                        <div id="frontend-secondary-contact-section" class="sum-frontend-secondary-section" style="display: none;">
                            <h3>👥 Secondary Contact</h3>
                            <div class="sum-frontend-form-grid">
                                <div class="sum-frontend-form-group">
                                    <label for="frontend-secondary-name">Full Name</label>
                                    <input type="text" id="frontend-secondary-name" name="secondary_contact_name" placeholder="Jane Doe">
                                </div>
                                
                                <div class="sum-frontend-form-group">
                                    <label for="frontend-secondary-phone">Phone</label>
                                    <input type="tel" id="frontend-secondary-phone" name="secondary_contact_phone" placeholder="+357 99 123456">
                                </div>
                                
                                <div class="sum-frontend-form-group">
                                    <label for="frontend-secondary-whatsapp">WhatsApp</label>
                                    <input type="tel" id="frontend-secondary-whatsapp" name="secondary_contact_whatsapp" placeholder="+357 99 123456">
                                </div>
                                
                                <div class="sum-frontend-form-group">
                                    <label for="frontend-secondary-email">Email</label>
                                    <input type="email" id="frontend-secondary-email" name="secondary_contact_email" placeholder="jane@example.com">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="sum-frontend-form-actions">
                <button type="button" class="sum-frontend-btn sum-frontend-btn-secondary" id="frontend-cancel-btn">Cancel</button>
                <button type="submit" class="sum-frontend-btn sum-frontend-btn-primary" id="frontend-save-btn">
                    <span class="sum-frontend-btn-icon">💾</span>
                    Save Unit
                </button>
            </div>
        </form>
    </div>
</div>

<div id="frontend-bulk-add-modal" class="sum-frontend-modal" style="display: none;">
    <div class="sum-frontend-modal-overlay"></div>
    <div class="sum-frontend-modal-content">
        <div class="sum-frontend-modal-header">
            <h2>Bulk Add Storage Units</h2>
            <button type="button" class="sum-frontend-modal-close">✕</button>
        </div>
        
        <form id="frontend-bulk-add-form" class="sum-frontend-form">
            <div class="sum-frontend-form-section">
                <h3>📦 Bulk Unit Creation</h3>
                <div class="sum-frontend-form-grid">
                    <div class="sum-frontend-form-group">
                        <label for="frontend-bulk-prefix">Prefix</label>
                        <input type="text" id="frontend-bulk-prefix" name="prefix" required placeholder="e.g., A, B, C">
                    </div>
                    
                    <div class="sum-frontend-form-group">
                        <label for="frontend-bulk-start">Start Number</label>
                        <input type="number" id="frontend-bulk-start" name="start_number" required min="1" placeholder="1">
                    </div>
                    
                    <div class="sum-frontend-form-group">
                        <label for="frontend-bulk-end">End Number</label>
                        <input type="number" id="frontend-bulk-end" name="end_number" required min="1" placeholder="10">
                    </div>
                    
                    <div class="sum-frontend-form-group">
                        <label for="frontend-bulk-size">Size (Optional)</label>
                        <input type="text" id="frontend-bulk-size" name="bulk_size" placeholder="e.g., Small, Medium">
                    </div>
                    
                    <div class="sum-frontend-form-group">
                        <label for="frontend-bulk-sqm">Square Meters (Optional)</label>
                        <input type="number" id="frontend-bulk-sqm" name="bulk_sqm" step="0.1" placeholder="e.g., 5.0">
                    </div>
                    
                    <div class="sum-frontend-form-group">
                        <label for="frontend-bulk-price">Monthly Price (€)</label>
                        <input type="number" id="frontend-bulk-price" name="bulk_price" step="0.01" min="0" placeholder="e.g., 100.00">
                    </div>
                </div>
                
                <div class="sum-frontend-bulk-preview">
                    <h4>Preview:</h4>
                    <p id="frontend-bulk-preview-text">Units A1 to A10 will be created</p>
                </div>
            </div>
            
            <div class="sum-frontend-form-actions">
                <button type="button" class="sum-frontend-btn sum-frontend-btn-secondary" id="frontend-bulk-cancel-btn">Cancel</button>
                <button type="submit" class="sum-frontend-btn sum-frontend-btn-primary" id="frontend-bulk-save-btn">
                    <span class="sum-frontend-btn-icon">📦</span>
                    Create Units
                </button>
            </div>
        </form>
    </div>
</div>