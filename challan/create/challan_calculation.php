<style>
    .challan-calculation {
        margin-top: 10px;
    }

    .challan-calculation table {
        width: 100%;
        border-collapse: collapse;
    }

    .challan-calculation thead tr,
    .challan-calculation tbody tr {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
    }

    .challan-calculation th,
    .challan-calculation td {
        border: 1px solid #000;
        padding: 2px;
        vertical-align: middle;
        font-size: 15px;
    }

    .challan-calculation th {
        background: #86b5ff;

    }

    .challan-calculation input:focus {
        background-color: #86b5ff;
    }
</style>
<div class="challan-calculation">



    <table>
        <thead>
            <tr>
                <th style="display: flex; justify-content: space-between; align-items: center;">Paid <div style="font-size:14px; margin-top:4px; margin-right: 20px;">Cash: <span id="calc-paid-cash">0</span> | TBB:
                        <span id="calc-paid-tbb">0</span></div>
                </th>
                <th>Freight</th>
                <th>Recovery</th>
                <th>Cutting</th>
                <th>Commission</th>
                <th>Final</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><input type="text" name="challan-paid" id="calc-paid" value="0" readonly tabindex="-1"></td>
                <td><input type="text" name="challan-freight" id="calc-freight" value="0" readonly tabindex="-1"></td>
                <td><input type="text" name="challan-recovery" id="calc-recovery" value="0" readonly tabindex="-1"></td>
                <td><input type="text" name="challan-cutting" id="calc-cutting" value="0" readonly tabindex="-1"></td>
                <td><input type="text" name="challan-commission" id="calc-commission" value="0" readonly tabindex="-1"></td>
                <td><input type="text" name="challan-final" id="calc-final" value="0" readonly data-manual="0" tabindex="-1         "></td>
            </tr>
        </tbody>
    </table>
</div>

<script>
    (function initChallanCalculationFallback() {
        function toNumber(value) {
            const cleaned = String(value ?? "").replace(/[^0-9.-]/g, "");
            const number = parseFloat(cleaned);
            return Number.isFinite(number) ? number : 0;
        }

        function formatAmount(value) {
            const number = Number(value || 0);
            return String(Math.round(number));
        }

        function getCommissionPercent() {
            const stationInput = document.getElementById("challan-station");
            const agentInput = document.getElementById("challan-agent-name");
            const headerData = window.challanHeaderData || {};
            const agentDetails = Array.isArray(headerData.agentDetails) ? headerData.agentDetails : [];

            const selectedAgent = String(agentInput?.value || "").trim().toLowerCase();
            if (selectedAgent) {
                const agentRow = agentDetails.find((agent) =>
                    String(agent.agent_name || "").trim().toLowerCase() === selectedAgent
                );
                if (agentRow) {
                    return toNumber(agentRow.commission_percent || 0);
                }
            }

            const stationValue = String(stationInput?.value || "").trim().toLowerCase();
            if (stationValue) {
                const stationRow = agentDetails.find((agent) =>
                    String(agent.station || "").trim().toLowerCase() === stationValue
                );
                if (stationRow) {
                    return toNumber(stationRow.commission_percent || 0);
                }
            }

            return 0;
        }

        function calculateAndRender() {
            try {
                const dispatchBody = document.getElementById("dispatch-bilty-body");
                const paidInput = document.getElementById("calc-paid");
                const freightInput = document.getElementById("calc-freight");
                const recoveryInput = document.getElementById("calc-recovery");
                const cuttingInput = document.getElementById("calc-cutting");
                const commissionInput = document.getElementById("calc-commission");
                const finalInput = document.getElementById("calc-final");
                const cashPaidSpan = document.getElementById("calc-paid-cash");
                const tbbPaidSpan = document.getElementById("calc-paid-tbb");
                const isEditMode = !!(window.challanEditData && Number(window.challanEditData.challan_id || 0) > 0);

                if (!dispatchBody || !paidInput || !freightInput || !recoveryInput || !cuttingInput || !commissionInput) {
                    return;
                }

                let paid = 0;
                let cashPaid = 0;
                let tbbPaid = 0;
                let freight = 0;
                let recovery = 0;

                const rows = Array.from(dispatchBody.querySelectorAll("tr"));
                rows.forEach((row) => {
                    const checkbox = row.querySelector(".dispatch-bilty-checkbox");
                    if (!checkbox) {
                        return;
                    }

                    const cells = row.querySelectorAll("td");
                    const freightText = cells.length >= 7 ? cells[6].textContent : "0";
                    const rowFreight = toNumber(freightText);
                    const rowRecovery = toNumber(row.dataset.recovery || 0);
                    const paymentType = String(row.dataset.paymentType || "").trim().toLowerCase();

                    freight += rowFreight;
                    recovery += rowRecovery;

                    if (paymentType === "cash") {
                        cashPaid += rowFreight;
                        paid += rowFreight;
                    } else if (paymentType === "tbb") {
                        tbbPaid += rowFreight;
                        paid += rowFreight;
                    }
                });

                const cutting = toNumber(cuttingInput.value || 0);
                const commissionPercent = getCommissionPercent();
                const commissionBase = Math.max(0, freight - recovery - cutting);
                const commission = (commissionBase * commissionPercent) / 100;

                paidInput.value = formatAmount(paid);
                freightInput.value = formatAmount(freight);
                recoveryInput.value = formatAmount(recovery);
                commissionInput.value = formatAmount(commission);
                if (finalInput) {
                    const shouldAutoFinal = !(isEditMode && finalInput.dataset.manual === "1");
                    if (shouldAutoFinal) {
                        finalInput.value = formatAmount(freight - recovery - commission);
                    }
                }
                if (cashPaidSpan) {
                    cashPaidSpan.textContent = formatAmount(cashPaid);
                }
                if (tbbPaidSpan) {
                    tbbPaidSpan.textContent = formatAmount(tbbPaid);
                }
            } catch (error) {
                console.error("Challan calculation error:", error);
            }
        }

        function bindEvents() {
            const dispatchBody = document.getElementById("dispatch-bilty-body");
            const cuttingInput = document.getElementById("calc-cutting");
            const stationInput = document.getElementById("challan-station");
            const agentInput = document.getElementById("challan-agent-name");
            const finalInput = document.getElementById("calc-final");
            const isEditMode = !!(window.challanEditData && Number(window.challanEditData.challan_id || 0) > 0);

            if (cuttingInput) {
                cuttingInput.readOnly = !isEditMode;
            }

            if (finalInput) {
                finalInput.readOnly = !isEditMode;
            }

            if (cuttingInput) {
                cuttingInput.addEventListener("input", calculateAndRender);
                cuttingInput.addEventListener("change", calculateAndRender);
            }

            if (finalInput && isEditMode) {
                finalInput.addEventListener("input", function () {
                    finalInput.dataset.manual = "1";
                });
            }

            if (stationInput) {
                stationInput.addEventListener("input", calculateAndRender);
                stationInput.addEventListener("change", calculateAndRender);
            }

            if (agentInput) {
                agentInput.addEventListener("input", calculateAndRender);
                agentInput.addEventListener("change", calculateAndRender);
            }

            if (dispatchBody) {
                const observer = new MutationObserver(calculateAndRender);
                observer.observe(dispatchBody, { childList: true, subtree: true, characterData: true });
            }

            document.addEventListener("challan:dispatch-updated", calculateAndRender);
        }

        window.addEventListener("load", function () {
            bindEvents();
            calculateAndRender();
        });
    })();
</script>