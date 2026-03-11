var Admin = {
    add: function(form) {
        var price = $(form).find('#price').val();
        var points = $(form).find('#points').val();

        $.post(Config.URL + "donate_mmo/admin/add", {
            price: price,
            points: points,
            csrf_token_name: Config.CSRF
        }, function(data) {
            if(data == 'SUCCESS') {
                window.location.reload();
            } else {
                Swal.fire({
                    title: "Error",
                    text: data,
                    icon: "error"
                });
            }
        });
    },

    edit: function(id, current_price, current_points) {
        Swal.fire({
            title: 'Edit Package',
            html:
                '<input id="swal-price" class="swal2-input" placeholder="MMO Amount" value="' + current_price + '">' +
                '<input id="swal-points" class="swal2-input" placeholder="Donation Points" value="' + current_points + '">',
            focusConfirm: false,
            preConfirm: () => {
                return [
                    document.getElementById('swal-price').value,
                    document.getElementById('swal-points').value
                ]
            }
        }).then((result) => {
            if (result.value) {
                $.post(Config.URL + "donate_mmo/admin/edit", {
                    id: id,
                    price: result.value[0],
                    points: result.value[1],
                    csrf_token_name: Config.CSRF
                }, function(data) {
                    if(data == 'SUCCESS') {
                        window.location.reload();
                    } else {
                        Swal.fire("Error", data, "error");
                    }
                });
            }
        });
    },

    delete: function(id) {
        Swal.fire({
            title: 'Delete Package',
            text: "Are you sure you want to delete this donation package?",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.value) {
                $.post(Config.URL + "donate_mmo/admin/delete", {
                    id: id,
                    csrf_token_name: Config.CSRF
                }, function(data) {
                    if(data == 'SUCCESS') {
                        window.location.reload();
                    } else {
                        Swal.fire("Error", data, "error");
                    }
                });
            }
        });
    }
};
