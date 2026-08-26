// Edit mode state
let editMode = false;
let currentBiltyId = null;
let pageInitialized = false;
let lastSavedBiltyId = null;
let keyboardShortcutsBound = false;
let printCompletionListenerBound = false;
const manualGRStorageKey = 'biltyManualGREntry';

function setLastSavedBiltyId(biltyId) {
    const id = parseInt(biltyId, 10);
    if (!Number.isInteger(id) || id <= 0) {
        return;
    }
    lastSavedBiltyId = id;
    try {
        localStorage.setItem('lastSavedBiltyId', String(id));
    } catch (error) {
        console.warn('Could not store last saved bilty id:', error);
    }
}

function getLastSavedBiltyId() {
    if (lastSavedBiltyId && Number.isInteger(lastSavedBiltyId) && lastSavedBiltyId > 0) {
        return lastSavedBiltyId;
    }

    try {
        const stored = parseInt(localStorage.getItem('lastSavedBiltyId') || '', 10);
        if (Number.isInteger(stored) && stored > 0) {
            lastSavedBiltyId = stored;
            return stored;
        }
    } catch (error) {
        console.warn('Could not read last saved bilty id:', error);
    }

    return null;
}

function focusConsignorInput() {
    const consignorInput = document.getElementById('consignor_name');
    if (!consignorInput) {
        return;
    }

    consignorInput.focus();
    consignorInput.select();
}

function setDefaultConsignor() {
    const consignorInput = document.getElementById('consignor_name');
    const consignorId = document.getElementById('consignor_id');

    if (!consignorInput || editMode) {
        return;
    }

    if (!consignorInput.value.trim()) {
        consignorInput.value = 'Self';
    }

    if (consignorId && consignorInput.value.trim().toLowerCase() === 'self') {
        consignorId.value = '';
    }
}

function removePrintIframe() {
    const frame = document.getElementById('bilty_print_iframe');
    if (frame) {
        frame.remove();
    }
}

function handlePrintCompletionMessage(event) {
    const data = event && event.data ? event.data : null;
    if (!data || data.type !== 'bilty-print-complete') {
        return;
    }

    removePrintIframe();
    setTimeout(() => {
        focusConsignorInput();
    }, 0);
}

function bindPrintCompletionListener() {
    if (printCompletionListenerBound) {
        return;
    }

    window.addEventListener('message', handlePrintCompletionMessage);
    printCompletionListenerBound = true;
}

function openPrintDialogWithoutNewTab(printUrl) {
    removePrintIframe();
    bindPrintCompletionListener();

    const iframe = document.createElement('iframe');
    iframe.id = 'bilty_print_iframe';
    iframe.src = printUrl;
    iframe.setAttribute('aria-hidden', 'true');
    iframe.style.position = 'fixed';
    iframe.style.width = '0';
    iframe.style.height = '0';
    iframe.style.border = '0';
    iframe.style.opacity = '0';
    iframe.style.pointerEvents = 'none';
    iframe.style.right = '0';
    iframe.style.bottom = '0';

    document.body.appendChild(iframe);

    // Cleanup old iframe after print flow completes.
    setTimeout(() => {
        removePrintIframe();
    }, 120000);
}

function printLastSavedBilty(openInSameTab = false, shortcutNoNewTab = false) {
    const biltyId = currentBiltyId || getLastSavedBiltyId();

    if (!biltyId) {
        showWarning('Please save a bilty first, then click Print.');
        return;
    }

    const printUrl = `../print/index.php?id=${encodeURIComponent(biltyId)}&auto_print=1`;

    if (shortcutNoNewTab) {
        openPrintDialogWithoutNewTab(printUrl);
        return;
    }

    if (openInSameTab) {
        window.location.href = printUrl;
        return;
    }

    const printWindow = window.open(printUrl, 'biltyPrintWindow', 'noopener,noreferrer,width=1024,height=768');
    if (printWindow) {
        printWindow.focus();
        return;
    }

    // Fallback if popup is blocked
    window.location.href = printUrl;
}

function updateBiltyAndPrint() {
    updateBilty((biltyId) => {
        if (!biltyId) {
            showError('Unable to print: bilty id not found after update.');
            return;
        }

        printLastSavedBilty(false, true);

        setTimeout(() => {
            window.location.href = '../filter';
        }, 1500);
    });
}

function isEnterShortcutKey(event, allowMainEnter = false) {
    if (event.code === 'NumpadEnter' || (event.key === 'Enter' && event.location === KeyboardEvent.DOM_KEY_LOCATION_NUMPAD)) {
        return true;
    }

    // Some browsers/keyboards report numpad enter as plain Enter when Ctrl+Alt are held.
    return allowMainEnter && event.key === 'Enter';
}

function handleGlobalShortcuts(event) {
    const isBookingPrintShortcut = event.ctrlKey && event.altKey && isEnterShortcutKey(event, true);
    const isNumpadEnter = isEnterShortcutKey(event);
    const isBookShortcut = event.ctrlKey && !event.altKey && isNumpadEnter;
    const isPrintShortcut = event.altKey && !event.ctrlKey && isNumpadEnter;
    const isEscapeShortcut = event.key === 'Escape';
    const isPlusKey = event.key === '+' || event.code === 'NumpadAdd' || (event.key === '=' && event.shiftKey);
    const isMinusKey = event.key === '-' || event.code === 'NumpadSubtract' || event.code === 'Minus';
    const isAddItemShortcut = event.ctrlKey && isPlusKey;
    const isRemoveItemShortcut = event.ctrlKey && isMinusKey;

    if (event.repeat) {
        return;
    }

    if (isBookingPrintShortcut) {
        event.preventDefault();
        event.stopPropagation();
        if (editMode) {
            updateBiltyAndPrint();
            return;
        }

        saveBiltyAndPrint();
        return;
    }

    if (isPrintShortcut) {
        event.preventDefault();
        event.stopPropagation();
        printLastSavedBilty(false, true);
        return;
    }

    if (isBookShortcut) {
        event.preventDefault();
        event.stopPropagation();
        if (editMode) {
            updateBilty();
            return;
        }

        saveBilty();
        return;
    }

    if (isAddItemShortcut) {
        event.preventDefault();
        event.stopPropagation();
        if (addItemButton && !addItemButton.disabled) {
            addItemButton.click();
        }
        return;
    }

    if (isRemoveItemShortcut) {
        event.preventDefault();
        event.stopPropagation();
        removeLastItemRow();
        return;
    }

    if (isEscapeShortcut && editMode) {
        event.preventDefault();
        event.stopPropagation();
        cancelBilty();
    }
}

// Focus on date input field (DD position only)
function selectDateInput(input) {
    input.focus();
    // Set cursor position at start (DD position)
    input.setSelectionRange(0, 2);
}

// Get and populate the next GR number
function getNextGRNumber() {
    if (isManualGREntryEnabled()) {
        return;
    }

    fetch('api/get_gr.php?action=getNextGR')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const grInput = document.querySelector('input[name="gr_number"]');
                if (grInput) {
                    grInput.value = data.gr_number;
                }
            }
        })
        .catch(error => console.log('Could not fetch GR number:', error));
}

function isManualGREntryEnabled() {
    const toggle = document.getElementById('manual_gr_entry');
    return Boolean(toggle && toggle.checked);
}

function setManualGRStorage(enabled) {
    try {
        sessionStorage.setItem(manualGRStorageKey, enabled ? '1' : '0');
    } catch (error) {
        console.warn('Could not store manual GR preference:', error);
    }
}

function getManualGRStorage() {
    try {
        return sessionStorage.getItem(manualGRStorageKey) === '1';
    } catch (error) {
        console.warn('Could not read manual GR preference:', error);
        return false;
    }
}

function applyManualGREntry(enabled, options = {}) {
    const grInput = document.querySelector('input[name="gr_number"]');
    const dateInput = document.getElementById('currentDateTime');
    const toggle = document.getElementById('manual_gr_entry');
    const shouldPersist = options.persist !== false;
    const shouldFetchAutoGR = options.fetchAutoGR !== false;

    if (toggle) {
        toggle.checked = enabled;
    }

    if (!grInput) {
        if (shouldPersist) {
            setManualGRStorage(enabled);
        }
        return;
    }

    if (enabled) {
        grInput.removeAttribute('tabindex');
        grInput.readOnly = false;
        grInput.value = '';
        grInput.style.borderColor = '#000000';
        if (dateInput) {
            dateInput.removeAttribute('tabindex');
        }
    } else {
        grInput.setAttribute('tabindex', '-1');
        grInput.readOnly = false;
        if (dateInput) {
            dateInput.setAttribute('tabindex', '-1');
        }
        if (shouldFetchAutoGR && !editMode) {
            getNextGRNumber();
        }
    }

    if (shouldPersist) {
        setManualGRStorage(enabled);
    }
}

function initializeManualGRToggle() {
    const toggle = document.getElementById('manual_gr_entry');
    if (!toggle) {
        return;
    }

    toggle.addEventListener('change', () => {
        applyManualGREntry(toggle.checked, { persist: true });
        if (toggle.checked) {
            focusConsignorInput();
        }
    });

    const grInput = document.querySelector('input[name="gr_number"]');
    if (grInput) {
        grInput.addEventListener('keydown', handleManualGRKey);
    }

    const dateInput = document.getElementById('currentDateTime');
    if (dateInput) {
        dateInput.addEventListener('keydown', handleManualDateKey);
    }
}

function focusFirstItemQuantity() {
    const firstItemQty = document.querySelector('.item-row .item-quantity');
    if (firstItemQty) {
        firstItemQty.focus();
        firstItemQty.select();
    }
}

function handleManualGRKey(event) {
    if (!isManualGREntryEnabled() || event.key !== 'Enter') {
        return;
    }

    event.preventDefault();
    const dateInput = document.getElementById('currentDateTime');
    if (dateInput) {
        selectDateInput(dateInput);
    }
}

function handleManualDateKey(event) {
    if (!isManualGREntryEnabled() || event.key !== 'Enter') {
        return;
    }

    event.preventDefault();
    validateAndFormatDateTime();

    const stationInput = document.getElementById('to_station');
    if (stationInput && stationInput.value.trim() && stationInput.getAttribute('tabindex') === '-1') {
        focusFirstItemQuantity();
        return;
    }

    if (stationInput) {
        stationInput.focus();
        stationInput.select();
        return;
    }

    focusFirstItemQuantity();
}

// Validate GR number in real-time (allow any format)
function validateGRNumber(input) {
    const grNumber = input.value.trim();
    
    // Allow empty (optional field)
    if (!grNumber) {
        input.style.borderColor = '#000000';
        return;
    }
    
    // Check uniqueness only (no format restriction)
    fetch(`api/get_gr.php?action=checkGR&gr=${encodeURIComponent(grNumber)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.is_unique) {
                input.style.borderColor = '#51cf66'; // Green - available
            } else {
                input.style.borderColor = '#ff6b6b'; // Red - already exists
            }
        })
        .catch(error => {
            input.style.borderColor = '#ccc'; // Gray on error
        });
}

let stationIndex = -1;
let stationResults = [];
let lastStationQuery = '';
let isStationSelecting = false;

function setStationTabSkip(shouldSkip) {
    const stationElement = document.getElementById('to_station');
    if (!stationElement) return;

    if (shouldSkip) {
        stationElement.setAttribute('tabindex', '-1');
    } else {
        stationElement.removeAttribute('tabindex');
    }
}

function searchStation(input) {

    if (isStationSelecting) {
        isStationSelecting = false;
        return;
    }

    const query = input.value.trim();
    const box = document.getElementById('station_float');

    if (query !== lastStationQuery) {
        stationIndex = -1;
        lastStationQuery = query;
    }

    if (query.length < 1) {
        box.style.display = 'none';
        stationIndex = -1;
        return;
    }

    fetch(`api/station_search.php?ajax=station_search&q=${encodeURIComponent(query)}`)
        .then(res => {
            if (!res.ok) {
                throw new Error('API error: ' + res.statusText);
            }
            return res.json();
        })
        .then(data => {
            console.log('Station search response:', data);
            
            // Handle both array response and object response
            const stations = Array.isArray(data) ? data : (data.data || data);
            
            if (!Array.isArray(stations)) {
                console.warn('Invalid station response format:', data);
                box.style.display = 'none';
                return;
            }

            stationResults = stations;
            box.innerHTML = '';

            if (!stations.length) {
                box.style.display = 'none';
                return;
            }

            stations.forEach((s, index) => {
                const div = document.createElement('div');
                div.className = 'float-item';
                div.textContent = s.station_name;

                div.onclick = () => selectStation(index, input);

                box.appendChild(div);
            });

            positionFloatBelowInput(input, box, { minWidth: 180, maxWidth: 300 });
            box.style.display = 'block';
            highlightStation(box.querySelectorAll('.float-item'));
        })
        .catch(error => {
            console.error('Station search error:', error);
            box.style.display = 'none';
            showError('Error searching stations: ' + error.message);
        });
}

function handleStationKey(e) {

    const box = document.getElementById('station_float');
    const items = box.querySelectorAll('.float-item');
    const input = e.target;

    if (e.key === 'Tab') {
        box.style.display = 'none';
        stationIndex = -1;
        return;
    }

    if (!items.length) return;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        stationIndex++;
        if (stationIndex >= items.length) stationIndex = items.length - 1;
        highlightStation(items);
    }

    if (e.key === 'ArrowUp') {
        e.preventDefault();
        stationIndex--;
        if (stationIndex < 0) stationIndex = 0;
        highlightStation(items);
    }

    if (e.key === 'Enter' && stationIndex >= 0) {
        e.preventDefault();
        isStationSelecting = true;
        selectStation(stationIndex, input);
    }

    if (e.key === 'Escape') {
        box.style.display = 'none';
        stationIndex = -1;
    }
}

function selectStation(index, input) {
    const s = stationResults[index];
    if (!s) return;

    input.value = s.station_name;
    setStationTabSkip(false);
    document.getElementById('station_float').style.display = 'none';
    stationIndex = -1;
    focusNextAfterStationSelection();
}

function focusNextAfterStationSelection() {
    const nextInput = document.querySelector('.item-row .item-quantity');
    if (nextInput) {
        nextInput.focus();
        nextInput.select();
    }
}

function highlightStation(items) {
    items.forEach(i => i.classList.remove('active'));
    if (stationIndex >= 0 && items[stationIndex]) {
        items[stationIndex].classList.add('active');
        items[stationIndex].scrollIntoView({ block: 'nearest' });
    }
}

/* CLOSE ON OUTSIDE CLICK */
document.addEventListener('click', function (e) {
    if (!e.target.closest('#to_station')) {
        document.getElementById('station_float').style.display = 'none';
        stationIndex = -1;
    }
});

// ==========================================
// DATE/TIME FUNCTIONALITY
// ==========================================

/**
 * Set current date in the billing form
 */
function setCurrentDateTime() {
    const today = new Date();
    const day = String(today.getDate()).padStart(2, '0');
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const year = today.getFullYear();
    // Format: DD-MM-YYYY for display
    const dateString = `${day}-${month}-${year}`;
    const dateTimeElement = document.getElementById('currentDateTime');
    dateTimeElement.value = dateString;
    dateTimeElement.placeholder = 'DD-MM-YYYY';
}

// Validate date format (DD-MM-YYYY)
function validateAndFormatDateTime() {
    const input = document.getElementById('currentDateTime');
    const value = input.value.trim();
    
    // Allow empty for default
    if (!value) {
        setCurrentDateTime();
        return true;
    }
    
    // Check if it's in correct format DD-MM-YYYY or DD/MM/YYYY
    let dateMatch = value.match(/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/);
    if (dateMatch) {
        const day = String(dateMatch[1]).padStart(2, '0');
        const month = String(dateMatch[2]).padStart(2, '0');
        const year = dateMatch[3];
        
        // Validate if it's a valid date
        const dateObj = new Date(`${year}-${month}-${day}`);
        if (isNaN(dateObj.getTime())) {
            showError('Invalid date. Please enter a valid date in DD-MM-YYYY format.');
            return false;
        }
        
        // Format as DD-MM-YYYY with hyphens
        input.value = `${day}-${month}-${year}`;
        return true;
    }
    
    showWarning('Invalid date format. Please use: DD-MM-YYYY');
    return false;
}

// Initialize date on page load
window.addEventListener('load', setCurrentDateTime);

// Add event listener to validate date on blur
document.addEventListener('DOMContentLoaded', function() {
    const dateInput = document.getElementById('currentDateTime');
    if (dateInput) {
        dateInput.addEventListener('blur', validateAndFormatDateTime);
    }
});

// ==========================================
// PARTY SEARCH FUNCTIONALITY (Consignor/Consignee)
// ==========================================
let activeIndex = -1;
let currentResults = [];
let lastQuery = '';
let isSelecting = false;

/**
 * Search for parties (Consignor/Consignee) by name
 * @param {HTMLInputElement} inputElement - The party search input field
 * @param {string} partyType - Type of party ('Consignor' or 'Consignee')
 */
function searchParty(inputElement, partyType) {
    if (isSelecting) {
        isSelecting = false;
        return;
    }

    const searchQuery = inputElement.value.trim();
    const floatBoxId = partyType === 'Consignor' ? 'consignor_float' : 'consignee_float';
    const resultsContainer = document.getElementById(floatBoxId);

    // Reset index if query changed
    if (searchQuery !== lastQuery) {
        activeIndex = -1;
        lastQuery = searchQuery;
    }

    // Hide dropdown if empty query
    if (searchQuery.length < 1) {
        resultsContainer.style.display = 'none';
        activeIndex = -1;
        return;
    }

    // Fetch party data
    fetch(`api/search_party.php?ajax=party_search&type=${partyType}&q=${encodeURIComponent(searchQuery)}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('API error: ' + response.statusText);
            }
            return response.json();
        })
        .then(data => {
            console.log('Party search response:', data);
            
            // Handle both array response and object response
            const parties = Array.isArray(data) ? data : (data.data || data);
            
            if (!Array.isArray(parties)) {
                console.warn('Invalid party response format:', data);
                resultsContainer.style.display = 'none';
                return;
            }
            
            currentResults = parties;
            resultsContainer.innerHTML = '';

            if (parties.length) {
                // Display each party as a dropdown item
                parties.forEach((party, index) => {
                    const item = document.createElement('div');
                    item.className = 'float-item';
                    const stationName = (party.station || '').trim();
                    item.textContent = stationName ? `${party.name} - ${stationName}` : party.name;
                    item.onclick = () => selectParty(index, inputElement, partyType);
                    resultsContainer.appendChild(item);
                });
                positionFloatBelowInput(inputElement, resultsContainer, { minWidth: 240, maxWidth: 420 });
                resultsContainer.style.display = 'block';
                highlight(resultsContainer.querySelectorAll('.float-item'));
            } else {
                resultsContainer.style.display = 'none';
                showInfo(partyType + ' not found. Create one in Party section.');
            }
        })
        .catch(error => {
            console.error('Party search error:', error);
            resultsContainer.style.display = 'none';
            showError('Error searching ' + partyType.toLowerCase() + ': ' + error.message);
        });
}

/**
 * Handle keyboard navigation for party search
 * @param {KeyboardEvent} event
 * @param {string} partyType - Type of party ('Consignor' or 'Consignee')
 */
function handlePartyKey(event, partyType) {
    const floatBoxId = partyType === 'Consignor' ? 'consignor_float' : 'consignee_float';
    const floatBox = document.getElementById(floatBoxId);
    const items = floatBox.querySelectorAll('.float-item');

    if (event.key === 'Tab') {
        floatBox.style.display = 'none';
        activeIndex = -1;
        return;
    }

    if (!items.length) return;

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex++;
        if (activeIndex >= items.length) activeIndex = items.length - 1;
        highlight(items);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex--;
        if (activeIndex < 0) activeIndex = 0;
        highlight(items);
    } else if (event.key === 'Enter' && activeIndex >= 0) {
        event.preventDefault();
        isSelecting = true;
        selectParty(activeIndex, event.target, partyType, true);
    } else if (event.key === 'Escape') {
        document.getElementById(floatBoxId).style.display = 'none';
        activeIndex = -1;
    }
}

function focusNextAfterPartySelection(partyType) {
    if (partyType === 'Consignor') {
        const nextInput = document.getElementById('consignee_name');
        if (nextInput) {
            nextInput.focus();
            nextInput.select();
        }
        return;
    }

    if (isManualGREntryEnabled()) {
        const grInput = document.querySelector('input[name="gr_number"]');
        if (grInput) {
            grInput.focus();
            grInput.select();
            return;
        }
    }

    const stationInput = document.getElementById('to_station');
    if (stationInput && stationInput.getAttribute('tabindex') !== '-1') {
        stationInput.focus();
        stationInput.select();
        return;
    }

    focusFirstItemQuantity();
}

/**
 * Select a party from the dropdown
 * @param {number} index - Index of selected party
 * @param {HTMLInputElement} inputElement - The party input field
 * @param {string} partyType - Type of party ('Consignor' or 'Consignee')
 */
function selectParty(index, inputElement, partyType, moveToNextInput = false) {
    const party = currentResults[index];
    if (!party) return;

    const partyPrefix = partyType === 'Consignor' ? 'consignor' : 'consignee';
    const addressElement = document.getElementById(partyPrefix + '_address');
    const contactElement = document.getElementById(partyPrefix + '_contact');
    const floatBoxElement = document.getElementById(partyPrefix + '_float');
    const idInputElement = document.getElementById(partyPrefix + '_id');

    // Fill in party details
    inputElement.value = party.name;
    addressElement.innerText = party.address1;
    contactElement.innerText = party.contact;

    // Store party ID
    if (idInputElement) {
        idInputElement.value = party.id || '';
        console.log('Party ID set: ' + (party.id || 'none') + ' for ' + partyType);
    }

    // Set payment type based on consignor's bilty type
    if (partyType === 'Consignor') {
        const paymentElement = document.getElementById('payment');
        if (paymentElement) {
            paymentElement.value = party.bilty_type === 'tbb' ? 'TBB' : 'Topay';
            console.log('Payment set to ' + paymentElement.value + ' (bilty_type: ' + party.bilty_type + ')');
        }
    }

    // Auto-fill destination station from selected consignee
    if (partyType === 'Consignee') {
        const stationElement = document.getElementById('to_station');
        const consigneeStation = (party.station || '').trim();
        if (stationElement && consigneeStation) {
            stationElement.value = consigneeStation;
            setStationTabSkip(true);
            console.log('Station auto-filled from consignee: ' + consigneeStation);
        } else {
            setStationTabSkip(false);
        }
    }

    // Close dropdown
    floatBoxElement.style.display = 'none';
    activeIndex = -1;

    if (moveToNextInput) {
        focusNextAfterPartySelection(partyType);
    }
}

/**
 * Highlight the currently selected party in dropdown
 * @param {NodeList} items - List of dropdown items
 */
function highlight(items) {
    items.forEach(item => item.classList.remove('active'));
    if (activeIndex >= 0 && items[activeIndex]) {
        items[activeIndex].classList.add('active');
        items[activeIndex].scrollIntoView({ block: 'nearest' });
    }
}

// Close party dropdowns when clicking elsewhere
document.addEventListener('click', (event) => {
    if (!event.target.closest('.consignor') && !event.target.closest('.consignee')) {
        document.querySelectorAll('.float-box').forEach(box => box.style.display = 'none');
        activeIndex = -1;
    }
});

// ==========================================
// ITEM/PRODUCT ROW MANAGEMENT
// ==========================================
const maxItems = 7;
const itemContainer = document.getElementById("itemContainer");
const addItemButton = document.getElementById("addItemBtn");

function normalizeRateBasisValue(value) {
    const normalized = String(value || '').trim().toLowerCase().replace(/\s+/g, '_');

    if (
        normalized === 'weight' ||
        normalized === 'per_quintle' ||
        normalized === 'per_quintal' ||
        normalized === 'quintle' ||
        normalized === 'quintal'
    ) {
        return 'per_quintle';
    }

    if (normalized === 'nag' || normalized === 'per_nag') {
        return 'per_nag';
    }

    return 'per_nag';
}

function getDbRateBasisValue(value) {
    return normalizeRateBasisValue(value) === 'per_quintle' ? 'Weight' : 'Nag';
}

function syncItemRowRateBasis(row, preserveWeightValue = true) {
    if (!row) {
        return;
    }

    const basisSelect = row.querySelector('.product-rate-basis');
    const weightInput = row.querySelector('.product-weight');
    const normalizedBasis = normalizeRateBasisValue(basisSelect?.value || 'per_nag');

    if (basisSelect) {
        basisSelect.value = normalizedBasis;
    }

    if (weightInput) {
        // Weight stays visible/editable for both basis options.
        weightInput.disabled = false;
    }
}

function attachItemRowListeners(row) {
    if (!row) {
        return;
    }

    const quantityInput = row.querySelector('.item-quantity');
    const rateInput = row.querySelector('.product-rate');
    const weightInput = row.querySelector('.product-weight');
    const basisSelect = row.querySelector('.product-rate-basis');

    if (quantityInput && !quantityInput.dataset.listenersBound) {
        quantityInput.addEventListener('input', calculateFreight);
        quantityInput.addEventListener('input', calculateTotalQtyAndWeight);
        quantityInput.dataset.listenersBound = 'true';
    }

    if (rateInput && !rateInput.dataset.listenersBound) {
        rateInput.addEventListener('input', calculateFreight);
        rateInput.dataset.listenersBound = 'true';
    }

    if (weightInput && !weightInput.dataset.listenersBound) {
        weightInput.addEventListener('input', calculateFreight);
        weightInput.addEventListener('input', calculateTotalQtyAndWeight);
        weightInput.dataset.listenersBound = 'true';
    }

    if (basisSelect && !basisSelect.dataset.listenersBound) {
        basisSelect.addEventListener('change', function () {
            syncItemRowRateBasis(row, false);
            calculateFreight();
            calculateTotalQtyAndWeight();
        });
        basisSelect.dataset.listenersBound = 'true';
    }

    syncItemRowRateBasis(row, true);
}

/**
 * Add a new item/product row to the bilty
 */
addItemButton.addEventListener("click", function () {
    const currentRows = itemContainer.querySelectorAll('.item-row');
    const currentRowCount = currentRows.length;

    // Check if maximum items reached
    if (currentRowCount >= maxItems) {
        showWarning("Maximum 7 items allowed");
        return;
    }

    // Create new row element
    const newRow = document.createElement("div");
    newRow.className = "item-row";
    newRow.innerHTML = `
        <div class="item-desc">
            <input type="text" class="item-quantity" placeholder="No." required>
        </div>
        <div class="item-desc" style="position:relative;">
            <input type="text" class="item-product-name" placeholder="Item Name" name="dec_name" autocomplete="off" 
                   onkeyup="searchProduct(this)" onkeydown="handleProductKey(event)" required>
            <div class="product_float float-box" tabindex="-1"></div>
        </div>
        <div class="item-desc">
            <select class="product-rate-basis" autocomplete="off">
                <option value="per_nag">Per Nag</option>
                <option value="per_quintle">Per Quintle</option>
            </select>
        </div>
        <div class="item-desc">
            <input type="text" class="product-rate" placeholder="Rate">
        </div>
        <div class="item-desc">
            <input type="text" class="product-weight" placeholder="Weight">
        </div>
        <div class="btn">
            <button type="button" class="remove-item-btn" tabindex="-1">❌</button>
        </div>
    `;

    itemContainer.appendChild(newRow);

    const previousRow = currentRows[currentRows.length - 1] || null;
    const previousBasis = normalizeRateBasisValue(previousRow?.querySelector('.product-rate-basis')?.value || 'per_nag');
    const newBasisSelect = newRow.querySelector('.product-rate-basis');
    if (newBasisSelect) {
        newBasisSelect.value = previousBasis;
    }

    attachItemRowListeners(newRow);

    const quantityInput = newRow.querySelector('.item-quantity');
    if (quantityInput) {
        quantityInput.focus();
    }
});

/**
 * Remove an item row from the bilty
 */
itemContainer.addEventListener("click", function (event) {
    if (event.target.classList.contains("remove-item-btn")) {
        event.target.closest(".item-row").remove();
        renumberItems();
        calculateFreight();
        calculateTotalQtyAndWeight();
    }
});

/**
 * Remove the last added item row from the bilty.
 * Keeps at least one item row in the form.
 */
function removeLastItemRow() {
    const currentRows = itemContainer.querySelectorAll('.item-row');
    if (currentRows.length <= 1) {
        showWarning('At least one item row must remain');
        return;
    }

    currentRows[currentRows.length - 1].remove();
    renumberItems();
    calculateFreight();
    calculateTotalQtyAndWeight();
}

/**
 * Renumber items after removal (placeholder for future implementation)
 */
function renumberItems() {
    // Placeholder for future renumbering logic
} function calculateTotalCharges() { let e = 0; document.querySelectorAll(".charge-input").forEach(t => { e += (parseFloat(t.value) || 0), console.log('Charge: ' + parseFloat(t.value) + ', Running Total: ' + e) }); const t = document.getElementById("totalCharge"); t && (t.value = String(Math.round(e)), console.log('Total Input Updated: ' + Math.round(e))) } document.addEventListener('DOMContentLoaded', function () { document.querySelectorAll(".charge-input").forEach(e => e.addEventListener('input', calculateTotalCharges)), calculateTotalCharges() });


// ==========================================
// PRODUCT SEARCH FUNCTIONALITY
// ==========================================
let productIndex = -1;
let productResults = [];
let lastProductQuery = '';
let isProductSelecting = false;
let currentProductInput = null;

function positionFloatBelowInput(inputElement, container, options = {}) {
    if (!inputElement || !container) {
        return;
    }

    const rect = inputElement.getBoundingClientRect();
    const viewportWidth = window.innerWidth || document.documentElement.clientWidth;
    const minWidth = options.minWidth || 180;
    const maxWidth = Math.min(options.maxWidth || 420, Math.max(180, viewportWidth - 16));
    const dropdownWidth = Math.min(Math.max(rect.width, minWidth), maxWidth);
    const left = Math.min(Math.max(8, rect.left), Math.max(8, viewportWidth - dropdownWidth - 8));

    container.style.position = 'fixed';
    container.style.top = `${rect.bottom + 2}px`;
    container.style.left = `${left}px`;
    container.style.width = `${dropdownWidth}px`;
    container.style.zIndex = '10000';
}

function positionProductFloat(inputElement, container) {
    positionFloatBelowInput(inputElement, container, { minWidth: 220, maxWidth: 350 });
}

/**
 * Search for products from Consignor, Consignee, and General catalogs
 * Fetches from all three sources concurrently and combines results
 * @param {HTMLInputElement} inputElement - The product search input field
 */
function searchProduct(inputElement) {
    if (isProductSelecting) {
        isProductSelecting = false;
        return;
    }

    currentProductInput = inputElement;
    const searchQuery = inputElement.value.trim();
    const productContainer = inputElement.closest('.item-row').querySelector('.product_float');

    if (!productContainer) {
        console.error('product_float container not found');
        return;
    }

    positionProductFloat(inputElement, productContainer);

    console.log('=== PRODUCT SEARCH INITIATED ===');
    console.log('Search Query:', searchQuery);

    // Reset index if query changed
    if (searchQuery !== lastProductQuery) {
        productIndex = -1;
        lastProductQuery = searchQuery;
    }

    // Hide dropdown if empty query
    if (searchQuery.length < 1) {
        productContainer.style.display = 'none';
        productIndex = -1;
        return;
    }

    // Get party IDs and destination station
    const consignorId = document.getElementById('consignor_id').value;
    const consigneeId = document.getElementById('consignee_id').value;
    const toStation   = (document.getElementById('to_station')?.value || '').trim();

    console.log('Fetching product sources. Station:', toStation);

    const fetchPromises = [];

    if (consignorId) {
        fetchPromises.push(
            fetch(`api/get_party_products.php?ajax=party_products&party_id=${consignorId}&q=${encodeURIComponent(searchQuery)}&station=${encodeURIComponent(toStation)}`)
                .then(response => response.json())
                .then(products => Array.isArray(products)
                    ? products.map(product => ({ ...product, source_label: 'Consignor' }))
                    : []
                )
                .catch(error => {
                    console.error('Consignor product fetch error:', error);
                    return [];
                })
        );
    }

    if (consigneeId) {
        fetchPromises.push(
            fetch(`api/get_party_products.php?ajax=party_products&party_id=${consigneeId}&q=${encodeURIComponent(searchQuery)}&station=${encodeURIComponent(toStation)}`)
                .then(response => response.json())
                .then(products => Array.isArray(products)
                    ? products.map(product => ({ ...product, source_label: 'Consignee' }))
                    : []
                )
                .catch(error => {
                    console.error('Consignee product fetch error:', error);
                    return [];
                })
        );
    }

    fetchPromises.push(
        fetch(`api/product_search.php?ajax=product_search&q=${encodeURIComponent(searchQuery)}&station=${encodeURIComponent(toStation)}`)
            .then(response => response.json())
            .then(products => Array.isArray(products) ? products : [])
            .catch(error => {
                console.error('Saved/general product fetch error:', error);
                return [];
            })
    );

    Promise.all(fetchPromises)
        .then(allResults => {
            const productList = [];
            const seen = new Set();

            allResults.flat().forEach(product => {
                const name = String(product.name || '').trim();
                if (!name) return;

                const rate = product.rate !== null && product.rate !== undefined ? String(product.rate).trim() : '';
                const source = product.source_label || 'General';
                const key = `${name.toLowerCase()}|${source.toLowerCase()}|${rate}`;

                if (seen.has(key)) return;
                seen.add(key);
                productList.push(product);
            });

            productResults = productList;
            displayProducts(productContainer, productList);
        })
        .catch(error => {
            console.error('Product fetch error:', error);
            productContainer.style.display = 'none';
        });
}

/**
 * Display product list in dropdown container
 * @param {HTMLElement} container - The dropdown container element
 * @param {Array} products - Array of product objects
 */
function displayProducts(container, products) {
    console.log('Displaying products:', products);
    productResults = products;
    container.innerHTML = '';

    if (currentProductInput) {
        positionProductFloat(currentProductInput, container);
    }

    if (products && products.length > 0) {
        // Create a dropdown item for each product
        products.forEach((product, index) => {
            const item = document.createElement('div');
            const sourceLabel = product.source_label || 'General';
            const sourceClass = getProductSourceClass(sourceLabel);
            const displayLabel = getProductSourceDisplayLabel(sourceLabel);
            item.className = `float-item ${sourceClass}`;

            const isSavedBiltyProduct = isSavedBiltySource(sourceLabel);
            const rate = !isSavedBiltyProduct && (product.rate || product.rate === 0) ? product.rate : null;
            const rateInfo = rate !== null && rate !== '' ? ` ₹${rate}` : '';
            item.textContent = `${product.name} - ${displayLabel}${rateInfo}`;

            console.log(`Item: ${product.name}, Rate: ${rate}, Source: ${sourceLabel}`);

            item.onclick = () => selectProduct(index, currentProductInput);
            container.appendChild(item);
        });

        container.style.display = 'block';
        if (currentProductInput) {
            positionProductFloat(currentProductInput, container);
        }
        highlightProduct(container.querySelectorAll('.float-item'));
    } else {
        console.log('No products found');
        // Empty state: allow user to open Product page and create missing item quickly.
        const emptyState = document.createElement('div');
        emptyState.className = 'product-empty-state';

        const emptyText = document.createElement('span');
        emptyText.className = 'product-empty-text';
        emptyText.textContent = 'Product not found';

        const addButton = document.createElement('button');
        addButton.type = 'button';
        addButton.className = 'product-add-btn';
        addButton.tabIndex = -1;
        addButton.textContent = '+ Add Product';
        addButton.onclick = (event) => {
            event.preventDefault();
            event.stopPropagation();
            showInfo('Opening Product Create Page');
            window.open('../../product/', '_blank');
        };

        emptyState.appendChild(emptyText);
        emptyState.appendChild(addButton);
        container.appendChild(emptyState);
        container.style.display = 'block';
        if (currentProductInput) {
            positionProductFloat(currentProductInput, container);
        }
        productIndex = -1;
    }
}

function getProductSourceClass(sourceLabel) {
    const source = String(sourceLabel || 'General').toLowerCase();
    if (source === 'consignor') return 'float-item--consignor';
    if (source === 'consignee') return 'float-item--consignee';
    if (source === 'saved bilty') return 'float-item--saved';
    return 'float-item--general';
}

function getProductSourceDisplayLabel(sourceLabel) {
    const source = String(sourceLabel || 'General').toLowerCase();
    if (source === 'saved bilty') return 'Saved Product';
    if (source === 'general') return 'Fixed Rate';
    return sourceLabel || 'Fixed Rate';
}

function isSavedBiltySource(sourceLabel) {
    return String(sourceLabel || '').toLowerCase() === 'saved bilty';
}

/**
 * Handle keyboard navigation for product search
 * @param {KeyboardEvent} event
 */
function handleProductKey(event) {
    if (!currentProductInput) return;

    const productContainer = currentProductInput.closest('.item-row')
        ? currentProductInput.closest('.item-row').querySelector('.product_float')
        : document.getElementById('product_float');

    if (event.key === 'Tab') {
        productContainer.style.display = 'none';
        productIndex = -1;
        return;
    }

    const items = productContainer.querySelectorAll('.float-item');

    if (!items.length) return;

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        productIndex++;
        if (productIndex >= items.length) productIndex = items.length - 1;
        highlightProduct(items);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        productIndex--;
        if (productIndex < 0) productIndex = 0;
        highlightProduct(items);
    } else if (event.key === 'Enter' && productIndex >= 0) {
        event.preventDefault();
        isProductSelecting = true;
        selectProduct(productIndex, currentProductInput);
    } else if (event.key === 'Escape') {
        productContainer.style.display = 'none';
        productIndex = -1;
    }
}

/**
 * Select a product from the dropdown
 * @param {number} index - Index of selected product
 * @param {HTMLInputElement} inputElement - The product input field
 */
function selectProduct(index, inputElement) {
    const product = productResults[index];
    if (!product) return;

    console.log('Product selected:', product);

    const itemRow = inputElement.closest('.item-row');
    let rateInputToFocus = null;
    inputElement.value = product.name;

    // Auto-fill rate and weight in the same row
    if (itemRow) {
        const rateInput = itemRow.querySelector('.product-rate');
        const weightInput = itemRow.querySelector('.product-weight');
        const basisSelect = itemRow.querySelector('.product-rate-basis');
        rateInputToFocus = rateInput;

        const isSavedBiltyProduct = isSavedBiltySource(product.source_label);

        if (basisSelect && !isSavedBiltyProduct) {
            basisSelect.value = normalizeRateBasisValue(product.rate_basis || 'per_nag');
            syncItemRowRateBasis(itemRow, false);
        }

        if (rateInput && !isSavedBiltyProduct && (product.rate || product.rate === 0)) {
            const rateValue = parseFloat(product.rate) || product.rate;
            rateInput.value = rateValue;
            console.log('Rate set to: ' + rateValue + ' (from ' + product.source_label + ')');
            rateInput.dispatchEvent(new Event('input', { bubbles: true }));
        }

        if (weightInput) {
            if (!isSavedBiltyProduct && (product.weight || product.weight === 0)) {
                const weightValue = parseFloat(product.weight) || product.weight;
                weightInput.value = weightValue;
                console.log('Weight set to: ' + weightValue);
                weightInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
    }

    // Close all dropdowns
    document.querySelectorAll('.float-box').forEach(box => box.style.display = 'none');
    productIndex = -1;
    calculateFreight();
    calculateTotalQtyAndWeight();

    if (rateInputToFocus) {
        setTimeout(() => {
            rateInputToFocus.focus();
            rateInputToFocus.select();
        }, 0);
    }
}

/**
 * Highlight the currently selected product in dropdown
 * @param {NodeList} items - List of dropdown items
 */
function highlightProduct(items) {
    items.forEach(item => item.classList.remove('active'));
    if (productIndex >= 0 && items[productIndex]) {
        items[productIndex].classList.add('active');
        items[productIndex].scrollIntoView({ block: 'nearest' });
    }
}

// Close product dropdowns when clicking elsewhere
document.addEventListener('click', (event) => {
    if (!event.target.closest('#product_name') && !event.target.closest('.item-product-name')) {
        document.querySelectorAll('#product_float').forEach(box => box.style.display = 'none');
        document.querySelectorAll('.product_float').forEach(box => box.style.display = 'none');
        productIndex = -1;
    }
});



// ==========================================
// CALCULATION FUNCTIONS
// ==========================================

/**
 * Calculate total quantity and weight from all item rows
 */
function calculateTotalQtyAndWeight() {
    const itemContainerElement = document.getElementById('itemContainer');
    if (!itemContainerElement) return;

    let totalQuantity = 0;
    let totalWeight = 0;

    // Sum up quantity and weight from all rows
    itemContainerElement.querySelectorAll('.item-row').forEach(row => {
        const quantityInput = row.querySelector('.item-quantity');
        const weightInput = row.querySelector('.product-weight');

        if (quantityInput) {
            totalQuantity += parseFloat(quantityInput.value) || 0;
        }

        if (weightInput) {
            totalWeight += parseFloat(weightInput.value) || 0;
        }
    });

    // Update display elements
    const totalQtyElement = document.getElementById('total-quantity');
    const totalWeightElement = document.getElementById('total-weight');

    if (totalQtyElement) {
        totalQtyElement.textContent = String(Math.round(totalQuantity));
        console.log('Total Quantity Updated: ' + totalQuantity);
    }

    if (totalWeightElement) {
        totalWeightElement.textContent = String(Math.round(totalWeight));
        console.log('Total Weight Updated: ' + totalWeight);
    }
}

/**
 * Calculate freight charges for each item row.
 * - Per Nag: quantity × rate
 * - Per Quintle: total weight × rate / 100
 * Updates the freight input field with total freight.
 */
function calculateFreight() {
    const itemContainerElement = document.getElementById('itemContainer');
    if (!itemContainerElement) {
        console.log('Item container not found');
        return;
    }

    const rows = itemContainerElement.querySelectorAll('.item-row');
    console.log('Total rows found: ' + rows.length);

    let totalFreight = 0;
    let hasCalculatedFreight = false;

    // Calculate freight per basis:
    // per_nag => quantity × rate
    // per_quintle => weight × rate / 100 (weight is total row weight)
    rows.forEach((row, rowIndex) => {
        const quantityInput = row.querySelector('.item-quantity');
        const rateInput = row.querySelector('.product-rate');
        const weightInput = row.querySelector('.product-weight');
        const basisSelect = row.querySelector('.product-rate-basis');

        if (quantityInput && rateInput) {
            const quantity = parseFloat(quantityInput.value) || 0;
            const rate = parseFloat(rateInput.value) || 0;
            const weight = parseFloat(weightInput?.value) || 0;
            const normalizedBasis = normalizeRateBasisValue(basisSelect?.value || 'per_nag');
            const canCalculate = normalizedBasis === 'per_quintle'
                ? (weight > 0 && rate > 0)
                : (quantity > 0 && rate > 0);

            if (!canCalculate) {
                return;
            }

            const rowFreight = normalizedBasis === 'per_quintle'
                ? ((weight * rate) / 100)
                : (quantity * rate);

            console.log('Row ' + rowIndex + ' Freight: ' + rowFreight + ' (' + normalizedBasis + ')');
            totalFreight += rowFreight;
            hasCalculatedFreight = true;
        }
    });

    console.log('Total Freight: ' + totalFreight);

    // Update freight input field
    const freightInput = document.getElementById('freight-input');
    if (freightInput) {
        if (hasCalculatedFreight) {
            freightInput.value = String(Math.round(totalFreight));
            console.log('Freight Input Updated: ' + Math.round(totalFreight));
        }
        calculateTotalCharges();
    } else {
        console.log('Freight input not found');
    }

    calculateTotalQtyAndWeight();
}

/**
 * Calculate total charges (freight + all other charges)
 */
function calculateTotalCharges() {
    let totalCharges = 0;

    // Sum all individual charge inputs
    document.querySelectorAll('.charge-input').forEach(chargeInput => {
        const chargeValue = parseFloat(chargeInput.value) || 0;
        totalCharges += chargeValue;
        console.log('Charge: ' + chargeValue + ', Running Total: ' + totalCharges);
    });

    // Update total charge display
    const totalChargeElement = document.getElementById('totalCharge');
    if (totalChargeElement) {
        totalChargeElement.value = String(Math.round(totalCharges));
        console.log('Total Input Updated: ' + Math.round(totalCharges));
    }
}

function focusPrivateMarkInput() {
    const privateMarkInput = document.querySelector('input[name="private_mark"]');
    if (privateMarkInput) {
        privateMarkInput.focus();
        privateMarkInput.select();
    }
}

function bindPFreightNavigation() {
    const chargeInputs = document.querySelectorAll('.charge-input');
    const pFreightInput = chargeInputs[2];

    if (!pFreightInput) {
        return;
    }

    pFreightInput.addEventListener('keydown', function (event) {
        const isEnter = event.key === 'Enter';
        const isTabForward = event.key === 'Tab' && !event.shiftKey;

        if (!isEnter && !isTabForward) {
            return;
        }

        if (!pFreightInput.value.trim()) {
            return;
        }

        event.preventDefault();
        focusPrivateMarkInput();
    });
}

function focusBiltyTypeInput() {
    const paymentSelect = document.getElementById('payment');
    if (paymentSelect) {
        paymentSelect.focus();
    }
}

function focusPrimaryActionButton() {
    const updateButton = document.getElementById('bilty_update');
    if (updateButton && updateButton.style.display !== 'none') {
        updateButton.focus();
        return;
    }

    const bookButton = document.getElementById('bilty_save');
    if (bookButton) {
        bookButton.focus();
    }
}

function bindPrivateMarkNavigation() {
    const privateMarkInput = document.querySelector('input[name="private_mark"]');
    if (!privateMarkInput) {
        return;
    }

    privateMarkInput.addEventListener('keydown', function (event) {
        const isEnter = event.key === 'Enter';
        const isTabForward = event.key === 'Tab' && !event.shiftKey;

        if (!isEnter && !isTabForward) {
            return;
        }

        event.preventDefault();
        focusBiltyTypeInput();
    });
}

function bindBiltyTypeNavigation() {
    const paymentSelect = document.getElementById('payment');
    if (!paymentSelect) {
        return;
    }

    paymentSelect.addEventListener('keydown', function (event) {
        const isEnter = event.key === 'Enter';
        const isTabForward = event.key === 'Tab' && !event.shiftKey;

        if (!isEnter && !isTabForward) {
            return;
        }

        event.preventDefault();
        focusPrimaryActionButton();
    });
}

/**
 * Initialize charge inputs and calculations on page load
 */
document.addEventListener('DOMContentLoaded', function () {
    // Add event listeners to all charge inputs
    document.querySelectorAll('.charge-input').forEach(chargeInput => {
        chargeInput.addEventListener('input', calculateTotalCharges);
    });

    // Calculate initial total charges
    calculateTotalCharges();
    bindPFreightNavigation();
    bindPrivateMarkNavigation();
    bindBiltyTypeNavigation();

    // Add event listeners to first item row inputs
    const firstRow = document.getElementById('itemContainer').querySelector('.item-row');
    if (firstRow) {
        attachItemRowListeners(firstRow);
    }

    // Calculate initial totals
    calculateTotalQtyAndWeight();
});


// ==========================================
// BILTY DATA COLLECTION & SAVE/UPDATE
// ==========================================

/**
 * Collect all bilty data from the form into a single object
 * @returns {Object} Bilty data object with all form values
 */
function collectBiltyData() {
    const itemContainerElement = document.getElementById('itemContainer');
    const rows = itemContainerElement.querySelectorAll('.item-row');
    const items = [];

    // Collect data from each item row
    rows.forEach(row => {
        const quantity = row.querySelector('.item-quantity').value;
        const productName = row.querySelector('.item-product-name').value;
        const rate = row.querySelector('.product-rate').value;
        const basisValue = row.querySelector('.product-rate-basis')?.value || 'per_nag';
        const weight = row.querySelector('.product-weight').value;

        // Only add rows with product names filled
        if (productName) {
            items.push({
                quantity: quantity || 0,
                name: productName,
                rate: rate || 0,
                weight: weight || 0,
                rate_basis: getDbRateBasisValue(basisValue)
            });
        }
    });

    // Convert DD-MM-YYYY to YYYY-MM-DD HH:MM:SS for database
    let biltyDate = document.getElementById('currentDateTime').value;
    if (biltyDate) {
        const dateMatch = biltyDate.match(/^(\d{2})-(\d{2})-(\d{4})$/);
        if (dateMatch) {
            const day = dateMatch[1];
            const month = dateMatch[2];
            const year = dateMatch[3];
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            biltyDate = `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
        }
    }

    // Collect all form data
    return {
        consignor_id: document.getElementById('consignor_id').value || 0,
        consignor_name: document.getElementById('consignor_name').value,
        consignee_id: document.getElementById('consignee_id').value || 0,
        consignee_name: document.getElementById('consignee_name').value,
        to_station: document.getElementById('to_station').value,
        gr_number: document.querySelector('input[name="gr_number"]').value,
        invoice_number: document.querySelector('input[name="invoice_number"]')?.value || '',
        invoice_value: document.querySelector('input[name="invoice_value"]')?.value || 0,
        eway_bill: document.querySelector('input[name="eway_bill"]')?.value || '',
        private_mark: document.querySelector('input[name="private_mark"]')?.value || '',
        remark: document.querySelector('input[name="remark"]')?.value || '',
        delivery_location: document.getElementById('delivery_location').value,
        freight: Math.round(parseFloat(document.getElementById('freight-input').value) || 0),
        hammali: Math.round(parseFloat(document.querySelectorAll('.charge-input')[1].value) || 0),
        p_freight: Math.round(parseFloat(document.querySelectorAll('.charge-input')[2].value) || 0),
        brokerage: Math.round(parseFloat(document.querySelectorAll('.charge-input')[3].value) || 0),
        dd_charge: Math.round(parseFloat(document.querySelectorAll('.charge-input')[4].value) || 0),
        gr_charge: Math.round(parseFloat(document.querySelectorAll('.charge-input')[5].value) || 0),
        total_charge: Math.round(parseFloat(document.getElementById('totalCharge').value) || 0),
        payment_type: document.getElementById('payment').value,
        bilty_date: biltyDate,
        total_qty: Math.round(parseFloat(document.getElementById('total-quantity').textContent) || 0),
        total_weight: Math.round(parseFloat(document.getElementById('total-weight').textContent) || 0),
        items: items
    };
}

/**
 * Clear the bilty form after successful save
 */
function clearBiltyForm() {
    // Clear party information
    document.getElementById('consignor_name').value = 'Self';
    document.getElementById('consignor_id').value = '';
    document.getElementById('consignor_address').innerText = '';
    document.getElementById('consignor_contact').innerText = '';
    
    document.getElementById('consignee_name').value = '';
    document.getElementById('consignee_id').value = '';
    document.getElementById('consignee_address').innerText = '';
    document.getElementById('consignee_contact').innerText = '';
    
    // Clear station and other fields
    document.getElementById('to_station').value = '';
    setStationTabSkip(false);
    document.querySelector('input[name="gr_number"]').value = '';
    document.querySelector('input[name="invoice_number"]').value = '';
    document.querySelector('input[name="invoice_value"]').value = '';
    document.querySelector('input[name="eway_bill"]').value = '';
    document.querySelector('input[name="private_mark"]').value = '';
    document.querySelector('input[name="remark"]').value = '';
    
    // Reset payment to default
    document.getElementById('payment').value = 'Topay';
    
    // Clear all charge inputs
    document.querySelectorAll('.charge-input').forEach(input => {
        input.value = '0';
    });
    
    // Clear all item rows except the first one
    const itemContainer = document.getElementById('itemContainer');
    const rows = itemContainer.querySelectorAll('.item-row');
    
    // Remove all rows except first
    rows.forEach((row, index) => {
        if (index > 0) {
            row.remove();
        }
    });
    
    // Clear first row
    if (rows.length > 0) {
        const firstRow = rows[0];
        firstRow.querySelector('.item-quantity').value = '';
        firstRow.querySelector('.item-product-name').value = '';
        const rateBasis = firstRow.querySelector('.product-rate-basis');
        firstRow.querySelector('.product-rate').value = '';
        firstRow.querySelector('.product-weight').value = '';
        if (rateBasis) {
            rateBasis.value = 'per_nag';
        }
        syncItemRowRateBasis(firstRow, false);
    }
    
    // Reset totals
    document.getElementById('total-quantity').textContent = '0';
    document.getElementById('total-weight').textContent = '0';
    document.getElementById('totalCharge').value = '0';
    
    // Reset GR number border color
    const grInput = document.querySelector('input[name="gr_number"]');
    if (grInput) {
        grInput.style.borderColor = '#ccc';
    }
    applyManualGREntry(isManualGREntryEnabled(), { persist: true });
    
    // Set current date
    setCurrentDateTime();
    
    // Focus on consignor name for next entry
    focusConsignorInput();
}

/**
 * Validate required fields and focus on first missing field
 */
function validateBiltyFields() {
    const consignorName = document.getElementById('consignor_name');
    const consigneeName = document.getElementById('consignee_name');
    const toStation = document.getElementById('to_station');
    const currentDateTime = document.getElementById('currentDateTime');
    const itemContainer = document.getElementById('itemContainer');
    const items = itemContainer.querySelectorAll('.item-row');
    
    let hasValidItem = false;
    
    // Check if at least one item has product name
    items.forEach(row => {
        const productName = row.querySelector('.item-product-name').value.trim();
        if (productName) {
            hasValidItem = true;
        }
    });
    
    // Validate Consignor - accept manual entry OR selected from list
    if (!consignorName.value.trim()) {
        showWarning('Consignor name is required!');
        consignorName.focus();
        return false;
    }
    
    // Validate Consignee - accept manual entry OR selected from list
    if (!consigneeName.value.trim()) {
        showWarning('Consignee name is required!');
        consigneeName.focus();
        return false;
    }
    
    // Validate Date
    if (!currentDateTime.value.trim()) {
        showWarning('Date is required!');
        selectDateInput(currentDateTime);
        return false;
    }
    
    // Validate Station
    if (!toStation.value.trim()) {
        showWarning('Destination station is required!');
        toStation.focus();
        return false;
    }
    
    // Validate Items
    if (!hasValidItem) {
        showWarning('Please add at least one item!');
        const firstItemInput = items[0].querySelector('.item-product-name');
        if (firstItemInput) {
            firstItemInput.focus();
        }
        return false;
    }
    
    return true;
}

function saveBilty(afterSaveCallback = null) {
    // First validate all required fields
    if (!validateBiltyFields()) {
        return;
    }
    
    const biltyData = collectBiltyData();

    // Prepare FormData for submission
    const formData = new FormData();
    formData.append('action', 'save');

    // Add all bilty data to form
    Object.keys(biltyData).forEach(key => {
        if (key === 'items') {
            formData.append(key, JSON.stringify(biltyData[key]));
        } else {
            formData.append(key, biltyData[key]);
        }
    });

    // Send to server
    fetch('api/save_bilty.php', {
        method: 'POST',
        body: formData
    })
        .then(response => {
            // Check if response is ok
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            // Try to parse as JSON
            return response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response:', text);
                    throw new Error('Invalid server response: ' + text);
                }
            });
        })
        .then(data => {
            if (data.success) {
                const savedBiltyId = parseInt(data.bilty_id, 10);
                if (Number.isInteger(savedBiltyId) && savedBiltyId > 0) {
                    currentBiltyId = savedBiltyId;
                    setLastSavedBiltyId(savedBiltyId);
                }

                const grRef = data.gr_number || data.id || '';
                showSuccess('Bilty saved successfully! GR: ' + grRef);

                if (typeof afterSaveCallback === 'function') {
                    const biltyIdForCallback = Number.isInteger(savedBiltyId) && savedBiltyId > 0 ? savedBiltyId : currentBiltyId;
                    afterSaveCallback(biltyIdForCallback);
                    return;
                }

                setTimeout(() => window.location.reload(), 1000);
            } else {
                showError('Error: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Save error:', error);
            showError('Error saving bilty: ' + error.message);
        });
}

function saveBiltyAndPrint() {
    saveBilty((biltyId) => {
        if (!biltyId) {
            showError('Unable to print: bilty id not found after save.');
            return;
        }

        printLastSavedBilty(false, true);

        setTimeout(() => {
            window.location.reload();
        }, 1500);
    });
}

/**
 * Update an existing bilty in the database
 */
function updateBilty(afterUpdateCallback = null) {
    if (!currentBiltyId) {
        showError('No bilty loaded to update');
        return;
    }

    // Validate required fields
    if (!validateBiltyFields()) {
        return;
    }

    const biltyData = collectBiltyData();
    biltyData.bilty_id = currentBiltyId;

    const formData = new FormData();
    formData.append('action', 'update');

    Object.keys(biltyData).forEach(key => {
        if (key === 'items') {
            formData.append(key, JSON.stringify(biltyData[key]));
        } else {
            formData.append(key, biltyData[key]);
        }
    });

    fetch('api/save_bilty.php', {
        method: 'POST',
        body: formData
    })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response:', text);
                    throw new Error('Invalid server response: ' + text);
                }
            });
        })
        .then(data => {
            if (data.success) {
                setLastSavedBiltyId(currentBiltyId);
                showSuccess('Bilty updated successfully! GR: ' + (data.gr_number || data.id || biltyData.gr_number || ''));
                if (typeof afterUpdateCallback === 'function') {
                    afterUpdateCallback(currentBiltyId);
                    return;
                }

                setTimeout(() => window.location.reload(), 1200);
            } else {
                showError('Error: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Update error:', error);
            showError('Error updating bilty: ' + error.message);
        });
}

// ----------------------
// Cancel edit mode
// ----------------------
function cancelBilty() {
    // Redirect to filter page
    window.location.href = '../filter';
}

// ----------------------
// Edit mode loader
// ----------------------
function loadBiltyForEdit(biltyId) {
    // Show update UI while loading
    const saveBtn = document.getElementById('bilty_save');
    const updateBtn = document.getElementById('bilty_update');
    const cancelBtn = document.getElementById('bilty_cancel');
    if (saveBtn) saveBtn.style.display = 'none';
    if (updateBtn) updateBtn.style.display = 'inline-block';
    if (cancelBtn) cancelBtn.style.display = 'inline-block';

    fetch(`api/load_bilty.php?id=${encodeURIComponent(biltyId)}`)
        .then(r => r.text())
        .then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Invalid JSON from load_bilty:', text);
                throw e;
            }
        })
        .then(data => {
            if (!data.success) {
                showError(data.message || 'Failed to load bilty');
                if (saveBtn) saveBtn.style.display = 'inline-block';
                if (updateBtn) updateBtn.style.display = 'none';
                if (cancelBtn) cancelBtn.style.display = 'none';
                editMode = false;
                currentBiltyId = null;
                applyManualGREntry(getManualGRStorage(), { persist: false });
                return;
            }
            currentBiltyId = biltyId;
            editMode = true;
            populateFormWithBiltyData(data.bilty, data.items || []);

            // Toggle buttons
            if (saveBtn) saveBtn.style.display = 'none';
            if (updateBtn) updateBtn.style.display = 'inline-block';

            showInfo('Editing bilty GR: ' + (data.bilty.gr_number || biltyId));
        })
        .catch(err => {
            console.error(err);
            showError('Could not load bilty');
            // Fallback to create mode UI if load fails
            if (saveBtn) saveBtn.style.display = 'inline-block';
            if (updateBtn) updateBtn.style.display = 'none';
            editMode = false;
            currentBiltyId = null;
            applyManualGREntry(getManualGRStorage(), { persist: false });
        });
}

// Populate form fields
function populateFormWithBiltyData(bilty, items) {
    const setVal = (selector, value) => {
        const el = document.querySelector(selector);
        if (el) el.value = value ?? '';
    };
    const wholeNumber = (value, fallback = '') => {
        if (value === null || value === undefined || value === '') {
            return fallback;
        }

        const parsed = parseFloat(value);
        return Number.isFinite(parsed) ? String(Math.round(parsed)) : fallback;
    };

    // Header
    setVal('#consignor_name', bilty.consignor_name);
    setVal('#consignor_id', bilty.consignor_id);
    setVal('#consignee_name', bilty.consignee_name);
    setVal('#consignee_id', bilty.consignee_id);
    setVal('#to_station', bilty.to_station);
    setStationTabSkip(false);
    setVal('input[name="gr_number"]', bilty.gr_number);
    const grInput = document.querySelector('input[name="gr_number"]');
    if (grInput) {
        grInput.readOnly = true;
    }
    const manualGRToggle = document.getElementById('manual_gr_entry');
    if (manualGRToggle) {
        manualGRToggle.checked = false;
        manualGRToggle.disabled = true;
    }
    setVal('#currentDateTime', bilty.bilty_date_formatted || (bilty.bilty_date ? bilty.bilty_date : ''));

    // Invoice / remarks
    setVal('input[name="invoice_number"]', bilty.invoice_number);
    setVal('input[name="invoice_value"]', wholeNumber(bilty.invoice_value));
    setVal('input[name="eway_bill"]', bilty.eway_bill);
    setVal('input[name="private_mark"]', bilty.private_mark);
    setVal('input[name="remark"]', bilty.remark);

    // Delivery location: DB stores lowercase (g/d), options use uppercase — match case-insensitively
    const rawDelivery = (bilty.delivery_location || 'G').toLowerCase();
    const deliverySelect = document.querySelector('select[name="delivery_location"]');
    if (deliverySelect) {
        let dlMatched = false;
        Array.from(deliverySelect.options).forEach(opt => {
            if (opt.value.toLowerCase() === rawDelivery) {
                deliverySelect.value = opt.value;
                dlMatched = true;
            }
        });
        if (!dlMatched) deliverySelect.selectedIndex = 0;
    }

    // Payment: DB stores lowercase (topay/cash/tbb), options use mixed case — match case-insensitively
    const rawPayment = (bilty.payment_type || bilty.payment || '').toLowerCase();
    const paymentSelect = document.getElementById('payment');
    if (paymentSelect) {
        let matched = false;
        Array.from(paymentSelect.options).forEach(opt => {
            if (opt.value.toLowerCase() === rawPayment) {
                paymentSelect.value = opt.value;
                matched = true;
            }
        });
        if (!matched) paymentSelect.selectedIndex = 0;
    }

    // Charges
    const charges = ['freight','hammali','p_freight','brokerage','dd_charge','gr_charge'];
    const chargeInputs = document.querySelectorAll('.charge-input');
    charges.forEach((key, idx) => {
        if (chargeInputs[idx]) chargeInputs[idx].value = wholeNumber(bilty[key], chargeInputs[idx].value);
    });
    setVal('#totalCharge', wholeNumber(bilty.total_charge));

    // Items
    const container = document.getElementById('itemContainer');
    if (container) {
        // Ensure at least one row (with the add button)
        if (!container.querySelector('.item-row') && addItemButton) {
            addItemButton.click();
        }

        const targetCount = items && items.length ? items.length : 1;

        // Add rows until we reach target count
        while (container.querySelectorAll('.item-row').length < targetCount && addItemButton) {
            addItemButton.click();
        }

        // Remove extra rows (keep first row to retain add button)
        while (container.querySelectorAll('.item-row').length > targetCount && container.querySelectorAll('.item-row').length > 1) {
            const allRows = container.querySelectorAll('.item-row');
            const toRemove = allRows[allRows.length - 1];
            if (toRemove) toRemove.remove();
        }

        const rows = container.querySelectorAll('.item-row');

        // Populate rows
        if (items && items.length) {
            items.forEach((it, idx) => {
                const row = rows[idx];
                if (!row) return;
                const qty = row.querySelector('.item-quantity');
                const name = row.querySelector('.item-product-name');
                const basis = row.querySelector('.product-rate-basis');
                const rate = row.querySelector('.product-rate');
                const weight = row.querySelector('.product-weight');
                if (qty) qty.value = wholeNumber(it.quantity, '1');
                if (name) name.value = it.item_name ?? it.name ?? '';
                if (basis) basis.value = normalizeRateBasisValue(it.rate_basis || 'Nag');
                if (rate) rate.value = wholeNumber(it.rate, '0');
                if (weight) weight.value = wholeNumber(it.weight, '0');
                syncItemRowRateBasis(row, true);
            });
        } else {
            const firstRow = rows[0];
            if (firstRow) {
                const qty = firstRow.querySelector('.item-quantity');
                const name = firstRow.querySelector('.item-product-name');
                const basis = firstRow.querySelector('.product-rate-basis');
                const rate = firstRow.querySelector('.product-rate');
                const weight = firstRow.querySelector('.product-weight');
                if (qty) qty.value = '';
                if (name) name.value = '';
                if (basis) basis.value = 'per_nag';
                if (rate) rate.value = '';
                if (weight) weight.value = '';
                syncItemRowRateBasis(firstRow, false);
            }
        }
    }

    // Recalculate totals
    calculateFreight();
    calculateTotalCharges();
    calculateTotalQtyAndWeight();
}

// Initialize page: detect edit_id
function initializePage() {
    if (pageInitialized) return;
    pageInitialized = true;

    initializeManualGRToggle();

    if (!keyboardShortcutsBound) {
        document.addEventListener('keydown', handleGlobalShortcuts, true);
        keyboardShortcutsBound = true;
    }

    bindPrintCompletionListener();

    const params = new URLSearchParams(window.location.search);
    const editId = params.get('edit_id');
    if (editId) {
        editMode = true;
        currentBiltyId = editId;
        setLastSavedBiltyId(editId);
        loadBiltyForEdit(editId);
    } else {
        // default create mode
        editMode = false;
        currentBiltyId = null;
        getLastSavedBiltyId();
        const manualGRToggle = document.getElementById('manual_gr_entry');
        if (manualGRToggle) {
            manualGRToggle.disabled = false;
        }
        applyManualGREntry(getManualGRStorage(), { persist: false });
        const saveBtn = document.getElementById('bilty_save');
        const updateBtn = document.getElementById('bilty_update');
        const cancelBtn = document.getElementById('bilty_cancel');
        if (saveBtn) saveBtn.style.display = 'inline-block';
        if (updateBtn) updateBtn.style.display = 'none';
        if (cancelBtn) cancelBtn.style.display = 'none';
        setDefaultConsignor();
    }

    // Focus consignor
    const stationElement = document.getElementById('to_station');
    if (stationElement) {
        stationElement.addEventListener('input', () => setStationTabSkip(false));
    }

    setTimeout(() => {
        setDefaultConsignor();
        focusConsignorInput();
    }, 200);
}

// Safety: initialize on DOM ready in case inline hook is missing
document.addEventListener('DOMContentLoaded', initializePage);
