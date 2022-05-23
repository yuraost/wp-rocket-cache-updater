jQuery(document).ready(function($){
	$('[id^="wp-admin-bar-cache-updater"] a').click(function(e) {
		e.preventDefault();
	});

	$(document).on('click', '.update-cache-start a', function() {
		let $this = $(this),
			text = $this.text(),
			href = $this.attr('href');

		$this.text('Starting...');

		$.post(ajaxurl, {
			action:	'cache-updater-start',
			type: href.replace('#', '')
		}, function(resp) {
			$this.text(text);
			update_state(resp);
		});
	});

	$(document).on('click', '#wp-admin-bar-cache-updater-stop a', function() {
		let $this = $(this),
			text = $this.text();
		
		$this.text('Stopping...');

		clearTimeout(state_timeout);

		$.post(ajaxurl, {
			action: 'cache-updater-stop'
		}, function(resp) {
			$this.text(text);
			update_state(resp);
		});
	});

	let state_timeout = 0;
	function update_state(state) {
		if (typeof state == 'undefined') {
			$.post(ajaxurl, {
				action: 'cache-updater-state'
			}, function(resp) {
				update_state(resp);
			});			

			return false;
		}

		$('#wp-admin-bar-cache-updater').removeClass('updated updating need-update');
		$('#wp-admin-bar-cache-updater').addClass(state['state']);
		$('#wp-admin-bar-cache-updater .need-update-count').text(state['need-update']);

		if (state.state == 'updating') {
			state_timeout = setTimeout(update_state, 10000);
		}
	}

	if ($('#wp-admin-bar-cache-updater').hasClass('updating')) {
		update_state();
	}
});