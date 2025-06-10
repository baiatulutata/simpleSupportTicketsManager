
jQuery(document).ready(function($) {
    // Handle ticket submission form
    $('#support-ticket-form').on('submit', function(e) {
        e.preventDefault();

        var formData = new FormData(this);
        formData.append('action', 'submit_support_ticket');
        formData.append('nonce', supportTicketsAjax.nonce);

        $.ajax({
            url: supportTicketsAjax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    $('#ticket-form-messages').html('<div class=\"success-message\">' + response.data.message + '</div>');
                    $('#support-ticket-form')[0].reset();
                } else {
                    $('#ticket-form-messages').html('<div class=\"error-message\">' + response.data + '</div>');
                }
            },
            error: function() {
                $('#ticket-form-messages').html('<div class=\"error-message\">An error occurred. Please try again.</div>');
            }
        });
    });

    // Load user tickets
    if ($('#user-tickets-list').length) {
        loadUserTickets();
    }

    function loadUserTickets() {
        $.ajax({
            url: supportTicketsAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'get_user_tickets',
                nonce: supportTicketsAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayUserTickets(response.data);
                } else {
                    $('#user-tickets-list').html('<p>Error loading tickets: ' + response.data + '</p>');
                }
            }
        });
    }

    function displayUserTickets(tickets) {
        var html = '';

        if (tickets.length === 0) {
            html = '<p>You have no support tickets.</p>';
        } else {
            html = '<div class=\"tickets-table\">';
            tickets.forEach(function(ticket) {
                var statusClass = 'status-' + ticket.status;
                var priorityClass = 'priority-' + ticket.priority;

                html += '<div class=\"ticket-row ' + statusClass + '\">';
                html += '<div class=\"ticket-title\"><strong>' + ticket.title + '</strong></div>';
                html += '<div class=\"ticket-meta\">';
                html += '<span class=\"ticket-status ' + statusClass + '\">' + ticket.status.replace('_', ' ').toUpperCase() + '</span>';
                html += '<span class=\"ticket-priority ' + priorityClass + '\">' + ticket.priority.toUpperCase() + '</span>';
                html += '<span class=\"ticket-date\">' + ticket.date + '</span>';
                html += '<span class=\"ticket-replies\">Replies: ' + ticket.reply_count + '</span>';
                html += '</div>';
                html += '<div class=\"ticket-actions\">';
                html += '<button class=\"view-ticket-btn\" data-ticket-id=\"' + ticket.id + '\">View & Reply</button>';
                html += '</div>';
                html += '</div>';
            });
            html += '</div>';
        }

        $('#user-tickets-list').html(html);
    }

    // Handle view ticket button
    $(document).on('click', '.view-ticket-btn', function() {
        var ticketId = $(this).data('ticket-id');
        loadTicketDetails(ticketId);
    });

    function loadTicketDetails(ticketId) {
        $.ajax({
            url: supportTicketsAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'reply_to_ticket',
                action_type: 'get_replies',
                ticket_id: ticketId,
                nonce: supportTicketsAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayTicketModal(response.data);
                } else {
                    alert('Error loading ticket details: ' + response.data);
                }
            }
        });
    }

    function displayTicketModal(data) {
        var ticket = data.ticket;
        var replies = data.replies;
        var images = data.images;

        var detailsHtml = '<div class=\"ticket-info\">';
        detailsHtml += '<h4>' + ticket.title + '</h4>';
        detailsHtml += '<div class=\"ticket-meta\">';
        detailsHtml += '<span class=\"status-' + ticket.status + '\">Status: ' + ticket.status.replace('_', ' ').toUpperCase() + '</span>';
        detailsHtml += '<span class=\"priority-' + ticket.priority + '\">Priority: ' + ticket.priority.toUpperCase() + '</span>';
        detailsHtml += '<span>Date: ' + ticket.date + '</span>';
        detailsHtml += '</div>';
        detailsHtml += '<div class=\"ticket-content\">' + ticket.content.replace(/\\n/g, '<br>') + '</div>';

        // Show initial ticket images
        var ticketImages = images.filter(img => img.reply_id === null);
        if (ticketImages.length > 0) {
            detailsHtml += '<div class=\"ticket-images\">';
            ticketImages.forEach(function(image) {
                detailsHtml += '<img src=\"' + image.image_url + '\" alt=\"' + image.image_name + '\" style=\"max-width: 200px; margin: 5px;\" />';
            });
            detailsHtml += '</div>';
        }
        detailsHtml += '</div>';

        $('#ticket-details').html(detailsHtml);

        // Display replies
        var repliesHtml = '<div class=\"replies-list\">';
        if (replies.length > 0) {
            repliesHtml += '<h5>Replies:</h5>';
            replies.forEach(function(reply) {
                var isAdmin = reply.is_admin == 1;
                repliesHtml += '<div class=\"reply-item ' + (isAdmin ? 'admin-reply' : 'user-reply') + '\">';
                repliesHtml += '<div class=\"reply-header\">';
                repliesHtml += '<strong>' + reply.user_name + '</strong>';
                if (isAdmin) repliesHtml += ' <span class=\"admin-badge\">[Admin]</span>';
                repliesHtml += ' - ' + reply.created_at;
                repliesHtml += '</div>';
                repliesHtml += '<div class=\"reply-content\">' + reply.reply_content.replace(/\\n/g, '<br>') + '</div>';

                // Show reply images
                var replyImages = images.filter(img => img.reply_id == reply.id);
                if (replyImages.length > 0) {
                    repliesHtml += '<div class=\"reply-images\">';
                    replyImages.forEach(function(image) {
                        repliesHtml += '<img src=\"' + image.image_url + '\" alt=\"' + image.image_name + '\" style=\"max-width: 150px; margin: 2px;\" />';
                    });
                    repliesHtml += '</div>';
                }
                repliesHtml += '</div>';
            });
        } else {
            repliesHtml += '<p>No replies yet.</p>';
        }
        repliesHtml += '</div>';

        $('#ticket-replies').html(repliesHtml);
        $('#ticket-reply-modal').data('ticket-id', ticket.id).show();
    }

    // Handle reply submission
    $('#submit-reply').on('click', function() {
        var ticketId = $('#ticket-reply-modal').data('ticket-id');
        var replyContent = $('#reply-content').val();

        if (!replyContent.trim()) {
            alert('Please enter a reply.');
            return;
        }

        var formData = new FormData();
        formData.append('action', 'reply_to_ticket');
        formData.append('action_type', 'add_reply');
        formData.append('ticket_id', ticketId);
        formData.append('reply_content', replyContent);
        formData.append('nonce', supportTicketsAjax.nonce);

        // Add images if any
        var replyImages = $('#reply-images')[0].files;
        for (var i = 0; i < replyImages.length; i++) {
            formData.append('reply_images[]', replyImages[i]);
        }

        $.ajax({
            url: supportTicketsAjax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    $('#reply-content').val('');
                    $('#reply-images').val('');
                    loadTicketDetails(ticketId); // Reload to show new reply
                } else {
                    alert('Error adding reply: ' + response.data);
                }
            }
        });
    });

    // Close modal
    $('.close-modal, .ticket-modal').on('click', function(e) {
        if (e.target === this) {
            $('#ticket-reply-modal').hide();
        }
    });
});