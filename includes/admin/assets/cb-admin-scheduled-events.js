function canEdit(start_time, status)  {
    // Parse start_time into a Date object
    const eventDate = new Date(start_time);
    const now = new Date();
    // Two weeks in milliseconds
    const twoWeeksMs = 14 * 24 * 60 * 60 * 1000;
    // Check if event is less than 2 weeks old
    return !!(eventDate > now || ((now - eventDate) <= twoWeeksMs && (status === 'active' || status === 'completed')));
}

function cbAdminEscape(value) {
    return jQuery('<div>').text(value == null ? '' : String(value)).html().replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

jQuery(document).ready(function($) {
  $(document).on('click', '#cb-refresh-scheduled-events', function(e) {
      e.preventDefault(); const $button=$(this); $button.prop('disabled',true).text('Refreshing…');
      $.post(cb_admin.ajaxurl,{action:'cb_sync_scheduled_events_now',nonce:cb_admin.nonce},function(r){ if(r&&r.success) window.location.reload(); else alert((r&&r.data&&r.data.message)||'Unable to refresh scheduled events.'); },'json').fail(function(){ alert('Unable to refresh scheduled events.'); }).always(function(){ $button.prop('disabled',false).text('Refresh from Calendly'); });
  });


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
        const email = $(this).data('email') || '';
        const uuid  = $(this).data('uuid');
        const endpoint = email
            ? (CB_REST.root || '/wp-json/calendly-bookings/v1/') + 'scheduled-events/invitee-history-by-email?email=' + encodeURIComponent(email)
            : (CB_REST.root || '/wp-json/calendly-bookings/v1/') + 'scheduled-events/invitee-history/' + encodeURIComponent(invitee);
        
        $('#cb-dynamic-history-modal').remove();
        
        const $modal = $('<div id="cb-dynamic-history-modal" style="display:none;"></div>');
        $modal.append('<div class="cb-thickbox-form"><div class="cb-history-content"><p>Loading history…</p></div></div>');
        $('body').append($modal);
        
        const $container = $modal.find('.cb-history-content');
        
        $.ajax({ url: endpoint, method: 'GET', headers: { 'X-WP-Nonce': CB_REST.nonce } }).done(function(response) {
            let content = '';
            
            if (response && response.success && response.data && response.data.length > 0) {
                response.data.forEach(row => {
                    let notes = {};
                    try { notes = row.notes ? JSON.parse(row.notes) : {}; } catch(e) { notes = {}; }
                    
                    content += `
                    <div class="history-item">
                        <p><strong>Date/Time:</strong> ${cbAdminEscape(row.start_time)}</p>
                        <p><strong>Event:</strong> ${cbAdminEscape(row.event_name)}</p>
                        <p><strong>Status:</strong> ${cbAdminEscape(row.status)}</p>
                        <p><strong>Location:</strong> ${cbAdminEscape(row.location)}</p>
                        <div class="notes-section">
                            <p><strong>What was discussed:</strong> ${cbAdminEscape(notes.discussed || '')}</p>
                            <p><strong>Guidance provided:</strong> ${cbAdminEscape(notes.guidance || '')}</p>
                            <p><strong>Follow-up actions:</strong> ${cbAdminEscape(notes.follow_up || '')}</p>`;
                    if(canEdit(row.start_time_iso || row.start_time, row.status)) {
                        content+= `<p><strong>Admin notes:</strong> <span class="admin-notes-text">${cbAdminEscape(notes.admin || '')}</span></p>`;
                    } else {
                        content+= `<p><strong>Admin notes:</strong> ${cbAdminEscape(notes.admin || '')}</p>`;
                    }
                    content+= `
                        </div>
                    `;
                        
                    if(canEdit(row.start_time_iso || row.start_time, row.status)) {
                        content+= `
                        <div class="admin-notes">
                            <p><a href="#" class="cb-add-admin-notes" data-uuid="${cbAdminEscape(row.event_id)}">Add/Edit Notes</a></p>
                        </div>`;
                    }
                    content += '</div>';
                });
            } else {
                content = '<p>No history found for ' + cbAdminEscape(invitee) + '.</p>';
            }
            
            $container.html(content);

            tb_show('Invitee History: ' + invitee.replace(/_/g, ' '), '#TB_inline?inlineId=cb-dynamic-history-modal');
        }).fail(function(xhr) {
            $container.html('<p>' + cbAdminEscape(xhr.responseJSON?.message || 'Unable to load the record. Reload the page and try again.') + '</p>');
            // $container.html('<p>Error loading history.</p>');
            tb_show('View Invitee History', '#TB_inline?inlineId=cb-dynamic-history-modal');
        });
    });
    
    
    /**
     * Add Admin Notes
     */
    $(document).on('click', '.cb-add-admin-notes', function(e) {
        e.preventDefault();

        const form = $(this).closest('.history-item');
        const uuid  = $(this).data('uuid');
        const notes = form.find('.admin-notes-text').text();

        $(this).hide();
        form.find('.admin-notes').after(
        //tb_show('Add Admin Notes', '#TB_inline?inlineId=cb-admin-notes-content-modal')
        `<div class="cb-admin-notes-editor cb-thickbox-form" style="margin-bottom:30px;">
            <h2>Add Admin Notes</h2>
            <div class="cb-thickbox-form">
                <label for="notes-admin">Thoughts for next session
                <textarea id="notes-discussed" name="notes-admin" class="large-text" autofocus>${cbAdminEscape(notes)}</textarea>
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
        const endpoint = (CB_REST.root || '/wp-json/calendly-bookings/v1/') + 'scheduled-events/view/' + encodeURIComponent(uuid);
    
        $('#cb-event-' + uuid + '-modal').remove();
    
        const $modal = $('<div id="cb-event-' + uuid + '-modal" style="display:none;"></div>');
        $modal.append('<div class="cb-thickbox-form"><div class="cb-event-content"><p>Loading event…</p></div></div>');
        $('body').append($modal);
        
        const $container = $modal.find('.cb-event-content');
    
        $.ajax({ url: endpoint, method: 'GET', headers: { 'X-WP-Nonce': CB_REST.nonce } }).done(function(response) {
            let content = '';
        
            if (response && response.success && response.data) {
                const row = response.data;
            
                let notes = {};
                try { notes = row.notes ? JSON.parse(row.notes) : {}; } catch(e) { notes = {}; }
            
content = `
  <h2>Event Details</h2>
  <div class="cb-thickbox-form cb-event-details">
    <p><strong>Invitee:</strong> ${cbAdminEscape(row.invitee_name)}</p>
    <p><strong>Event:</strong> ${cbAdminEscape(row.event_name)}</p>
    <p><strong>Date/Time:</strong> ${cbAdminEscape(row.start_time)}</p>
    <p><strong>Location:</strong> ${cbAdminEscape(row.location)}</p>
    <p><strong>Status:</strong> <span class="record-status" data-status="${cbAdminEscape(row.status)}">${cbAdminEscape(row.status)}</span></p>
    
    <h3>Notes</h3>
    <p><strong>What was discussed:</strong> <span class="note-text" data-field="discussed">${cbAdminEscape(notes.discussed || '')}</span></p>
    <p><strong>Guidance provided:</strong> <span class="note-text" data-field="guidance">${cbAdminEscape(notes.guidance || '')}</span></p>
    <p><strong>Follow-up actions:</strong> <span class="note-text" data-field="follow_up">${cbAdminEscape(notes.follow_up || '')}</span></p>
    <p><strong>Admin notes:</strong> <span class="note-text" data-field="admin">${cbAdminEscape(notes.admin || '')}</span></p>
    
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
                `<textarea name="notes-${field}">${cbAdminEscape(value)}</textarea>`
            );
        });
        
        form.find('.cb-save-btn').show();
        form.find('.cb-cancel-btn').show();
        $(this).hide();
    });
    
    /**
     * Handle walk-in submission
     */
    function handleWalkinSubmit($button) {
        const form = $button.closest('form');
        const firstname = form.find('input[name="firstname"]').val();
        const lastname = form.find('input[name="lastname"]').val();
        const email = form.find('input[name="email"]').val();
        const initialSession = form.find('#initial_session option:selected');
        if (!form[0].reportValidity()) return;
        const start_time = form.find('#initial_date').val()+'T'+ form.find('#initial_time').val()+':00';
        const location = form.find('#location option:selected');
        const notes = {
            discussed: form.find('textarea[name="notes-discussed"]').val(),
            guidance: form.find('textarea[name="notes-guidance"]').val(),
            follow_up: form.find('textarea[name="notes-follow-up"]').val()
        };
        const followupSession = form.find('#followup_session option:selected');
        const followup_date = form.find('#followup_date').val();
        const followup_time = form.find('#followup_time').val();

        const data = [];
        data.push({ name: 'firstname', value: firstname });
        data.push({ name: 'lastname', value: lastname });
        data.push({ name: 'email', value: email });
        data.push({ name: 'initial_session', value: initialSession.text() });
        data.push({ name: 'initial_session_id', value: initialSession.data('id') });
        data.push({ name: 'initial_session_product_id', value: initialSession.data('pid') });
        data.push({ name: 'initial_session_uuid', value: initialSession.data('uuid') });
        data.push({ name: 'start_time', value: start_time });
        data.push({ name: 'location', value: location.data('id') });
        data.push({ name: 'notes', value: notes });
        data.push({ name: 'followup_session', value: followupSession.text() });
        data.push({ name: 'followup_session_id', value: followupSession.data('id') });
        data.push({ name: 'followup_session_product_id', value: followupSession.data('pid') });
        data.push({ name: 'followup_session_uuid', value: followupSession.data('uuid') });
        data.push({ name: 'followup_date', value: followup_date });
        data.push({ name: 'followup_time', value: followup_time });

        if (!confirm("Are you sure you want to create this walk-in?")) return;
    
        $.post(ajaxurl, {
            action: 'cb_create_walk_in',
            nonce: cb_admin.nonce,
            data: JSON.stringify(data)
        }, function(response) {
            if (response.success) {
                alert('Walk-in created successfully');
                tb_remove();
                window.location.reload();
            } else {
                alert('Error: ' + response.data.message);
            }
        });
    }

    /**
     * Handle bulk update submission
     */
    function handleBulkUpdateSubmit() {
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
            nonce: cb_admin.nonce,
            uuids: uuids,
            status: bulk_status
        }, function(response) {
            if (response.success) {
                alert('Events updated successfully.');
                tb_remove();
                window.location.reload();
            } else {
                alert(response.data.message || 'Update failed.');
            }
        });
    }

    /**
     * Handle admin notes submission
     */
    function handleAdminNotesSubmit($button) {
        const form = $button.closest('.cb-admin-notes-editor');
        const uuid = $button.data('uuid');

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
            nonce: cb_admin.nonce,
            uuid: uuid,
            notes: notes
        }, function(response) {
            if (response.success) {
                alert('Changes saved.');
                window.location.reload();
            } else {
                alert('Error: ' + (response.data?.message || 'Save failed.'));
            }
        }).fail(function() {
            alert('Error saving notes.');
        });
    }

    /**
     * Handle event details submission
     */
    function handleEventDetailsSubmit($button) {
        const form = $button.closest('.cb-thickbox-form');
        const uuid = form.find('input[name="uuid"]').val();
    
        if (!uuid) {
            alert('Could not determine event UUID.');
            return;
        }
    
        const status = form.find('input[name="event-status"]:checked').val();
        const notes = {
            discussed: form.find('textarea[name="notes-discussed"]').val(),
            guidance: form.find('textarea[name="notes-guidance"]').val(),
            follow_up: form.find('textarea[name="notes-follow_up"]').val(),
            admin: form.find('textarea[name="notes-admin"]').val()
        };
    
        if (!confirm("Are you sure you want to save these changes?")) return;
    
        $.post(ajaxurl, {
            action: 'calendly_bookings_update_scheduled_event',
            nonce: cb_admin.nonce,
            uuid: uuid,
            status: status,
            notes: notes
        }, function(response) {
            if (response.success) {
                alert('Changes saved.');
                tb_remove();
                window.location.reload();
            } else {
                alert('Error: ' + (response.data?.message || 'Save failed.'));
            }
        }).fail(function() {
            alert('Error saving notes.');
        });
    }

    /**
     * Save edits via AJAX
     */
    $(document).on('click', '.cb-save-btn', function(e) {
        e.preventDefault();

        const id = $(this).attr('id');
        const $button = $(this);

        switch(id) {
            case 'cb-walkin-submit':
                handleWalkinSubmit($button);
                break;
            case 'cb-bulk-update-submit':
                handleBulkUpdateSubmit();
                break;
            case 'cb-admin-notes-submit':
                handleAdminNotesSubmit($button);
                break;
            case 'cb-event-details-submit':
                handleEventDetailsSubmit($button);
                break;
        }
    });

    /**
     * Create Walk-in
     */
    $(document).on('click', '#cb-create-walkin', function() {
        // Clear old options
        $('#initial_session, #location, #followup_session').empty();
    
        $('#initial_session').append(`<option value="">Select a session</option>`);
        $('#followup_session').append(`<option value="">Select a session</option>`);
        $('#location').append(`<option value="">Select a location</option>`);
        
        // Fetch event types
        $.get((CB_REST.root || '/wp-json/calendly-bookings/v1/') + 'event-types', function(response) {
            if (response.success && response.data) {
                response.data.forEach(type => {
                        $('#initial_session').append(`<option name="${type.name}" value="${type.name}" data-id="${type.id}" data-pid="${type.product_id}" data-uuid="${type.uuid}">${type.name}</option>`);
                        if(type.name.toLowerCase() !== "initial consultation") {
                            $('#followup_session').append(`<option name="${type.name}" value="${type.name}" data-id="${type.id}" data-pid="${type.product_id}" data-uuid="${type.uuid}">${type.name}</option>`);
                        }
                });
            }
        });
    
        // Fetch meeting locations
        $.ajax({ url: (CB_REST.root || '/wp-json/calendly-bookings/v1/') + 'scheduled-events/locations', headers: { 'X-WP-Nonce': cb_admin.rest_nonce || CB_REST.nonce } }).done( function(response) {
            if (response.success && response.data) {
                response.data.forEach(loc => {
                    $('#location').append(`<option value="${loc.uuid}" data-id="${loc.id}">${loc.name}</option>`);
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

        fetch((CB_REST.root || '/wp-json/calendly-bookings/v1/') + `event-availability?uuid=${uuid}&start_iso=${encodeURIComponent(startIso)}`, {
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
            if (!firstDate) { $('#next-available-slot').text('No available follow-up appointments.'); return; }
            $('#next-available-slot').text("First available date: " + firstDate);

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
        const selectedDateStr = $(this).find('option:selected').val();
        const uuid = $('#followup_session').find('option:selected').data('uuid');

        if (!uuid || !selectedDateStr) return;

        // Parse the selected date string into a Date object
        const selectedDateObj = new Date(selectedDateStr);
        const startIso = selectedDateObj.toISOString().split('T')[0]; // just the YYYY-MM-DD part

        fetch((CB_REST.root || '/wp-json/calendly-bookings/v1/') + `event-availability?uuid=${uuid}&start_iso=${encodeURIComponent(startIso)}`, {
            credentials: 'same-origin'
        })
        .then(res => res.json())
        .then(response => {
            if (!response.success || !response.data) return;

            // Filter slots that match the selected date
            const slots = response.data.filter(slot =>
                slot.start_time.startsWith(startIso)
            );

            const $time = $('#followup_time');
            $('#next-available-slot').text(""); 
            $time.empty();
            $time.append(`<option>Select a time</option>`);

            slots.forEach(slot => {
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
                    `<span class="note-text" data-field="${field}">${cbAdminEscape(value)}</span>`
                );
            });
        
            form.find('.cb-edit-toggle').show();
            form.find('.cb-save-btn').hide();
            form.find('.cb-cancel-btn').hide();
        } else {
			if($(this).attr('id') === 'cb-admin-notes-cancel') {
                $(this).closest('.history-item').find('.cb-add-admin-notes').show();
                $(this).closest('.cb-admin-notes-editor').remove();
			} else {
                tb_remove();
			}
		}
    });



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
