jQuery(document).ready(function($) {
    let units = [];
    let editingUnit = null;
    let currentViewMode = localStorage.getItem('sum_frontend_view_mode') || 'grid'; // Default/Stored mode
    
    // --- View Mode Logic ---

    // Function to create a table row (list view)
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
                    statusClass = 'sum-table-status-overdue';
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

    function toggleViewMode(mode) {
        const $wrapper = $('#frontend-units-view-wrapper');
        const $gridBtn = $('#frontend-view-grid');
        const $listBtn = $('#frontend-view-list');
        
        if (mode === 'list') {
            $wrapper.removeClass('sum-view-mode-grid').addClass('sum-view-mode-list');
            $listBtn.addClass('active').removeClass('sum-frontend-btn-secondary');
            $gridBtn.removeClass('active').addClass('sum-frontend-btn-secondary');
            currentViewMode = 'list';
        } else {
            $wrapper.removeClass('sum-view-mode-list').addClass('sum-view-mode-grid');
            $gridBtn.addClass('active').removeClass('sum-frontend-btn-secondary');
            $listBtn.removeClass('active').addClass('sum-frontend-btn-secondary');
            currentViewMode = 'grid';
        }
        
        // Rerender units to populate the correct container
        renderUnits();
    }
    
    // ----------------------------------------------------------------
    
    // Load units on page load
    loadUnits();
    
    // Define the missing filterUnits function
    function filterUnits() {
        renderUnits();
    }
    
    // Search and filter
    $('#frontend-search-units').on('input', function() {
        filterUnits();
    });
    
    $('#frontend-filter-status').on('change', function() {
        filterUnits();
    });
    
    // --- FIX: View Toggle Event Handlers ---
    $('#frontend-view-grid').on('click', function() {
        toggleViewMode('grid');
        localStorage.setItem('sum_frontend_view_mode', 'grid');
    });
    
    $('#frontend-view-list').on('click', function() {
        toggleViewMode('list');
        localStorage.setItem('sum_frontend_view_mode', 'list');
    });
    // ---------------------------------------
    
    // Add unit functionality
    $('#frontend-add-unit-btn, #frontend-add-first-unit-btn').on('click', function() {
        openModal();
    });
    
    $('#frontend-bulk-add-btn').on('click', function() {
        openBulkModal();
    });
    
    // Modal events
    $('.sum-frontend-modal-close, #frontend-cancel-btn, #frontend-bulk-cancel-btn').on('click', function() {
        closeModal();
        closeBulkModal();
    });
    
    $('#frontend-unit-modal, #frontend-bulk-add-modal').on('click', function(e) {
        if ($(e.target).hasClass('sum-frontend-modal-overlay')) {
            closeModal();
            closeBulkModal();
        }
    });
    
    $('#frontend-is-occupied').on('change', function() {
        toggleOccupancyDetails();
    });
    
    $('#frontend-has-secondary-contact').on('change', function() {
        toggleSecondaryContact();
    });
    
    $('#frontend-unit-form').on('submit', function(e) {
        e.preventDefault();
        saveUnit();
    });
    
    $('#frontend-bulk-add-form').on('submit', function(e) {
        e.preventDefault();
        bulkAddUnits();
    });
    
    // Bulk add preview update
    $('#frontend-bulk-prefix, #frontend-bulk-start, #frontend-bulk-end').on('input', function() {
        updateBulkPreview();
    });
    
    function loadUnits() {
        const $grid = $('#frontend-units-grid');
        const $tableBody = $('#frontend-units-table-body');
        
        $grid.html('<div class="sum-frontend-loading">Loading units...</div>');
        $tableBody.html('<tr><td colspan="8" class="sum-frontend-loading">Loading units...</td></tr>');
        
        $.ajax({
            url: sum_frontend_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sum_get_units_frontend',
                nonce: sum_frontend_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    units = response.data;
                    // Initial toggle and render based on saved/default mode
                    toggleViewMode(currentViewMode); 
                    updateStats();
                } else {
                    const msg = response.data === 'Not authenticated' || response.data === 'Access denied' 
                                ? 'Access denied. Please refresh.' 
                                : 'Failed to load units: ' + response.data;
                    showError(msg);
                }
            },
            error: function() {
                showError('Failed to load units via AJAX.');
            }
        });
    }
    
    function renderUnits() {
        const filteredUnits = getFilteredUnits();
        const $grid = $('#frontend-units-grid');
        const $tableBody = $('#frontend-units-table-body');
        const $noUnits = $('#frontend-no-units');
        
        $grid.empty();
        $tableBody.empty();
        
        if (filteredUnits.length === 0) {
            $('#frontend-units-view-wrapper').hide();
            $noUnits.show();
            return;
        }
        
        $noUnits.hide();
        $('#frontend-units-view-wrapper').show();

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
            paymentBadge = `<span class="sum-frontend-badge ${badgeClass}">${badgeIcon} ${paymentStatus}</span>`;
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

    function getFilteredUnits() {
        const searchTerm = $('#frontend-search-units').val().toLowerCase();
        const filterStatus = $('#frontend-filter-status').val();
        
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
        
    function bindEvents() {
        // Grid/Table actions
        $('.frontend-edit-unit').off('click').on('click', function() {
            const unitId = $(this).data('unit-id');
            editUnit(unitId);
        });
        
        $('.frontend-delete-unit').off('click').on('click', function() {
            const unitId = $(this).data('unit-id');
            deleteUnit(unitId);
        });
        
        // Card/Contact actions
        $('.frontend-send-invoice-btn').off('click').on('click', function() {
            const unitId = $(this).data('unit-id');
            sendInvoice(unitId);
        });
        
        $('.frontend-regenerate-pdf-btn').off('click').on('click', function() {
            const unitId = $(this).data('unit-id');
            regeneratePdf(unitId);
        });
        
        $('.frontend-toggle-occupancy').off('click').on('click', function() {
            const unitId = $(this).data('unit-id');
            toggleOccupancy(unitId);
        });
        
        // Table row click events (for editing from list view)
        $('.sum-frontend-table tbody tr').off('click').on('click', function(e) {
             // Only trigger if click wasn't on a button/link/toggle
            if (!$(e.target).closest('button, a, input, label').length) {
                const unitId = $(this).data('unit-id');
                if (unitId) {
                    editUnit(unitId);
                }
            }
        });
    }
        
    function showSuccess(message) {
        // Create a modern toast notification
        const toast = $(`
            <div class="sum-frontend-toast sum-frontend-toast-success">
                <div class="sum-frontend-toast-icon">✅</div>
                <div class="sum-frontend-toast-message">${message}</div>
            </div>
        `);
        
        $('body').append(toast);
        
        setTimeout(() => { toast.addClass('sum-frontend-toast-show'); }, 100);
        setTimeout(() => { toast.removeClass('sum-frontend-toast-show'); setTimeout(() => toast.remove(), 300); }, 3000);
    }
    
    function showError(message) {
        // Create a modern toast notification
        const toast = $(`
            <div class="sum-frontend-toast sum-frontend-toast-error">
                <div class="sum-frontend-toast-icon">❌</div>
                <div class="sum-frontend-toast-message">${message}</div>
            </div>
        `);
        
        $('body').append(toast);
        
        setTimeout(() => { toast.addClass('sum-frontend-toast-show'); }, 100);
        setTimeout(() => { toast.removeClass('sum-frontend-toast-show'); setTimeout(() => toast.remove(), 300); }, 5000);
    }
    
    window.showSuccess = showSuccess;
    window.showError = showError;
        
    function updateStats() {
        const total = units.length;
        const occupied = units.filter(unit => parseInt(unit.is_occupied)).length;
        const available = total - occupied;
        const unpaid = units.filter(unit => parseInt(unit.is_occupied) && unit.payment_status !== 'paid').length;
        
        $('#frontend-total-units').text(total);
        $('#frontend-occupied-units').text(occupied);
        $('#frontend-available-units').text(available);
        $('#frontend-unpaid-units').text(unpaid);
    }
    
    function openModal(unit = null) {
        editingUnit = unit;
        
        if (unit) {
            $('#frontend-modal-title').text('Edit Storage Unit');
            populateForm(unit);
        } else {
            $('#frontend-modal-title').text('Add New Storage Unit');
            resetForm();
        }
        
        $('#frontend-unit-modal').show();
        $('body').css('overflow', 'hidden');
    }
    
    function closeModal() {
        $('#frontend-unit-modal').hide();
        $('body').css('overflow', 'auto');
        editingUnit = null;
        resetForm();
    }
    
    function openBulkModal() {
        $('#frontend-bulk-add-modal').show();
        $('body').css('overflow', 'hidden');
        updateBulkPreview();
    }
    
    function closeBulkModal() {
        $('#frontend-bulk-add-modal').hide();
        $('body').css('overflow', 'auto');
        $('#frontend-bulk-add-form')[0].reset();
    }
    
    function updateBulkPreview() {
        const prefix = $('#frontend-bulk-prefix').val() || 'A';
        const start = parseInt($('#frontend-bulk-start').val()) || 1;
        const end = parseInt($('#frontend-bulk-end').val()) || 10;
        
        const count = Math.max(0, end - start + 1);
        $('#frontend-bulk-preview-text').text(`Units ${prefix}${start} to ${prefix}${end} will be created (${count} units)`);
    }
    
    function populateForm(unit) {
        $('#frontend-unit-id').val(unit.id);
        $('#frontend-unit-name').val(unit.unit_name);
        $('#frontend-size').val(unit.size || '');
        $('#frontend-sqm').val(unit.sqm || '');
        $('#frontend-monthly-price').val(unit.monthly_price || '');
        $('#frontend-website-name').val(unit.website_name || '');
        $('#frontend-is-occupied').prop('checked', parseInt(unit.is_occupied));
        $('#frontend-period-from').val(unit.period_from || '');
        $('#frontend-period-until').val(unit.period_until || '');
        $('#frontend-payment-status').val(unit.payment_status || 'paid');
        $('#frontend-primary-name').val(unit.primary_contact_name || '');
        $('#frontend-primary-phone').val(unit.primary_contact_phone || '');
        $('#frontend-primary-whatsapp').val(unit.primary_contact_whatsapp || '');
        $('#frontend-primary-email').val(unit.primary_contact_email || '');
        $('#frontend-secondary-name').val(unit.secondary_contact_name || '');
        $('#frontend-secondary-phone').val(unit.secondary_contact_phone || '');
        $('#frontend-secondary-whatsapp').val(unit.secondary_contact_whatsapp || '');
        $('#frontend-secondary-email').val(unit.secondary_contact_email || '');
        
        const hasSecondary = unit.secondary_contact_name && unit.secondary_contact_name.trim() !== '';
        $('#frontend-has-secondary-contact').prop('checked', hasSecondary);
        
        toggleOccupancyDetails();
        toggleSecondaryContact();
    }
    
    function resetForm() {
        $('#frontend-unit-form')[0].reset();
        $('#frontend-unit-id').val('');
        $('#frontend-has-secondary-contact').prop('checked', false);
        $('#frontend-is-occupied').prop('checked', false);
        $('#frontend-payment-status').val('paid');
        toggleOccupancyDetails();
        toggleSecondaryContact();
    }
    
    function toggleOccupancyDetails() {
        const isOccupied = $('#frontend-is-occupied').is(':checked');
        $('#frontend-occupancy-details').toggle(isOccupied);
    }
    
    function toggleSecondaryContact() {
        const hasSecondary = $('#frontend-has-secondary-contact').is(':checked');
        $('#frontend-secondary-contact-section').toggle(hasSecondary);
    }
    
    function saveUnit() {
        const formData = $('#frontend-unit-form').serialize();
        const isOccupied = $('#frontend-is-occupied').is(':checked') ? 1 : 0;
        
        // Show loading state
        const $saveBtn = $('#frontend-save-btn');
        const originalText = $saveBtn.html();
        $saveBtn.html('<span class="sum-frontend-btn-icon">⏳</span> Saving...').prop('disabled', true);
        
        $.ajax({
            url: sum_frontend_ajax.ajax_url,
            type: 'POST',
            data: formData + '&action=sum_save_unit_frontend&nonce=' + sum_frontend_ajax.nonce + '&is_occupied=' + isOccupied,
            success: function(response) {
                if (response.success) {
                    closeModal();
                    loadUnits();
                    showSuccess(response.data);
                } else {
                    showError(response.data);
                }
            },
            error: function() {
                showError('Failed to save unit');
            },
            complete: function() {
                $saveBtn.html(originalText).prop('disabled', false);
            }
        });
    }
    
    function bulkAddUnits() {
        const formData = $('#frontend-bulk-add-form').serialize();
        
        // Show loading state
        const $saveBtn = $('#frontend-bulk-save-btn');
        const originalText = $saveBtn.html();
        $saveBtn.html('<span class="sum-frontend-btn-icon">⏳</span> Creating...').prop('disabled', true);
        
        $.ajax({
            url: sum_frontend_ajax.ajax_url,
            type: 'POST',
            data: formData + '&action=sum_bulk_add_units_frontend&nonce=' + sum_frontend_ajax.nonce,
            success: function(response) {
                if (response.success) {
                    closeBulkModal();
                    loadUnits();
                    showSuccess(response.data);
                } else {
                    showError(response.data);
                }
            },
            error: function() {
                showError('Failed to create units');
            },
            complete: function() {
                $saveBtn.html(originalText).prop('disabled', false);
            }
        });
    }
    
    function editUnit(unitId) {
        const unit = units.find(u => u.id == unitId);
        if (unit) {
            openModal(unit);
        }
    }
    
    function deleteUnit(unitId) {
        if (!confirm('Are you sure you want to delete this storage unit?')) {
            return;
        }
        
        $.ajax({
            url: sum_frontend_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sum_delete_unit_frontend',
                nonce: sum_frontend_ajax.nonce,
                unit_id: unitId
            },
            success: function(response) {
                if (response.success) {
                    loadUnits();
                    showSuccess(response.data);
                } else {
                    showError(response.data);
                }
            },
            error: function() {
                showError('Failed to delete unit');
            }
        });
    }
    
    function toggleOccupancy(unitId) {
        $.ajax({
            url: sum_frontend_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sum_toggle_occupancy_frontend',
                nonce: sum_frontend_ajax.nonce,
                unit_id: unitId
            },
            success: function(response) {
                if (response.success) {
                    loadUnits();
                    showSuccess(response.data);
                } else {
                    showError(response.data);
                }
            },
            error: function() {
                showError('Failed to update occupancy status');
            }
        });
    }
    
    function sendInvoice(unitId) {
        if (!confirm('Are you sure you want to send an invoice to this customer?')) {
            return;
        }
        
        $.ajax({
            url: sum_frontend_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sum_send_manual_invoice_frontend',
                nonce: sum_frontend_ajax.nonce,
                unit_id: unitId
            },
            success: function(response) {
                if (response.success) {
                    showSuccess(response.data);
                } else {
                    showError(response.data);
                }
            },
            error: function() {
                showError('Failed to send invoice');
            }
        });
    }
    
    function regeneratePdf(unitId) {
        if (!confirm('Generate a new PDF invoice for this unit?')) {
            return;
        }
        
        $.ajax({
            url: sum_frontend_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sum_regenerate_pdf_frontend',
                nonce: sum_frontend_ajax.nonce,
                unit_id: unitId
            },
            success: function(response) {
                if (response.success) {
                    showSuccess(response.data.message);
                    
                    if (response.data.download_url) {
                        window.open(response.data.download_url, '_blank');
                    }
                } else {
                    showError(response.data);
                }
            },
            error: function() {
                showError('Failed to generate PDF');
            }
        });
    }
});