var mrbsFreeRoomsRequest = null;

function mrbsSelectedValue(field) {
    "use strict";

    if (!field) {
        return '';
    }
    if (field.options) {
        return field.options[field.selectedIndex].value;
    }
    if (field.length && typeof field.value === 'undefined') {
        var i;
        for (i = 0; i < field.length; i++) {
            if (field[i].checked) {
                return field[i].value;
            }
        }
        return '';
    }
    return field.value;
}

function mrbsSetRoomsMessage(message) {
    "use strict";

    var roomsInput = document.getElementsByName('rooms[]')[0];
    if (!roomsInput) {
        return;
    }

    roomsInput.options.length = 0;
    roomsInput.options[0] = new Option(message, '');
    roomsInput.options[0].disabled = true;
}

function updateFreeRooms() {
    "use strict";

    var form = document.forms["main"];
    var searchstring, area, areasInput, forcebookInput, xmlHttp, hasPeriod;

    if (!form) {
        return false;
    }

    hasPeriod = !!form.period;
    searchstring = "?day=" + encodeURIComponent(mrbsSelectedValue(form.day)) +
        "&month=" + encodeURIComponent(mrbsSelectedValue(form.month)) +
        "&year=" + encodeURIComponent(mrbsSelectedValue(form.year)) +
        "&duration=" + encodeURIComponent(form.duration ? form.duration.value : '') +
        "&dur_units=" + encodeURIComponent(mrbsSelectedValue(form.dur_units)) +
        "&currentroom=" + encodeURIComponent(typeof currentroom === 'undefined' ? 0 : currentroom);

    if (hasPeriod) {
        searchstring += "&period=" + encodeURIComponent(form.period.selectedIndex);
    } else {
        searchstring += "&hour=" + encodeURIComponent(form.hour ? form.hour.value : 0) +
            "&minute=" + encodeURIComponent(form.minute ? form.minute.value : 0);
        if (form.ampm) {
            searchstring += "&ampm=" + encodeURIComponent(mrbsSelectedValue(form.ampm));
        }
    }

    if (form.all_day && form.all_day.checked) {
        searchstring += "&all_day=yes";
    }

    areasInput = document.getElementsByName('areas');
    area = areasInput.length ? mrbsSelectedValue(areasInput[0]) : 0;
    searchstring += "&area=" + encodeURIComponent(area);

    if (typeof canforcebook !== 'undefined' && canforcebook) {
        forcebookInput = document.getElementsByName('forcebook');
        if (forcebookInput.length && forcebookInput[0].checked) {
            searchstring = "?area=" + encodeURIComponent(area);
        }
    }

    try {
        xmlHttp = new XMLHttpRequest();
    } catch (e) {
        mrbsSetRoomsMessage(window.mrbsUpdateRoomsFailure || 'Could not update rooms.');
        return false;
    }

    if (mrbsFreeRoomsRequest) {
        mrbsFreeRoomsRequest.abort();
    }
    mrbsFreeRoomsRequest = xmlHttp;

    xmlHttp.onreadystatechange = function () {
        var freeRooms, roomsInput, currentSelection, i, room;

        if (xmlHttp.readyState !== 4) {
            return;
        }

        if (mrbsFreeRoomsRequest === xmlHttp) {
            mrbsFreeRoomsRequest = null;
        }

        roomsInput = document.getElementsByName('rooms[]')[0];
        if (!roomsInput) {
            return;
        }

        if (xmlHttp.status < 200 || xmlHttp.status >= 300) {
            mrbsSetRoomsMessage(window.mrbsUpdateRoomsFailure || 'Could not update rooms.');
            return;
        }

        freeRooms = xmlHttp.responseText.split("\n");
        currentSelection = [];

        if (roomsInput.selectedIndex !== -1) {
            for (i = 0; i < roomsInput.length; i++) {
                currentSelection[roomsInput.options[i].value] = roomsInput.options[i].selected;
            }
        }

        roomsInput.options.length = 0;

        for (i = 0; i < freeRooms.length; i++) {
            if (freeRooms[i].search(/^\s*$/) !== -1) {
                continue;
            }
            room = freeRooms[i].split(/,(.*)/);
            roomsInput.options[roomsInput.length] = new Option(room[1], room[0]);

            if (currentSelection[room[0]]) {
                roomsInput.options[roomsInput.length - 1].selected = true;
            }
        }

        if (typeof window.CustomEvent === 'function') {
            document.dispatchEvent(new CustomEvent('block-mrbs:roomsupdated', {
                detail: {
                    form: form
                }
            }));
        }
    };

    xmlHttp.onerror = function () {
        mrbsSetRoomsMessage(window.mrbsUpdateRoomsFailure || 'Could not update rooms.');
    };
    xmlHttp.ontimeout = function () {
        mrbsSetRoomsMessage(window.mrbsUpdateRoomsFailure || 'Could not update rooms.');
    };
    xmlHttp.timeout = 10000;
    xmlHttp.open("GET", "updatefreerooms.php" + searchstring, true);
    xmlHttp.send(null);

    return true;
}
