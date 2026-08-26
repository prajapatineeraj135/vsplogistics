/* =============================
   Challan Notification Module
============================= */
(function initChallanNotificationModule() {
    if (typeof window.showChallanNotification === "function") {
        return;
    }

    window.showChallanNotification = function showChallanNotification(message, type = "success", duration = 2600) {
        const text = String(message || "").trim();
        if (!text) {
            return;
        }

        const mappedType = String(type || "success").toLowerCase();
        if (mappedType === "danger") {
            if (typeof window.showFailed === "function") {
                window.showFailed(text, duration);
            } else if (typeof window.showError === "function") {
                window.showError(text, duration);
            }
            return;
        }

        if (mappedType === "warning") {
            if (typeof window.showWarning === "function") {
                window.showWarning(text, duration);
            }
            return;
        }

        if (mappedType === "save" && typeof window.showSave === "function") {
            window.showSave(text, duration);
            return;
        }

        if (mappedType === "update" && typeof window.showUpdate === "function") {
            window.showUpdate(text, duration);
            return;
        }

        if (typeof window.showSuccess === "function") {
            window.showSuccess(text, duration);
        }
    };
})();

/* =============================
   Challan Header Module
============================= */
(function initChallanHeaderModule() {
    const headerData = window.challanHeaderData || {};
    const editData = window.challanEditData || null;
    const isEditMode = !!(editData && Number(editData.challan_id || 0) > 0);
    const stationDetails = Array.isArray(headerData.stationDetails) ? headerData.stationDetails : [];
    const vehicleDetails = Array.isArray(headerData.vehicleDetails) ? headerData.vehicleDetails : [];
    const agentDetails = Array.isArray(headerData.agentDetails) ? headerData.agentDetails : [];

    function escapeHtml(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/\"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function bindSuggestionList(input, listEl, options, onSelect, config = {}) {
        let highlightedIndex = -1;
        const getValue = config.getValue || ((item) => String(item));
        const getLabel = config.getLabel || ((item) => String(item));
        const getKeywords = config.getKeywords || ((item) => [String(item)]);
        const createUrl = config.createUrl || "";
        const createLabel = config.createLabel || "";

        function getSelectableItems() {
            return Array.from(listEl.querySelectorAll("li[data-value]"));
        }

        function setActiveItem(items, index) {
            items.forEach((item) => item.classList.remove("active"));
            if (index >= 0 && index < items.length) {
                items[index].classList.add("active");
                items[index].scrollIntoView({ block: "nearest" });
            }
        }

        function selectOption(item) {
            if (!item) {
                return;
            }

            input.value = item.dataset.value;
            listEl.classList.remove("show");
            highlightedIndex = -1;
            input.dispatchEvent(new CustomEvent("suggestion:selected", {
                bubbles: true,
                detail: { value: item.dataset.value }
            }));
            onSelect(item.dataset.value);
        }

        function renderSuggestions() {
            const keyword = input.value.trim().toLowerCase();

            if (keyword === "") {
                listEl.classList.remove("show");
                highlightedIndex = -1;
                return;
            }

            const filtered = options
                .filter((item) => {
                    const keywords = getKeywords(item)
                        .map((value) => String(value || "").toLowerCase());
                    return keywords.some((value) => value.includes(keyword));
                })
                .slice(0, 8);

            highlightedIndex = -1;

            if (filtered.length === 0) {
                listEl.innerHTML = `<li class="empty">No match found</li>${createUrl ? `<li class="create-item"><button type="button" class="create-btn" data-create-link="${escapeHtml(createUrl)}">+ ${escapeHtml(createLabel)}</button></li>` : ""}`;
                listEl.classList.add("show");
                return;
            }

            listEl.innerHTML = filtered
                .map((item) => {
                    const value = getValue(item);
                    const label = getLabel(item);
                    return `<li data-value="${escapeHtml(value)}">${escapeHtml(label)}</li>`;
                })
                .join("");
            listEl.classList.add("show");
        }

        input.addEventListener("focus", () => {
            if (input.value.trim() === "") {
                listEl.classList.remove("show");
                highlightedIndex = -1;
                return;
            }

            renderSuggestions();
        });

        input.addEventListener("input", renderSuggestions);

        input.addEventListener("blur", () => {
            setTimeout(() => {
                listEl.classList.remove("show");
                highlightedIndex = -1;
            }, 120);
        });

        input.addEventListener("keydown", (event) => {
            const items = getSelectableItems();
            if (!listEl.classList.contains("show") || items.length === 0) {
                return;
            }

            if (event.key === "ArrowDown") {
                event.preventDefault();
                highlightedIndex = (highlightedIndex + 1) % items.length;
                setActiveItem(items, highlightedIndex);
                return;
            }

            if (event.key === "ArrowUp") {
                event.preventDefault();
                highlightedIndex = highlightedIndex <= 0 ? items.length - 1 : highlightedIndex - 1;
                setActiveItem(items, highlightedIndex);
                return;
            }

            if (event.key === "Enter" && highlightedIndex >= 0) {
                event.preventDefault();
                selectOption(items[highlightedIndex]);
                return;
            }

            if (event.key === "Escape") {
                listEl.classList.remove("show");
                highlightedIndex = -1;
            }
        });

        listEl.addEventListener("mousedown", (event) => {
            const createButton = event.target.closest("[data-create-link]");
            if (createButton) {
                window.location.href = createButton.dataset.createLink;
                return;
            }

            const option = event.target.closest("li[data-value]");
            if (!option) {
                return;
            }
            selectOption(option);
        });

        listEl.addEventListener("mousemove", (event) => {
            const option = event.target.closest("li[data-value]");
            if (!option) {
                return;
            }

            const items = getSelectableItems();
            highlightedIndex = items.indexOf(option);
            setActiveItem(items, highlightedIndex);
        });
    }

    function initChallanHeader() {
        const challanDateInput = document.getElementById("challan-date");
        const challanNoInput = document.getElementById("challan-no");
        const challanNoModeSelect = document.getElementById("challan-no-mode");
        const stationInput = document.getElementById("challan-station");
        const vehicleInput = document.getElementById("challan-vehicle");
        const stationSuggestions = document.getElementById("station-suggestions");
        const vehicleSuggestions = document.getElementById("vehicle-suggestions");
        const driverInput = document.getElementById("challan-driver");
        const contactInput = document.getElementById("challan-contact");
        const agentNameInput = document.getElementById("challan-agent-name");
        const agentContactInput = document.getElementById("challan-agent-contact");

        if (!challanDateInput || !challanNoInput || !challanNoModeSelect || !stationInput || !vehicleInput || !stationSuggestions || !vehicleSuggestions || !driverInput || !contactInput || !agentNameInput || !agentContactInput) {
            return;
        }

        const today = new Date();
        const localToday = new Date(today.getTime() - today.getTimezoneOffset() * 60000)
            .toISOString()
            .split("T")[0];

        const stationOptions = stationDetails
            .map((station) => (station.station_name || "").trim())
            .filter((value) => value !== "");

        const vehicleOptions = vehicleDetails
            .map((vehicle) => ({
                vehicle_number: (vehicle.vehicle_number || "").trim(),
                driver_name: (vehicle.driver_name || "").trim(),
                mobile: (vehicle.mobile || "").trim()
            }))
            .filter((vehicle) => vehicle.vehicle_number !== "");

        const vehicleMap = {};
        vehicleDetails.forEach((vehicle) => {
            vehicleMap[(vehicle.vehicle_number || "").trim()] = {
                driver_name: vehicle.driver_name || "",
                mobile: vehicle.mobile || ""
            };
        });

        function normalizeStationKey(value) {
            return String(value || "")
                .trim()
                .toLowerCase()
                .replace(/\s+/g, " ");
        }

        const stationAgentMap = {};
        const stationAgentList = [];
        agentDetails.forEach((agent) => {
            const stationName = normalizeStationKey(agent.station || "");
            if (stationName === "") {
                return;
            }

            const details = {
                agent_name: (agent.agent_name || "").trim(),
                contact: (agent.contact || "").trim(),
                commission_percent: Number(agent.commission_percent || 0),
                station_key: stationName
            };

            stationAgentList.push(details);

            if (stationAgentMap[stationName]) {
                return;
            }

            stationAgentMap[stationName] = {
                agent_name: details.agent_name,
                contact: details.contact,
                commission_percent: details.commission_percent
            };
        });

        function getAgentDetailsByStation(stationValue) {
            const stationKey = normalizeStationKey(stationValue);
            if (stationKey === "") {
                return null;
            }

            if (stationAgentMap[stationKey]) {
                return stationAgentMap[stationKey];
            }

            const partialMatch = stationAgentList.find((entry) =>
                entry.station_key.includes(stationKey) || stationKey.includes(entry.station_key)
            );

            if (!partialMatch) {
                return null;
            }

            return {
                agent_name: partialMatch.agent_name,
                contact: partialMatch.contact,
                commission_percent: partialMatch.commission_percent
            };
        }

        function fillVehicleDetails() {
            const selectedVehicle = vehicleInput.value.trim();
            const details = vehicleMap[selectedVehicle];

            if (details) {
                driverInput.value = details.driver_name;
                contactInput.value = details.mobile;
                return;
            }

            driverInput.value = "";
            contactInput.value = "";
        }

        function fillAgentDetailsByStation() {
            const details = getAgentDetailsByStation(stationInput.value);

            if (details) {
                agentNameInput.value = details.agent_name;
                agentContactInput.value = details.contact;
                return;
            }

            agentNameInput.value = "";
            agentContactInput.value = "";
        }

        function applyChallanNoMode() {
            if (challanNoModeSelect.value === "auto") {
                challanNoInput.value = challanNoInput.dataset.autoValue || "";
                challanNoInput.readOnly = true;
                return;
            }

            challanNoInput.readOnly = false;
        }

        if (!isEditMode) {
            bindSuggestionList(stationInput, stationSuggestions, stationOptions, function () {
                fillAgentDetailsByStation();
            }, {
                createUrl: "../../station/index.php",
                createLabel: "Add Station"
            });
        } else {
            stationInput.readOnly = true;
            stationInput.setAttribute("aria-readonly", "true");
            stationSuggestions.classList.remove("show");
            stationSuggestions.innerHTML = "";
        }

        bindSuggestionList(
            vehicleInput,
            vehicleSuggestions,
            vehicleOptions,
            function () {
                fillVehicleDetails();
            },
            {
                getValue: (item) => item.vehicle_number,
                getLabel: (item) => item.driver_name
                    ? `${item.vehicle_number} - ${item.driver_name}`
                    : item.vehicle_number,
                getKeywords: (item) => [item.vehicle_number, item.driver_name],
                createUrl: "../../vehicle/index.php",
                createLabel: "Add Vehicle"
            }
        );

        vehicleInput.addEventListener("change", fillVehicleDetails);
        vehicleInput.addEventListener("input", fillVehicleDetails);
        stationInput.addEventListener("change", fillAgentDetailsByStation);
        stationInput.addEventListener("input", fillAgentDetailsByStation);
        challanNoModeSelect.addEventListener("change", applyChallanNoMode);

        applyChallanNoMode();

        if (isEditMode) {
            const challanIdInput = document.getElementById("challan-id");
            const challanDateOnly = String(editData.challan_date || "").split(" ")[0];

            if (challanIdInput) {
                challanIdInput.value = String(editData.challan_id || "");
            }

            challanNoInput.dataset.autoValue = String(editData.challan_no || challanNoInput.dataset.autoValue || "");
            challanNoInput.value = String(editData.challan_no || challanNoInput.value || "");
            challanNoModeSelect.value = "manual";
            challanNoInput.readOnly = true;
            challanNoModeSelect.disabled = true;

            challanDateInput.value = challanDateOnly || localToday;
            stationInput.value = String(editData.challan_station || "").trim();
            stationInput.readOnly = true;
            vehicleInput.value = String(editData.vehicle_no || "").trim();
            driverInput.value = String(editData.driver_name || "").trim();
            contactInput.value = String(editData.driver_contact || "").trim();
            agentNameInput.value = String(editData.agent_name || "").trim();
            agentContactInput.value = String(editData.agent_contact || "").trim();
            return;
        }

        challanDateInput.value = localToday;
        stationInput.focus();
    }

    window.addEventListener("load", initChallanHeader);
})();

/* =============================
   Bilty Dispatch Module
============================= */
(function initBiltyDispatchModule() {
    const editData = window.challanEditData || null;
    const headerData = window.challanHeaderData || {};
    const agentDetails = Array.isArray(headerData.agentDetails) ? headerData.agentDetails : [];

    function escapeHtml(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/\"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function firstWord(value) {
        const text = String(value ?? "").trim();
        if (!text) {
            return "";
        }
        return text.split(/\s+/)[0];
    }

    function normalizeStationKey(value) {
        return String(value || "")
            .trim()
            .toLowerCase()
            .replace(/\s+/g, " ");
    }

    function buildStationCommissionMap() {
        const map = {};
        agentDetails.forEach((agent) => {
            const key = normalizeStationKey(agent.station || "");
            if (!key || Object.prototype.hasOwnProperty.call(map, key)) {
                return;
            }

            map[key] = Number(agent.commission_percent || 0);
        });
        return map;
    }

    function formatAmount(value) {
        const number = Number(value || 0);
        return String(Math.round(number));
    }

    function calculateDispatchRecovery(row) {
        if (row && row.recovery !== undefined && row.recovery !== null && row.recovery !== "") {
            return Number(row.recovery || 0);
        }

        const hammali = Number(row?.hammali || 0);
        const brokerage = Number(row?.brokerage || 0);
        const pFreight = Number(row?.p_freight || 0);
        return hammali + brokerage + pFreight + brokerage;
    }

    function initBiltyDispatch() {
        const isEditMode = !!(editData && Number(editData.challan_id || 0) > 0);
        const stationInput = document.getElementById("challan-station");
        const bookedBody = document.getElementById("booked-bilty-body");
        const dispatchBody = document.getElementById("dispatch-bilty-body");
        const bookedTotalFreight = document.getElementById("booked-total-freight");
        const dispatchTotalFreight = document.getElementById("dispatch-total-freight");
        const selectAllBooked = document.getElementById("select-all-booked");
        const selectAllDispatch = document.getElementById("select-all-dispatch");
        const selectedStationBooked = document.getElementById("selected-station-booked");
        const selectedStationDispatch = document.getElementById("selected-station-dispatch");
        const paidInput = document.getElementById("calc-paid");
        const freightInput = document.getElementById("calc-freight");
        const recoveryInput = document.getElementById("calc-recovery");
        const cuttingInput = document.getElementById("calc-cutting");
        const commissionInput = document.getElementById("calc-commission");
        const finalInput = document.getElementById("calc-final");
        const cashPaidSpan = document.getElementById("calc-paid-cash");
        const tbbPaidSpan = document.getElementById("calc-paid-tbb");
        const agentNameInput = document.getElementById("challan-agent-name");

        const stationCommissionMap = buildStationCommissionMap();

        if (cuttingInput) {
            cuttingInput.readOnly = !isEditMode;
        }
        if (finalInput) {
            finalInput.readOnly = !isEditMode;
        }

        if (!stationInput || !bookedBody || !dispatchBody || !bookedTotalFreight || !dispatchTotalFreight || !selectAllBooked || !selectAllDispatch) {
            return;
        }

        let bookedRowsState = [];
        let dispatchRowsState = [];
        const editDispatchRows = editData && Number(editData.challan_id || 0) > 0 && Array.isArray(editData.bilty_rows)
            ? editData.bilty_rows.map((row) => ({ ...row, status: "Dispatch" }))
            : [];

        if (isEditMode) {
            if (cuttingInput) {
                cuttingInput.value = formatAmount(Number(editData?.cutting_total || 0));
            }
            if (finalInput) {
                const editFinal = Number(editData?.final_total || 0);
                if (editFinal > 0) {
                    finalInput.value = formatAmount(editFinal);
                    finalInput.dataset.manual = "1";
                }
            }
        }

        function getCalculationState() {
            const totals = dispatchRowsState.reduce((acc, row) => {
                const freight = Number(row.freight || 0);
                const recovery = calculateDispatchRecovery(row);
                const paymentType = String(row.payment_type || "").trim().toLowerCase();

                acc.freight += freight;
                acc.recovery += recovery;
                if (paymentType === "cash") {
                    acc.cashPaid += freight;
                    acc.paid += freight;
                } else if (paymentType === "tbb") {
                    acc.tbbPaid += freight;
                    acc.paid += freight;
                }
                return acc;
            }, { paid: 0, cashPaid: 0, tbbPaid: 0, freight: 0, recovery: 0 });

            const stationKey = normalizeStationKey(stationInput.value);
            const agentNameKey = String(agentNameInput?.value || "").trim().toLowerCase();
            const selectedAgent = agentDetails.find((agent) => String(agent.agent_name || "").trim().toLowerCase() === agentNameKey);
            const commissionPercent = selectedAgent
                ? Number(selectedAgent.commission_percent || 0)
                : Number(stationCommissionMap[stationKey] || 0);
            const cutting = Number(cuttingInput?.value || 0);
            const commissionBase = Math.max(0, totals.freight - totals.recovery - cutting);
            const commission = (commissionBase * commissionPercent) / 100;

            return {
                paid: totals.paid,
                cashPaid: totals.cashPaid,
                tbbPaid: totals.tbbPaid,
                freight: totals.freight,
                recovery: totals.recovery,
                cutting,
                commissionPercent,
                commission
            };
        }

        function renderCalculationState() {
            const state = getCalculationState();
            if (paidInput) paidInput.value = formatAmount(state.paid);
            if (freightInput) freightInput.value = formatAmount(state.freight);
            if (recoveryInput) recoveryInput.value = formatAmount(state.recovery);
            if (commissionInput) commissionInput.value = formatAmount(state.commission);
            if (finalInput) {
                const shouldAutoFinal = !(isEditMode && finalInput.dataset.manual === "1");
                if (shouldAutoFinal) {
                    finalInput.value = formatAmount(state.freight - state.recovery - state.commission);
                }
            }
            if (cashPaidSpan) cashPaidSpan.textContent = formatAmount(state.cashPaid);
            if (tbbPaidSpan) tbbPaidSpan.textContent = formatAmount(state.tbbPaid);
        }

        function getBookedCheckboxes() {
            return Array.from(bookedBody.querySelectorAll(".booked-bilty-checkbox"));
        }

        function getDispatchCheckboxes() {
            return Array.from(dispatchBody.querySelectorAll(".dispatch-bilty-checkbox"));
        }

        function syncSelectAllBooked() {
            const checkboxes = getBookedCheckboxes();
            if (checkboxes.length === 0) {
                selectAllBooked.checked = false;
                selectAllBooked.indeterminate = false;
                return;
            }

            const checkedCount = checkboxes.filter((checkbox) => checkbox.checked).length;
            selectAllBooked.checked = checkedCount === checkboxes.length;
            selectAllBooked.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
        }

        function bindBookedCheckboxEvents() {
            const checkboxes = getBookedCheckboxes();
            checkboxes.forEach((checkbox) => {
                checkbox.addEventListener("change", syncSelectAllBooked);
            });
            syncSelectAllBooked();
        }

        function syncSelectAllDispatch() {
            const checkboxes = getDispatchCheckboxes();
            if (checkboxes.length === 0) {
                selectAllDispatch.checked = false;
                selectAllDispatch.indeterminate = false;
                return;
            }

            const checkedCount = checkboxes.filter((checkbox) => checkbox.checked).length;
            selectAllDispatch.checked = checkedCount === checkboxes.length;
            selectAllDispatch.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
        }

        function bindDispatchCheckboxEvents() {
            const checkboxes = getDispatchCheckboxes();
            checkboxes.forEach((checkbox) => {
                checkbox.addEventListener("change", syncSelectAllDispatch);
            });
            syncSelectAllDispatch();
        }

        const grNumberSorter = new Intl.Collator(undefined, { numeric: true, sensitivity: "base" });
        const nameSorter = new Intl.Collator(undefined, { sensitivity: "base" });

        function getRowPartyName(row) {
            return String(row?.consignor_name || row?.consignee_name || "").trim();
        }

        function sortBiltyRows(rows) {
            return [...(rows || [])].sort((a, b) => {
                const grCompare = grNumberSorter.compare(String(a?.gr_number || ""), String(b?.gr_number || ""));
                if (grCompare !== 0) {
                    return grCompare;
                }

                const nameCompare = nameSorter.compare(getRowPartyName(a), getRowPartyName(b));
                if (nameCompare !== 0) {
                    return nameCompare;
                }

                return nameSorter.compare(String(a?.id || ""), String(b?.id || ""));
            });
        }

        function setBookedPlaceholder(text) {
            bookedBody.innerHTML = `<tr><td colspan="7" style="text-align:left; font-size: 12px;">${escapeHtml(text)}</td></tr>`;
            bookedTotalFreight.textContent = "0";
            selectAllBooked.checked = false;
            selectAllBooked.indeterminate = false;
        }

        function setDispatchPlaceholder(text) {
            dispatchBody.innerHTML = `<tr><td colspan="7" style="text-align:left; font-size: 12px;">${escapeHtml(text)}</td></tr>`;
            dispatchTotalFreight.textContent = "0";
            selectAllDispatch.checked = false;
            selectAllDispatch.indeterminate = false;
            renderCalculationState();
        }

        function updateSelectedStationLabels() {
            const station = stationInput.value.trim();
            const displayText = station || "-";

            if (selectedStationBooked) {
                selectedStationBooked.textContent = displayText;
            }

            if (selectedStationDispatch) {
                selectedStationDispatch.textContent = displayText;
            }
        }

        function renderBookedRows(rows) {
            const bookedRows = sortBiltyRows(rows);

            if (bookedRows.length === 0) {
                setBookedPlaceholder("No booked bilty found for selected station");
                return;
            }

            let totalFreight = 0;
            bookedBody.innerHTML = bookedRows.map((row) => {
                const freight = Number(row.freight || 0);
                totalFreight += freight;

                return `<tr style="text-align: left; font-size: 16px; ">
                    <td><input type="checkbox" class="booked-bilty-checkbox" value="${escapeHtml(row.id)}" tabindex="-1"></td>
                    <td>${escapeHtml(row.gr_number)}</td>
                    <td>${escapeHtml(row.consignor_name)}</td>
                    <td>${escapeHtml(row.consignee_name)}</td>
                    <td>${escapeHtml(firstWord(row.content))}</td>
                    <td>${escapeHtml(row.item_count)}</td>
                    <td>${Math.round(freight)}</td>
                </tr>`;
            }).join("");

            bookedTotalFreight.textContent = String(Math.round(totalFreight));
            bindBookedCheckboxEvents();
            renderCalculationState();
        }

        function renderDispatchRows(rows) {
            const dispatchRows = sortBiltyRows(rows);

            if (dispatchRows.length === 0) {
                setDispatchPlaceholder("No dispatch bilty found for selected station");
                return;
            }

            let totalFreight = 0;
            dispatchBody.innerHTML = dispatchRows.map((row) => {
                const freight = Number(row.freight || 0);
                const recovery = Number(row.recovery || 0);
                const paymentType = String(row.payment_type || "");
                totalFreight += freight;

                return `<tr data-recovery="${escapeHtml(recovery)}" data-payment-type="${escapeHtml(paymentType)}" style="text-align: left; font-size: 16px; ">
                    <td><input type="checkbox" class="dispatch-bilty-checkbox" value="${escapeHtml(row.id)}" tabindex="-1"></td>
                    <td>${escapeHtml(row.gr_number)}</td>
                    <td>${escapeHtml(row.consignor_name)}</td>
                    <td>${escapeHtml(row.consignee_name)}</td>
                    <td>${escapeHtml(firstWord(row.content))}</td>
                    <td>${escapeHtml(row.item_count)}</td>
                    <td>${Math.round(freight)}</td>
                </tr>`;
            }).join("");

            dispatchTotalFreight.textContent = String(Math.round(totalFreight));
            bindDispatchCheckboxEvents();
            renderCalculationState();
            document.dispatchEvent(new CustomEvent("challan:dispatch-updated"));
        }

        selectAllBooked.addEventListener("change", function () {
            const checkboxes = getBookedCheckboxes();
            checkboxes.forEach((checkbox) => {
                checkbox.checked = selectAllBooked.checked;
            });
            syncSelectAllBooked();
        });

        selectAllDispatch.addEventListener("change", function () {
            const checkboxes = getDispatchCheckboxes();
            checkboxes.forEach((checkbox) => {
                checkbox.checked = selectAllDispatch.checked;
            });
            syncSelectAllDispatch();
        });

        function renderTables() {
            renderBookedRows(bookedRowsState);
            renderDispatchRows(dispatchRowsState);
        }

        async function loadBookedByStation() {
            const station = stationInput.value.trim();
            updateSelectedStationLabels();

            if (!station) {
                setBookedPlaceholder("Select station to load booked bilty");
                setDispatchPlaceholder("Select station to load dispatch bilty");
                return;
            }

            setBookedPlaceholder("Loading...");
            setDispatchPlaceholder("Loading...");

            try {
                const response = await fetch(`api/get_station_booked_bilty.php?station=${encodeURIComponent(station)}`);
                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.message || "Failed to load bilty data");
                }

                bookedRowsState = sortBiltyRows((data.rows || []).map((row) => ({ ...row })));
                dispatchRowsState = [];

                const selectedStation = stationInput.value.trim().toLowerCase();
                const editStation = String(editData?.challan_station || "").trim().toLowerCase();
                if (selectedStation !== "" && selectedStation === editStation && editDispatchRows.length > 0) {
                    dispatchRowsState = sortBiltyRows(editDispatchRows.map((row) => ({ ...row })));
                }

                renderTables();
            } catch (error) {
                setBookedPlaceholder(error.message || "Failed to load bilty data");
                setDispatchPlaceholder(error.message || "Failed to load bilty data");
            }
        }

        window.dispatchBilty = async function () {
            const selectedIds = getBookedCheckboxes()
                .filter((checkbox) => checkbox.checked)
                .map((checkbox) => checkbox.value);

            if (selectedIds.length === 0) {
                showChallanNotification("Please select at least one bilty to dispatch.", "warning");
                return;
            }

            const selectedSet = new Set(selectedIds.map((value) => String(value)));
            const movingRows = bookedRowsState.filter((row) => selectedSet.has(String(row.id)));

            if (movingRows.length === 0) {
                showChallanNotification("Selected bilty rows not found.", "danger");
                return;
            }

            bookedRowsState = sortBiltyRows(bookedRowsState.filter((row) => !selectedSet.has(String(row.id))));
            dispatchRowsState = sortBiltyRows([...dispatchRowsState, ...movingRows.map((row) => ({ ...row, status: "Dispatch" }))]);
            renderTables();
        };

        window.removeBilty = async function () {
            const selectedIds = getDispatchCheckboxes()
                .filter((checkbox) => checkbox.checked)
                .map((checkbox) => checkbox.value);

            if (selectedIds.length === 0) {
                showChallanNotification("Please select at least one bilty to remove.", "warning");
                return;
            }

            const selectedSet = new Set(selectedIds.map((value) => String(value)));
            const movingRows = dispatchRowsState.filter((row) => selectedSet.has(String(row.id)));

            if (movingRows.length === 0) {
                showChallanNotification("Selected dispatch bilty rows not found.", "danger");
                return;
            }

            dispatchRowsState = sortBiltyRows(dispatchRowsState.filter((row) => !selectedSet.has(String(row.id))));
            bookedRowsState = sortBiltyRows([...bookedRowsState, ...movingRows.map((row) => ({ ...row, status: "Booked" }))]);
            renderTables();
        };

        window.removeSelfBilty = async function () {
            const selfRows = dispatchRowsState.filter((row) =>
                String(row.consignor_name || "").trim().toLowerCase() === "self"
            );

            if (selfRows.length === 0) {
                showChallanNotification("No Self bilty found in dispatch.", "warning");
                return;
            }

            const selfIds = new Set(selfRows.map((row) => String(row.id)));
            dispatchRowsState = sortBiltyRows(dispatchRowsState.filter((row) => !selfIds.has(String(row.id))));
            bookedRowsState = sortBiltyRows([...bookedRowsState, ...selfRows.map((row) => ({ ...row, status: "Booked" }))]);
            renderTables();
            showChallanNotification("Self bilty removed from dispatch.", "success");
        };

        stationInput.addEventListener("change", loadBookedByStation);
        stationInput.addEventListener("suggestion:selected", loadBookedByStation);
        stationInput.addEventListener("input", renderCalculationState);
        if (agentNameInput) {
            agentNameInput.addEventListener("input", renderCalculationState);
            agentNameInput.addEventListener("change", renderCalculationState);
        }
        if (cuttingInput) {
            cuttingInput.addEventListener("input", renderCalculationState);
        }
        if (finalInput && isEditMode) {
            finalInput.addEventListener("input", function () {
                finalInput.dataset.manual = "1";
            });
        }
        window.addEventListener("challan:refresh-bilty", loadBookedByStation);
        updateSelectedStationLabels();
        renderCalculationState();

        if (stationInput.value.trim() !== "") {
            loadBookedByStation();
        }
    }

    window.addEventListener("load", initBiltyDispatch);
})();

/* =============================
   Challan Save/Update Module
============================= */
(function initChallanSaveUpdateModule() {
    function getChallanPayload() {
        const challanIdInput = document.getElementById("challan-id");
        const challanNoInput = document.getElementById("challan-no");
        const challanDateInput = document.getElementById("challan-date");
        const stationInput = document.getElementById("challan-station");
        const vehicleInput = document.getElementById("challan-vehicle");
        const driverInput = document.getElementById("challan-driver");
        const driverContactInput = document.getElementById("challan-contact");
        const agentNameInput = document.getElementById("challan-agent-name");
        const agentContactInput = document.getElementById("challan-agent-contact");
        const paidInput = document.getElementById("calc-paid");
        const freightInput = document.getElementById("calc-freight");
        const recoveryInput = document.getElementById("calc-recovery");
        const cuttingInput = document.getElementById("calc-cutting");
        const commissionInput = document.getElementById("calc-commission");
        const finalInput = document.getElementById("calc-final");

        const biltyIds = Array.from(document.querySelectorAll("#dispatch-bilty-body .dispatch-bilty-checkbox"))
            .map((checkbox) => String(checkbox.value || "").trim())
            .filter((id) => id !== "");

        return {
            challan_id: Number(challanIdInput?.value || 0),
            challan_no: challanNoInput?.value.trim() || "",
            challan_date: challanDateInput?.value.trim() || "",
            challan_station: stationInput?.value.trim() || "",
            vehicle_no: vehicleInput?.value.trim() || "",
            driver_name: driverInput?.value.trim() || "",
            driver_contact: driverContactInput?.value.trim() || "",
            agent_name: agentNameInput?.value.trim() || "",
            agent_contact: agentContactInput?.value.trim() || "",
            paid_total: Number(paidInput?.value || 0),
            freight_total: Number(freightInput?.value || 0),
            recovery_total: Number(recoveryInput?.value || 0),
            cutting_total: Number(cuttingInput?.value || 0),
            commission_total: Number(commissionInput?.value || 0),
            final_total: Number(finalInput?.value || 0),
            bilty_ids: [...new Set(biltyIds)]
        };
    }

    function getMissingPayloadFields(payload) {
        const required = [
            ["challan_no", "Challan No."],
            ["challan_date", "Challan Date"],
            ["challan_station", "Station"],
            ["vehicle_no", "Vehicle"],
            ["driver_name", "Driver Name"],
            ["driver_contact", "Driver Contact"]
        ];

        return required
            .filter(([key]) => !payload[key])
            .map(([, label]) => label);
    }

    async function submitChallan(url, payload) {
        const response = await fetch(url, {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify(payload)
        });

        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.message || "Failed to save challan");
        }

        return data;
    }

    function initChallanSaveUpdate() {
        window.__challanSaveModuleReady = true;
        const saveButton = document.getElementById("challan-save");
        const updateButton = document.getElementById("challan-update");
        const challanIdInput = document.getElementById("challan-id");
        const refreshDelayMs = 2500;

        if (!challanIdInput || (!saveButton && !updateButton)) {
            return;
        }

        if (saveButton) {
            saveButton.addEventListener("click", async function (event) {
                event.preventDefault();
                event.stopPropagation();
                const payload = getChallanPayload();
                const missingFields = getMissingPayloadFields(payload);

                if (missingFields.length > 0) {
                    showChallanNotification(`Please fill required fields before save: ${missingFields.join(", ")}`, "warning", refreshDelayMs);
                    return;
                }

                if (payload.bilty_ids.length === 0) {
                    showChallanNotification("No dispatch bilty found to save challan.", "warning");
                    return;
                }

                try {
                    const data = await submitChallan("api/save_challan.php", payload);
                    challanIdInput.value = String(data.challan_id || "");
                    window.dispatchEvent(new Event("challan:refresh-bilty"));
                    showChallanNotification(data.message || "Challan saved successfully", "success", refreshDelayMs);
                    setTimeout(() => {
                        window.location.reload();
                    }, refreshDelayMs);
                } catch (error) {
                    showChallanNotification(error.message || "Failed to save challan", "danger", refreshDelayMs);
                }
            });
        }

        if (updateButton) {
            updateButton.addEventListener("click", async function (event) {
                event.preventDefault();
                event.stopPropagation();
                const payload = getChallanPayload();
                const missingFields = getMissingPayloadFields(payload);

                if (payload.challan_id <= 0) {
                    showChallanNotification("Please save challan first, then update.", "warning");
                    return;
                }

                if (missingFields.length > 0) {
                    showChallanNotification(`Please fill required fields before update: ${missingFields.join(", ")}`, "warning", 3400);
                    return;
                }

                if (payload.bilty_ids.length === 0) {
                    showChallanNotification("No dispatch bilty found to update challan.", "warning");
                    return;
                }

                try {
                    const data = await submitChallan("api/update_challan.php", payload);
                    challanIdInput.value = String(data.challan_id || payload.challan_id);
                    window.dispatchEvent(new Event("challan:refresh-bilty"));
                    showChallanNotification(data.message || "Challan updated successfully", "warning", refreshDelayMs);
                    setTimeout(() => {
                        window.location.reload();
                    }, refreshDelayMs);
                } catch (error) {
                    showChallanNotification(error.message || "Failed to update challan", "danger", refreshDelayMs);
                }
            });
        }

    }

    window.addEventListener("load", initChallanSaveUpdate);
})();
