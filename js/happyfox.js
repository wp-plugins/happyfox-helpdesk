jQuery(document).ready(function($) {
	//Create dialog box
	$('<div><div id="happyfox-dialog"><div id="happyfox-dialog-inner"><h1 id="happyfox-dialog-title">Dialog Title</h1><div id="happyfox-dialog-body"></div><br class="clearfix" /><div id="happyfox-dialog-footer"><a href="http://www.happyfox.com/" target="_blank" title="customer support software">customer support software</a> by HappyFox</div></div></div></div>').appendTo('body').hide();
	
	$('<div><div id="happyfox-success-dialog"><div id="happyfox-success-dialog-inner"><h2 class="happyfox-success-title">Success!</h2><p>Ticket <a target="_blank" href="#" class="happyfox-success-ticket-id">#2991</a> was successfully created.</p><a href="#" class="button success-close">Close this window</a><br class="clearfix" /><div id="happyfox-dialog-footer"><a href="http://www.happyfox.com/" target="_blank" title="customer support software">customer support software</a> by HappyFox</div></div></div></div>').appendTo('body').hide();
	
	$('.happyfox-tickets-table tr:odd').addClass('alt');
	
	//Tickets pagination
	function paginate_widget() {
		//Figure out which view needs the pagination
		var inactive_view = $('.happyfox-inactive').text();
		var view = '';
		
		if(inactive_view === "Completed") {
			view = "solved";
		} else {
			view = "pending";
		}
		
		$('.happyfox-tickets-pagination').jqPagination({
			paged: function(page) {
				//alert("Page " + page + " of {max_page} requested");
				$('.happyfox-tickets-pagination').find('.happyfox-admin-notice').remove();
				
				var params = {
					'action': 'happyfox_paging',
					'page': page,
					'view': view
				};
				
				var loader = $('.happyfox-filters').parent().find('.happyfox-spinner');
				$(loader).show();
				
				$.post(ajaxurl, params, function(response) {
					if(response.status == 200) {
						$('.happyfox-tickets-widget-main').find('.happyfox-tickets-table').remove();
						$('.happyfox-tickets-widget-main').prepend(response.html);
					} else {
						$('.happyfox-tickets-pagination').append(create_notice(response.error));
					}
					
					$(loader).hide();
				}, 'json');
			}
		});
	}
	
	paginate_widget();
	
	//Single ticket view
	$('.happyfox-ticket-id > a, .happyfox-ticket-subject > a, .happyfox-ticket-status > a').live('click', function() {
		var ticket_id = $(this).attr('data-id');
		
		var params = {
			'action': 'view_happyfox_ticket',
			'ticket_id': ticket_id,
		};
		
		var tr = $(this).parents('tr');
		var ticket_id_text = $(this).parents('tr').find('a.happyfox-ticket-id-text');
		var loader = $(tr).find('.happyfox-spinner');
		
		//hide Ticket ID text and show loading gif
		$(ticket_id_text).hide();
		$(loader).show();
		
		$.post(ajaxurl, params, function(response) {
			if(response.status == 200) {
				var ticket = response.ticket;
				var html = response.html;
				
				$(".happyfox-ticket-title").text(ticket.display_id);
				$("#happyfox-ticket-details-placeholder").html(html).autolink().mailto();
				
				$(".happyfox-tickets-widget-main, p.happyfox-widget-title").slideUp();
				$(".happyfox-tickets-widget-single").slideDown();
			} else {
				console.log(response);
			}
			
			$(loader).hide();
			$(ticket_id_text).show();
			
		}, 'json');
		
		return false;
	});
	
	$('.happyfox-cancel').click(function() {
		$(".happyfox-tickets-widget-single").slideUp();
		$(".happyfox-tickets-widget-main, p.happyfox-widget-title").slideDown();
		return false;
	});
	
	//HappyFox Ticket filters
	$('.happyfox-filters > a').click(function() {
		if($(this).hasClass('happyfox-inactive')) {
			//clicking existing view, so return
			return;
		}
		
		var loader = $(this).parent().find('.happyfox-spinner');
		$(loader).show();
		var filter = "";
		
		if($(this).hasClass('happyfox-filter-pending')) {
			filter = "pending";
			console.log("Getting " + filter + " tickets");
		} else {
			filter = "solved";
			console.log("Getting " + filter + " tickets");
		}
		
		var params = {
			'action': 'happyfox_filter_view',
			'view': filter
		};
		
		$.post(ajaxurl, params, function(response) {
			if(response.status == 200) {
				$('.happyfox-tickets-widget-main').slideUp(343).empty().append(response.html).slideDown(343);
				$('.happyfox-filters > a').removeClass('happyfox-inactive').attr('href', '#');
				$('.happyfox-filter-' + filter).addClass('happyfox-inactive').removeAttr('href');
				$('.filter-type').empty().append(filter.charAt(0).toUpperCase() + filter.slice(1));
				paginate_widget();
			} else {
				alert(response.error);
				console.log(response);
			}
			
			$(loader).hide();
		}, 'json');
		
		return false;
	});
	
	//Convert comment to ticket and add admin reply
	$('.happyfox-ticket-converter-form').live('submit', function(){
		var loader = $(this).find('.happyfox-spinner');
		$(loader).show();
		$(this).find('.happyfox-submit').hide();
		
		var form = this;
		var comment_id = $(form).find('[name="happyfox_comment_id"]').val();
		var comment_reply = $(form).find('[name="comment-reply"]').val();
		var happyfox_public_comment = $(form).find('[name="happyfox_comment_reply_option"]:checked').val();
		
		if(happyfox_public_comment != "public" && happyfox_public_comment != "email") {
			happyfox_public_comment = "email"; //default
		}
		
		var params = {
			'action': 'happyfox_convert_to_ticket',
			'comment_id': comment_id,
			'comment_reply': comment_reply,
			'happyfox_public_comment': happyfox_public_comment
		};
		
		$.post(ajaxurl, params, function(response) {
			if(response.status == 200) {
				$('#happyfox-success-dialog .happyfox-success-ticket-id').attr('href', response.ticket_url);
				$('#happyfox-success-dialog .happyfox-success-ticket-id').text(response.ticket_id);
				$('#happyfox-success-dialog p').wrap('<div class="updated">');
				
				$.colorbox({
					inline: true,
					href: '#happyfox-success-dialog',
					width: '480px',
				});
			} else {
				$(form).find('.happyfox-notification-area').addClass('error').html(create_notice(response.message)).show();
			}
			$(loader).hide();
			$.colorbox.resize();
		}, 'json');
		
		return false;
	});
	
	//Setup Convert to HappyFox ticket dialog
	$('.happyfox-convert').click(function() {
		var comment_id = $(this).attr('data-id');
		var dialog_is_open = false;
		
		$.colorbox({
			initialWidth: '300px',
			initialHeight: '150px',
			maxHeight: '700px',
			overlayClose: false,
			opacity: 0.6,
			onOpen: function() { dialog_is_open = true; $('#cboxLoadingGraphic').show(); },
			onComplete: function() { $('#cboxLoadingGraphic').hide(); $.colorbox.resize(); },
			onCleanup: function() { dialog_is_open = false; }
		});
		
		var params = {
			'action': 'happyfox_convert_to_ticket_dialog',
			'comment_id': comment_id
		};
				
		$.post(ajaxurl, params, function(response) {
			if(!dialog_is_open) return;
			
			if(response.status == 200) {
				$('#happyfox-dialog-body').html(response.html).autolink().mailto();
				$('#happyfox-dialog-title').text('Convert this comment into a HappyFox ticket');

				// Replace the colorbox with our new dialog.
				$.colorbox({
						inline: true,
						href: "#happyfox-dialog",
						width: '743px',
						overlayClose: false
				});
			} else {
				console.log("FAILED!");
			}
		}, 'json');
	});
	
	//HappyFox support tab
	$("#happyfox_support_tab").change(function() {
		if($(this).val() != "disabled") {
			$("#happyfox_support_tab_code").closest('tr').fadeIn();
		} else {
			$("#happyfox_support_tab_code").closest('tr').fadeOut();
		}
	});
	
	if ( $('#happyfox_support_tab').val() == 'disabled') {
		$('#happyfox_support_tab_code').closest('tr').hide();
	}
		
	
	function create_notice(text) {
		return '<div class="happyfox-admin-notice happyfox-alert"><p>' + text + '</p></div>';
	}
	
	/* Settings related. Move to different file later.*/
	$('.happyfox_edit_account').click(function() {
		$(this).hide();
		$('.happyfox_account').hide();
		$('.happyfox_new_account, .happyfox_new_account > input').show();
	});
	
	$('.happyfox_edit_api_key').click(function() {
		$(this).hide();
		$('.happyfox_api_key').hide();
		$('.happyfox_new_api_key').show();
	});
	
	$('.happyfox_edit_api_auth').click(function() {
		$(this).hide();
		$('.happyfox_api_auth').hide();
		$('.happyfox_new_api_auth').show();
	});
	
	$('#happyfox-success-dialog .success-close').click(function() {
		$.colorbox.close();
		return false;
	});
	
});

// Creates auto links ( modified: http://forum.jquery.com/topic/jquery-simple-autolink-and-highlight-12-1-2010 )
jQuery.fn.autolink = function () {
    return this.each( function(){
        var re = /((http|https|ftp):\/\/[\w?=&.\/-;#~%-]+(?![\w\s?&.\/;#~%"=-]*>))/g;
        jQuery(this).html( jQuery(this).html().replace(re, '<a target="_blank" href="$1">$1</a>' ));
    });
}

// Creates auto e-mails ( modified: http://forum.jquery.com/topic/jquery-simple-autolink-and-highlight-12-1-2010 )
jQuery.fn.mailto = function () {
    return this.each( function() {
        var re = /(([a-z0-9*._+]){1,}\@(([a-z0-9]+[-]?){1,}[a-z0-9]+\.){1,}(travel|museum|[a-z]{2,4})(?![\w\s?&.\/;#~%"=-]*>))/g
        jQuery(this).html( jQuery(this).html().replace( re, '<a href="mailto:$1">$1</a>' ));
    });
}