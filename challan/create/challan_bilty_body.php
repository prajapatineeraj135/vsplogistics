<!-- Bilty list layout and functionality are loaded from shared assets -->
<div class="bilty">




    <div class="bilty-left ">
        <div class="scrollable">

            <table>
                <thead>
                    <tr>

                        <th colspan="7" style="text-align: center; font-size: 15px; background: #86b5ff; ">
                            Bilty-Booked : <span id="selected-station-booked">-</span></th>
                    </tr>
                    <tr>
                        <th width="2%" ><input type="checkbox" id="select-all-booked" tabindex="-1"></th>
                        <th>Gr</th>
                        <th>Consignor</th>
                        <th>Consignee</th>
                        <th>Content</th>
                        <th>Item</th>
                        <th>Freight</th>
                    </tr>
                </thead>
                <tbody id="booked-bilty-body" class="bilty-body">
                    <tr>
                        <td colspan="7" style="text-align:center;">Select station to load booked bilty</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="6" style="text-align: right; font-weight: 700; padding-right: 10px;">Total Freight</th>
                        <th id="booked-total-freight">0</th>
                    </tr>


                </tfoot>
            </table>
        </div>
        <button class="dispatch btn" onclick="dispatchBilty()" tabindex="-1">Add Dispatch</button>
    </div>
    <div class="bilty-right">
        <div class="scrollable">

            <table>
                <thead>

                    <tr>
                        <th colspan="7" class="dispatch-title" style="text-align: center; font-size: 15px; background: #86b5ff;">
                            <button type="button" class="remove-self btn" onclick="removeSelfBilty()" tabindex="-1">Remove Self</button>
                            <span>Bilty-Dispatching : <span id="selected-station-dispatch">-</span></span>
                        </th>
                    </tr>
                    <tr>
                        <th width="2%" ><input type="checkbox" id="select-all-dispatch" tabindex="-1"></th>
                        <th>Gr</th>
                        <th>Consignor</th>
                        <th>Consignee</th>
                        <th>Content</th>
                        <th>Item</th>
                        <th>Freight</th>
                    </tr>
                </thead>
                <tbody id="dispatch-bilty-body" class="bilty-body ">
                    <tr>
                        <td colspan="7" style="text-align:center;">No dispatch bilty selected</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="6" style="text-align: right; font-weight: 700; padding-right: 10px;">Total Freight</th>
                        <th id="dispatch-total-freight">0</th>
                    </tr>

                </tfoot>
            </table>
        </div>
        <button class="remove btn" onclick="removeBilty()" tabindex="-1">Remove</button>
    </div>
</div>

