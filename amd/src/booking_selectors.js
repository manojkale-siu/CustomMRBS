// This file is part of the MRBS block for Moodle.

define([], function() {
    "use strict";

    var config = {};
    var elements = {};

    function option(value, label, selected) {
        var item = document.createElement('option');
        item.value = value;
        item.textContent = label;
        if (selected) {
            item.selected = true;
        }
        return item;
    }

    function clearSelect(select, placeholder) {
        select.innerHTML = '';
        select.appendChild(option('', placeholder, false));
        select.disabled = true;
    }

    function fillSelect(select, items, placeholder, selected, emptyLabel) {
        select.innerHTML = '';
        select.appendChild(option('', placeholder, false));

        if (!items.length && emptyLabel) {
            select.appendChild(option('0', emptyLabel, selected === 0 || selected === '0'));
        } else {
            items.forEach(function(item) {
                select.appendChild(option(String(item.id), item.label, String(item.id) === String(selected)));
            });
        }
        select.disabled = false;
    }

    function request(action, params) {
        var url = new URL(config.endpoint, window.location.origin);
        url.searchParams.set('action', action);
        Object.keys(params || {}).forEach(function(key) {
            if (params[key] !== undefined && params[key] !== null && params[key] !== '') {
                url.searchParams.set(key, params[key]);
            }
        });

        return fetch(url.toString(), {
            credentials: 'same-origin'
        }).then(function(response) {
            if (!response.ok) {
                throw new Error('Request failed');
            }
            return response.json();
        }).then(function(data) {
            if (data.error) {
                throw new Error(data.error);
            }
            return data.results || [];
        });
    }

    function selectedText(select) {
        if (!select || select.selectedIndex < 0) {
            return '';
        }
        var selected = select.options[select.selectedIndex];
        return selected && selected.value ? selected.textContent : '';
    }

    function updateReservationName() {
        var course = selectedText(elements.course);
        var faculty = selectedText(elements.faculty);
        var label = course && faculty ? course + ' - ' + faculty : '';

        if (elements.name) {
            elements.name.value = label;
        }
        if (elements.preview) {
            elements.preview.textContent = label || config.emptyReservationText;
        }
    }

    function showError(message) {
        if (elements.message) {
            elements.message.textContent = message || '';
        }
    }

    function loadProgrammes(selected) {
        clearSelect(elements.programme, config.programmePlaceholder);
        return request('programmes').then(function(items) {
            fillSelect(elements.programme, items, config.programmePlaceholder, selected);
        });
    }

    function loadBatches(selected) {
        clearSelect(elements.batch, config.batchPlaceholder);
        clearSelect(elements.semester, config.semesterPlaceholder);
        clearSelect(elements.course, config.coursePlaceholder);
        clearSelect(elements.faculty, config.facultyPlaceholder);
        fillSelect(elements.group, [], config.groupPlaceholder, 0, config.allStudentsLabel);
        updateReservationName();

        if (!elements.programme.value) {
            return Promise.resolve();
        }

        return request('batches', {programmeid: elements.programme.value}).then(function(items) {
            fillSelect(elements.batch, items, config.batchPlaceholder, selected);
        });
    }

    function loadSemesters(selected) {
        clearSelect(elements.semester, config.semesterPlaceholder);
        clearSelect(elements.course, config.coursePlaceholder);
        clearSelect(elements.faculty, config.facultyPlaceholder);
        fillSelect(elements.group, [], config.groupPlaceholder, 0, config.allStudentsLabel);
        updateReservationName();

        if (!elements.batch.value) {
            return Promise.resolve();
        }

        return request('semesters', {batchid: elements.batch.value}).then(function(items) {
            fillSelect(elements.semester, items, config.semesterPlaceholder, selected);
        });
    }

    function loadCourses(selected) {
        clearSelect(elements.course, config.coursePlaceholder);
        clearSelect(elements.faculty, config.facultyPlaceholder);
        fillSelect(elements.group, [], config.groupPlaceholder, 0, config.allStudentsLabel);
        updateReservationName();

        if (!elements.semester.value) {
            return Promise.resolve();
        }

        return request('courses', {semesterid: elements.semester.value}).then(function(items) {
            fillSelect(elements.course, items, config.coursePlaceholder, selected);
        });
    }

    function loadFaculties(selected) {
        clearSelect(elements.faculty, config.facultyPlaceholder);
        updateReservationName();

        if (!elements.course.value) {
            return Promise.resolve();
        }

        return request('faculties', {courseid: elements.course.value}).then(function(items) {
            fillSelect(elements.faculty, items, config.facultyPlaceholder, selected);
            updateReservationName();
        });
    }

    function loadGroups(selected) {
        clearSelect(elements.group, config.groupPlaceholder);

        if (!elements.course.value) {
            fillSelect(elements.group, [], config.groupPlaceholder, 0, config.allStudentsLabel);
            return Promise.resolve();
        }

        return request('groups', {courseid: elements.course.value}).then(function(items) {
            fillSelect(elements.group, items, config.groupPlaceholder, selected || 0, config.allStudentsLabel);
        });
    }

    function bindEvents() {
        elements.programme.addEventListener('change', function() {
            showError('');
            loadBatches(0).catch(function(error) {
                showError(error.message || config.failureMessage);
            });
        });
        elements.batch.addEventListener('change', function() {
            showError('');
            loadSemesters(0).catch(function(error) {
                showError(error.message || config.failureMessage);
            });
        });
        elements.semester.addEventListener('change', function() {
            showError('');
            loadCourses(0).catch(function(error) {
                showError(error.message || config.failureMessage);
            });
        });
        elements.course.addEventListener('change', function() {
            showError('');
            Promise.all([loadFaculties(0), loadGroups(0)]).catch(function(error) {
                showError(error.message || config.failureMessage);
            });
        });
        elements.faculty.addEventListener('change', updateReservationName);
    }

    function init(initConfig) {
        config = initConfig || {};
        elements.form = document.forms[config.formName || 'main'];
        if (!elements.form) {
            return;
        }

        elements.programme = elements.form.elements.programmeid;
        elements.batch = elements.form.elements.batchid;
        elements.semester = elements.form.elements.semesterid;
        elements.course = elements.form.elements.courseid;
        elements.faculty = elements.form.elements.facultyid;
        elements.group = elements.form.elements.groupid;
        elements.name = elements.form.elements.name;
        elements.preview = document.getElementById(config.previewId);
        elements.message = document.getElementById(config.messageId);

        bindEvents();
        loadProgrammes(config.selectedProgramme || 0)
            .then(function() {
                return loadBatches(config.selectedBatch || 0);
            })
            .then(function() {
                return loadSemesters(config.selectedSemester || 0);
            })
            .then(function() {
                return loadCourses(config.selectedCourse || 0);
            })
            .then(function() {
                return Promise.all([
                    loadFaculties(config.selectedFaculty || 0),
                    loadGroups(config.selectedGroup || 0)
                ]);
            })
            .then(updateReservationName)
            .catch(function(error) {
                showError(error.message || config.failureMessage);
            });
    }

    return {
        init: init
    };
});
