var Orders = {
    refund: function(id, button) {
        if (button.disabled) return;
        button.disabled = true;
        Swal.fire({text: lang('refund_question', 'store'), icon: 'warning', showCancelButton: true,
            confirmButtonText: lang('refund', 'store')}).then(function(result) {
            if (!result.isConfirmed) { button.disabled = false; return; }
            $('#orders-feedback').text('');
            $.ajax({url: Config.URL + 'store/admin_orders/refund/' + id, method: 'POST', dataType: 'json',
                data: {csrf_token_name: Config.CSRF}}).done(function(data) {
                if (!data.ok) { $('#orders-feedback').text(lang('refund_failed', 'store')); return; }
                $(button).closest('tr').remove();
                $('#orders-total').text(Math.max(0, Number($('#orders-total').text()) - 1));
                $('#orders-feedback').text(lang('refund_done', 'store'));
            }).fail(function() { $('#orders-feedback').text(lang('refund_failed', 'store')); })
                .always(function() { button.disabled = false; });
        });
    }
};
