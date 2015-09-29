<?php 
/*
*
*
*     Author: Paolo Abadesco
*      Email: admin@epay.ph
*        Web: https://epay.ph
*    Details: ePay.ph Payment Plugin IPN
*
* aMember PRO is a commercial software. Any distribution is strictly prohibited.
*
*
*/

require_once("../../../config.inc.php");

$pl = & instantiate_plugin('payment', 'epayph');
$vars = $_POST;
if($vars['txn_type']=='cart' || $vars['txn_type']=='web_accept')
	$GLOBALS['amember_is_recurring'] = 0;
else
	$GLOBALS['amember_is_recurring'] = 1;
$pl->handle_postback($vars);

?>
