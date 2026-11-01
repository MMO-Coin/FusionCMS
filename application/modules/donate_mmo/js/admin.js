var Admin = {
    post: async function (action, fields) {
        const token = document.getElementById('mmo-admin-csrf-hash');
        const body = new URLSearchParams(fields);
        body.set(document.getElementById('mmo-admin-csrf-name').value, token.value);
        try {
            const response = await fetch(Config.URL + 'donate_mmo/admin/' + action, {
                method: 'POST', credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'}, body: body,
            });
            const renewed = response.headers.get('X-CSRF-Token');
            if (renewed) token.value = renewed;
            const text = await response.text();
            if (response.ok && text === 'SUCCESS') {
                window.location.reload();
            } else {
                Swal.fire({title: 'Unable to save package', text: response.ok ? text : 'Reload this page and try again.', icon: 'error'});
            }
        } catch (error) {
            Swal.fire({title: 'Connection failed', text: 'Please try again.', icon: 'error'});
        }
    },
    add: function (form) {
        return Admin.post('add', {price: form.elements.price.value, points: form.elements.points.value});
    },
    edit: function (id, price, points) {
        Swal.fire({
            title: 'Edit package',
            html: '<input id="swal-price" class="swal2-input" placeholder="MMOCoin amount">' +
                '<input id="swal-points" class="swal2-input" placeholder="Donation points">',
            didOpen: function () {
                document.getElementById('swal-price').value = price;
                document.getElementById('swal-points').value = points;
            },
            preConfirm: function () {
                return {id: id, price: document.getElementById('swal-price').value, points: document.getElementById('swal-points').value};
            },
        }).then(function (result) { if (result.isConfirmed) Admin.post('edit', result.value); });
    },
    delete: function (id) {
        Swal.fire({title: 'Delete package?', text: 'Existing invoices keep their original amount and points.',
            icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete',
        }).then(function (result) { if (result.isConfirmed) Admin.post('delete', {id: id}); });
    },
};
