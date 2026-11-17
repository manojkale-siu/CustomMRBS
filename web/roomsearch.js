// This file is part of the MRBS block for Moodle
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

document.addEventListener('DOMContentLoaded', function() {
    "use strict";

    const form = document.getElementById('mrbs-room-search');
    if (!form) {
        return;
    }
    const rows = document.getElementById('mrbs-room-rows');
    const status = document.getElementById('mrbs-room-status');
    const table = document.getElementById('mrbs-room-table');
    let request;

    async function roomSearch() {
        if (request) {
            request.abort();
        }
        if (!form.checkValidity()) {
            return;
        }
        request = new AbortController();
        const current = request;
        const params = new URLSearchParams(new FormData(form));
        const [year, month, day] = params.get('date').split('-');
        params.set('year', year);
        params.set('month', month);
        params.set('day', day);
        params.delete('date');
        if (params.has('time')) {
            const [hour, minute] = params.get('time').split(':');
            params.set('hour', hour);
            params.set('minute', minute);
            params.delete('time');
        }

        rows.replaceChildren();
        status.hidden = true;
        table.hidden = true;
        try {
            const response = await fetch(form.dataset.endpoint + '?' + params, {signal: current.signal});
            if (!response.ok) {
                throw new Error('Room search failed');
            }
            const rooms = await response.json();
            if (current !== request) {
                return;
            }
            if (rooms.length === 0) {
                status.textContent = form.dataset.empty;
                status.className = 'alert alert-info';
                status.hidden = false;
                return;
            }

            for (const room of rooms) {
                const row = rows.insertRow();
                row.insertCell().textContent = room.area;
                const link = document.createElement('a');
                link.href = room.url;
                link.textContent = room.name;
                row.insertCell().appendChild(link);
                row.insertCell().textContent = room.description || '';
                row.insertCell().textContent = room.capacity;
            }
            table.hidden = false;
        } catch (error) {
            if (error.name !== 'AbortError' && current === request) {
                status.textContent = form.dataset.error;
                status.className = 'alert alert-warning';
                status.hidden = false;
            }
        }
    }

    form.addEventListener('submit', function(event) {
        event.preventDefault();
        roomSearch();
    });
    form.addEventListener('change', roomSearch);
    roomSearch();
});

var mrbs_weekdaynames = null;

function SetWeekDayNames(mon, tue, wed, thu, fri, sat, sun) {
    "use strict";
    mrbs_weekdaynames = new Array(7);
    mrbs_weekdaynames[1] = mon;
    mrbs_weekdaynames[2] = tue;
    mrbs_weekdaynames[3] = wed;
    mrbs_weekdaynames[4] = thu;
    mrbs_weekdaynames[5] = fri;
    mrbs_weekdaynames[6] = sat;
    mrbs_weekdaynames[0] = sun;
}

function ChangeOptionDays(formObj, prefix, updatefreerooms, roomsearch) {
    "use strict";
    var j;
    var DaysObject = eval("formObj." + prefix + "day");
    var currentDay = DaysObject.selectedIndex;
    var MonthObject = eval("formObj." + prefix + "month");
    var YearObject = eval("formObj." + prefix + "year");

    //wipe current list
    for (j = DaysObject.options.length; j>=0; j--) {
        DaysObject.options[j] = null;
    }
    var day = DaysObject.selectedIndex + 1;
    var month = MonthObject.selectedIndex;
    var year = YearObject.options[YearObject.selectedIndex].value;

    var i = new Date();
    i.setDate(1);
    i.setMonth(month);
    i.setYear(year);

    while (i.getMonth() == month) {

        DaysObject.options[i.getDate() - 1] = new Option(mrbs_weekdaynames[i.getDay()] + " " + i.getDate(), i.getDate());
        i.setTime(i.getTime() + 86400000);
    }
    DaysObject.selectedIndex = currentDay;

    if (updatefreerooms) {
        updateFreeRooms();
    }
    if (roomsearch) {
        document.getElementById('mrbs-room-search')?.requestSubmit();
    }
}