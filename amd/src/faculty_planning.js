define([], function() {
    "use strict";
    return {init: function() {
        var rows = document.getElementById('planning-rows');
        var count = document.getElementById('planning-rowcount');
        var add = document.getElementById('planning-add');
        if (add) {
            add.addEventListener('click', function() {
                if (rows.children.length < 500) {
                    rows.appendChild(document.getElementById('planning-row-template').content.cloneNode(true));
                    count.value = rows.children.length;
                }
            });
        }
        rows.addEventListener('click', function(event) {
            if (event.target.classList.contains('planning-remove') && rows.children.length > 1) {
                event.target.closest('.planning-row').remove();
                count.value = rows.children.length;
            }
        });
    }};
});
