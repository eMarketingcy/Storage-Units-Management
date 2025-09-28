jQuery(document).ready(function($) {
    let units = [];
    let editingUnit = null;
    
    // Initialize
    loadUnits();
    
    // Event listeners
    $('#add-unit-btn, #add-first-unit-btn').on('click', function() {
        openModal();
    });
    
    $('#bulk-add-btn').on('click', function() {
        openBulkModal();
    });
    
    $('.sum-modal-close, #cancel-btn, #bulk-cancel-btn').on('click', function() {
        closeModal();
        closeBulkModal();
    });
    
    $('#unit-modal, #bulk-add-modal').on('click', function(e) {
        if (e.target === this) {
            closeModal();
            closeBulkModal();
        }
    });
    
    $('#is-occupied').on('change', function() {
        toggleOccupancyDetails();
    });
    
    $('#has-secondary-contact').on('change', function() {
        toggleSecondaryContact();
    });
    
    $('#unit-form').on('submit', function(e) {
        e.preventDefault();
        saveUnit();
    });
    
    $('#bulk-add-form').on('submit', function(e) {
        e.preventDefault();
        bulkAddUnits();
    });
    
    $('#search-units').on('input', function() {
        filterUnits();
    });
    
    $('#filter-status').on('change', function() {
        filterUnits();
    });
    
    // Bulk add preview update
    $('#bulk-prefix, #bulk-start, #bulk-end').on('input', function() {
        updateBulkPreview();
    });
    
    // Functions
    function loadUnits() {
        $('#units-grid').html('<div class="sum-loading">Loading units...</div>');
        
        const filterValue = $('#filter-status').val();
        
        $.ajax({
            url: sum_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sum_get_units',
                nonce: sum_ajax.nonce,
                filter: filterValue
            },
            success: function(response) {
                if (response.success) {
                    units = response.data;
                    renderUnits();
                    updateStats();
                } else {
                    showError('Failed to load units');
                }
            },
            error: function() {
                showError('Failed to load units');
            }
        });
    }
    
    function renderUnits() {
        const filteredUnits = getFilteredUnits();
        const $grid = $('#units-grid');
        const $noUnits = $('#no-units-message');
        
        if (filteredUnits.length === 0) {
            $grid.hide();
            $noUnits.show();
            return;
        }
        
        $noUnits.hide();
        $grid.show();
        
        let html = '';
        filteredUnits.forEach(function(unit) {
            html += renderUnitCard(unit);
        });
        
        $grid.html(html);
        
        // Bind events
        $('.edit-unit').on('click', function() {
            const unitId = $(this).data('unit-id');
            editUnit(unitId);
        });
        
        $('.delete-unit').on('click', function() {
            const unitId = $(this).data('unit-id');
            deleteUnit(unitId);
        });
        
        $('.send-invoice-btn').on('click', function() {
            const unitId = $(this).data('unit-id');
            sendInvoice(unitId);
        });
        
        $('.regenerate-pdf-btn').on('click', function() {
            const unitId = $(this).data('unit-id');
            regeneratePdf(unitId);
        });
        
        $('.toggle-occupancy').on('click', function() {
            const unitId = $(this).data('unit-id');
            toggleOccupancy(unitId);
        });
    }
    
    function renderUnitCard(unit) {
        const isOccupied = parseInt(unit.is_occupied);
        const statusClass = isOccupied ? 'sum-status-occupied' : 'sum-status-available';
        const statusText = isOccupied ? 'Occupied' : 'Available';
        const toggleClass = isOccupied ? 'checked' : '';
        
        // Format price
        const monthlyPrice = unit.monthly_price ? `€${parseFloat(unit.monthly_price).toFixed(2)}/month` : '';
        
        // Payment status indicator
        let paymentBadge = '';
        if (isOccupied) {
            const paymentStatus = unit.payment_status || 'paid';
            const paymentClass = paymentStatus === 'paid' ? 'sum-payment-paid' : 'sum-payment-unpaid';
            const paymentText = paymentStatus.charAt(0).toUpperCase() + paymentStatus.slice(1);
            paymentBadge = `<span class="sum-payment-badge ${paymentClass}">${paymentText}</span>`;
        }
        
        // Check if past due
        let pastDueBadge = '';
        if (isOccupied && unit.period_until) {
            const today = new Date();
            const endDate = new Date(unit.period_until);
            if (endDate < today) {
                pastDueBadge = '<span class="sum-past-due-badge">Past Due</span>';
            }
        }
        
        let contactInfo = '';
        if (isOccupied && unit.primary_contact_name) {
            contactInfo = `
                <div class="sum-contact-info">
                    <h4>👤 Primary Contact</h4>
                    <div class="sum-contact-details">
                        <div><strong>Name:</strong> ${unit.primary_contact_name}</div>
                        <div><strong>Phone:</strong> ${unit.primary_contact_phone || 'N/A'}</div>
                        <div><strong>WhatsApp:</strong> ${unit.primary_contact_whatsapp || 'N/A'}</div>
                        <div><strong>Email:</strong> ${unit.primary_contact_email || 'N/A'}</div>
                        ${unit.period_from && unit.period_until ? 
                            `<div><strong>Period:</strong> ${unit.period_from} - ${unit.period_until}</div>` : ''}
                        ${unit.primary_contact_email ? 
                            `<button type="button" class="send-invoice-btn" data-unit-id="${unit.id}" style="margin-top: 10px; background: #00a32a; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer;">📧 Send Invoice</button>` : ''}
                        ${unit.primary_contact_email ? 
                            `<button type="button" class="regenerate-pdf-btn" data-unit-id="${unit.id}" style="margin-top: 10px; margin-left: 5px; background: #2271b1; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer;">📄 PDF</button>` : ''}
                    </div>
                    ${unit.secondary_contact_name ? `
                        <div class="sum-secondary-contact">
                            <h4>👥 Secondary Contact</h4>
                            <div class="sum-contact-details">
                                <div><strong>Name:</strong> ${unit.secondary_contact_name}</div>
                                <div><strong>Phone:</strong> ${unit.secondary_contact_phone || 'N/A'}</div>
                                <div><strong>Email:</strong> ${unit.secondary_contact_email || 'N/A'}</div>
                            </div>
                        </div>
                    ` : ''}
                </div>
            `;
        }
        
        return `
            <div class="sum-unit-card">
                <div class="sum-unit-header">
                    <div class="sum-unit-info">
                        <h3>${unit.unit_name}</h3>
                        <p>${unit.size ? unit.size : ''}${unit.sqm ? ` • ${unit.sqm} m²` : ''}${monthlyPrice ? ` • ${monthlyPrice}` : ''}</p>
                        ${unit.website_name ? `<p><strong>Website:</strong> ${unit.website_name}</p>` : ''}
                        <div class="sum-badges">
                            ${paymentBadge}
                            ${pastDueBadge}
                        </div>
                    </div>
                    <div class="sum-unit-actions">
                        <button type="button" class="edit-unit edit-btn" data-unit-id="${unit.id}" title="Edit">
                            <span class="dashicons dashicons-edit"></span>
                        </button>
                        <button type="button" class="delete-unit delete-btn" data-unit-id="${unit.id}" title="Delete">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </div>
                </div>
                
                <div class="sum-unit-status">
                    <span class="${statusClass}">${statusText}</span>
                    <div class="sum-toggle-wrapper">
                        <input type="checkbox" class="sum-toggle-input toggle-occupancy" 
                               id="toggle-${unit.id}" data-unit-id="${unit.id}" ${toggleClass}>
                        <label for="toggle-${unit.id}" class="sum-toggle-label">
                            <span class="sum-toggle-slider"></span>
                        </label>
                    </div>
                </div>
                
                ${contactInfo}
            </div>
        `;
    }
    
    function getFilteredUnits() {
        const searchTerm = $('#search-units').val().toLowerCase();
        
        if (!units || !Array.isArray(units)) {
            return [];
        }
        
        return units.filter(function(unit) {
            if (!unit || typeof unit !== 'object') return false;
            
            return (unit.unit_name && unit.unit_name.toLowerCase().includes(searchTerm)) ||
                   (unit.website_name && unit.website_name.toLowerCase().includes(searchTerm)) ||
                   (unit.primary_contact_name && unit.primary_contact_name.toLowerCase().includes(searchTerm));
        });
    }
    
    function filterUnits() {
        renderUnits();
    }
    
    function updateStats() {
        const total = units.length;
        const occupied = units.filter(unit => parseInt(unit.is_occupied)).length;
        const available = total - occupied;
        const unpaid = units.filter(unit => parseInt(unit.is_occupied) && unit.payment_status !== 'paid').length;
        
        $('#total-units').text(total);
        $('#occupied-units').text(occupied);
        $('#available-units').text(available);
        $('#unpaid-units').text(unpaid);
    }
    
    function openModal(unit = null) {
        editingUnit = unit;
        
        if (unit) {
            $('#modal-title').text('Edit Storage Unit');
            populateForm(unit);
        } else {
            $('#modal-title').text('Add New Storage Unit');
            resetForm();
        }
        
        $('#unit-modal').show();
    }
    
    function closeModal() {
        $('#unit-modal').hide();
        editingUnit = null;
        resetForm();
    }
    
    function openBulkModal() {
        $('#bulk-add-modal').show();
        updateBulkPreview();
    }
    
    function closeBulkModal() {
        $('#bulk-add-modal').hide();
        $('#bulk-add-form')[0].reset();
    }
    
    function updateBulkPreview() {
        const prefix = $('#bulk-prefix').val() || 'A';
        const start = parseInt($('#bulk-start').val()) || 1;
        const end = parseInt($('#bulk-end').val()) || 10;
        
        const count = Math.max(0, end - start + 1);
        $('#bulk-preview-text').text(`Units ${prefix}${start} to ${prefix}${end} will be created (${count} units)`);
    }
    
    function bulkAddUnits() {
        const formData = $('#bulk-add-form').serialize();
        
        $.ajax({
            url: sum_ajax.ajax_url,
            type: 'POST',
            data: formData + '&action=sum_bulk_add_units&nonce=' + sum_ajax.nonce,
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
            }
        });
    }
    
    function populateForm(unit) {
        $('#unit-id').val(unit.id);
        $('#unit-name').val(unit.unit_name);
        $('#size').val(unit.size || '');
        $('#sqm').val(unit.sqm || '');
        $('#monthly-price').val(unit.monthly_price || '');
        $('#website-name').val(unit.website_name || '');
        $('#is-occupied').prop('checked', parseInt(unit.is_occupied));
        $('#period-from').val(unit.period_from || '');
        $('#period-until').val(unit.period_until || '');
        $('#payment-status').val(unit.payment_status || 'paid');
        $('#primary-name').val(unit.primary_contact_name || '');
        $('#primary-phone').val(unit.primary_contact_phone || '');
        $('#primary-whatsapp').val(unit.primary_contact_whatsapp || '');
        $('#primary-email').val(unit.primary_contact_email || '');
        $('#secondary-name').val(unit.secondary_contact_name || '');
        $('#secondary-phone').val(unit.secondary_contact_phone || '');
        $('#secondary-whatsapp').val(unit.secondary_contact_whatsapp || '');
        $('#secondary-email').val(unit.secondary_contact_email || '');
        
        const hasSecondary = unit.secondary_contact_name && unit.secondary_contact_name.trim() !== '';
        $('#has-secondary-contact').prop('checked', hasSecondary);
        
        toggleOccupancyDetails();
        toggleSecondaryContact();
    }
    
    function resetForm() {
        $('#unit-form')[0].reset();
        $('#unit-id').val('');
        $('#has-secondary-contact').prop('checked', false);
        $('#payment-status').val('paid');
        toggleOccupancyDetails();
        toggleSecondaryContact();
    }
    
    function toggleOccupancyDetails() {
        const isOccupied = $('#is-occupied').is(':checked');
        $('#occupancy-details').toggle(isOccupied);
    }
    
    function toggleSecondaryContact() {
        const hasSecondary = $('#has-secondary-contact').is(':checked');
        $('#secondary-contact-section').toggle(hasSecondary);
    }
    
    function saveUnit() {
        const formData = $('#unit-form').serialize();
        const isOccupied = $('#is-occupied').is(':checked') ? 1 : 0;
        
        $.ajax({
            url: sum_ajax.ajax_url,
            type: 'POST',
            data: formData + '&action=sum_save_unit&nonce=' + sum_ajax.nonce + '&is_occupied=' + isOccupied,
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
            url: sum_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sum_delete_unit',
                nonce: sum_ajax.nonce,
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
            url: sum_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sum_toggle_occupancy',
                nonce: sum_ajax.nonce,
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
    
    function showSuccess(message) {
        alert('Success: ' + message);
    }
    
    function showError(message) {
        alert('Error: ' + message);
    }
    
    function sendInvoice(unitId) {
        if (!confirm('Are you sure you want to send an invoice to this customer?')) {
            return;
        }
        
        $.ajax({
            url: sum_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sum_send_manual_invoice',
                nonce: sum_ajax.nonce,
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
        
        console.log('Starting PDF regeneration for unit:', unitId);
        
        $.ajax({
            url: sum_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sum_regenerate_pdf',
                nonce: sum_ajax.nonce,
                unit_id: unitId
            },
            success: function(response) {
                console.log('PDF regeneration response:', response);
                
                if (response.success) {
                    showSuccess(response.data.message);
                    
                    console.log('Download URL:', response.data.download_url);
                    console.log('File size:', response.data.file_size);
                    
                    // Try multiple download methods
                    if (response.data.download_url) {
                        // Method 1: Direct window.open
                        const newWindow = window.open(response.data.download_url, '_blank');
                        
                        // Method 2: Create download link as fallback
                        setTimeout(function() {
                            const link = document.createElement('a');
                            link.href = response.data.download_url;
                            link.download = response.data.filename;
                            link.target = '_blank';
                            link.style.display = 'none';
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);
                        }, 1000);
                        
                        // Method 3: Show direct link to user
                        showSuccess(response.data.message + ' - <a href="' + response.data.download_url + '" target="_blank">Click here if download doesn\'t start</a>');
                    } else {
                        showError('PDF generated but download URL is missing');
                    }
                } else {
                    console.error('PDF generation failed:', response.data);
                    showError(response.data);
                }
            },
            error: function() {
                console.error('AJAX request failed');
                showError('Failed to generate PDF');
            }
        });
    }
});