<?php

require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Application;

// error_reporting(E_ALL);
// ini_set("display_errors", 1);

define("SMSRU_API_KEY", "XXXXXXXXXXXXXXXXX");
define("DEBUG_TAD", TRUE);
// define("DEBUG_TAD", FALSE);
define("CODES_FILE_ENTITY", "sended_codes");
define("PHONES_FILE_ENTITY", "all_phones");
define("CENTRIFUGO_SECRET", "XXXXXXXXXXXXXXXXX");

/**
 * Класс для работы с API сайта sms.ru для PHP 5.3 и выше
 * Разработчик WebProgrammer (kl.dm.vl@yandex.ru), легкие корректировки - Роман Гудев <rgudev@bk.ru>
 */
class SMSRU {

	private $ApiKey;
	private $protocol = 'https';
	private $domain = 'sms.ru';
	private $count_repeat = 5; //количество попыток достучаться до сервера если он не доступен

	function __construct($ApiKey) {
		$this->ApiKey = $ApiKey;
	}

	/**
	 * Совершает отправку СМС сообщения одному или нескольким получателям.
	 * @param $post
	 *   $post->to = string - Номер телефона получателя (либо несколько номеров, через запятую — до 100 штук за один запрос). Если вы указываете несколько номеров и один из них указан неверно, то на остальные номера сообщения также не отправляются, и возвращается код ошибки.
	 *   $post->msg = string - Текст сообщения в кодировке UTF-8
	 *   $post->multi = array('номер получателя' => 'текст сообщения') - Если вы хотите в одном запросе отправить разные сообщения на несколько номеров, то воспользуйтесь этим параметром (до 100 сообщений за 1 запрос). В этом случае, параметры to и text использовать не нужно
	 *   $post->from = string - Имя отправителя (должно быть согласовано с администрацией). Если не заполнено, в качестве отправителя будет указан ваш номер.
	 *   $post->time = Если вам нужна отложенная отправка, то укажите время отправки. Указывается в формате UNIX TIME (пример: 1280307978). Должно быть не больше 7 дней с момента подачи запроса. Если время меньше текущего времени, сообщение отправляется моментально.
	 *   $post->translit = 1 - Переводит все русские символы в латинские. (по умолчанию 0)
	 *   $post->test = 1 - Имитирует отправку сообщения для тестирования ваших программ на правильность обработки ответов сервера. При этом само сообщение не отправляется и баланс не расходуется. (по умолчанию 0)
	 *   $post->partner_id = int - Если вы участвуете в партнерской программе, укажите этот параметр в запросе и получайте проценты от стоимости отправленных сообщений.
	 *   $post->ip = string - IP адрес пользователя, в случае если вы отправляете код авторизации ему на номер в ответ на его запрос (к примеру, при регистрации). В случае аттаки на ваш сайт, наша система сможет помочь с защитой.
	 * @return array|mixed|\stdClass
	 */
	public function send_one($post) {
		$url = $this->protocol . '://' . $this->domain . '/sms/send';
		$request = $this->Request($url, $post);
		$resp = $this->CheckReplyError($request, 'send');

		if ($resp->status == "OK") {
			$temp = (array) $resp->sms;
			unset($resp->sms);

			$temp = array_pop($temp);

			if ($temp) {
				return $temp;
			} else {
				return $resp;
			}
		} else {
			return $resp;
		}

	}

	public function send($post) {
		$url = $this->protocol . '://' . $this->domain . '/sms/send';
		$request = $this->Request($url, $post);
		return $this->CheckReplyError($request, 'send');
	}

	/**
	 * Отправка СМС сообщений по электронной почте
	 * @param $post
	 *   $post->from = string - Ваш электронный адрес
	 *   $post->charset = string - кодировка переданных данных
	 *   $post->send_charset = string - кодировка переданных письма
	 *   $post->subject = string - тема письма
	 *   $post->body = string - текст письма
	 * @return bool
	 */
	public function sendSmtp($post) {
		$post->to = $this->ApiKey . '@' . $this->domain;
		$post->subject = $this->sms_mime_header_encode($post->subject, $post->charset, $post->send_charset);
		if ($post->charset != $post->send_charset) {
			$post->body = iconv($post->charset, $post->send_charset, $post->body);
		}
		$headers = "From: $post->\r\n";
		$headers .= "Content-type: text/plain; charset=$post->send_charset\r\n";
		return mail($post->to, $post->subject, $post->body, $headers);
	}

	public function getStatus($id) {
		$url = $this->protocol . '://' . $this->domain . '/sms/status';

		$post = new stdClass();
		$post->sms_id = $id;

		$request = $this->Request($url, $post);
		return $this->CheckReplyError($request, 'getStatus');
	}

	/**
	 * Возвращает стоимость сообщения на указанный номер и количество сообщений, необходимых для его отправки.
	 * @param $post
	 *   $post->to = string - Номер телефона получателя (либо несколько номеров, через запятую — до 100 штук за один запрос) Если вы указываете несколько номеров и один из них указан неверно, то возвращается код ошибки.
	 *   $post->text = string - Текст сообщения в кодировке UTF-8. Если текст не введен, то возвращается стоимость 1 сообщения. Если текст введен, то возвращается стоимость, рассчитанная по длине сообщения.
	 *   $post->translit = int - Переводит все русские символы в латинские
	 * @return mixed|\stdClass
	 */
	public function getCost($post) {
		$url = $this->protocol . '://' . $this->domain . '/sms/cost';
		$request = $this->Request($url, $post);
		return $this->CheckReplyError($request, 'getCost');
	}

	/**
	 * Получение состояния баланса
	 */
	public function getBalance() {
		$url = $this->protocol . '://' . $this->domain . '/my/balance';
		$request = $this->Request($url);
		return $this->CheckReplyError($request, 'getBalance');
	}

	/**
	 * Получение текущего состояния вашего дневного лимита.
	 */
	public function getLimit() {
		$url = $this->protocol . '://' . $this->domain . '/my/limit';
		$request = $this->Request($url);
		return $this->CheckReplyError($request, 'getLimit');
	}

	/**
	 * Получение списка отправителей
	 */
	public function getSenders() {
		$url = $this->protocol . '://' . $this->domain . '/my/senders';
		$request = $this->Request($url);
		return $this->CheckReplyError($request, 'getSenders');
	}

	/**
	 * Проверка номера телефона и пароля на действительность.
	 * @param $post
	 *   $post->login = string - номер телефона
	 *   $post->password = string - пароль
	 * @return mixed|\stdClass
	 */
	public function authCheck($post) {
		$url = $this->protocol . '://' . $this->domain . '/auth/check';
		$post->api_id = 'none';
		return $this->CheckReplyError($request, 'AuthCheck');
	}

	/**
	 * На номера, добавленные в стоплист, не доставляются сообщения (и за них не списываются деньги)
	 * @param string $phone Номер телефона.
	 * @param string $text Примечание (доступно только вам).
	 * @return mixed|\stdClass
	 */
	public function addStopList($phone, $text = "") {
		$url = $this->protocol . '://' . $this->domain . '/stoplist/add';

		$post = new stdClass();
		$post->stoplist_phone = $phone;
		$post->stoplist_text = $text;

		$request = $this->Request($url, $post);
		return $this->CheckReplyError($request, 'addStopList');
	}

	/**
	 * Удаляет один номер из стоплиста
	 * @param string $phone Номер телефона.
	 * @return mixed|\stdClass
	 */
	public function delStopList($phone) {
		$url = $this->protocol . '://' . $this->domain . '/stoplist/del';

		$post = new stdClass();
		$post->stoplist_phone = $phone;

		$request = $this->Request($url, $post);
		return $this->CheckReplyError($request, 'delStopList');
	}

	/**
	 * Получить номера занесённые в стоплист
	 */
	public function getStopList() {
		$url = $this->protocol . '://' . $this->domain . '/stoplist/get';
		$request = $this->Request($url);
		return $this->CheckReplyError($request, 'getStopList');
	}

	/**
	 * Позволяет отправлять СМС сообщения, переданные через XML компании UCS, которая создала ПО R-Keeper CRM (RKeeper). Вам достаточно указать адрес ниже в качестве адреса шлюза и сообщения будут доставляться автоматически.
	 */
	public function ucsSms() {
		$url = $this->protocol . '://' . $this->domain . '/ucs/sms';
		$request = $this->Request($url);
		$output->status = "OK";
		$output->status_code = '100';
		return $output;
	}

	/**
	 * Добавить URL Callback системы на вашей стороне, на которую будут возвращаться статусы отправленных вами сообщений
	 * @param $post
	 *    $post->url = string - Адрес обработчика (должен начинаться на http://)
	 * @return mixed|\stdClass
	 */
	public function addCallback($post) {
		$url = $this->protocol . '://' . $this->domain . '/callback/add';
		$request = $this->Request($url, $post);
		return $this->CheckReplyError($request, 'addCallback');
	}

	/**
	 * Удалить обработчик, внесенный вами ранее
	 * @param $post
	 *   $post->url = string - Адрес обработчика (должен начинаться на http://)
	 * @return mixed|\stdClass
	 */
	public function delCallback($post) {
		$url = $this->protocol . '://' . $this->domain . '/callback/del';
		$request = $this->Request($url, $post);
		return $this->CheckReplyError($request, 'delCallback');
	}

	/**
	 * Все имеющиеся у вас обработчики
	 */
	public function getCallback() {
		$url = $this->protocol . '://' . $this->domain . '/callback/get';
		$request = $this->Request($url);
		return $this->CheckReplyError($request, 'getCallback');
	}

	private function Request($url, $post = FALSE) {
		if ($post) {
			$r_post = $post;
		}
		$ch = curl_init($url . "?json=1");
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);

		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);

		curl_setopt($ch, CURLOPT_VERBOSE, 1);

		if (!$post) {
			$post = new stdClass();
		}

		if (!empty($post->api_id) && $post->api_id == 'none') {
		} else {
			$post->api_id = $this->ApiKey;
		}

		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query((array) $post));

		$body = curl_exec($ch);
		if ($body === FALSE) {
			$error = curl_error($ch);
		} else {
			$error = FALSE;
		}
		curl_close($ch);
		if ($error && $this->count_repeat > 0) {
			$this->count_repeat--;
			return $this->Request($url, $r_post);
		}
		return $body;
	}

	private function CheckReplyError($res, $action) {

		if (!$res) {
			$temp = new stdClass();
			$temp->status = "ERROR";
			$temp->status_code = "000";
			$temp->status_text = "Невозможно установить связь с сервером SMS.RU. Проверьте - правильно ли указаны DNS сервера в настройках вашего сервера (nslookup sms.ru), и есть ли связь с интернетом (ping sms.ru).";
			return $temp;
		}

		$result = json_decode($res);

		if (!$result || !$result->status) {
			$temp = new stdClass();
			$temp->status = "ERROR";
			$temp->status_code = "000";
			$temp->status_text = "Невозможно установить связь с сервером SMS.RU. Проверьте - правильно ли указаны DNS сервера в настройках вашего сервера (nslookup sms.ru), и есть ли связь с интернетом (ping sms.ru)";
			return $temp;
		}

		return $result;
	}

	private function sms_mime_header_encode($str, $post_charset, $send_charset) {
		if ($post_charset != $send_charset) {
			$str = iconv($post_charset, $send_charset, $str);
		}
		return "=?" . $send_charset . "?B?" . base64_encode($str) . "?=";
	}
}

class Tools {

    /**
     * Метод генерирует последовательность из случайных чисел
     *
     * @param int $length
     * @return string
     * @throws \Exception
     */
    public static function generateSmsCode($length = 4) {
        $number_list = '0123456789';
        $str = '';

        for ($i = 0; $i < $length; ++$i) {
            $str .= $number_list[random_int(0, 9)];
        }
        return $str;
    }

    /**
     * Преобразует номер телефона в регулярное выражение для возможности поиска по базе данных
     *
     * В базе данных номера пользователей могут быть записаны в абсолютно разных форматах, поэтому
     * найти пользователя с конкретным номером возможно только по регулярному выражению
     *
     * @param $phone
     * @return string
     */
    public static function getPhoneRegexp ($phone) {
        $delimiter = "[\\(\\) \\-]*";
        $phone = preg_replace('/[^0-9]/', '', $phone);
        $phone = str_split(substr($phone, 1));
        $regexp = implode($delimiter, $phone);
        return "^\\+?[78]" . $delimiter . $regexp . "$";
    }

    public static function translate($str, $locale) {        
        return "Ваш код подтверждения: ";
    }


    public static function get_file_data($entity) {
        $file_content = file_get_contents("$entity.json");
        return json_decode($file_content, true);
    }

    public static function set_file_data($entity, $data) {
        return file_put_contents("$entity.json", json_encode($data));
    }


}

class Sms {
    private $data;
    private $status;

    private function __construct($phone, $message) {
        $this->data = new stdClass();
        $this->data->to = $phone;
        $this->data->text = $message;
    }

    // Позволяет выполнить запрос в тестовом режиме без реальной отправки сообщения
    private function markAsTestSms() {
        $this->data->test = 1; 
    }

    /**
     * Метод создает объект класса Sms и вызывает функцию отправки сообщения
     *
     * @param $phone
     * @param $message
     * @return Sms
     */
    public static function send($phone, $message = "", $test = false) {
        $sms = new Sms($phone, $message);

        if ($test) {
            $sms->markAsTestSms();
        }

        if ( !isset($message) || empty($message) ) {
            $sms->setOtcMessage();
        }

        $sms->sending();

        return $sms;
    }

    /**
     * Метод отправляет сообщение
     *
     */
    private function sending() {
        $sms = new SMSRU(SMSRU_API_KEY);
        $this->status = $sms->send_one($this->data);
    }

    /**
     * Метод для просмотра статуса отправки сообщения
     *
     * @return mixed
     */
    public function getStatus() {
        return $this->status;
    }    

    /* Установить сообщение включающее сгенерированный код */ 
    private function setOtcMessage() {
        $otc = Tools::generateSmsCode();
        $this->data->text = "Ваш код: $otc";
    }
    
}

class TwoFactorAuth {

    private $otc_lifetime = 60*10;
    private $otc_blockedtime = 40;

    private $params = [ 
        "phone"     => false,
        "email"     => false,
        "salt"      => "",
        "otc"       => "",
        "provider"  => "sms",
    ];

    private $formated_phone_number = FALSE;

    public $status = "Empty";
    public $status_code = 0;
    public $status_message = "Пустой ответ";

    private static function init() {
        return new TwoFactorAuth();
    }

    protected static $instance;
    private function __construct(){ /* ... @return Singleton */ }
    private function __clone()    { /* ... @return Singleton */ } 
    private function __wakeup()   { /* ... @return Singleton */ }  

    public static function getInstance()  {
        if ( !isset(self::$instance) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function setParams($params) {        
        $this->params = array_merge($this->params, $params);
        return self::$instance;
    }

    public function set($key, $value) {        
        $this->params = array_merge($this->params, [ $key => $value ]);
        return self::$instance;
    }

    private function get_codes_data() {
        return Tools::get_file_data(CODES_FILE_ENTITY);
    }

    private function set_codes_data($data) {
        return Tools::set_file_data(CODES_FILE_ENTITY, $data);
    }

    private function get_phones_data() {
        return Tools::get_file_data(PHONES_FILE_ENTITY);
    }

    private function set_phones_data($data) {
        return Tools::set_file_data(PHONES_FILE_ENTITY, $data);
    }

    private function params_hash() {
        $this->format_phone();
        return md5(json_encode([
            $this->formated_phone_number,
            $this->params['email'],
            $this->params['salt'],
            $this->params['provider'],
        ]));
    }

    /* Перебор, чистка, возврат найденого кода */
    private function findAndClearExpired() {
        $finded = false;
        $already_sended_codes = $this->get_codes_data();
        foreach ($already_sended_codes as $index => $sended_code) {
            // Удаляем все просроченые
            if ($sended_code['otc_lifetime'] < time()) {
                unset($already_sended_codes[$index]);
                continue;
            }
            // Находим текущий код
            if ($sended_code['hash'] == $this->params_hash()) {
                $finded = $sended_code;
                $this->status = "Finded";
            }

        }
        $this->set_codes_data($already_sended_codes);
        return $finded;
    }
    
    public function format_phone() {
        if ($this->formated_phone_number) return $this->formated_phone_number;

        $this->formated_phone_number = preg_replace('/\D+/', '', $this->params['phone']);
        if ( $this->formated_phone_number[0] == 9 && strlen($this->formated_phone_number) == 10 ) {
            $this->formated_phone_number = "7".$this->formated_phone_number;
        }

        return $this->formated_phone_number;
    }

    /* Проверить телефон на правильность и доступность (HLR/Ping) и на уже отправленный код */
    private function checkAvailabilityOfPhoneNumber() {
        $saved_phone = $this->get_saved_phone_number();

        if ( $saved_phone !== false && 
             ($saved_phone['verified'] || $saved_phone['checked']) 
            ) {
                return TRUE;
        }

        var_dump("asdasdasd");

        // Проверка формата телефона
        $is_verified = false;

        // $regex = "/^[7-8][0-9]{10}/";
        // $is_verified = preg_match($regex,$this->formated_phone_number) == 1;

        if ( strlen($this->formated_phone_number) == 11 
            &&  (
                $this->formated_phone_number[0] == 7 
                || $this->formated_phone_number[0] == 8
                ) 
            ) {
            $is_verified = true;
        }
        
        if (!$is_verified) {
            $this->status_message = "Не правильно указан номер телефона";
            return FALSE;
        } 

        // Проверка сервисом HLR/Ping
        // Пропуск
        // $this->save_phone_number('checked');
        return TRUE;
    }

    /* Сохранить телефон в проверенных */
    private function save_phone_number($status = "requested") { 
        // Сохранить в файле чтобы не проверять, например внешним сервисом HLR/Ping
        $all_phones = $this->get_phones_data();

        if (!isset($all_phones[$this->formated_phone_number])) {
            $all_phones[$this->formated_phone_number] = [
                'update_time' => time(),
                'requested' => 0,
                'checked' => 0,
                'verified' => 0,
            ];
        }

        $mark = 1;
        if ("requested" == $status) { $mark = $all_phones[$this->formated_phone_number][$status] + 1; }
        $all_phones[$this->formated_phone_number][$status] = $mark;

        $this->set_phones_data($all_phones);   
    }

    private function get_saved_phone_number() { 
        $all_phones = $this->get_phones_data();
        return isset($all_phones[$this->formated_phone_number])?$all_phones[$this->formated_phone_number]:false;
    }    

    /* Сохранить отметку отправки кода */
    private function saveOtcSended() {
        $already_sended_codes = $this->get_codes_data();
        $sended_code = [
            'creation_time' => time(),
            'otc_lifetime'  => time() + $this->otc_lifetime,
            'otc_blockedtime'  => time() + $this->otc_blockedtime,
            'otc'   => $this->params['otc'],
            'hash'  => $this->params_hash(),
            'check_count' => 0,
        ];
        
        $already_sended_codes[$this->params_hash()] = $sended_code;

        $this->set_codes_data($already_sended_codes);        
    }
    
    /* Обновить отметку отправки кода */
    private function updateOtcSended($index, $update_data) {
        $already_sended_codes = $this->get_codes_data();
        $already_sended_codes[$index] = array_merge($already_sended_codes[$index], $update_data);
        $this->set_codes_data($already_sended_codes);
    }
    
    /* Удалить отметку отправки кода */
    private function deleteOtcSended() {
        $already_sended_codes = $this->get_codes_data();
        foreach ($already_sended_codes as $index => $sended_code) {
            // Удаляем все просроченые
            if ($sended_code['otc_lifetime'] < time()) {
                unset($already_sended_codes[$index]);
                continue;
            }
            // Находим текущий код и удаляем
            if ($sended_code['hash'] == $this->params_hash()) {
                unset($already_sended_codes[$index]);
                continue;
            }

        }
        $this->set_codes_data($already_sended_codes);       
    }
    
    /* Очистить отметки отправки старых кодов */
    private function deleteAllExpiredOtc() {
        $already_sended_codes = $this->get_codes_data();
        foreach ($already_sended_codes as $index => $sended_code) {
            // Удаляем все просроченые
            if ($sended_code['otc_lifetime'] < time()) {
                unset($already_sended_codes[$index]);
                continue;
            }
        }
        $this->set_codes_data($already_sended_codes);
    }

    /* Отправить код */    
    public function sendCode() {
        switch ($this->params["provider"]) {
            case "sms":                
                $this->format_phone();
                $this->save_phone_number();
                $access = $this->checkAvailabilityOfPhoneNumber();
                break;
            default:
                $access = true;
        }
        
        if ($access === false) { 
            $this->status_code = "423";
            return self::$instance; 
        }

        // Поиск в активных отправленых кодах
        $finded = $this->findAndClearExpired();
        if ($finded !== false) {
            // Проверка на доступность повторной отправки кода
            if ($finded['otc_blockedtime'] > time()) {                
                $this->status_code = "423";
                $last_seconds = $finded['otc_blockedtime'] - time();
                $this->status_message = "Код уже отправлен. Повторная отправка кода доступна через $last_seconds сек.";
                return self::$instance; 
            }
        }

        $method_name = "sendCodeBy_".$this->params["provider"];
        $method_res = call_user_func([$this, $method_name]);
        
        if ($method_res) {
            $this->status_code = "200";
            $this->status_message = "Код успешно отправлен";
            
            if ($finded === false ) {
                $this->saveOtcSended();
            } else {
                $this->updateOtcSended($finded['hash'],[                    
                    'otc_blockedtime'  => time() + $this->otc_blockedtime,
                ]);
            }
        } else {
            $this->status_code = "502";
            $this->status_message = "Ошибка отправки кода, обратитесь в тех.поддержку";
        }

        return self::$instance;
    }

    private function sendCodeBy_sms() {
        if (DEBUG_TAD) return true;
        $sms = Sms::send(
            $this->params["phone"],
            Tools::translate("Your OTC: ", "RU").$this->params["otc"],
            true,
        );
        $res = $sms->getStatus()->status_code == 100;
        return $res;
    }

    private function sendCodeBy_email() {
        if (DEBUG_TAD) return true;
        // Для отправки HTML-письма должен быть установлен заголовок Content-type
        $headers  = 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";

        // Дополнительные заголовки
        $headers .= 'From: AVT <no-reply@avtsport.ru>';

        $res = mail($this->params["email"], Tools::translate("Your OTC: ", "RU"), $this->params["otc"], $headers);
        return $res;
    }

    /* Проверить что код правильный */    
    public function checkOtc() {
        // Проверить код в отправленых    
        $finded = $this->findAndClearExpired();

        if ($finded === false) {
            $this->status = "Miss";
            $this->status_code = "404";
            $this->status_message = "Такой код не найден";            
            return self::$instance;
        }
        
        if ($finded['otc'] != $this->params['otc']) {
            $this->status_code = "409";
            $this->status_message = "Код недействителен";            
            return self::$instance;
        }
        
        $this->status_message = "Код правильный";  
        $this->status = "Correct";   

        // Удалить отметку об отправке, так как телефон/email и код уже проверен
        $this->deleteOtcSended();

        // Сохранить телефон в проверенных
        switch ($this->params["provider"]) {
            case "sms":                
                $this->format_phone();
                $this->save_phone_number('verified');
                break;
        }

        return self::$instance;
    }
}

class BitrixAuth {
    
    /**
     * Метод проверяет наличие данных юзера в БД не зависимо от статуса ACTIVE
     *
     * @param $phone
     * @return mixed
     */
    public static function checkUserByPhone($phone) {
        $regexp = Tools::getPhoneRegexp($phone);
        $connection = Application::getConnection();
        $sqlHelper = $connection->getSqlHelper();
        $query = "
            SELECT ID
            FROM b_user 
            WHERE 
                PERSONAL_PHONE RLIKE '". $sqlHelper->forSql($regexp) ."'
                OR PERSONAL_MOBILE RLIKE '". $sqlHelper->forSql($regexp) ."'  
            LIMIT 1
        ";

        $res = $connection->query($query);
        $data = $res->fetch();

        return  ($data) ? $data['ID'] : $data;
    }

    public static function auth($phone) {        
        global $USER;
        $res = false;

        $id = self::checkUserByPhone($phone);
        if(boolval($id)) {   
            $res = $USER->Authorize((int)$id, true);
        }

        return $res?($USER):false;
    }
    
    /**
     * Метод для авторизации пользователя по логину и паролю
     *
     * @param $login
     * @param $password
     * @return mixed
     */
    public static function login($login, $password) {
        global $USER;
        $result = $USER->Login($login, $password, 'Y');
        if ($result === true) {
            $result = $USER;
        } else {
            // Авторизация не удалась
            $result = FALSE;
        }

        return $result;
    }

    /**
     * Метод для авторизации пользователя по логину после проверки одноразового кода
     *
     * @param $email
     * @return mixed
     */
    public static function auth_by_email($email) {
        global $USER;
        $result = FALSE;
      
        $connection = Application::getConnection();
        $sqlHelper = $connection->getSqlHelper();  
        $query = "
            SELECT ID
            FROM b_user 
            WHERE 
            LOGIN = '".$sqlHelper->forSql($email, 50)."'
            LIMIT 1
        ";

        $res = $connection->query($query);
        $data = $res->fetch();

        if ($data) {
            $auth = $USER->Authorize($data["ID"]);
            if ($auth === true) {
                $result = $USER;
            }
        }


        return $result;
    }
    /**
     * Метод для разлогина пользователя
     *
     * @return mixed
     */
    public static function logout() {
        global $USER;
        return $USER->Logout();
    }
}

class TadSimpleJWT {

    public static function token($payload_data, $secret) {
        
        $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = json_encode($payload_data);
        

        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        

        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));


        $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
        return $jwt;

    }
}

class MobileRequests {
    private $current_user;
    private $req_params = [
        'delay' => false,
    ];
    private $response = [];
    private $default_api_res = [
        "status" => 200,
        "message" => "Mobile API v2",
    ];
    private $default_error = [
        "status" => 401,
        "message" => "No Authorized",
    ];

    public function __construct() {
        global $USER;
        $this->current_user = $USER;
    }
    
    /* CORE */
    
    public function processRequest() {
        $this->parseRequestParams();
        $this->checkResponseDelay();
        // $this->checkAccess();
        $this->callProcedure();
        $this->sendResponse();
    }

    private function parseRequestParams() {
        $this->req_params = array_replace_recursive( $this->req_params, $_REQUEST);

        $json = file_get_contents('php://input');
        if ($json) $this->req_params = array_replace_recursive( $this->req_params, json_decode($json, true) );
        
        $this->log($json);
        // $this->log($this->req_params);
    }

    private function checkResponseDelay() {
        if ($this->req_params['delay']) {
            sleep($this->req_params['delay']);
        }
    }
    
    private function addDataToResponse($data) {
        $this->response = array_replace_recursive($this->response, $data);
    }

    private function callProcedure() {
        if (!isset($this->req_params['p'])) return FALSE;

        $method_name = "callable_" . htmlspecialchars(trim($this->req_params['p']));
        if (!method_exists($this,$method_name)) {
            $this->addDataToResponse([
                'status'=> 206,
                'message' => 'Procedure not exist',
            ]);
            return FALSE;
        }

        $method_res = call_user_func([$this, $method_name]);

        return $method_res;
    }
    
    public function sendResponse($response = NULL, $header = 'Content-type: application/json', $json_encode = true) {
        header($header);

        if (!isset($response)) {
            $response = $this->response;
        }

        if (empty($response)) {
            $response = $this->default_api_res;
        }

        $response = ($json_encode) ? json_encode($response) : $response;
        echo $response;
    }
    
    private function checkParamsNeeded($params_must_be_exist) {
        $all_exist = TRUE;
        $required_params_names = [];
        foreach ($params_must_be_exist as $param_name) {
            if (!isset($this->req_params[$param_name])) {
                $all_exist = FALSE;
                $required_params_names[] = $param_name;
            }
        }
        if (!$all_exist) {
            $this->addDataToResponse([
                'status'=> 206,
                'message' => 'Miss params',
                'missed_params_list' => $required_params_names,
            ]);
        }

        return $all_exist;
    }

    private function getUserByToken() {
        return [
            'id' => $this->req_params['token'],            
        ];
    }
    
    private function log($data) {
        return file_put_contents('log.txt', json_encode($data));
    }
    
    /* CALLABLE ROUTES */
    private function callable_test() {
        $need_params = ['token'];
        if (!$this->checkParamsNeeded($need_params)) return FALSE;

        $this->addDataToResponse([
            'status'=> 200,
            'message' => 'Response OK',
            'data' => [
                'time' => time(),
                'token' => $this->req_params['token'],
            ],
        ]);
    }

    private function transitParamKeys($keys_pairs) {
        $params = [];
        foreach ($keys_pairs as $new_key => $req_params_key) {
            if (isset($this->req_params[$req_params_key])) {
                $params[$new_key] = $this->req_params[$req_params_key];
            }
        }
        return $params;
    }

    /* Запрос отправки одноразового кода доступа */
    private function callable_request_otc() {
        $need_params = ['phone', 'app_id'];
        if (!$this->checkParamsNeeded($need_params)) return FALSE;
        $TFA_Service = TwoFactorAuth::getInstance()
                ->setParams($this->transitParamKeys([
                    "phone"     => 'phone',
                    "salt"      => 'app_id',
                    "provider"  => 'provider',
                    "email"     => 'email',
                ]))
                ->setParams([
                    "otc"       => Tools::generateSmsCode(),
                ])
                ->sendCode();

        $this->addDataToResponse([
            'status'=> $TFA_Service->status_code,
            'message' => $TFA_Service->status_message,
            'data' => [
                'time' => time(),
                'status' => $TFA_Service->status,
            ],
        ]);
    }

    private function callable_check_otc_and_auth() {
        $need_params = ['app_id', 'code'];
        if (!$this->checkParamsNeeded($need_params)) return FALSE;
        $TFA_Service = TwoFactorAuth::getInstance()
                ->setParams($this->transitParamKeys([
                    "phone"     => 'phone',
                    "salt"      => 'app_id',
                    "provider"  => 'provider',
                    "email"     => 'email',
                    "otc"       => 'code',
                ]))
                ->checkOtc();
                
        $status_code = $TFA_Service->status_code;
        $status_message = $TFA_Service->status_message;
        $status = $TFA_Service->status;    

        if ($TFA_Service->status == "Correct") {
            $status_code = 302;
            $status_message = "Пользователь не найден";
            $status = "Register Available";
            
            switch ($this->params["provider"]) {
                case "sms":                
                    $bitrixUser = BitrixAuth::auth( $TFA_Service->format_phone() );
                    break;
                default:
                    $bitrixUser = BitrixAuth::auth_by_email($this->req_params['email']);
                    break;
            }

            if ($bitrixUser !==false) {
                $payload = [
                    "name" => $bitrixUser::GetFullName(),
                    "id" => $bitrixUser::GetID(),
                ];
                // Сгенерировать JWT токен
                $jwtToken = TadSimpleJWT::token($payload, CENTRIFUGO_SECRET);
                $status_code = 200;
                $status_message = "Пользователь авторизован";
                $status = $jwtToken;
            }
        }

        $this->addDataToResponse([
            'status'=> $status_code,
            'message' => $status_message,
            'data' => [
                'time' => time(),
                'status' => $status,
            ],
        ]);
    }
    
    private function callable_login_by_email() {
        $need_params = ['email', 'app_id'];
        if (!$this->checkParamsNeeded($need_params)) return FALSE;

        $status_code = 404;
        $status_message = "Empty";
        $status = "Empty"; 

        // Check need TFAuth by code?
        if (!isset($this->req_params['password'])) {            
            $TFA_Service = TwoFactorAuth::getInstance()
                ->setParams($this->transitParamKeys([
                    "salt"      => 'app_id',
                    "email"     => 'email',
                ]))
                ->setParams([
                    "otc"       => Tools::generateSmsCode(),
                    "provider"  => "email",
                ])
                ->sendCode();
            $status_code = $TFA_Service->status_code;
            $status_message = $TFA_Service->status_message;
            $status = $TFA_Service->status;   
        } else {
            
            $status_code = 403;
            $status_message = "Неверная почта или пароль.";
            $status = "Empty"; 
            $bitrixUser = BitrixAuth::login($this->req_params['email'], $this->req_params['password']);
            if ($bitrixUser !== false) {
                $payload = [
                    "name" => $bitrixUser::GetFullName(),
                    "id" => $bitrixUser::GetID(),
                ];
                // Сгенерировать JWT токен
                $jwtToken = TadSimpleJWT::token($payload, CENTRIFUGO_SECRET);
                $status_code = 200;
                $status_message = "Пользователь авторизован";
                $status = $jwtToken;
            }
        }

        $this->addDataToResponse([
            'status'=> $status_code,
            'message' => $status_message,
            'data' => [
                'time' => time(),
                'status' => $status,
            ],
        ]);
    }
    
    private function callable_logout() {
        BitrixAuth::logout();
        $this->addDataToResponse([
            'status'=> 200,
            'message' => 'logout',
        ]);
    }
}

$mbReq = new MobileRequests();
$mbReq->processRequest();
exit();
