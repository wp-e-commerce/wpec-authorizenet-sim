jQuery(document).ready(function($) {

	$('#auth_sim').submit(function() {
		//disable & hide this button to prevent double-submit
		$('#submit').attr("disabled", "disabled").fadeOut();
		return true;
	});	

	$('#submit').click();

});