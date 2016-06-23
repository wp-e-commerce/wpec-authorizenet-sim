<?php
$url = wpsc_merchant_authorize_sim::getSIMURL();
?>
<form method='post' id="auth_sim" action='<?php echo $url; ?>' >
<?php

foreach ( $_SESSION['sim-checkout'] as $name => $value ) {
	 echo "<input type='hidden' name='{$name}' value='{$value}' />\n";
}

?>
<input type='submit' id='submit' value='Click if not redirected to Authorize.net within 10 seconds' />
</form>
