jQuery(document).ready(function($) {
    let pallets = [];
    let editingPallet = null;
    
    // Initialize
    loadPallets();
    
    // Event listeners
    $('#frontend-add-pallet-btn, #frontend-add-first-pallet-btn').on('click', function() {
        openModal();
    });
    
    $('.sum-pallet-modal-close, #frontend-pallet-cancel-btn').on('click', function() {
        closeModal();
    });
    
    $('.sum-pallet-modal-overlay').on('click', function() {
        closeModal();
    });
    
    $('#frontend-pallet-form').on('submit', function(e) {
        e.preventDefault();
        savePallet();
    });
    
    $('#frontend-search-pallets').on('input', function() {
        filterPallets();
    });
    
    $('#frontend-filter-status').on('change', function() {
        filterPallets();
    });
    
    // Generate pallet name when customer name changes
    $('#frontend-primary-name').on('blur', function() {
        const customerName = $(this).val();
        if (customerName && !$('#frontend-pallet-name').val()) {
            generatePalletName(customerName);
        }
    });
    
    // Toggle secondary contact
    $('#frontend-has-secondary-contact').on('change', function() {
        toggleSecondaryContact();
    });
    
    // Functions
    function loadPallets() {
        $('#frontend-pallets-grid').html('<div class="sum-pallet-loading">Loading pallets...</div>');
        
        const filterValue = $('#frontend-filter-status').val();
        
        $.ajax({
            url: sum_pallet_frontend_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sum_get_pallets_frontend',
                nonce: sum_pallet_frontend_ajax.nonce,
                filter: filterValue
            },
            success: function(response) {
                if (response.success) {
                    pallets = response.data;
                    renderPallets();
                    updateStats();
                } else {
                    if (response.data === 'Not authenticated' || response.data === 'Access denied') {
                        showError('Access denied. Please refresh the page and try again.');
                    } else {
                        showError('Failed to load pallets');
                    }
                }
            },
            error: function() {
                showError('Failed to load pallets');
            }
        });
    }
    
    function renderPallets() {
        const filteredPallets = getFilteredPallets();
        const $grid = $('#frontend-pallets-grid');
        const $noPallets = $('#frontend-no-pallets');
        
        if (filteredPallets.length === 0) {
            $grid.hide();
            $noPallets.show();
            return;
        }
        
        $noPallets.hide();
        $grid.show();
        
        let html = '';
        filteredPallets.forEach(function(pallet) {
            html += renderPalletCard(pallet);
        });
        
        $grid.html(html);
        
        // Bind events
        $('.frontend-edit-pallet').on('click', function() {
            const palletId = $(this).data('pallet-id');
            editPallet(palletId);
        });
        
        $('.frontend-delete-pallet').on('click', function() {
            const palletId = $(this).data('pallet-id');
            deletePallet(palletId);
        });
        
        $('.frontend-send-pallet-invoice-btn').on('click', function() {
            const palletId = $(this).data('pallet-id');
            sendPalletInvoice(palletId);
        });
        
        $('.frontend-regenerate-pallet-pdf-btn').on('click', function() {
            const palletId = $(this).data('pallet-id');
            regeneratePalletPdf(palletId);
        });
    }
    
    function renderPalletCard(pallet) {
        const monthlyPrice = pallet.monthly_price ? `€${parseFloat(pallet.monthly_price).toFixed(2)}/month` : '';
        
        // Payment status badge
        let paymentBadge = '';
        const paymentStatus = pallet.payment_status || 'paid';
        const badgeClass = paymentStatus === 'paid' ? 'sum-pallet-badge-paid' : 
                          paymentStatus === 'overdue' ? 'sum-pallet-badge-overdue' : 'sum-pallet-badge-unpaid';
        const badgeIcon = paymentStatus === 'paid' ? '✅' : paymentStatus === 'overdue' ? '⚠️' : '⏳';
        paymentBadge = `<span class="sum-pallet-badge ${badgeClass}">${badgeIcon} ${paymentStatus}</span>`;
        
        // Check if past due
        let pastDueBadge = '';
        if (pallet.period_until) {
            const today = new Date();
            const endDate = new Date(pallet.period_until);
            if (endDate < today) {
                pastDueBadge = '<span class="sum-pallet-badge sum-pallet-badge-past-due">⚠️ Past Due</span>';
            }
        }
        
        let contactInfo = '';
        if (pallet.primary_contact_name) {
            contactInfo = `
                <div class="sum-pallet-contact-info">
                    <div class="sum-pallet-contact-section">
                        <h4>👤 Primary Contact</h4>
                        <div class="sum-pallet-contact-details">
                            <div class="sum-pallet-contact-item">
                                <span class="sum-pallet-contact-label">Name:</span>
                                <span class="sum-pallet-contact-value">${pallet.primary_contact_name}</span>
                            </div>
                            <div class="sum-pallet-contact-item">
                                <span class="sum-pallet-contact-label">Phone:</span>
                                <span class="sum-pallet-contact-value">${pallet.primary_contact_phone || 'N/A'}</span>
                            </div>
                            <div class="sum-pallet-contact-item">
                                <span class="sum-pallet-contact-label">WhatsApp:</span>
                                <span class="sum-pallet-contact-value">${pallet.primary_contact_whatsapp || 'N/A'}</span>
                            </div>
                            <div class="sum-pallet-contact-item">
                                <span class="sum-pallet-contact-label">Email:</span>
                                <span class="sum-pallet-contact-value">${pallet.primary_contact_email || 'N/A'}</span>
                            </div>
                            ${pallet.period_from && pallet.period_until ? `
                                <div class="sum-pallet-contact-item">
                                    <span class="sum-pallet-contact-label">Period:</span>
                                    <span class="sum-pallet-contact-value">${pallet.period_from} - ${pallet.period_until}</span>
                                </div>
                            ` : ''}
                        </div>
                        ${pallet.primary_contact_email ? `
                            <div class="sum-pallet-contact-actions">
                                <button type="button" class="sum-pallet-contact-btn sum-pallet-contact-btn-invoice frontend-send-pallet-invoice-btn" data-pallet-id="${pallet.id}">
                                    📧 Send Invoice
                                </button>
                                <button type="button" class="sum-pallet-contact-btn sum-pallet-contact-btn-pdf frontend-regenerate-pallet-pdf-btn" data-pallet-id="${pallet.id}">
                                    📄 PDF
                                </button>
                            </div>
                        ` : ''}
                    </div>
                    ${pallet.secondary_contact_name ? `
                        <div class="sum-pallet-contact-section">
                            <h4>👥 Secondary Contact</h4>
                            <div class="sum-pallet-contact-details">
                                <div class="sum-pallet-contact-item">
                                    <span class="sum-pallet-contact-label">Name:</span>
                                    <span class="sum-pallet-contact-value">${pallet.secondary_contact_name}</span>
                                </div>
                                <div class="sum-pallet-contact-item">
                                    <span class="sum-pallet-contact-label">Phone:</span>
                                    <span class="sum-pallet-contact-value">${pallet.secondary_contact_phone || 'N/A'}</span>
                                </div>
                                <div class="sum-pallet-contact-item">
                                    <span class="sum-pallet-contact-label">Email:</span>
                                    <span class="sum-pallet-contact-value">${pallet.secondary_contact_email || 'N/A'}</span>
                                </div>
                            </div>
                        </div>
                    ` : ''}
                </div>
            `;
        }
        
        return `
            <div class="sum-pallet-card">
                <div class="sum-pallet-card-header">
                    <div class="sum-pallet-card-title">
                        <div class="sum-pallet-card-info">
                            <h3>🟠 ${pallet.pallet_name}</h3>
                            <p>${pallet.pallet_type} Pallet • ${pallet.actual_height}m (${pallet.charged_height}m) • ${parseFloat(pallet.cubic_meters).toFixed(2)} m³${monthlyPrice ? ` • ${monthlyPrice}` : ''}</p>
                        </div>
                        <div class="sum-pallet-card-actions">
                            <button type="button" class="sum-pallet-card-btn frontend-edit-pallet" data-pallet-id="${pallet.id}" title="Edit">
                                ✏️
                            </button>
                            <button type="button" class="sum-pallet-card-btn frontend-delete-pallet" data-pallet-id="${pallet.id}" title="Delete">
                                🗑️
                            </button>
                        </div>
                    </div>
                    <div class="sum-pallet-badges">
                        ${paymentBadge}
                        ${pastDueBadge}
                    </div>
                </div>
                
                ${contactInfo}
            </div>
        `;
    }
    
    function getFilteredPallets() {
        const searchTerm = $('#frontend-search-pallets').val().toLowerCase();
        const filterStatus = $('#frontend-filter-status').val();
        
        return pallets.filter(function(pallet) {
            const matchesSearch = pallet.pallet_name.toLowerCase().includes(searchTerm) ||
                                (pallet.primary_contact_name && pallet.primary_contact_name.toLowerCase().includes(searchTerm));
            
            let matchesFilter = true;
            
            if (filterStatus === 'eu') {
                matchesFilter = pallet.pallet_type === 'EU';
            } else if (filterStatus === 'us') {
                matchesFilter = pallet.pallet_type === 'US';
            } else if (filterStatus === 'past_due') {
                if (pallet.period_until) {
                    const today = new Date();
                    const endDate = new Date(pallet.period_until);
                    matchesFilter = endDate < today;
                } else {
                    matchesFilter = false;
                }
            } else if (filterStatus === 'unpaid') {
                matchesFilter = pallet.payment_status !== 'paid';
            }
            
            return matchesSearch && matchesFilter;
        });
    }
    
    function filterPallets() {
        renderPallets();
    }
    
    function updateStats() {
        const total = pallets.length;
        const unpaid = pallets.filter(pallet => pallet.payment_status !== 'paid').length;
        const eu = pallets.filter(pallet => pallet.pallet_type === 'EU').length;
        const us = pallets.filter(pallet => pallet.pallet_type === 'US').length;
        
        $('#frontend-total-pallets').text(total);
        $('#frontend-unpaid-pallets').text(unpaid);
        $('#frontend-eu-pallets').text(eu);
        $('#frontend-us-pallets').text(us);
    }
    
    function openModal(pallet = null) {
        editingPallet = pallet;
        
        if (pallet) {
            $('#frontend-pallet-modal-title').text('Edit Pallet Storage');
            populateForm(pallet);
        } else {
            $('#frontend-pallet-modal-title').text('Add New Pallet Storage');
            resetForm();
        }
        
        $('#frontend-pallet-modal').show();
        $('body').css('overflow', 'hidden');
    }
    
    function closeModal() {
        $('#frontend-pallet-modal').hide();
        $('body').css('overflow', 'auto');
        editingPallet = null;
        resetForm();
    }
    
    function populateForm(pallet) {
        $('#frontend-pallet-id').val(pallet.id);
        $('#frontend-pallet-name').val(pallet.pallet_name);
        $('#frontend-pallet-type').val(pallet.pallet_type || 'EU');
        $('#frontend-actual-height').val(pallet.actual_height || '');
        $('#frontend-period-from').val(pallet.period_from || '');
        $('#frontend-period-until').val(pallet.period_until || '');
        $('#frontend-payment-status').val(pallet.payment_status || 'paid');
        $('#frontend-primary-name').val(pallet.primary_contact_name || '');
        $('#frontend-primary-phone').val(pallet.primary_contact_phone || '');
        $('#frontend-primary-whatsapp').val(pallet.primary_contact_whatsapp || '');
        $('#frontend-primary-email').val(pallet.primary_contact_email || '');
        $('#frontend-secondary-name').val(pallet.secondary_contact_name || '');
        $('#frontend-secondary-phone').val(pallet.secondary_contact_phone || '');
        $('#frontend-secondary-whatsapp').val(pallet.secondary_contact_whatsapp || '');
        $('#frontend-secondary-email').val(pallet.secondary_contact_email || '');
        
        const hasSecondary = pallet.secondary_contact_name && pallet.secondary_contact_name.trim() !== '';
        $('#frontend-has-secondary-contact').prop('checked', hasSecondary);
        
        toggleSecondaryContact();
    }
    
    function resetForm() {
        $('#frontend-pallet-form')[0].reset();
        $('#frontend-pallet-id').val('');
        $('#frontend-has-secondary-contact').prop('checked', false);
        $('#frontend-payment-status').val('paid');
        $('#frontend-pallet-type').val('EU');
        toggleSecondaryContact();
    }
    
    function toggleSecondaryContact() {
        const hasSecondary = $('#frontend-has-secondary-contact').is(':checked');
        $('#frontend-secondary-contact-section').toggle(hasSecondary);
    }
    
    function generatePalletName(customerName) {
        $.ajax({
            url: sum_pallet_frontend_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sum_generate_pallet_name_frontend',
                nonce: sum_pallet_frontend_ajax.nonce,
                customer_name: customerName
            },
            success: function(response) {
                if (response.success) {
                    $('#frontend-pallet-name').val(response.data);
                }
            }
        });
    }
    
    function savePallet() {
        const formData = $('#frontend-pallet-form').serialize();
        
        // Show loading state
        const $saveBtn = $('#frontend-pallet-save-btn');
        const originalText = $saveBtn.html();
        $saveBtn.html('<span class="sum-pallet-btn-icon">⏳</span> Saving...').prop('disabled', true);
        
        $.ajax({
            url: sum_pallet_frontend_ajax.ajax_url,
            type: 'POST',
            data: formData + '&action=sum_save_pallet_frontend&nonce=' + sum_pallet_frontend_ajax.nonce,
            success: function(response) {
                if (response.success) {
                    closeModal();
                    loadPallets();
                    showSuccess(response.data);
                } else {
                    showError(response.data);
                }
            },
            error: function() {
                showError('Failed to save pallet');
            },
            complete: function() {
                $saveBtn.html(originalText).prop('disabled', false);
            }
        });
    }
    
    function editPallet(palletId) {
        const pallet = pallets.find(p => p.id == palletId);
        if (pallet) {
            openModal(pallet);
        }
    }
    
    function deletePallet(palletId) {
        if (!confirm('Are you sure you want to delete this pallet storage?')) {
            return;
        }
        
        $.ajax({
            url: sum_pallet_frontend_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sum_delete_pallet_frontend',
                nonce: sum_pallet_frontend_ajax.nonce,
                pallet_id: palletId
            },
            success: function(response) {
                if (response.success) {
                    loadPallets();
                    showSuccess(response.data);
                } else {
                    showError(response.data);
                }
            },
            error: function() {
                showError('Failed to delete pallet');
            }
        });
    }
    
    function sendPalletInvoice(palletId) {
        if (!confirm('Are you sure you want to send an invoice to this customer?')) {
            return;
        }
        
        $.ajax({
            url: sum_pallet_frontend_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sum_send_pallet_invoice_frontend',
                nonce: sum_pallet_frontend_ajax.nonce,
                pallet_id: palletId
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
    
    function regeneratePalletPdf(palletId) {
        if (!confirm('Generate a new PDF invoice for this pallet?')) {
            return;
        }
        
        $.ajax({
            url: sum_pallet_frontend_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sum_regenerate_pallet_pdf_frontend',
                nonce: sum_pallet_frontend_ajax.nonce,
                pallet_id: palletId
            },
            success: function(response) {
                if (response.success) {
                    showSuccess(response.data.message);
                    
                    if (response.data.download_url) {
                        // Try multiple download methods
                        window.open(response.data.download_url, '_blank');
                        
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
    
    function showSuccess(message) {
        // Create a modern toast notification
        const toast = $(`
            <div class="sum-pallet-toast sum-pallet-toast-success">
                <div class="sum-pallet-toast-icon">✅</div>
                <div class="sum-pallet-toast-message">${message}</div>
            </div>
        `);
        
        $('body').append(toast);
        
        setTimeout(() => {
            toast.addClass('sum-pallet-toast-show');
        }, 100);
        
        setTimeout(() => {
            toast.removeClass('sum-pallet-toast-show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
    
    function showError(message) {
        // Create a modern toast notification
        const toast = $(`
            <div class="sum-pallet-toast sum-pallet-toast-error">
                <div class="sum-pallet-toast-icon">❌</div>
                <div class="sum-pallet-toast-message">${message}</div>
            </div>
        `);
        
        $('body').append(toast);
        
        setTimeout(() => {
            toast.addClass('sum-pallet-toast-show');
        }, 100);
        
        setTimeout(() => {
            toast.removeClass('sum-pallet-toast-show');
            setTimeout(() => toast.remove(), 300);
        }, 5000);
    }
});

// Add toast styles dynamically
jQuery(document).ready(function($) {
    const toastStyles = `
        <style>
        .sum-pallet-toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            z-index: 10000;
            transform: translateX(400px);
            opacity: 0;
            transition: all 0.3s ease;
            max-width: 400px;
        }
        
        .sum-pallet-toast-show {
            transform: translateX(0);
            opacity: 1;
        }
        
        .sum-pallet-toast-success {
            border-left: 4px solid #10b981;
        }
        
        .sum-pallet-toast-error {
            border-left: 4px solid #ef4444;
        }
        
        .sum-pallet-toast-icon {
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        
        .sum-pallet-toast-message {
            font-weight: 500;
            color: #1e293b;
            line-height: 1.4;
        }
        
        @media (max-width: 480px) {
            .sum-pallet-toast {
                left: 10px;
                right: 10px;
                max-width: none;
                transform: translateY(-100px);
            }
            
            .sum-pallet-toast-show {
                transform: translateY(0);
            }
        }
        </style>
    `;
    
    $('head').append(toastStyles);
});