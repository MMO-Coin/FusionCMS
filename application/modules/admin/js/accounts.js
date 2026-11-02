var Accounts = {
	offset: 0,
	loadMoreCount: 10,
    logCount: 0,
    loadingLogs: false,

	/**
	 * Links for the ajax requests
	 */
	Links: {
		save: "admin/accounts/save/",
		loadMore: "admin/accounts/loadMoreLogs/",
	},
	
	/**
	 * Submit the form contents to the save link
	 * @param Object form
	 */
	save: function(form, id)
	{
		var values = {csrf_token_name: Config.CSRF};

		$("input, select", $(form)).each(function()
		{
			if($(this).attr("type") != "submit")
			{
				if($(this).attr("type") == "checkbox")
				{
					values[$(this).attr("name")] = this.checked;
				}
				else
				{
					values[$(this).attr("name")] = $(this).val();
				}
			}
		});

		$.post(Config.URL + this.Links.save + id, values, function(data)
		{

			if(data == "yes")
			{
				console.log(data);
				Swal.fire({
					icon: "success",
					title: "The account has been saved!",
				});
			}
			else
			{
				console.log(data);
				Swal.fire({
					icon: 'error',
					title: 'Oops...',
					text: data,
				})
			}
		});
	},

	loadMore: function(id)
	{

        if (this.loadingLogs || Number($('#js_load_more').val()) <= 0) return;
        this.loadingLogs = true;
        var nextOffset = this.offset + this.loadMoreCount;
        $.post(Config.URL + this.Links.loadMore + id, {offset: nextOffset, csrf_token_name: Config.CSRF})
            .done(function(data) {
                Accounts.offset = nextOffset;
                $('#show_more_count').remove();
                $('#overview .log-load-error').remove();
                $('#overview').append(data);
            }).fail(function() {
                $('#overview .log-load-error').remove();
                $('<p class="log-load-error text-danger" role="alert">').text($('#overview').data('load-error')).appendTo('#overview');
            }).always(function() { Accounts.loadingLogs = false; });
	},
	
	removePendingAcc: function(id, element)
	{
		Swal.fire({
			title: 'Do you really want to delete the pending account?',
			icon: 'warning',
			showCancelButton: true,
			confirmButtonColor: '#3085d6',
			cancelButtonColor: '#d33',
			confirmButtonText: 'Yes'
		}).then((result) => {
		if (result.isConfirmed) {
			$.get(Config.URL + "admin/accounts/deletePendingAcc/" + id, function(data)
			{
				console.log(data);
				if(data == "yes")
				{
					Swal.fire({
						icon: "success",
						title: "Pending account deleted!",
					});
					
					$(element).parents("tr").slideUp(300, function()
					{
						$(this).remove();
					});
				}
				else
				{
					Swal.fire({
						icon: 'error',
						title: 'Oops...',
						text: data,
					})
				}
			});
		}
		})
	},
	
	activatePendingAcc: function(id, element)
	{
		Swal.fire({
			title: 'Do you really want to activate the pending account?',
			icon: 'warning',
			showCancelButton: true,
			confirmButtonColor: '#3085d6',
			cancelButtonColor: '#d33',
			confirmButtonText: 'Yes'
		}).then((result) => {
		if (result.isConfirmed) {
			$.get(Config.URL + "admin/accounts/activatePendingAcc/" + id, function(data)
			{
				console.log(data);
				if(data == "yes")
				{
					console.log(data);
					Swal.fire({
						icon: "success",
						title: "Pending account activated!",
					});
					
					$(element).parents("tr").slideUp(300, function()
					{
						$(this).remove();
					});
				}
				else
				{
					console.log(data);
					Swal.fire({
						icon: 'error',
						title: 'Oops...',
						text: data,
					})
				}
			});
		}
		})
	},
}
