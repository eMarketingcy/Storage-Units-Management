// Rendering and Filtering logic
// Assumes sumState and all UI/Action functions are available globally or via a shared namespace.

function toggleViewMode(mode) {
    const $wrapper = jQuery('#frontend-units-view-wrapper');
    const $gridBtn = jQuery('#frontend-view-grid');
    const $listBtn = jQuery('#frontend-view-list');
    
    if (mode === 'list') {
        $wrapper.removeClass('sum-view-mode-grid').addClass('sum-view-mode-list');
        $listBtn.addClass('active').removeClass('sum-frontend-btn-secondary');
        $gridBtn.removeClass('active').addClass('sum-frontend-btn-secondary');
        window.sumState.currentViewMode = 'list';
    } else {
        $wrapper.removeClass('sum-view-mode-list').addClass('sum-view-mode-grid');
        $gridBtn.addClass('active').removeClass('sum-frontend-btn-secondary');
        $listBtn.removeClass('active').addClass('sum-frontend-btn-secondary');
        window.sumState.currentViewMode = 'grid';
    }
    
    // Rerender units to ensure the correct view is populated
    renderUnits();
}

function getFilteredUnits(units) {
    const searchTerm = jQuery('#frontend-search-units').val().toLowerCase();
    const filterStatus = jQuery('#frontend-filter-status').val();
    
    if (!units || !Array.isArray(units)) { return []; }
    
    return units.filter(function(unit) {
        if (!unit || typeof unit !== 'object') return false;
        
        const matchesSearch = unit.unit_name.toLowerCase().includes(searchTerm) ||
                            (unit.website_name && unit.website_name.toLowerCase().includes(searchTerm)) ||
                            (unit.primary_contact_name && unit.primary_contact_name.toLowerCase().includes(searchTerm));
        
        let matchesFilter = true;
        
        if (filterStatus === 'occupied') {
            matchesFilter = parseInt(unit.is_occupied);
        } else if (filterStatus === 'available') {
            matchesFilter = !parseInt(unit.is_occupied);
        } else if (filterStatus === 'past_due') {
            if (parseInt(unit.is_occupied) && unit.period_until) {
                const today = new Date();
                const endDate = new Date(unit.period_until);
                matchesFilter = endDate < today;
            } else {
                matchesFilter = false;
            }
        } else if (filterStatus === 'unpaid') {
            matchesFilter = parseInt(unit.is_occupied) && unit.payment_status !== 'paid';
        }
        
        return matchesSearch && matchesFilter;
    });
}

function filterUnits(units) {
    renderUnits(units);
}

function renderUnits() {
    const units = window.sumState.getUnits();
    const filteredUnits = getFilteredUnits(units);
    const $grid = jQuery('#frontend-units-grid');
    const $tableBody = jQuery('#frontend-units-table-body');
    const $noUnits = jQuery('#frontend-no-units');
    
    $grid.empty();
    $tableBody.empty();
    
    if (filteredUnits.length === 0) {
        // If the total list is empty OR a filter yields no results
        const showNoUnits = jQuery('#frontend-search-units').val() === '' && jQuery('#frontend-filter-status').val() === 'all';

        jQuery('#frontend-units-view-wrapper').toggle(!showNoUnits);
        $noUnits.toggle(showNoUnits);
        
        // Hide grid/table if no filtered units, but show 'no results' if not on initial load.
        if (!showNoUnits) {
             $grid.html('<p class="sum-frontend-no-results">No units match your current filter and search criteria.</p>');
             $tableBody.html('<tr><td colspan="8" class="sum-frontend-no-results">No units match your current filter and search criteria.</td></tr>');
        }
        
        return;
    }
    
    $noUnits.hide();
    jQuery('#frontend-units-view-wrapper').show();

    let gridHtml = '';
    let tableHtml = '';

    filteredUnits.forEach(function(unit) {
        gridHtml += renderUnitCard(unit);
        tableHtml += renderUnitRow(unit);
    });
    
    $grid.html(gridHtml);
    $tableBody.html(tableHtml);
    
    // Bind events for edit/delete/toggle on both views
    bindEvents();
}

function renderUnitRow(unit) {
    const isOccupied = parseInt(unit.is_occupied);
    const monthlyPrice = unit.monthly_price ? `€${parseFloat(unit.monthly_price).toFixed(2)}` : 'N/A';
    const sizeSqm = unit.size ? unit.size : (unit.sqm ? `${unit.sqm} m²` : 'N/A');
    
    let statusDisplay = isOccupied ? 'Occupied' : 'Available';
    let statusClass = isOccupied ? 'sum-table-status-occupied' : 'sum-table-status-available';

    if (isOccupied) {
        const paymentStatus = unit.payment_status || 'paid';
        statusDisplay = paymentStatus.charAt(0).toUpperCase() + paymentStatus.slice(1);
        statusClass = paymentStatus === 'paid' ? 'sum-table-status-paid' : 
                      paymentStatus === 'overdue' ? 'sum-table-status-overdue' : 'sum-table-status-unpaid';

        // Check if past due
        if (unit.period_until) {
            const today = new Date();
            const endDate = new Date(unit.period_until);
            if (endDate < today && paymentStatus !== 'paid') {
                statusDisplay = 'Past Due';
                statusClass = 'sum-table-status-past-due'; // Use a dedicated past-due class for clarity
            }
        }
    }
    
    // Actions column buttons
    const actions = `
        <div class="sum-table-actions">
            <button type="button" class="frontend-edit-unit sum-frontend-card-btn" data-unit-id="${unit.id}" title="Edit">✏️</button>
            <button type="button" class="frontend-delete-unit sum-frontend-card-btn" data-unit-id="${unit.id}" title="Delete">🗑️</button>
        </div>
    `;

    return `
        <tr data-unit-id="${unit.id}">
            <td>${unit.id}</td>
            <td>${unit.unit_name}</td>
            <td>${sizeSqm}</td>
            <td>${unit.primary_contact_name || 'N/A'}</td>
            <td>${unit.period_until || 'N/A'}</td>
            <td>${monthlyPrice}</td>
            <td><span class="sum-table-status-badge ${statusClass}">${statusDisplay}</span></td>
            <td>${actions}</td>
        </tr>
    `;
}

function renderUnitCard(unit) {
    const isOccupied = parseInt(unit.is_occupied);
    const monthlyPrice = unit.monthly_price ? `€${parseFloat(unit.monthly_price).toFixed(2)}/month` : '';
    const statusText = isOccupied ? 'Occupied' : 'Available';
    const statusClass = isOccupied ? 'sum-frontend-status-occupied' : 'sum-frontend-status-available';
    
    // Payment status badge
    let paymentBadge = '';
    if (isOccupied) {
        const paymentStatus = unit.payment_status || 'paid';
        const badgeClass = paymentStatus === 'paid' ? 'sum-frontend-badge-paid' : 
                          paymentStatus === 'overdue' ? 'sum-frontend-badge-overdue' : 'sum-frontend-badge-unpaid';
        const badgeIcon = paymentStatus === 'paid' ? '✅' : paymentStatus === 'overdue' ? '⚠️' : '⏳';
        paymentBadge = `<span class="sum-frontend-badge ${badgeClass}">${badgeIcon} ${paymentStatus.charAt(0).toUpperCase() + paymentStatus.slice(1)}</span>`;
    }
    
    // Check if past due
    let pastDueBadge = '';
    if (isOccupied && unit.period_until) {
        const today = new Date();
        const endDate = new Date(unit.period_until);
        if (endDate < today) {
            pastDueBadge = '<span class="sum-frontend-badge sum-frontend-badge-past-due">⚠️ Past Due</span>';
        }
    }
    
    let contactInfo = '';
    if (isOccupied && unit.primary_contact_name) {
        contactInfo = `
            <div class="sum-frontend-contact-info">
                <div class="sum-frontend-contact-section">
                    <h4>👤 Primary Contact</h4>
                    <div class="sum-frontend-contact-details">
                        <div class="sum-frontend-contact-item">
                            <span class="sum-frontend-contact-label">Name:</span>
                            <span class="sum-frontend-contact-value">${unit.primary_contact_name}</span>
                        </div>
                        <div class="sum-frontend-contact-item">
                            <span class="sum-frontend-contact-label">Email:</span>
                            <span class="sum-frontend-contact-value">${unit.primary_contact_email || 'N/A'}</span>
                        </div>
                        <div class="sum-frontend-contact-item">
                            <span class="sum-frontend-contact-label">Phone:</span>
                            <span class="sum-frontend-contact-value">${unit.primary_contact_phone || 'N/A'}</span>
                        </div>
                        ${unit.period_from && unit.period_until ? `
                            <div class="sum-frontend-contact-item">
                                <span class="sum-frontend-contact-label">Period:</span>
                                <span class="sum-frontend-contact-value">${unit.period_from} - ${unit.period_until}</span>
                            </div>
                        ` : ''}
                    </div>
                    ${unit.primary_contact_email ? `
                        <div class="sum-frontend-contact-actions">
                            <button type="button" class="sum-frontend-contact-btn sum-frontend-contact-btn-invoice frontend-send-invoice-btn" data-unit-id="${unit.id}">
                                📧 Send Invoice
                            </button>
                            <button type="button" class="sum-frontend-contact-btn sum-frontend-contact-btn-pdf frontend-regenerate-pdf-btn" data-unit-id="${unit.id}">
                                📄 PDF
                            </button>
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
    }
    
    return `
        <div class="sum-frontend-card">
            <div class="sum-frontend-card-header">
                <div class="sum-frontend-card-title">
                    <div class="sum-frontend-card-info">
                        <h3>📦 ${unit.unit_name}</h3>
                        <p>${unit.size ? unit.size : ''}${unit.sqm ? ` • ${unit.sqm} m²` : ''}${monthlyPrice ? ` • ${monthlyPrice}` : ''}</p>
                        ${unit.website_name ? `<p><strong>Website:</strong> ${unit.website_name}</p>` : ''}
                    </div>
                    <div class="sum-frontend-card-actions">
                        <button type="button" class="sum-frontend-card-btn frontend-edit-unit" data-unit-id="${unit.id}" title="Edit">✏️</button>
                        <button type="button" class="sum-frontend-card-btn frontend-delete-unit" data-unit-id="${unit.id}" title="Delete">🗑️</button>
                    </div>
                </div>
                <div class="sum-frontend-badges">
                    ${paymentBadge}
                    ${pastDueBadge}
                </div>
                <div class="sum-frontend-unit-status">
                    <span class="${statusClass}">${statusText}</span>
                    <div class="sum-frontend-toggle-wrapper">
                        <input type="checkbox" class="sum-frontend-toggle-input frontend-toggle-occupancy" 
                               id="frontend-toggle-${unit.id}" data-unit-id="${unit.id}" ${isOccupied ? 'checked' : ''}>
                        <label for="frontend-toggle-${unit.id}" class="sum-frontend-toggle-label">
                            <span class="sum-frontend-toggle-slider"></span>
                        </label>
                    </div>
                </div>
            </div>
            
            ${contactInfo}
        </div>
    `;
}

function bindEvents() {
    const $ = jQuery;
    const actions = window.sumActions;
    
    // Grid/Table actions
    $('.frontend-edit-unit').off('click').on('click', function() {
        const unitId = $(this).data('unit-id');
        actions.editUnit(unitId);
    });
    
    $('.frontend-delete-unit').off('click').on('click', function() {
        const unitId = $(this).data('unit-id');
        actions.deleteUnit(unitId);
    });
    
    // Card/Contact actions
    $('.frontend-send-invoice-btn').off('click').on('click', function() {
        const unitId = $(this).data('unit-id');
        actions.sendInvoice(unitId);
    });
    
    $('.frontend-regenerate-pdf-btn').off('click').on('click', function() {
        const unitId = $(this).data('unit-id');
        actions.regeneratePdf(unitId);
    });
    
    $('.frontend-toggle-occupancy').off('click').on('click', function(e) {
        // Prevent event from bubbling up to the table row click if in list view
        e.stopPropagation(); 
        const unitId = $(this).data('unit-id');
        actions.toggleOccupancy(unitId);
    });
    
    // Table row click events (for editing from list view)
    $('.sum-frontend-table tbody tr').off('click').on('click', function(e) {
         // Only trigger if click wasn't on a button/link/toggle
        if (!$(e.target).closest('button, a, input, label').length) {
            const unitId = $(this).data('unit-id');
            if (unitId) {
                actions.editUnit(unitId);
            }
        }
    });
}

// Expose to global scope for use in sum-main.js
window.toggleViewMode = toggleViewMode;
window.filterUnits = filterUnits;
window.renderUnits = renderUnits;
window.bindEvents = bindEvents;