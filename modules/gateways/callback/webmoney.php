<?php

/* Required File Includes */
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModule = 'webmoney';
$GATEWAY = getGatewayVariables($gatewayModule);

if (!$GATEWAY["type"]) {
	die("Module Not Activated");
}

$returnUrl = $GATEWAY['returnurl'];

$page = <<<HTML
<html>
	<head>
		<script type="text/javascript">
			setTimeout('location.replace("$returnUrl")', 100);
		</script>
		<noscript>
			<meta http-equiv="refresh" content="1; url=$returnUrl">
		</noscript>
	</head>
	<body></body>
</html>
HTML;

logTransaction($gatewayModule, $_REQUEST, $_REQUEST['step']);

/* общие параметры запроса сохраняем в локальных переменных */
$purse = $_REQUEST["LMI_PAYEE_PURSE"];
$amount = $_REQUEST["LMI_PAYMENT_AMOUNT"];
$simmode = $_REQUEST["LMI_MODE"];
$invDesc = $_REQUEST["LMI_PAYMENT_DESC"];
$PayerPurse = $_REQUEST["LMI_PAYER_PURSE"];
$PayerWMID = $_REQUEST["LMI_PAYER_WM"];
$invoiceid = $_REQUEST["LMI_PAYMENT_NO"];
$WMT_invoiceid = $_REQUEST['LMI_SYS_INVS_NO'];
$WMT_transactionid = $_REQUEST['LMI_SYS_TRANS_NO'];
$DateTime = $_REQUEST['LMI_SYS_TRANS_DATE'];
$hash = $_REQUEST['LMI_HASH'];

switch ($_REQUEST['step']) {
	case "result":
		$FAmountCorrect = true; //Флаг корректности суммы платежа
		$FPurseCorrect = true; //Флаг корректности номера кошелька

		$invoiceid = checkCbInvoiceID($invoiceid, $GATEWAY["name"]); /* Проверяем номер счета */

		/* получаем информацию о счете */
		$params = array('invoiceid' => $invoiceid);
		$Result = localAPI('getinvoice', $params, $GATEWAY['APIUser']);

		if (empty($Result) || $Result['result'] == 'error') die("ERROR");

		$inv_amount = $Result['balance'];
		$my_amount = $inv_amount;

		/* Проверяем соответствие суммы платежа и номер кошелька */
		if (isset($_REQUEST["M_CURRENCY"])) /* если менялась валюта платежа */ {
			/* получаем список валют */
			$Result = localAPI('getcurrencies', array(), $GATEWAY['APIUser']);

			if (empty($Result) || $Result['result'] == 'error') die("ERROR");

			//получаем курс оригинальной валюты
			$Currencies = $Result['currencies']['currency'];
			$currency = GetPaymentCurrency($Currencies, $_REQUEST["M_CURRENCY"]);
			$CurrencyRate = (float)$currency['rate'];

			$my_amount = round($my_amount / $CurrencyRate, 0);
			$my_amount = number_format($my_amount, 2, ".", "");

			/* проверям сумму платежа */
			if ($inv_amount != $_REQUEST["M_AMOUNT"] || $my_amount != $amount) {
				$FAmountCorrect = false;
				echo "Сумма платежа неверна";
			}

			/* проверяем номер кошелька */
			$BaseCurrency = GetBaseCurrency($Currencies); /* Находим базовую валюту */

			if ($GATEWAY['purse_' . $BaseCurrency['code']] != $purse) {
				$FPurseCorrect = false;
				echo "Номер кошелька неверен";
			}
		} else {
			//проверяем сумму платежа
			if ($my_amount != $amount) {
				$FAmountCorrect = false;
				echo "Сумма платежа неверна";
			}
			//проверяем номер кошелька
			if (!array_search($purse, $GATEWAY)) {
				$FPurseCorrect = false;
				echo "Номер кошелька неверен";
			}
		}

		if ($_REQUEST["LMI_PREREQUEST"] == 1) {
			//Предварительный запрос
			$trans_desc = $gatewayModule . ' Поступил предварительный запрос об оплате: ';

			if ($FPurseCorrect && $FAmountCorrect) {
				echo "YES";
				$trans_desc .= "Успешно";
			} else {
				$trans_desc .= "Ошибка (неверный номер кошелька и/или сумма платежа)";
			}
		} else {
			//оповещение о платеже
			$trans_desc = $gatewayModule . ' Поступило оповещение об оплате: ';

			if ($FPurseCorrect && $FAmountCorrect) {

				if ($GATEWAY['simmode'] == "Выкл.") $mode = 0; else $mode = 1;
				$SecretKeyField = "secretkey_" . substr(array_search($purse, $GATEWAY), -3, 3);
				$SecretKey = $GATEWAY[$SecretKeyField];

				$myhash = strtoupper(md5($purse . $my_amount . $invoiceid . $mode . $WMT_invoiceid . $WMT_transactionid . $DateTime . $SecretKey . $PayerPurse . $PayerWMID));

				if ($myhash == $hash) $trans_desc .= "Успешно"; else    $trans_desc .= "Ошибка (неверная контрольная сумма)";
			} else {
				$trans_desc .= "Ошибка (неверный номер кошелька и/или сумма платежа)";
			}
		}

		logTransaction($GATEWAY["name"], $_REQUEST, $trans_desc);
		break;

	case "success":
		//Платеж выполнен
		checkCbTransID($WMT_transactionid);
		if ($_REQUEST['M_SIM_MODE'] == 0) addInvoicePayment($invoiceid, $WMT_transactionid, $my_amount, 0, $gatewayModule);
		$trans_desc = $gatewayModule . ' Поступление оплаты: Успешно';
		logTransaction($GATEWAY["name"], $_REQUEST, $trans_desc);
		echo $page;
		break;

	case "fail":
		//Платеж не выполнен
		$trans_desc = $gatewayModule . ' Поступление оплаты: Ошибка';
		logTransaction($GATEWAY["name"], $_REQUEST, $trans_desc);
		echo $page;
		break;
}

