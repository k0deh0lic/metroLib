<?php

namespace NXLogisMetro;

class metroLib {
	/*
	* 수도권 전철 1호선, 3호선 및 4호선의 경우 행선지, 급행여부가 누락되거나 오기되는 경우가 있어 이를 TOPIS API를 이용하여 보정할 수 있습니다.
	* 이 기능을 사용하지 않는다면 false로 설정하십시오.
	*/
	private const USE_TOPIS_API = true;

	/*
	* TOPIS API 주소를 API 키와 함께 적어주십시오.
	* USE_TOPIS_API 상수가 false로 지정되었을 경우 사용되지 않습니다.
	* 형식은 반드시 'http://swopenapi.seoul.go.kr/api/subway/[YOUR API KEY]/json/realtimePosition/0/80/' 여야 합니다.
	*/
	private const TOPIS_URL = 'http://swopenapi.seoul.go.kr/api/subway//json/realtimePosition/0/80/';

	/*
	* 여기서부터는 건드리지 마십시오.
	*/
	private const PACKET_TYPE_LINE = 0;
	private const PACKET_TYPE_STN = 1;

	private const address = 'tcp://minwon.korail.com:20301';
	private const cipher = 'aes-128-ecb';
	private const key = '1231231230123123';

	private static $instance = null;
	private $socket = null;
	private $line_data = null;

	/*
	* metroLib 인스턴스를 가져옵니다.
	*
	* @access public
	* @return object
	*/
	public static function getInstance() : object {
		if (metroLib::$instance == null)
			metroLib::$instance = new metroLib();

		return metroLib::$instance;
	}

	/*
	* 특정 노선의 특정 역 기준으로 열차 운행정보를 갖고옵니다.
	* 기준 역 ±4역 범위 내의 열차 운행정보만을 가져올 수 있습니다.
	*
	* @access public
	* @param string $line_code, string $station_code
	* @return ?array
	* @throw \RuntimeException
	*/
	public function getDataByStation(string $line_code, string $station_code) : ?array {
		$train_list = $this->getDataFromServer($line_code, $station_code);
		return $train_list;
	}

	/*
	* 노선의 전체 열차 운행정보를 갖고옵니다.
	*
	* @access public
	* @param string $line_code
	* @return ?array
	* @throw \RuntimeException
	*/
	public function getDataByLine(string $line_code) : ?array {
		$trn_dirs = null;
		if ($line_code == '2')
			$trn_dirs = ['1', '2', '3', '4', '5', '6'];
		else
			$trn_dirs = ['1', '2'];

		$train_list = array();
		foreach ($trn_dirs as $trn_dir) {
			$res = $this->getDataFromServer($line_code, null, $trn_dir);
			if ($res != null)
				$train_list = array_merge($train_list, $res);
		}
		$train_list = count($train_list) > 0 ? $train_list : null;

		return $train_list;
	}

	/*
	* 서버에서 데이터를 갖고와서 처리 후 반환합니다.
	*
	* @access private
	* @param string $line_code, ?string $station_code = null, ?string $trn_dir = null
	* @return ?array
	* @throw \RuntimeException
	*/
	private function getDataFromServer(string $line_code, ?string $station_code = null, ?string $trn_dir = null) {
		$req = null;
		if ($station_code == null) {
			$req = "line={$line_code}&gbn=0&dir={$trn_dir}&";
			$req = $this->makePacket($req, self::PACKET_TYPE_LINE);
		} else {
			$req = "lineGbn=0&stationCd={$station_code}&lineCd={$line_code}&";
			$req = $this->makePacket($req, self::PACKET_TYPE_STN);
		}

		$res = fwrite($this->socket, $req);
		if ($res === false)
			throw new \RuntimeException('Socket writing error.');

		usleep(100000 * 3); // Wait 0.3s

		$res = stream_get_contents($this->socket);
		if ($res === false)
			throw new \RuntimeException('Socket reading error.');

		if (strlen($res) < 7)
			throw new \RuntimeException('Received data is invalid.');

		$res = $this->decryptData($res);
		if (empty($res))
			throw new \RuntimeException('Response is empty.');

		$res = json_decode($res, true);
		$err = json_last_error();
		if ($err != JSON_ERROR_NONE)
			throw new \RuntimeException('JSON parse error.');

		if (!$res['isValid'])
			throw new \RuntimeException('Remote server error.');

		$res = $res['trainVOList'];
		if (count($res) == 0)
			return null;

		$train_list = $this->processTrainList($res, $line_code, $station_code);
		return $train_list;
	}

	/*
	* 서버에서 받아온 데이터를 처리합니다.
	*
	* @access private
	* @param array $par, string $line_code, ?string $station_code = null
	* @return ?array
	* @throw \RuntimeException, \InvalidArgumentException
	*/
	private function processTrainList(array $par, string $line_code, ?string $station_code = null) : array {
		$station_name = null;
		if ($station_code != null) {
			foreach ($this->line_data[$line_code] as $stnNm => $stnCd) {
				if ($stnCd === $station_code) {
					$station_name = $stnNm;
					break;
				}
			}

			if ($station_name == null)
				throw new \InvalidArgumentException('$station_code is invalid., '.$station_code);
		}

		$train_list = array();
		foreach ($par as $e) {
			// for duplicated train
			$is_duplicated = false;
			for ($i = 0; $i < count($train_list); $i++) {
				if ($train_list[$i]['trn_no'] == $e['trainY']) {
					$train_list[$i]['stn_nm'] = isset($e['stnNm']) ? $e['stnNm'] : $station_name;
					
					$train_list[$i]['trn_sts'] = $e['sts'];
					if (isset($e['orgStnNm']) && isset($e['stnNm']) && $e['orgStnNm'] == $e['stnNm'] && $e['sts'] == 2)
						$train_list[$i]['trn_sts'] = 0;

					$is_duplicated = true;
					break;
				}
			}

			if ($is_duplicated)
				continue;

			$is_line2 = strval($e['line']) == '2';
			$trn_dirs = $is_line2 ? ['I', 'O', 'SI1', 'SO1', 'SI2', 'SO2'] : ['U', 'D'];

			$train = array();
			$train['trn_no'] = ($is_line2 && substr($e['trainY'], 0, 1) != 'S' ? 'S' : '').$e['trainY'];
			$train['line'] = strval($e['line']);

			$train['trn_form_no'] = $e['trainP'] ?? null;
			if ($train['trn_form_no'] == '000' || $train['trn_form_no'] == '0000')
				$train['trn_form_no'] = null;
			if ($train['trn_form_no'] != null)
				$train['trn_form_no'] = ltrim($train['trn_form_no'], '0');

			$train['trn_sts'] = $e['sts'];
			if (isset($e['orgStnNm']) && isset($e['stnNm']) && $e['orgStnNm'] == $e['stnNm'] && $e['sts'] == 2)
				$train['trn_sts'] = 0;
			else if (isset($e['orgStnNm']) && $e['orgStnNm'] == $station_name && $e['sts'] == 2)
				$train['trn_sts'] = 0;

			$train['is_exp'] = isset($e['express']) ? $e['express'] === 'Y' : false;

			$train['stn_nm'] = isset($e['stnNm']) ? $this->removeSubStnNm($e['stnNm']) : null;
			$train['stn_cd'] = isset($e['stnCd']) ? $e['stnCd'] : null;

			if (isset($e['dir']))
				$train['trn_dir'] = $trn_dirs[$e['dir'] - 1];
			else
				$train['trn_dir'] = null;

			$train['org_stn_nm'] = isset($e['orgStnNm']) && $e['orgStnNm'] != '0' ? $this->removeSubStnNm($e['orgStnNm']) : null;
			$train['org_stn_cd'] = isset($e['orgStn']) && $e['orgStn'] != '0' ? $e['orgStn'] : null;
			if (strlen($train['org_stn_cd']) != 4)
				$train['org_stn_cd'] = null;

			if ($is_line2 && ($e['dstStn'] == '88_1' || $e['dstStn'] == '88_2')) { // 내·외선순환 관련 처리
				$train['dst_stn_nm'] = $train['org_stn_nm'];
				$train['dst_stn_cd'] = $train['org_stn_cd'];
			} else {
				$train['dst_stn_nm'] = isset($e['dstStnNm']) && $e['dstStnNm'] != '0' ? $this->removeSubStnNm($e['dstStnNm']) : null;
				$train['dst_stn_cd'] = isset($e['dstStn']) && $e['dstStn'] != '0' ? $e['dstStn'] : null;
			}

			// 종착처리
			if (isset($train['dst_stn_nm']) && $train['dst_stn_nm'] == $train['stn_nm'] && $train['trn_sts'] == 2)
				$train['trn_sts'] = 4;

			$train['prev_stn_nm'] = isset($e['prevStnNm']) ? $this->removeSubStnNm($e['prevStnNm']) : (isset($e['startStnNm']) ? $this->removeSubStnNm($e['startStnNm']) : null);
			$train['prev_stn_nm'] = isset($e['prevStnCd']) ? $this->removeSubStnNm($e['prevStnCd']) : (isset($e['startStnCd']) ? $e['startStnCd'] : null);
			$train['next_stn_nm'] = isset($e['nextStnNm']) ? $this->removeSubStnNm($e['nextStnNm']) : (isset($e['endStnNm']) ? $this->removeSubStnNm($e['endStnNm']) : null);
			$train['next_stn_nm'] = isset($e['nextStnCd']) ? $this->removeSubStnNm($e['nextStnCd']) : (isset($e['endStnCd']) ? $e['endStnCd'] : null);

			$train_list[] = $train;
		}

		// TOPIS API를 이용하여 급행여부, 위치, 상태, 행선지 보정
		if (self::USE_TOPIS_API) {
			$train_list2 = $this->getDataByTopis($line_code);
			if ($train_list2 != null) {
				for ($i = 0; $i < count($train_list); $i++) {
					$trn_no = substr($train_list[$i]['trn_no'], 1);
					if (array_key_exists($trn_no, $train_list2)) {
						$train_list[$i]['is_exp'] = $train_list2[$trn_no]['is_exp'];

						$train_list[$i]['stn_nm'] = $train_list2[$trn_no]['stn_nm'];
						$train_list[$i]['stn_cd'] = $train_list2[$trn_no]['stn_cd'];
						$train_list[$i]['trn_sts'] = $train_list2[$trn_no]['trn_sts'] ?? $train_list[$i]['trn_sts'];

						$train_list[$i]['dst_stn_nm'] = $train_list2[$trn_no]['dst_stn_nm'];
						$train_list[$i]['dst_stn_cd'] = $train_list2[$trn_no]['dst_stn_cd'];
					}
				}
			}
		}

		// 행선지 보정
		for ($i = 0; $i < count($train_list); $i++) {
			if ($train_list[$i]['line'] == '1') {
				switch ($train_list[$i]['dst_stn_nm']) {
					case '서울': // 1호선 녹색급행 서울역행 관련 처리
						$train_list[$i]['dst_stn_nm'] = '서울역';
						$train_list[$i]['dst_stn_cd'] = $this->line_data['1']['서울역'];
						break;		
					case '수원': // 서동탄행이 수원행으로 나오는 문제가 있음.
						$train_list[$i]['dst_stn_nm'] = '서동탄';
						$train_list[$i]['dst_stn_cd'] = $this->line_data['1']['서동탄'];
						break;
					case '동대문': // 구로행이 동대문행으로 나오는 문제가 있음.
						$train_list[$i]['dst_stn_nm'] = '구로';
						$train_list[$i]['dst_stn_cd'] = $this->line_data['1']['구로'];
						break;
					default:
				}
			} else if ($train_list[$i]['line'] == '3') {
				// 행선지가 null이면 노선의 끝 역이라고 간주하면 됨.
				if ($train_list[$i]['dst_stn_nm'] == null) {
					if ($train_list[$i]['trn_dir'] == 'U') {
						$train_list[$i]['dst_stn_nm'] = '대화';
						$train_list[$i]['dst_stn_cd'] = $this->line_data['3']['대화'];
					} else {
						$train_list[$i]['dst_stn_nm'] = '오금';
						$train_list[$i]['dst_stn_cd'] = $this->line_data['3']['오금'];
					}
				}
			} else if ($train_list[$i]['line'] == '4') {
				// 행선지가 null이면 노선의 끝 역이라고 간주하면 됨.
				if ($train_list[$i]['dst_stn_nm'] == null) {
					if ($train_list[$i]['trn_dir'] == 'U') {
						$train_list[$i]['dst_stn_nm'] = '당고개';
						$train_list[$i]['dst_stn_cd'] = $this->line_data['4']['당고개'];
					} else {
						$train_list[$i]['dst_stn_nm'] = '오이도';
						$train_list[$i]['dst_stn_cd'] = $this->line_data['4']['오이도'];
					}
				}
			}
		}

		return $train_list;
	}

	/*
	* TOPIS API 서버로부터 열차 정보를 갖고옵니다. (1, 3, 4호선 보정용)
	*
	* @access private
	* @param string $line_code
	* @return ?array
	* @throw \RuntimeException
	*/
	private function getDataByTopis(string $line_code) : ?array {
		$line_list = ['1' => '1호선', '3' => '3호선', '4' => '4호선'];

		if (!array_key_exists($line_code, $line_list))
			return null;

		$url = self::TOPIS_URL.urlencode($line_list[$line_code]);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$res = curl_exec($ch);

		curl_close($ch);

		$res = json_decode($res, true);
		$err = json_last_error();
		if ($err != JSON_ERROR_NONE)
			throw new \RuntimeException('TOPIS JSON parse error.');

		if (isset($res['status']))
			throw new \RuntimeException('TOPIS server error. '.$res['message']);

		if (isset($res['errorMessage']) && $res['errorMessage']['status'] != 200)
			throw new \RuntimeException('TOPIS server error. '.$res['errorMessage']['message']);

		if (count($res['realtimePositionList']) == 0)
			return null;

		$train_list = array();
		foreach ($res['realtimePositionList'] as $e) {
			if ($e['statnNm'] === '서울')
				$e['statnNm'] = '서울역';
			if ($e['statnTnm'] === '서울')
				$e['statnTnm'] = '서울역';
			$e['statnNm'] = $this->removeSubStnNm($e['statnNm']);
			$e['statnTnm'] = $this->removeSubStnNm($e['statnTnm']);

			switch ($e['trainSttus']) {
				case '0':
					$e['trainSttus'] = 1;
					break;
				case '1':
					$e['trainSttus'] = 3;
					break;
				default:
					$e['trainSttus'] = null;
			}

			$train_list[$e['trainNo']] = array('dst_stn_nm' => $e['statnTnm'],
												'dst_stn_cd' => $this->line_data[$line_code][$e['statnTnm']],
												'is_exp' => $e['directAt'] == '1',
												'stn_nm' => $e['statnNm'],
												'stn_cd' => $this->line_data[$line_code][$e['statnNm']],
												'trn_sts' => $e['trainSttus']);
		}

		return $train_list;
	}

	/*
	* 요청 값을 형식에 맞게 암호화합니다.
	*
	* @access private
	* @param string $req, int $packet_type
	* @return string
	* @throw \RuntimeException, \InvalidArgumentException
	*/
	private function makePacket(string $req, int $packet_type) : string {
		// Block Size Padding
		$req_size = strlen($req);
		if ($req_size % 16 != 0)
			$req = str_pad($req, $req_size + 16 - $req_size % 16, "\0");

		// Encryption
		$req = openssl_encrypt($req, self::cipher, self::key, OPENSSL_RAW_DATA | OPENSSL_NO_PADDING);
		if ($req === false)
			throw new \RuntimeException('Encryption failed.');

		/*
		* Packet Structure
		*
		* Header (Total 7 bytes)
		* TR_CODE(2 bytes) : {0x0B, 0xC2} or {0x42, 0x72}
		* IS_ENCRYPTED(1 byte) : {0x1}
		* RETURN_CODE(2 bytes) : Request packet must be set to {0x0, 0x0}
		* DATA_LENGTH(2 bytes) : Body Length(UTF-8, encrypted)
		* -----
		* BODY(Variable) : The data must be encrypted with AES-128-ECB-NoPadding before send to the server.
		*/

		$req_size = strlen($req);

		$req = unpack('C*', $req);

		$req_data = array();
		// TR_CODE
		if ($packet_type == self::PACKET_TYPE_LINE) {
			$req_data[0] = 0x42;
			$req_data[1] = 0x72;
		} else if ($packet_type == self::PACKET_TYPE_STN) {
			$req_data[0] = 0x0B;
			$req_data[1] = 0xC2;
		} else
			throw new \InvalidArgumentException('$packet_type is invalid.');

		// IS_ENCRYPTED
		$req_data[2] = 0x1;
		// RET_CODE
		$req_data[3] = 0x0;
		$req_data[4] = 0x0;
		// DATA_LENGTH
		if ($req_size > 255) {
			$req_data[5] = dechex(intval($req_size / 256));
			$req_data[6] = dechex($req_size % 256);
		} else {
			$req_data[5] = 0x0;
			$req_data[6] = intval($req_size);
		}
		$req_data = array_merge($req_data, $req);
		$req_data = vsprintf(str_repeat('%c', count($req_data)), $req_data);

		return $req_data;
	}

	/*
	* 응답 메시지를 복호화합니다.
	*
	* @access private
	* @param string $stn_nm
	* @return string
	* @throw RuntimeErrorException
	*/
	private function decryptData(string $res) : string {
		$res = substr($res, 7); // Get response body only.
		
		$ret = openssl_decrypt($res, self::cipher, self::key, OPENSSL_RAW_DATA | OPENSSL_NO_PADDING);
		if ($ret === false)
			throw new \RuntimeException('Decryption failed.');

		$ret = trim($ret);
		return $ret;
	}

	/*
	* 부기역명을 제거합니다.
	*
	* @access private
	* @param string $stn_nm
	* @return string
	*/
	private function removeSubStnNm(string $stn_nm) : string {
		return preg_replace('/\([0-9가-힣A-z]+\)$/u', '', $stn_nm);
	}

	/*
	* 생성자. new metroLib()를 쓰지 마시고 metroLib::getInstance()를 써주세요.
	*
	* @access private
	*/
	private function __construct() {
		$line_data_filename = __DIR__.'/stations.json';
		if (!file_exists($line_data_filename))
			throw new \RuntimeException('stations.json is not exist.');

		$line_data = file_get_contents($line_data_filename);
		$line_data = json_decode($line_data, true);
		$err = json_last_error();
		if ($err != JSON_ERROR_NONE)
			throw new \RuntimeException('stations.json parse error.');
		$this->line_data = $line_data;

		$error_no = null;
		$error_str = null;
		$this->socket = stream_socket_client(self::address, $error_no, $error_str, 0.5);
		if ($this->socket === false)
			throw new \RuntimeException("Connection error: {$error_no}: {$error_str}");

		stream_set_blocking($this->socket, false);
	}
}
?>