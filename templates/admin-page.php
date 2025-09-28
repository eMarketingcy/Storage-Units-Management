<div class="wrap">
    <h1>Storage Unit Manager</h1>
    <p>Manage your storage units at selfstorage.cy</p>
    
    <!-- Stats Cards -->
    <div class="sum-stats-grid">
        <div class="sum-stat-card">
            <div class="sum-stat-icon sum-stat-total">📦</div>
            <div class="sum-stat-content">
                <div class="sum-stat-label">Total Units</div>
                <div class="sum-stat-value" id="total-units">0</div>
            </div>
        </div>
        <div class="sum-stat-card">
            <div class="sum-stat-icon sum-stat-occupied">🔴</div>
            <div class="sum-stat-content">
                <div class="sum-stat-label">Occupied</div>
                <div class="sum-stat-value" id="occupied-units">0</div>
            </div>
        </div>
        <div class="sum-stat-card">
            <div class="sum-stat-icon sum-stat-available">🟢</div>
            <div class="sum-stat-content">
                <div class="sum-stat-label">Available</div>
                <div class="sum-stat-value" id="available-units">0</div>
            </div>
        </div>
        <div class="sum-stat-card">
            <div class="sum-stat-icon sum-stat-unpaid">⚠️</div>
            <div class="sum-stat-content">
                <div class="sum-stat-label">Unpaid</div>
                <div class="sum-stat-value" id="unpaid-units">0</div>
            </div>
        </div>
    </div>
    
    <!-- Controls -->
    <div class="sum-controls">
        <div class="sum-search-filter">
            <input type="text" id="search-units" placeholder="Search units..." class="sum-search-input">
            <select id="filter-status" class="sum-filter-select">
                <option value="all">All Units</option>
                <option value="occupied">Occupied</option>
                <option value="available">Available</option>
                <option value="past_due">Past Due</option>
                <option value="unpaid">Unpaid</option>
            </select>
        </div>
        <div class="sum-action-buttons">
            <button type="button" class="button button-secondary" id="bulk-add-btn">
                <span class="dashicons dashicons-plus-alt2"></span> Bulk Add
            </button>
            <button type="button" class="button button-primary" id="add-unit-btn">
                <span class="dashicons dashicons-plus-alt"></span> Add Unit
            </button>
        </div>
    </div>
    
    <!-- Units Grid -->
    <div id="units-grid" class="sum-units-grid">
        <!-- Units will be loaded here via JavaScript -->
    </div>
    
    <!-- No Units Message -->
    <div id="no-units-message" class="sum-no-units" style="display: none;">
        <div class="sum-no-units-icon">📦</div>
        <h3>No units found</h3>
        <p>Get started by creating your first storage unit.</p>
        <button type="button" class="button button-primary" id="add-first-unit-btn">
            <span class="dashicons dashicons-plus-alt"></span> Add Your First Unit
        </button>
    </div>
</div>

<!-- Unit Form Modal -->
<div id="unit-modal" class="sum-modal" style="display: none;">
    <div class="sum-modal-content">
        <div class="sum-modal-header">
            <h2 id="modal-title">Add New Storage Unit</h2>
            <button type="button" class="sum-modal-close">&times;</button>
        </div>
        
        <form id="unit-form" class="sum-form">
            <input type="hidden" id="unit-id" name="unit_id">
            
            <div class="sum-form-grid">
                <div class="sum-form-group">
                    <label for="unit-name">Unit Name *</label>
                    <input type="text" id="unit-name" name="unit_name" required placeholder="e.g., A1, B2, D2">
                </div>
                
                <div class="sum-form-group">
                    <label for="size">Size</label>
                    <input type="text" id="size" name="size" placeholder="e.g., Small, Medium, Large">
                </div>
                
                <div class="sum-form-group">
                    <label for="sqm">Square Meters</label>
                    <input type="number" id="sqm" name="sqm" step="0.1" placeholder="e.g., 10.5">
                </div>
                
                <div class="sum-form-group">
                    <label for="monthly-price">Monthly Price (€)</label>
                    <input type="number" id="monthly-price" name="monthly_price" step="0.01" min="0" placeholder="e.g., 100.00">
                </div>
                
                <div class="sum-form-group">
                    <label for="website-name">Website Name</label>
                    <input type="text" id="website-name" name="website_name" placeholder="Name as shown on website">
                </div>
            </div>
            
            <div class="sum-occupancy-toggle">
                <label for="is-occupied">Unit Occupied</label>
                <div class="sum-toggle-wrapper">
                    <input type="checkbox" id="is-occupied" name="is_occupied" class="sum-toggle-input">
                    <label for="is-occupied" class="sum-toggle-label">
                        <span class="sum-toggle-slider"></span>
                    </label>
                </div>
            </div>
            
            <div id="occupancy-details" class="sum-occupancy-details" style="display: none;">
                <div class="sum-form-grid">
                    <div class="sum-form-group">
                        <label for="period-from">Period From</label>
                        <input type="date" id="period-from" name="period_from">
                    </div>
                    
                    <div class="sum-form-group">
                        <label for="period-until">Period Until</label>
                        <input type="date" id="period-until" name="period_until">
                    </div>
                    
                    <div class="sum-form-group">
                        <label for="payment-status">Payment Status</label>
                        <select id="payment-status" name="payment_status">
                            <option value="paid">Paid</option>
                            <option value="unpaid">Unpaid</option>
                            <option value="overdue">Overdue</option>
                        </select>
                    </div>
                </div>
                
                <div class="sum-contact-section">
                    <h3>👤 Primary Contact</h3>
                    <div class="sum-form-grid">
                        <input type="text" id="primary-name" name="primary_contact_name" placeholder="Full Name">
                        <input type="tel" id="primary-phone" name="primary_contact_phone" placeholder="Phone">
                        <input type="tel" id="primary-whatsapp" name="primary_contact_whatsapp" placeholder="WhatsApp">
                        <input type="email" id="primary-email" name="primary_contact_email" placeholder="Email">
                    </div>
                </div>
                
                <div class="sum-secondary-toggle">
                    <label>
                        <input type="checkbox" id="has-secondary-contact"> Add Secondary Contact
                    </label>
                </div>
                
                <div id="secondary-contact-section" class="sum-contact-section" style="display: none;">
                    <h3>👥 Secondary Contact</h3>
                    <div class="sum-form-grid">
                        <input type="text" id="secondary-name" name="secondary_contact_name" placeholder="Full Name">
                        <input type="tel" id="secondary-phone" name="secondary_contact_phone" placeholder="Phone">
                        <input type="tel" id="secondary-whatsapp" name="secondary_contact_whatsapp" placeholder="WhatsApp">
                        <input type="email" id="secondary-email" name="secondary_contact_email" placeholder="Email">
                    </div>
                </div>
            </div>
            
            <div class="sum-form-actions">
                <button type="button" class="button" id="cancel-btn">Cancel</button>
                <button type="submit" class="button button-primary" id="save-btn">Save Unit</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Add Modal -->
<div id="bulk-add-modal" class="sum-modal" style="display: none;">
    <div class="sum-modal-content">
        <div class="sum-modal-header">
            <h2>Bulk Add Storage Units</h2>
            <button type="button" class="sum-modal-close">&times;</button>
        </div>
        
        <form id="bulk-add-form" class="sum-form">
            <div class="sum-form-grid">
                <div class="sum-form-group">
                    <label for="bulk-prefix">Prefix *</label>
                    <input type="text" id="bulk-prefix" name="prefix" required placeholder="e.g., A, B, C">
                </div>
                
                <div class="sum-form-group">
                    <label for="bulk-start">Start Number *</label>
                    <input type="number" id="bulk-start" name="start_number" required min="1" placeholder="1">
                </div>
                
                <div class="sum-form-group">
                    <label for="bulk-end">End Number *</label>
                    <input type="number" id="bulk-end" name="end_number" required min="1" placeholder="10">
                </div>
                
                <div class="sum-form-group">
                    <label for="bulk-size">Size (Optional)</label>
                    <input type="text" id="bulk-size" name="bulk_size" placeholder="e.g., Small, Medium">
                </div>
                
                <div class="sum-form-group">
                    <label for="bulk-sqm">Square Meters (Optional)</label>
                    <input type="number" id="bulk-sqm" name="bulk_sqm" step="0.1" placeholder="e.g., 5.0">
                </div>
                
                <div class="sum-form-group">
                    <label for="bulk-price">Monthly Price (€)</label>
                    <input type="number" id="bulk-price" name="bulk_price" step="0.01" min="0" placeholder="e.g., 100.00">
                </div>
            </div>
            
            <div class="sum-bulk-preview">
                <h4>Preview:</h4>
                <p id="bulk-preview-text">Units A1 to A10 will be created</p>
            </div>
            
            <div class="sum-form-actions">
                <button type="button" class="button" id="bulk-cancel-btn">Cancel</button>
                <button type="submit" class="button button-primary" id="bulk-save-btn">Create Units</button>
            </div>
        </form>
    </div>
</div>