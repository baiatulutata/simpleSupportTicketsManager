
(function(blocks, element, editor, components) {
    var el = element.createElement;

    // Submit Form Block
    blocks.registerBlockType('support-tickets/submit-form', {
        title: 'Support Ticket Form',
        icon: 'feedback',
        category: 'widgets',
        edit: function() {
            return el('div', { className: 'support-ticket-form-placeholder' },
                el('h3', {}, 'Support Ticket Submission Form'),
                el('p', {}, 'This form will appear on the frontend for users to submit support tickets.')
            );
        },
        save: function() {
            return null; // Rendered in PHP
        }
    });

    // User Tickets Block
    blocks.registerBlockType('support-tickets/user-tickets', {
        title: 'My Support Tickets',
        icon: 'tickets-alt',
        category: 'widgets',
        edit: function() {
            return el('div', { className: 'user-tickets-placeholder' },
                el('h3', {}, 'My Support Tickets'),
                el('p', {}, "This will display the user's support tickets and allow them to reply.")
        );
        },
        save: function() {
            return null; // Rendered in PHP
        }
    });

})(
    window.wp.blocks,
    window.wp.element,
    window.wp.editor,
    window.wp.components
);