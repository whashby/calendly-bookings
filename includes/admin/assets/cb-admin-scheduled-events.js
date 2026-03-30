jQuery(document).ready(function($) {

    /**
     * Clear filter name
     */
    $(document).on('click', '#clear-name', function() {
        $('#filter-name').val('');
        $('#cb-filter-form').submit();
    });
    
    /**
     * Bulk Update Status
     */
    $(document).on('click', '.cb-bulk-update', function(e) {
        e.preventDefault();
        tb_show('Bulk Update Status', '#TB_inline?inlineId=cb-bulk-update-content-modal');
    });
    
    /**
     * View Invitee History
     */
    $(document).on('click', '.cb-view-history', function(e) {
        e.preventDefault();
        
        const invitee  = $(this).data('invitee');
        const uuid  = $(this).data('uuid');
        const endpoint = '/wp-json/calendly-bookings/v1/scheduled-events/invitee-history/' + encodeURIComponent(invitee);
        
        $('#cb-dynamic-history-modal').remove();
        
        const $modal = $('<div id="cb-dynamic-history-modal" style="display:none;"></div>');
        $modal.append('<div class="cb-thickbox-form"><div class="cb-history-content"><p>Loading history…</p></div></div>');
        $('body').append($modal);
        
        const $container = $modal.find('.cb-history-content');
        
        $.get(endpoint, function(response) {
            let content = '';
            
            if (response && response.success && response.data && response.data.length > 0) {
                response.data.forEach(row => {
                    let notes = {};
                    try { notes = row.notes ? JSON.parse(row.notes) : {}; } catch(e) { notes = {}; }
                    
                    content += `
                    <div class="history-item">
                        <p><strong>Date/Time:</strong> ${row.start_time}</p>
                        <p><strong>Event:</strong> ${row.event_name}</p>
                        <p><strong>Status:</strong> ${row.status}</p>
                        <p><strong>Location:</strong> ${row.location}</p>
                        <div class="notes-section">
                            <p><strong>What was discussed:</strong> ${notes.discussed || ''}</p>
                            <p><strong>Guidance provided:</strong> ${notes.guidance || ''}</p>
                            <p><strong>Follow-up actions:</strong> ${notes.follow_up || ''}</p>`;
                    if(canEdit(row.start_time, row.status)) {
                        content+= `<p><strong>Admin notes:</strong> <span class="admin-notes-text">${notes.admin || ''}</span></p>`;
                    } else {
                        content+= `<p><strong>Admin notes:</strong> ${notes.admin || ''}</p>`;
                    }
                    content+= `
                        </div>
                    </div>`;
                        
                    if(canEdit(row.start_time, row.status)) {
                        content+= `
                        <div id="admin-notes-url" class="admin-notes">
                            <p><a href="#" class="cb-add-admin-notes" data-uuid="` + uuid + `">Add/Edit Notes</a></p>
                        </div>`;
                    }
                });
            } else {
                content = '<p>No history found for ' + invitee + '.</p>';
            }
            
            $container.html(content);

            tb_show('Invitee History: ' + invitee.replace(/_/g, ' '), '#TB_inline?inlineId=cb-dynamic-history-modal');
        }).fail(function() {
            $container.html('<p>Error loading history.</p>');
            tb_show('View Invitee History', '#TB_inline?inlineId=cb-dynamic-history-modal');
        });
    });
    
    
    /**
     * Add Admin Notes
     */
    $(document).on('click', '.cb-add-admin-notes', function(e) {
        e.preventDefault();

        const form = $(this).closest('.cb-thickbox-form');
        const uuid  = $(this).data('uuid');
        const notes = form.find('.admin-notes-text').text();

        $(this).hide();
        $('#admin-notes-url').after(
        //tb_show('Add Admin Notes', '#TB_inline?inlineId=cb-admin-notes-content-modal')
        `<div id="cb-admin-notes-content-modal" style="margin-bottom:30px;">
            <h2>Add Admin Notes</h2>
            <div class="cb-thickbox-form">
                <label for="notes-admin">Thoughts for next session
                <textarea id="notes-discussed" name="notes-admin" class="large-text" autofocus>${notes || ''}</textarea>
                </label>
            </div>
            <div class="cb-thickbox-actions">
                <button type="submit" id="cb-admin-notes-submit" data-uuid="` + uuid + `" class="button button-primary cb-save-btn">Save</button>
                <button type="button" id="cb-admin-notes-cancel" class="button cb-cancel-btn">Cancel</button>
            </div>
        </div>`
        );
    });
    
    
    /**
     * View Scheduled Event Record
     */
    $(document).on('click', '.cb-view-record', function(e) {
        e.preventDefault();
        
        const uuid = $(this).data('uuid');
        const endpoint = '/wp-json/calendly-bookings/v1/scheduled-events/view/' + encodeURIComponent(uuid);
    
        $('#cb-event-' + uuid + '-modal').remove();
    
        const $modal = $('<div id="cb-event-' + uuid + '-modal" style="display:none;"></div>');
        $modal.append('<div class="cb-thickbox-form"><div class="cb-event-content"><p>Loading event…</p></div></div>');
        $('body').append($modal);
        
        const $container = $modal.find('.cb-event-content');
    
        $.get(endpoint, function(response) {
            let content = '';
        
            if (response && response.success && response.data) {
                const row = response.data;
            
                let notes = {};
                try { notes = row.notes ? JSON.parse(row.notes) : {}; } catch(e) { notes = {}; }
            
content = `
  <h2>Event Details</h2>
  <div class="cb-thickbox-form cb-event-details">
    <p><strong>Invitee:</strong> ${row.invitee_name}</p>
    <p><strong>Event:</strong> ${row.event_name}</p>
    <p><strong>Date/Time:</strong> ${row.start_time}</p>
    <p><strong>Location:</strong> ${row.location}</p>
    <p><strong>Status:</strong> <span class="record-status" data-status="${row.status}">${row.status}</span></p>
    
    <h3>Notes</h3>
    <p><strong>What was discussed:</strong> <span class="note-text" data-field="discussed">${notes.discussed || ''}</span></p>
    <p><strong>Guidance provided:</strong> <span class="note-text" data-field="guidance">${notes.guidance || ''}</span></p>
    <p><strong>Follow-up actions:</strong> <span class="note-text" data-field="follow_up">${notes.follow_up || ''}</span></p>
    <p><strong>Admin notes:</strong> <span class="note-text" data-field="admin">${notes.admin || ''}</span></p>
    
    <input type="hidden" name="uuid" value="${uuid}">
    <div class="cb-thickbox-actions">
        <button type="button" class="button cb-edit-toggle"${canEdit(row.start_time,row.status) ? '' : ' style=display:none;'}>Edit</button>
        <button type="submit" id="cb-event-details-submit" class="button button-primary cb-save-btn" style="display:none;">Save</button>
        <button type="button" id="cb-edit-event-cancel" class="button cb-cancel-btn" style="display:none;">Cancel</button>
    </div>
  </div>
`;

            
            
            } else {
                content = '<p>No event details found.</p>';
            }
    
            $container.html(content);

            tb_show('Scheduled Event', '#TB_inline?inlineId=cb-event-' + uuid + '-modal');
        }).fail(function() {
            $container.html('<p>Error loading event.</p>');
            tb_show('Scheduled Event', '#TB_inline?inlineId=cb-event-' + uuid + '-modal');
        });
    });
    
    /**
     * Edit toggle inside ThickBox
     */
    $(document).on('click', '.cb-edit-toggle', function() {
        const form = $(this).closest('.cb-thickbox-form');
        
        // Replace status span with radio button
        form.find('.record-status').each(function() {
            const status = $(this).data('status');
            if(status == 'active'){
                //const value = $(this).text();
                $(this).replaceWith(
                `<div class="cb-status-options">
                <label><input type="radio" name="event-status" value="active" ${status == 'active'? 'checked' : ''}>Active</label>
                <label><input type="radio" name="event-status" value="canceled" ${status == 'canceled'? 'checked' : ''}>Canceled</label>
                <label><input type="radio" name="event-status" value="completed" ${status == 'completed'? 'checked' : ''}>Completed</label>
                </div>`
                );
            }
        });
        
        // Replace each note-text span with a textarea
        form.find('.note-text').each(function() {
            const field = $(this).data('field');
            const value = $(this).text();
            $(this).replaceWith(
                `<textarea name="notes-${field}">${value}</textarea>`
            );
        });
        
        form.find('.cb-save-btn').show();
        form.find('.cb-cancel-btn').show();
        $(this).hide();
    });
    
    /**
     * Save edits via AJAX
     */
    $(document).on('click', '.cb-save-btn', function(e) {
        
        e.preventDefault();

        const id = $(this).attr('id');

        if (id === 'cb-walkin-submit') {
            const form = $(this).closest('form');
            const firstname = form.find('input[name="firstname"]').val();
            const lastname = form.find('input[name="lastname"]').val();
            const email = form.find('input[name="email"]').val();
            const initialSession = form.find('#initial_session option:selected');
            const start_time = form.find('#initial_date').val()+'T'+ form.find('#initial_time').val()+':00Z';
            const location = form.find('#location').val();
            const notes = {
                discussed: form.find('textarea[name="notes-discussed"]').val(),
                guidance: form.find('textarea[name="notes-guidance"]').val(),
                follow_up: form.find('textarea[name="notes-follow-up"]').val()
            };
            const followupSession = form.find('#followup_session option:selected');
            const followup_date = form.find('#followup_date').val();
            const followup_time = form.find('#followup_time').val();

            // Get form values as an array of {name, value}
            const data = [];
            
            // Add extra data attributes from the selected option
            data.push({ name: 'firstname', value: firstname });
            data.push({ name: 'lastname', value: lastname });
            data.push({ name: 'email', value: email });
            data.push({ name: 'initial_session_id', value: initialSession.data('id') });
            data.push({ name: 'initial_session_uuid', value: initialSession.data('uuid') });
            data.push({ name: 'start_time', value: start_time });
            data.push({ name: 'location', value: location });
            data.push({ name: 'notes', value: notes });
            data.push({ name: 'followup_session_id', value: followupSession.data('id') });
            data.push({ name: 'followup_session_uuid', value: followupSession.data('uuid') });
            data.push({ name: 'followup_date', value: followup_date });
            data.push({ name: 'followup_time', value: followup_time });


            console.log(data);
        
            if (!confirm("Are you sure you want to create this walk-in?")) return;
        
            $.post(ajaxurl, {
                action: 'cb_create_walkin',
                data: JSON.stringify(data)
            }, function(response) {
                if (response.success) {
                    alert('Walk-in created successfully');
                    tb_remove();
                    location.reload();
                } else {
                    alert('Error: ' + response.data.message);
                }
            });
            return;
        }
    
        if (id === 'cb-bulk-update-submit') {
            const bulk_status = $('input[name="bulk-status"]:checked').val();
            const selected = $('.cb-bulk-select:checked').map(function() {
                return $(this).val();
            }).get();

            if (!bulk_status || selected.length === 0) {
                alert('Please select events and a status.');
                return;
            }

            const uuids = selected.join(',');

            $.post(ajaxurl, {
                action: 'calendly_bookings_bulk_update_scheduled_events',
                uuids: uuids,
                status: bulk_status
            }, function(response) {
                if (response.success) {
                    alert('Events updated successfully.');
                    tb_remove();
                    location.reload();
                } else {
                    alert(response.data.message || 'Update failed.');
                }
            });
            return;
        }
    
        if (id === 'cb-admin-notes-submit') {
            const form = $(this).closest('.cb-thickbox-form');
            const uuid = $(this).data('uuid');

            if (!uuid) {
                alert('Could not determine event UUID.');
                return;
            }
    
            const notes = {
                admin: form.find('textarea[name="notes-admin"]').val(),
            }
        
            if (!confirm("Are you sure you want to save these changes?")) return;
            
            $.post(ajaxurl, {
                action: 'calendly_bookings_add_admin_notes',
                uuid: uuid,
                notes: notes
            }, function(response) {
                if (response.success) {
                    alert('Changes saved.');
                    location.reload();
                } else {
                    alert('Error: ' + (response.data?.message || 'Save failed.'));
                }
            }).fail(function() {
                alert('Error saving notes.');
            });
            return;
        }
    
        if (id === 'cb-event-details-submit') {
            const form = $(this).closest('.cb-thickbox-form');
            const uuid = form.find('input[name="uuid"]').val();
        
            if (!uuid) {
                alert('Could not determine event UUID.');
                return;
            }
        
            const status = $('input[name="event-status"]:checked').val();
            const notes = {
                discussed: form.find('textarea[name="notes-discussed"]').val(),
                guidance: form.find('textarea[name="notes-guidance"]').val(),
                follow_up: form.find('textarea[name="notes-follow_up"]').val()
            };
        
            if (!confirm("Are you sure you want to save these changes?")) return;
        
            $.post(ajaxurl, {
                action: 'calendly_bookings_update_scheduled_event',
                uuid: uuid,
                status: status,
                notes: notes
            }, function(response) {
                if (response.success) {
                    alert('Changes saved.');
                    tb_remove();
                    location.reload();
                } else {
                    alert('Error: ' + (response.data?.message || 'Save failed.'));
                }
            }).fail(function() {
                alert('Error saving notes.');
            });
            return;
        }
    });

    /**
     * Create Walk-in
     */
    $(document).on('click', '#cb-create-walkin', function() {
        // Clear old options
        $('#initial_session, #location, #followup_session').empty();
    
        $('#followup_session').append(`<option value="">Select a session</option>`);
        // Fetch event types
        $.get('/wp-json/calendly-bookings/v1/event-types', function(response) {
            if (response.success && response.data) {
                response.data.forEach(type => {
                        $('#initial_session').append(`<option name="${type.name}" value="${type.name}" data-id="${type.id}" data-uuid="${type.uuid}">${type.name}</option>`);
                        if(type.name.toLowerCase() !== "initial meeting") {
                            $('#followup_session').append(`<option name="${type.name}" value="${type.name}" data-id="${type.id}" data-uuid="${type.uuid}">${type.name}</option>`);
                        }
                });
            }
        });
    
        // Fetch meeting locations
        $.get('/wp-json/calendly-bookings/v1/scheduled-events/locations', function(response) {
            if (response.success && response.data) {
                response.data.forEach(loc => {
                    $('#location').append(`<option value="${loc.id}">${loc.name}</option>`);
                });
            }
        });

        tb_show('Create Walk-in', '#TB_inline?inlineId=cb-walkin-modal');
    });

    // When follow-up session changes, fetch availability
    $(document).on('change', '#followup_session', function () {
        const uuid = $(this).find('option:selected').data('uuid');

        if (!uuid) return;
        const startIso = new Date().toISOString();

        fetch(`/wp-json/calendly-bookings/v1/event-availability?uuid=${uuid}&start_iso=${startIso}`, {
            credentials: 'same-origin'
        })
        .then(res => res.json())
        .then(response => {
            if (!response.success || !response.data) {
                console.warn("No availability returned");
                return;
            }

            const slots = response.data;
            const grouped = {};
            slots.forEach(slot => {
                const date = slot.start_time.split('T')[0]; // YYYY-MM-DD
                if (!grouped[date]) grouped[date] = [];
                grouped[date].push(slot);
            });

            const $date = $('#followup_date');
            const $time = $('#followup_time');

            $('#followup_date').empty();
            $('#followup_date').append(`<option>Select a date</option>`);
            $('#followup_time').empty();
            $('#followup_time').append(`<option>Select a time</option>`);

            // Populate dates
            Object.keys(grouped).forEach(date => {
                $date.append(`<option value="${date}">${date}</option>`);
            });

            // Auto-select earliest date
            const firstDate = Object.keys(grouped)[0];
            $('#next-available-slot').text(firstDate);

            // Populate times for earliest date
            grouped[firstDate].forEach(slot => {
                const dateObj = new Date(slot.start_time);
                const time = dateObj.toLocaleTimeString([], {
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: true
                });
                $time.append(`<option value="${slot.start_time}">${time}</option>`);
            });
        });
    });

    // When date changes, update times (same as frontend.js)
    $(document).on('change', '#followup_date', function () {
        const selectedDate = $(this).val();
        const uuid = $('#followup_session').data('uuid');

        if (!uuid || !selectedDate) return;

        const startIso = new Date().toISOString();

        fetch(`/wp-json/calendly-bookings/v1/event-availability?uuid=${uuid}&start_iso=${startIso}`, {
            credentials: 'same-origin'
        })
        .then(res => res.json())
        .then(response => {
            if (!response.success || !response.data) return;

            const slots = response.data.filter(slot =>
                slot.start_time.startsWith(selectedDate)
            );

            const $time = $('#followup_time');
            $time.empty();

            slots.forEach(slot => {
                const time = new Date(slot.start_time).toTimeString().slice(0, 5);
                $time.append(`<option value="${slot.start_time}">${time}</option>`);
            });
        });
    });

    /**
     *  Cancel button handler
     */
    $(document).on('click', '.cb-cancel-btn', function() {
        
        if($(this).attr("id") === 'cb-edit-event-cancel') {
            const form = $(this).closest('.cb-thickbox-form');
        
            // Restore text view from textareas
            form.find('textarea').each(function() {
                const field = $(this).attr('name').replace('notes-', '');
                const value = $(this).val();
                $(this).replaceWith(
                    `<span class="note-text" data-field="${field}">${value}</span>`
                );
            });
        
            form.find('.cb-edit-toggle').show();
            form.find('.cb-save-btn').hide();
            form.find('.cb-cancel-btn').hide();
        } else {
			if($(this).attr('id') === 'cb-admin-notes-cancel') {
                $('#cb-admin-notes-content-modal').remove();
                $('.cb-add-admin-notes').show();
			} else {
                tb_remove();
			}
		}
    });
    

    
    function canEdit(start_time, status)  {
        // Parse start_time into a Date object
        const eventDate = new Date(start_time);
        const now = new Date();
        // Two weeks in milliseconds
        const twoWeeksMs = 14 * 24 * 60 * 60 * 1000;
        // Check if event is less than 2 weeks old
        return ((now - eventDate) <= twoWeeksMs && status === 'active')?true:false;
    }
    
    
    /**
    * Reschedule / Cancel (Calendly iframe)
    * TODO: redirect through API endpoint
    */
    $(document).on('click', '.cb-reschedule, .cb-cancel', function(e) {
        e.preventDefault();
        const url = $(this).attr('href');
        const title = $(this).hasClass('cb-reschedule') ? 'Reschedule Event' : 'Cancel Event';
        tb_show(title, url);
    });
    
});
