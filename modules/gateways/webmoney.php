<?php
/**
 * Description: Gateway module for WebMoney integration
 * Author: Aleksey Vaganov, Albert Thalidzhokov
 * URL: https://github.com/thalidzhokov/whmcs-webmoney
 */

if (!defined('WHMCS')) {
	die('This file cannot be accessed directly');
}

define('PATH', __DIR__);

/**
 * @return mixed
 */
function webmoney_config()
{
	$apiUser = _webmoney_getAdminUsername();

	$configarray['FriendlyName'] = [
		'Type' => 'System',
		'Value' => 'WebMoney'
	];

	//получаем список валют и создаем поля для ввода номеров кошельков для каждой валюты
	$Result = localAPI('getcurrencies', [], $apiUser);

	if (empty($Result) || $Result['result'] == 'error') {
		die('Ошибка');
	}

	$currencies = $Result['currencies']['currency'];

	if ($currencies) {

		foreach ($currencies as $currency) {
			$configarray['purse_' . $currency['code']] = [
				'FriendlyName' => 'Кошелек ' . $currency['code'],
				'Type' => 'text',
				'Size' => '13',
				'Description' => 'Укажите номер кошелька в валюте ' . $currency['code'] . ' (буква и 12 цифр)'
			];
			$configarray['secretkey_' . $currency['code']] = [
				'FriendlyName' => 'Секретный код кошелька в ' . $currency['code'],
				'Type' => 'text',
				'Size' => '30',
				'Description' => 'Укажите секретный код, который вы указали в настройках WM Transfer для кошелька в ' . $currency['code']
			];
		}
	} else {
		die('Нет валют');
	}

	$configarray['simmode'] = [
		'FriendlyName' => 'Тестовый режим',
		'Type' => 'dropdown',
		'Options' =>
			'Выкл.' . ',' .
			'Успешные операции' . ',' .
			'Операции с ошибкой' . ',' .
			'Комбинированный',
		'Description' => 'Выберите режим тестирования'
	];

	$configarray['logging'] = [
		'FriendlyName' => 'Ведение логов',
		'Type' => 'yesno',
		'Description' => 'Отметьте если нужно вести логи'
	];

	return $configarray;
}

/**
 * @param $params
 * @return string
 */
function webmoney_link($params = [])
{
	$apiUser = _webmoney_getAdminUsername();

	if ($params['logging'] == 'on') {
		_webmoney_logs('webmoney_link params: ' . print_r($params, true));
	}

	$invoiceid = $params['invoiceid'];
	$description = $params['description'];

	if (empty($description)) {
		$description = 'Оплата заказа №' . $invoiceid . ' ';
		$description .= 'Клиент: ' .
			$params['clientdetails']['lastname'] . ' ' .
			$params['clientdetails']['firstname'];
	}

	$description = base64_encode($description);
	$amount = $params['amount'];
	$paycurrency = $params['currency'];
	$purse = $params['purse_' . $paycurrency];

	//Если для выбранной пользователем валюты нет соответствующего кошелька WebMoney
	if (empty($purse)) {
		$MAmount = $amount; //сохраняем оригинальную сумму счета
		$FExchangePurse = 1; //устанавливаем флаг смены валюты и суммы платежа

		//получаем список валют
		$Result = localAPI('getcurrencies', [], $apiUser);

		if (empty($Result) || $Result['result'] == 'error') {
			die('Ошибка');
		}

		if ($params['logging'] == 'on') {
			_webmoney_logs('localAPI getcurrencies result: ' . print_r($Result, true));
		}

		//Находим базовую валюту
		$BaseCurrency = _webmoney_getBaseCurrency($Result['currencies']['currency']);

		//Берем номер кошелька для базовой валюты
		if ($BaseCurrency && $params['purse_' . $BaseCurrency['code']] != "") {
			$purse = $params['purse_' . $BaseCurrency['code']];
		} else {
			die('Не указан номер кошелька WebMoney для базовой валюты!');
		}

		//Вычисляем размер оплаты в базовой валюте
		//Для этого берем курс валюты, выбранной пользователем

		$currency = _webmoney_getPaymentCurrency($Result['currencies']['currency'], $paycurrency);
		$CurrencyRate = (float)$currency['rate'];

		//Вычисляем размер оплаты в базовой валюте
		if ($CurrencyRate) {
			$amount = round($amount / $CurrencyRate, 0);
		} else {
			die('Не найден курс валюты ' . $paycurrency);
		}
	}

	switch ($params['simmode']) {
		case 'Выкл.':
			$simmode = 10;
			break;

		case 'Успешные операции':
			$simmode = 0;
			break;

		case 'Операции с ошибкой':
			$simmode = 1;
			break;

		case 'Комбинированный':
			$simmode = 2;
			break;
	}

	$code = '<form method="POST" action="https://merchant.webmoney.ru/lmi/payment.asp">';
	//$code = '<form method="POST" action="/test.php">';
	$code .= '<input type="hidden" name="LMI_PAYMENT_AMOUNT" value="' . $amount . '">';
	$code .= '<input type="hidden" name="LMI_PAYMENT_DESC_BASE64" value="' . $description . '">';
	$code .= '<input type="hidden" name="LMI_PAYMENT_NO" value="' . $invoiceid . '">';
	$code .= '<input type="hidden" name="LMI_PAYEE_PURSE" value="' . $purse . '">';

	if (isset($simmode) && $simmode != 10) {
		$code .= '<input type="hidden" name="LMI_SIM_MODE" value="' . $simmode . '">';
		$code .= '<input type="hidden" name="M_SIM_MODE" value="1">';
	}

	if (isset($FExchangePurse) && $FExchangePurse &&
		isset($MAmount) && $MAmount
	) {
		$code .= '<input type="hidden" name="M_CURRENCY" value="' . $paycurrency . '">';
		$code .= '<input type="hidden" name="M_AMOUNT" value="' . $MAmount . '">';
	}

	$code .= '<input type="submit" value="Оплатить">';
	$code .= '</form>';


	return $code;
}


/**
 * Custom functions
 */

/**
 * @param string $message
 */
function _webmoney_logs($message = '')
{
	$logFile = PATH . '/webmoney.log';
	$file = fopen($logFile, 'a');
	fwrite($file, date('Y-m-d H:i:s') . ' - ' . $message . "\n");
	fclose($file);
}

/**
 * @param $Currencies
 * @return bool|mixed
 */
function _webmoney_getBaseCurrency($Currencies = [])
{
	$rtn = false;

	if (is_array($Currencies) && !empty($Currencies)) {

		foreach ($Currencies as $Currency) {

			if ($Currency['rate'] == 1) {
				$rtn = $Currency;
				break;
			}
		}
	}

	return $rtn;
}

/**
 * @param $Currencies
 * @param $Code
 * @return bool|mixed
 */
function _webmoney_getPaymentCurrency($Currencies = [], $Code = '')
{
	$rtn = false;

	if (is_array($Currencies) && !empty($Currencies)) {

		foreach ($Currencies as $Currency) {

			if ($Currency['code'] == $Code) {
				$rtn = $Currency;
				break;
			}
		}
	}

	return $rtn;
}

/**
 * @return bool
 */
function _webmoney_getAdminUsername()
{
	$rtn = false;

	$adminData = \Illuminate\Database\Capsule\Manager::table('tbladmins')
		->where('disabled', '=', 0)
		->first();

	if (!empty($adminData)) {
		$rtn = $adminData->username;
	}

	return $rtn;
}