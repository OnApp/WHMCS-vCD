<?php

if( ! isset( $_POST[ 'authenticity_token' ] ) ) {
	exit( 'Don\'t allowed!' );
}

$root = dirname( dirname( dirname( dirname( dirname( dirname( $_SERVER[ 'SCRIPT_FILENAME' ] ) ) ) ) ) ) . DIRECTORY_SEPARATOR;
require $root . 'init.php';

$iv_size = mcrypt_get_iv_size( MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB );
$iv = mcrypt_create_iv( $iv_size, MCRYPT_RAND );
$key = substr( $_SESSION[ 'utk' ][ 0 ], 0, 32 );
$crypttext = mcrypt_decrypt( MCRYPT_RIJNDAEL_256, $key, base64_decode( base64_decode( $_SESSION[ 'utk' ][ 1 ] ) ), MCRYPT_MODE_ECB, $iv );
$data = explode( '%%%', $crypttext );

if( count( $data ) != 2 ) {
	exit( 'Corrupted data!' );
}
else {
	$data = json_decode( $data[ 0 ] );
}

?>

<noscript>
	<meta http-equiv="refresh" content="0; url=<?php echo $data->server ?>">
</noscript>
<base href="<?php echo $data->server ?>">
<div id="cpform" style="display: none;">
	<?php
	require 'CURL.php';
	$curl = new CURL;
	$curl->addOption( CURLOPT_RETURNTRANSFER, true );
	$curl->addOption( CURLOPT_FOLLOWLOCATION, true );
	$cp = $curl->get( $data->server );

	if( $curl->getRequestInfo( 'http_code' ) == 200 ) {
		$js = <<<JS
			// fill data
            document.getElementById( 'user_login' ).value = '{$data->login}';
            document.getElementById( 'user_password' ).value = '{$data->password}';
            // add attributes
            document.getElementById( 'new_user' ).setAttribute( 'autocomplete', 'off' );
            document.getElementById( 'new_user' ).setAttribute( 'action', '{$data->server}/users/sign_in' );
            document.getElementById( 'user_password' ).setAttribute( 'type', 'hidden' );
            // submit form
            document.getElementById( 'new_user' ).submit();
            document.getElementById( 'new_user' ).outerHTML = '';
            document.getElementById( 'getcp' ).innerHTML = '';
            document.getElementById( 'cpform' ).style.display = 'block';
JS;
		$js = '<script type="text/javascript" id="getcp">' . $js . '</script>';
	}
	else {
		$js = '<script type="text/javascript">document.getElementById( "cpform" ).style.display = "block";</script>';
	}
	echo $cp . $js;
	?>
</div>