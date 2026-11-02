var Store = {
    escape: function(value) { return String(value == null ? '' : value).replace(/[&<>"']/g, function(c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); },
    Catalogue: {
        request: null, generation: 0, next: null, filters: null, initialized: false,
        initialize: function() {
            if (this.initialized) return;
            this.initialized = true;
            var next = $('#store_realms').attr('data-next');
            this.next = next === '' ? null : Number(next);
            this.filters = this.capture();
            $('#store_realms').on('click.catalogue', '[data-catalogue-add]', function() {
                var button = $(this);
                Store.Cart.add(Number(button.attr('data-id')), button.attr('data-item-id'), button.attr('data-name'), Number(button.attr('data-price')),
                    button.attr('data-price-type'), button.attr('data-realm-name'), Number(button.attr('data-realm')), Number(button.attr('data-quality')), Number(button.attr('data-tooltip')));
            });
            Store.Filter.updatePrices();
        },
        capture: function() {
            var fields = {}; $('#catalogue-filter').serializeArray().forEach(function(field) { fields[field.name] = field.value; });
            fields.name = String(fields.name || '').trim();
            return fields;
        },
        busy: function(value) {
            $('#store_realms').attr('aria-busy', String(value));
            $('#catalogue-filter :submit, #catalogue-more, #store_realms [data-catalogue-add]').prop('disabled', value);
        },
        realmChanged: function() {
            $('#catalogue-group').empty().append($('<option>').val('all').text(lang('catalogue_all_groups','store')));
            this.reload();
        },
        reload: function() { this.filters = this.capture(); this.next = null; this.fetch(false); },
        loadMore: function() { if (this.next !== null && !this.request) this.fetch(true); },
        groups: function(groups, selected) {
            var select = $('#catalogue-group').empty().append($('<option>').val('all').text(lang('catalogue_all_groups','store'))), found = selected === 'all';
            groups.forEach(function(group) { select.append($('<option>').val(group.id).text(group.title)); if (String(group.id) === String(selected)) found = true; });
            if (!found) select.append($('<option>').val(selected).text(lang('catalogue_missing_group','store')));
            select.val(String(selected));
        },
        fetch: function(append) {
            if (!this.filters.realm) return;
            var generation = ++this.generation, filter = Object.assign({}, this.filters), offset = append ? this.next : 0;
            if (this.request) this.request.abort();
            this.busy(true); $('#catalogue-error').prop('hidden', true).text('');
            $('#catalogue-status').text(lang('catalogue_loading','store'));
            if (!append) { $('#store_realms').empty(); $('#catalogue-more').prop('hidden', true); $('#catalogue-empty').prop('hidden', true); }
            var xhr = $.ajax({url: Config.URL+'store/catalogue', method:'POST', dataType:'json', data:Object.assign(filter, {offset:offset, limit:25, csrf_token_name:Config.CSRF})})
                .done(function(result) {
                    if (generation !== Store.Catalogue.generation) return;
                    if (append) $('#store_realms').append(result.html); else $('#store_realms').html(result.html);
                    Store.Catalogue.next = result.next;
                    Store.Catalogue.groups(result.groups, $('#catalogue-group').val() || filter.group);
                    var loaded = $('#store_realms [data-catalogue-id]').length;
                    $('#catalogue-status').text(lang('catalogue_progress','store').replace('{0}', loaded).replace('{1}', result.total));
                    $('#catalogue-empty').prop('hidden', result.total !== 0);
                    $('#catalogue-more').prop('hidden', result.next === null);
                    Store.Filter.updatePrices(); Tooltip.refresh();
                }).fail(function(xhr, status) {
                    if (status === 'abort' || generation !== Store.Catalogue.generation) return;
                    $('#catalogue-status').text('');
                    $('#catalogue-error').text(lang(append?'catalogue_more_failed':'catalogue_failed','store')).prop('hidden', false);
                    if (append) $('#catalogue-more').prop('hidden', false);
                }).always(function() {
                    if (generation !== Store.Catalogue.generation) return;
                    Store.Catalogue.request = null; Store.Catalogue.busy(false);
                });
            this.request = xhr;
        }
    },
    Filter: {
        initialize: function() { Store.Catalogue.initialize(); },
        updatePrices: function() {
            $('#store_realms [data-catalogue-add]').each(function() {
                var button = $(this), price = Number(button.attr('data-price')), currency = button.attr('data-price-type');
                button.css('opacity', price > Store.Customer[currency] ? 0.5 : 1);
            });
        }
    },

	/**
	 * Customer object, holds economical values
	 */
	Customer: {

		vp: 0,
		dp: 0,

		initialize: function(vp, dp)
		{
			this.vp = vp;
			this.dp = dp;

			Store.Filter.initialize();
		},

		/**
		 * Return points to the customer
		 * @param Int price
		 * @param String priceType
		 */
		add: function(price, priceType)
		{
			this[priceType] += price;

			if($("#info_" + priceType).length)
			{
				$("#info_" + priceType).html(this[priceType]);
			}

			Store.Filter.updatePrices();
		},

		/**
		 * Subtract to the customers money
		 * @param Int price
		 * @param String priceType
		 * @param Function callback
		 */
		subtract: function(price, priceType, callback)
		{
			var old = this[priceType];
			
			this[priceType] -= price;

			if(this[priceType] < 0)
			{
				this[priceType] = old;

				Swal.fire({
					icon: 'error',
					title: 'Oops...',
					text: lang("cant_afford", "store"),
				})
			}
			else
			{
				if($("#info_" + priceType).length)
				{
					$("#info_" + priceType).html(this[priceType]);
				}

				Store.Filter.updatePrices();

				callback();
			}
		}
	},

	/**
	 * Cart object, holds all shop actions
	 */
	Cart: {

		items: {},
		list: [],
		vpCost: 0,
		dpCost: 0,
		count: 0,

		/**
		 * Add an item to the cart
		 * @param Int id
		 * @param Int itemId
		 * @param String name
		 * @param Int price
		 * @param String priceType
		 * @param String realm
		 */
		add: function(id, itemId, name, price, priceType, realm, realmId, quality, tooltip)
		{
			name = Store.escape(name);
			realm = Store.escape(realm);
            itemId = Store.escape(itemId);

			Store.Customer.subtract(price, priceType, function()
			{
				if(priceType == "vp")
				{
					Store.Cart.vpCost += price;
				}
				else if(priceType == "dp")
				{
					Store.Cart.dpCost += price;
				}

				var itemObject = {
					id: id,
					price: price,
					priceType: priceType
				};

				Store.Cart.list.push({id:id, type:priceType});

				Store.Cart.updatePrice();

				$("#cart_item_count").html(Store.Cart.list.length);

				var isInCart = Store.Cart.isInCart(id, priceType);
				
				if(isInCart)
				{
					var countField = $("#cart_item_count_" + isInCart),
						itemCount;
					
					if(countField.html().length == 0)
					{
						itemCount = 2;
					}
					else
					{
						itemCount = parseInt(countField.html().replace("x ", "")) + 1;
					}

					countField.html("x " + itemCount);
					Store.Cart.count++;
				}
				else
				{
					Store.Cart.items[Store.Cart.count] = itemObject;

					$("#empty_cart").fadeOut(50, function()
					{
						var tooltipHTML = (tooltip) ? 'data-realm="' + realmId +'" rel="item=' + itemId +'"' : '';

						var itemHTML = '<div class="store_item row" id="cart_item_' + Store.Cart.count + '">' +
											
											'<a href="' + Config.URL + 'item/' + realmId + '/' + itemId + '" class="item_name col-5 q' + quality  +'" ' + tooltipHTML + '>' +
												name  + 
											'</a>' +
											'<span class="col-1" id="cart_item_count_' + Store.Cart.count + '"></span>' +
											'<div class="item_price col-2">' +
												'<img src="' + Config.URL + 'application/images/icons/' + ((priceType == "vp") ? "lightning" : "coins") + '.png" align="absmiddle" />' +
												price + " " + ((priceType == "vp") ? lang("vp", "store") : lang("dp", "store")) +
											'</div>' +
											'<div class="item_realm col-3">' + realm + '</div>' +
											'<a href="javascript:void(0)" onClick="Store.Cart.remove(' + id + ', ' + Store.Cart.count + ')" class="delete_item col-1">' +
												'<i class="fa-duotone fa-trash"></i>' +
											'</a>' +
											'<div class="clear"></div>' +
										'</div>';

						$("#cart_items").append(itemHTML);
						$("#cart_item_" + Store.Cart.count).slideDown(150, function()
						{
							Tooltip.refresh();
						});

						Store.Cart.count++;
					});
				}
			});
		},

		/**
		 * Check if the item exists in the cart already
		 * @param Int id
		 * @param String priceType
		 * @return Boolean
		 */
		isInCart: function(id, priceType)
		{
			for(i in this.items)
			{
				try
				{
					if(id == this.items[i].id
					&& priceType == this.items[i].priceType)
					{
						return i;
					}
				}
				catch(error)
				{
					// ...
				}
			}

			return false;
		},

		/**
		 * Remove an item from the cart
		 * @param Int id
		 * @param Int countId
		 */
		remove: function(id, countId)
		{
			var priceType;

			try
			{
				var itemCount;

				if($("#cart_item_count_" + countId).html().length != 0)
				{
					itemCount = parseInt($("#cart_item_count_" + countId).html().replace("x ", ""));
				}
				else
				{
					itemCount = 1;
				}

				this[this.items[countId].priceType + "Cost"] -= this.items[countId].price * itemCount;

				// Return the customer money
				Store.Customer.add(this.items[countId].price * itemCount, this.items[countId].priceType);

				priceType = this.items[countId].priceType;

				// Delete the object
				this.items[countId] = null;
			}
			catch(error)
			{
				// ...
			}

			// Remove it from the list array
			var n;

			for(var i = 0;i < itemCount;i++)
			{
				for(n in Store.Cart.list)
				{
					if(Store.Cart.list[n].id == id
					&& (Store.Cart.list[n].type == priceType || typeof priceType == "undefined"))
					{
						this.list.splice(n, 1);
					}
				}
			}

			this.updatePrice();

			$("#cart_item_count").html(Store.Cart.list.length);

			// Remove the element
			$("#cart_item_" + countId).slideUp(150, function()
			{
				$(this).remove();

				if(Store.Cart.list.length == 0)
				{
					setTimeout(function()
					{
						if(Store.Cart.list.length == 0)
						{
							$("#empty_cart").fadeIn(150);
						}
					}, 500);
				}
			});
		},

		/**
		 * Submit the cart values to the back end
		 * @param Element button
		 */
		checkout: function(button)
		{
			var cartList = JSON.stringify(this.list);

			// Change the button state to "Loading..."
			$(button).addClass("nice_active").removeAttr("href").removeAttr("onClick").html("Loading...");

			// Submit to the back end
			$.post(Config.URL + "store/checkout", {cart: cartList, csrf_token_name: Config.CSRF}, function(data)
			{
				$("#store").fadeOut(150, function()
				{
					// Restore the button
					$(button).removeClass("nice_active").attr("href", "javascript:void(0)").attr("onClick", "Store.Cart.checkout(this)").html(lang("checkout", "store"));

					// Display the content
					$("#checkout").html(data).fadeIn(150, function()
					{
						Tooltip.refresh();
					});
				});
			});
		},

		/**
		 * Submit the cart and the characters to the final payment gate
		 */
		pay: function()
		{
			Swal.fire({
				title: lang("want_to_buy", "store"),
				text: "You won't be able to revert this!",
				icon: 'warning',
				showCancelButton: true,
				confirmButtonColor: '#3085d6',
				cancelButtonColor: '#d33',
				confirmButtonText: lang("yes", "store")
			}).then((result) => {
			if (result.isConfirmed) {
				$("#checkout select[data-id]").attr("data-available", "1");

				for(i in Store.Cart.list)
				{
					if(typeof Store.Cart.list[i].character != "undefined")
					{
						delete Store.Cart.list[i]["character"];
					}

					$("#checkout select[data-id=\"" + Store.Cart.list[i].id + "\"]").each(function()
					{
						if($(this).attr("data-available") == "1"
						&& typeof Store.Cart.list[i].character == "undefined")
						{
							$(this).attr("data-available", "0");
							Store.Cart.list[i].character = $(this).val();
						}
					});
				}

				var data = JSON.stringify(Store.Cart.list);

				$("#checkout").fadeOut(150, function()
				{
					$("#checkout").html('<center><i class="fa-duotone fa-spinner fa-xl fa-spin"></i></center>').fadeIn(150, function()
					{
						$.post(Config.URL + "store/pay", {data: data, csrf_token_name: Config.CSRF}, function(data)
						{
							$("#store_wrapper").fadeOut(150, function()
							{
								$("#store_wrapper").html(data).fadeIn(150, function()
								{
									// Reset cart after successful or failed purchase
									Store.Cart.items= {};
									Store.Cart.list = [];
									Store.Cart.vpCost = 0;
									Store.Cart.dpCost = 0;
									Store.Cart.count = 0;

									Store.Cart.updatePrice();
									$("#cart_item_count").html('0');
								});
							});
						});
					})
				});
			}
			});
		},

		/**
		 * Display the total price
		 */
		updatePrice: function()
		{
			// Hide the total cost if cart is empty
			if(this.list.length == 0)
			{
				this.vpCost = 0;
				this.dpCost = 0;

				setTimeout(function()
				{
					$("#cart_price").fadeOut(300);
				}, 350);
			}

			// Show the total cost if cart is not empty
			else if(!$("#cart_price").is(":visible"))
			{
				setTimeout(function()
				{
					$("#cart_price").fadeIn(300);
				}, 350);
			}

			var vp = $("#vp_price");
			var dp = $("#dp_price");

			if(vp.html() != this.vpCost)
			{
				vp.fadeTo(300, 0, function()
				{
					vp.html(Store.Cart.vpCost);
					vp.fadeTo(300, 1);
				});
			}

			if(dp.html() != this.dpCost)
			{
				dp.fadeTo(300, 0, function()
				{
					dp.html(Store.Cart.dpCost);
					dp.fadeTo(300, 1);
				});
			}
		},

		/**
		 * Return to the original store list view
		 */
		back: function()
		{
			$("#checkout").fadeOut(150, function()
			{
				$("#store").fadeIn(150);
			});
		}
	}
}
