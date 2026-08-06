/**
 * Main JavaScript file for PhD Time-Off Portal
 * Common functionality used across the application
 */

// ===================================
// Document Ready
// ===================================
$(document).ready(function() {
    // Initialize all components
    initMobileMenu();
    initUserMenu();
    initFlashMessages();
    initDataTables();
    initDeleteConfirmations();
    initFormValidation();
    initDateInputs();
    initTooltips();
    initButtonLoadingStates();
    initNavDropdowns();

    // Set CSRF token for AJAX requests
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });
});

// ===================================
// Mobile Menu Toggle
// ===================================
function initMobileMenu() {
    const menuButton = $('#mobile-menu-button');
    const mobileMenu = $('#mobile-menu');

    menuButton.on('click', function() {
        mobileMenu.toggleClass('hidden');

        // Toggle icon
        const icon = $(this).find('i');
        if (mobileMenu.hasClass('hidden')) {
            icon.removeClass('fa-times').addClass('fa-bars');
        } else {
            icon.removeClass('fa-bars').addClass('fa-times');
        }
    });

    // Close mobile menu when clicking outside
    $(document).on('click', function(event) {
        if (!$(event.target).closest('#mobile-menu-button, #mobile-menu').length) {
            mobileMenu.addClass('hidden');
            menuButton.find('i').removeClass('fa-times').addClass('fa-bars');
        }
    });
}

// ===================================
// User Menu Dropdown
// ===================================
function initUserMenu() {
    const userMenuButton = $('#user-menu-button');
    const userMenu = $('#user-menu');

    // Only initialize if elements exist
    if (userMenuButton.length === 0 || userMenu.length === 0) {
        return;
    }

    // Remove any existing handlers to prevent duplicates
    userMenuButton.off('click.userMenu');
    $(document).off('click.userMenu');

    userMenuButton.on('click.userMenu', function(e) {
        e.preventDefault();
        e.stopPropagation();
        userMenu.toggleClass('hidden');

        // Update aria-expanded attribute for accessibility
        const isExpanded = !userMenu.hasClass('hidden');
        userMenuButton.attr('aria-expanded', isExpanded);
    });

    // Close user menu when clicking outside
    $(document).on('click.userMenu', function(event) {
        if (!$(event.target).closest('#user-menu-button, #user-menu').length) {
            userMenu.addClass('hidden');
            userMenuButton.attr('aria-expanded', 'false');
        }
    });

    // Close on escape key
    $(document).on('keydown.userMenu', function(e) {
        if (e.key === 'Escape' && !userMenu.hasClass('hidden')) {
            userMenu.addClass('hidden');
            userMenuButton.attr('aria-expanded', 'false');
            userMenuButton.focus();
        }
    });
}

// ===================================
// Flash Messages
// ===================================
function initFlashMessages() {
    // Auto-hide flash messages after 5 seconds
    const flashMessages = $('.flash-message');

    if (flashMessages.length > 0) {
        setTimeout(function() {
            flashMessages.fadeOut('slow', function() {
                $(this).remove();
            });
        }, 5000);
    }
}

// Show flash message dynamically
function showFlashMessage(type, message, duration = 5000) {
    const colors = {
        success: { bg: 'bg-green-50', border: 'border-green-200', text: 'text-green-800', icon: 'fa-check-circle' },
        error: { bg: 'bg-red-50', border: 'border-red-200', text: 'text-red-800', icon: 'fa-exclamation-circle' },
        warning: { bg: 'bg-yellow-50', border: 'border-yellow-200', text: 'text-yellow-800', icon: 'fa-exclamation-triangle' },
        info: { bg: 'bg-blue-50', border: 'border-blue-200', text: 'text-blue-800', icon: 'fa-info-circle' }
    };

    const color = colors[type] || colors.info;

    const flashHtml = `
        <div class="flash-message mb-6 p-4 rounded-lg ${color.bg} border ${color.border}" role="alert">
            <div class="flex items-center">
                <i class="fas ${color.icon} ${color.text} mr-3"></i>
                <p class="text-sm ${color.text} flex-1">${escapeHtml(message)}</p>
                <button type="button" class="ml-3 inline-flex text-gray-400 hover:text-gray-600" onclick="this.parentElement.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
    `;

    $('#flash-message-container').html(flashHtml);

    // Auto-hide after duration
    if (duration > 0) {
        setTimeout(function() {
            $('.flash-message').fadeOut('slow', function() {
                $(this).remove();
            });
        }, duration);
    }
}

// ===================================
// DataTables Initialization
// ===================================
function initDataTables() {
    if ($.fn.DataTable) {
        // Initialize all tables with .datatable class
        $('.datatable').each(function() {
            if (!$.fn.DataTable.isDataTable(this)) {
                $(this).DataTable({
                    responsive: true,
                    pageLength: 25,
                    order: [[0, 'desc']], // Sort by first column descending by default
                    language: {
                        search: 'Search:',
                        lengthMenu: 'Show _MENU_ entries',
                        info: 'Showing _START_ to _END_ of _TOTAL_ entries',
                        infoEmpty: 'No entries available',
                        infoFiltered: '(filtered from _MAX_ total entries)',
                        paginate: {
                            first: 'First',
                            last: 'Last',
                            next: 'Next',
                            previous: 'Previous'
                        }
                    }
                });
            }
        });
    }
}

// ===================================
// Button Loading States
// ===================================
function initButtonLoadingStates() {
    // Add loading state to forms on submit
    $('form').on('submit', function(e) {
        const form = $(this);
        const submitBtn = form.find('button[type="submit"], input[type="submit"]');

        // Skip if form has noloading class or is invalid
        if (form.hasClass('noloading') || !form[0].checkValidity()) {
            return;
        }

        // Add loading state to submit button
        if (submitBtn.length && !submitBtn.hasClass('btn-loading')) {
            submitBtn.addClass('btn-loading');
            submitBtn.prop('disabled', true);

            // Store original text for potential restoration
            submitBtn.data('original-text', submitBtn.html());
        }
    });
}

// Manually set button loading state
function setButtonLoading(button, loading = true) {
    const $btn = $(button);

    if (loading) {
        if (!$btn.data('original-text')) {
            $btn.data('original-text', $btn.html());
        }
        $btn.addClass('btn-loading').prop('disabled', true);
    } else {
        $btn.removeClass('btn-loading').prop('disabled', false);
        if ($btn.data('original-text')) {
            $btn.html($btn.data('original-text'));
        }
    }
}

// ===================================
// Delete Confirmations
// ===================================
function initDeleteConfirmations() {
    // Confirm before delete
    $('.delete-confirm').on('click', function(e) {
        const message = $(this).data('confirm') || 'Are you sure you want to delete this item? This action cannot be undone.';
        if (!confirm(message)) {
            e.preventDefault();
            return false;
        }
    });

    // Confirm before form submission
    $('form.delete-form').on('submit', function(e) {
        const message = $(this).data('confirm') || 'Are you sure you want to delete this item? This action cannot be undone.';
        if (!confirm(message)) {
            e.preventDefault();
            return false;
        }
    });
}

// Generic confirm dialog
function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

// ===================================
// Form Validation
// ===================================
function initFormValidation() {
    // Add real-time validation to forms
    $('form.validate').on('submit', function(e) {
        let isValid = true;

        // Clear previous errors
        $(this).find('.form-error').remove();
        $(this).find('.error').removeClass('error');

        // Validate required fields
        $(this).find('[required]').each(function() {
            const field = $(this);
            const value = field.val().trim();

            if (!value) {
                isValid = false;
                markFieldError(field, 'This field is required');
            }
        });

        // Validate email fields
        $(this).find('input[type="email"]').each(function() {
            const field = $(this);
            const value = field.val().trim();

            if (value && !isValidEmail(value)) {
                isValid = false;
                markFieldError(field, 'Please enter a valid email address');
            }
        });

        // Validate date fields
        $(this).find('input[type="date"]').each(function() {
            const field = $(this);
            const value = field.val();

            if (value && !isValidDate(value)) {
                isValid = false;
                markFieldError(field, 'Please enter a valid date');
            }
        });

        // Validate start/end dates
        const startDate = $(this).find('input[name="start_date"]').val();
        const endDate = $(this).find('input[name="end_date"]').val();

        if (startDate && endDate && new Date(startDate) > new Date(endDate)) {
            isValid = false;
            markFieldError($(this).find('input[name="end_date"]'), 'End date must be after start date');
        }

        if (!isValid) {
            e.preventDefault();
            // Scroll to first error
            const firstError = $(this).find('.error').first();
            if (firstError.length) {
                $('html, body').animate({
                    scrollTop: firstError.offset().top - 100
                }, 500);
            }
        }
    });

    // Real-time validation on blur
    $('form.validate input, form.validate select, form.validate textarea').on('blur', function() {
        const field = $(this);
        validateField(field);
    });
}

function validateField(field) {
    const value = field.val().trim();
    let isValid = true;
    let errorMessage = '';

    // Clear previous error
    field.siblings('.form-error').remove();
    field.removeClass('error');

    // Check required
    if (field.prop('required') && !value) {
        isValid = false;
        errorMessage = 'This field is required';
    }

    // Check email
    if (field.attr('type') === 'email' && value && !isValidEmail(value)) {
        isValid = false;
        errorMessage = 'Please enter a valid email address';
    }

    // Check date
    if (field.attr('type') === 'date' && value && !isValidDate(value)) {
        isValid = false;
        errorMessage = 'Please enter a valid date';
    }

    if (!isValid) {
        markFieldError(field, errorMessage);
    }

    return isValid;
}

function markFieldError(field, message) {
    field.addClass('error');
    field.after('<div class="form-error">' + escapeHtml(message) + '</div>');
}

// ===================================
// Date Input Helpers
// ===================================
function initDateInputs() {
    // Set min date to today for future date inputs
    $('input[type="date"].future-only').attr('min', getTodayDate());

    // Set max date to today for past date inputs
    $('input[type="date"].past-only').attr('max', getTodayDate());
}

function getTodayDate() {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

// ===================================
// AJAX Helper Functions
// ===================================

// Generic AJAX GET request
function ajaxGet(url, successCallback, errorCallback) {
    showLoading();

    $.ajax({
        url: url,
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            hideLoading();
            if (successCallback) {
                successCallback(response);
            }
        },
        error: function(xhr, status, error) {
            hideLoading();
            handleAjaxError(xhr, error);
            if (errorCallback) {
                errorCallback(xhr, error);
            }
        }
    });
}

// Generic AJAX POST request
function ajaxPost(url, data, successCallback, errorCallback) {
    showLoading();

    $.ajax({
        url: url,
        method: 'POST',
        data: data,
        dataType: 'json',
        success: function(response) {
            hideLoading();
            if (response.success && response.message) {
                showFlashMessage('success', response.message);
            }
            if (successCallback) {
                successCallback(response);
            }
        },
        error: function(xhr, status, error) {
            hideLoading();
            handleAjaxError(xhr, error);
            if (errorCallback) {
                errorCallback(xhr, error);
            }
        }
    });
}

// Generic AJAX DELETE request
function ajaxDelete(url, successCallback, errorCallback) {
    showLoading();

    $.ajax({
        url: url,
        method: 'DELETE',
        dataType: 'json',
        success: function(response) {
            hideLoading();
            if (response.success && response.message) {
                showFlashMessage('success', response.message);
            }
            if (successCallback) {
                successCallback(response);
            }
        },
        error: function(xhr, status, error) {
            hideLoading();
            handleAjaxError(xhr, error);
            if (errorCallback) {
                errorCallback(xhr, error);
            }
        }
    });
}

// Handle AJAX errors
function handleAjaxError(xhr, error) {
    let message = 'An error occurred. Please try again.';

    if (xhr.responseJSON && xhr.responseJSON.message) {
        message = xhr.responseJSON.message;
    } else if (xhr.status === 403) {
        message = 'Access denied. You do not have permission to perform this action.';
    } else if (xhr.status === 404) {
        message = 'Resource not found.';
    } else if (xhr.status === 500) {
        message = 'Server error. Please contact raj.saroj@iitb.ac.in ';
    }

    showFlashMessage('error', message);
}

// ===================================
// Loading Spinner
// ===================================
function showLoading() {
    $('#loading-spinner').removeClass('hidden');
}

function hideLoading() {
    $('#loading-spinner').addClass('hidden');
}

// ===================================
// Tooltips (if using a tooltip library)
// ===================================
function initTooltips() {
    // Add title attributes for basic tooltips
    $('[data-tooltip]').each(function() {
        $(this).attr('title', $(this).data('tooltip'));
    });
}

// ===================================
// Validation Helpers
// ===================================
function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

function isValidDate(dateString) {
    const date = new Date(dateString);
    return date instanceof Date && !isNaN(date);
}

function isValidUrl(url) {
    try {
        new URL(url);
        return true;
    } catch (e) {
        return false;
    }
}

// ===================================
// Utility Functions
// ===================================

// Escape HTML to prevent XSS
function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}

// Format date
function formatDate(dateString) {
    const date = new Date(dateString);
    const options = { year: 'numeric', month: 'short', day: 'numeric' };
    return date.toLocaleDateString('en-US', options);
}

// Format datetime
function formatDateTime(dateString) {
    const date = new Date(dateString);
    const options = {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    };
    return date.toLocaleDateString('en-US', options);
}

// Calculate days between two dates
function daysBetween(startDate, endDate) {
    const start = new Date(startDate);
    const end = new Date(endDate);
    const diffTime = Math.abs(end - start);
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    return diffDays + 1; // Include both start and end date
}

// Debounce function for search inputs
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Copy to clipboard
function copyToClipboard(text) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function() {
            showFlashMessage('success', 'Copied to clipboard!', 2000);
        }, function(err) {
            showFlashMessage('error', 'Failed to copy to clipboard');
        });
    } else {
        // Fallback for older browsers
        const textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed";
        textArea.style.opacity = "0";
        document.body.appendChild(textArea);
        textArea.select();
        try {
            document.execCommand('copy');
            showFlashMessage('success', 'Copied to clipboard!', 2000);
        } catch (err) {
            showFlashMessage('error', 'Failed to copy to clipboard');
        }
        document.body.removeChild(textArea);
    }
}

// Print page
function printPage() {
    window.print();
}

// Export table to CSV
function exportTableToCSV(tableId, filename) {
    const table = document.getElementById(tableId);
    let csv = [];

    // Get headers
    const headers = [];
    const headerCells = table.querySelectorAll('thead th');
    headerCells.forEach(cell => {
        headers.push(cell.textContent.trim());
    });
    csv.push(headers.join(','));

    // Get rows
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const rowData = [];
        const cells = row.querySelectorAll('td');
        cells.forEach(cell => {
            let cellText = cell.textContent.trim();
            // Escape commas and quotes
            cellText = cellText.replace(/"/g, '""');
            if (cellText.includes(',') || cellText.includes('"')) {
                cellText = '"' + cellText + '"';
            }
            rowData.push(cellText);
        });
        csv.push(rowData.join(','));
    });

    // Download
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', filename);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// ===================================
// Page-specific Functions
// ===================================

// These can be called from individual pages as needed

// Calculate leave days (excluding weekends and holidays)
function calculateLeaveDays(startDate, endDate, holidays = []) {
    const start = new Date(startDate);
    const end = new Date(endDate);
    let count = 0;

    for (let date = new Date(start); date <= end; date.setDate(date.getDate() + 1)) {
        const dayOfWeek = date.getDay();
        const dateString = date.toISOString().split('T')[0];

        // Skip weekends (Saturday = 6, Sunday = 0)
        if (dayOfWeek !== 0 && dayOfWeek !== 6) {
            // Skip holidays
            if (!holidays.includes(dateString)) {
                count++;
            }
        }
    }

    return count;
}

// Update leave day calculation in forms
function updateLeaveDaysCalculation() {
    const startDateInput = $('input[name="start_date"]');
    const endDateInput = $('input[name="end_date"]');
    const daysDisplay = $('#leave-days-display');

    if (startDateInput.length && endDateInput.length && daysDisplay.length) {
        const startDate = startDateInput.val();
        const endDate = endDateInput.val();

        if (startDate && endDate) {
            const days = daysBetween(startDate, endDate);
            daysDisplay.text(days + ' day' + (days !== 1 ? 's' : ''));
        }
    }
}

// ===================================
// Export functions for use in other scripts
// ===================================
window.showFlashMessage = showFlashMessage;
window.confirmAction = confirmAction;
window.ajaxGet = ajaxGet;
window.ajaxPost = ajaxPost;
window.ajaxDelete = ajaxDelete;
window.showLoading = showLoading;
window.hideLoading = hideLoading;
window.escapeHtml = escapeHtml;
window.formatDate = formatDate;
window.formatDateTime = formatDateTime;
window.daysBetween = daysBetween;
window.debounce = debounce;
window.copyToClipboard = copyToClipboard;
window.printPage = printPage;
window.exportTableToCSV = exportTableToCSV;
window.calculateLeaveDays = calculateLeaveDays;
window.updateLeaveDaysCalculation = updateLeaveDaysCalculation;
window.setButtonLoading = setButtonLoading;

// ===================================
// Grouped Navigation Dropdowns
// ===================================
/**
 * Drives the admin navbar's grouped menus.
 *
 * Delegated from document so it covers every [data-nav-menu] button without
 * needing to know how many groups the nav defines. Opening one closes the
 * others, since two panels overlapping is the mess this grouping set out to fix.
 */
function initNavDropdowns() {
    const buttons = document.querySelectorAll('.nav-menu-btn');
    if (!buttons.length) {
        return;
    }

    function closeAll(except) {
        document.querySelectorAll('.nav-menu').forEach(function (menu) {
            if (menu !== except) {
                menu.classList.add('hidden');
            }
        });
        document.querySelectorAll('.nav-menu-btn').forEach(function (btn) {
            if (!except || btn.getAttribute('data-nav-menu') !== except.id) {
                btn.setAttribute('aria-expanded', 'false');
            }
        });
    }

    buttons.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const menu = document.getElementById(btn.getAttribute('data-nav-menu'));
            if (!menu) {
                return;
            }
            const willOpen = menu.classList.contains('hidden');
            closeAll(willOpen ? menu : null);
            menu.classList.toggle('hidden', !willOpen);
            btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
    });

    // Clicking anywhere else, or Escape, closes them.
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.nav-menu, .nav-menu-btn')) {
            closeAll(null);
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeAll(null);
        }
    });
}
