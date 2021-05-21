<?php

namespace NXLogisMetro;

class metroLib {
	/*
	* 수도권 전철 1호선, 3호선 및 4호선의 경우 행선지, 급행여부가 누락되거나 오기되는 경우가 있어 이를 TOPIS API를 이용하여 보정할 수 있습니다.
	* 이 기능을 사용하지 않는다면 false로 설정하십시오.
	*/
	protected const USE_TOPIS_API = true;

	/*
	* TOPIS API 주소를 API 키와 함께 적어주십시오.
	* USE_TOPIS_API 상수가 false로 지정되었을 경우 사용되지 않습니다.
	* 형식은 반드시 'http://swopenapi.seoul.go.kr/api/subway/[YOUR API KEY]/json/realtimePosition/0/80/' 여야 합니다.
	*/
	protected const TOPIS_URL = 'http://swopenapi.seoul.go.kr/api/subway//json/realtimePosition/0/80/';

	/*
	* 여기서부터는 건드리지 마십시오.
	*/
	protected const GET_TYPE_LINE = 0;
	protected const GET_TYPE_STN = 1;

	protected static $instance = null;
	protected $line_data = null;

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
	* @param string $station_code
	* @return ?array
	* @throw \RuntimeException
	*/
	public function getDataByStation(string $station_code) : ?array {
		$train_list = $this->getDataFromServer(self::GET_TYPE_STN, $station_code);
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
		$train_list = $this->getDataFromServer(self::GET_TYPE_LINE, $line_code);
		return $train_list;
	}

	/*
	* 서버에서 데이터를 갖고와서 처리 후 반환합니다.
	*
	* @access protected
	* @param int $type
	* @param string $param
	* @return ?array
	* @throw \RuntimeException
	*/
	protected function getDataFromServer(int $type, string $param) : ?array {
		$url = '';
		switch ($type) {
			case self::GET_TYPE_LINE:
				$url = 'https://smss.seoulmetro.co.kr/api/3010.do';
				$param = "lineNumCd={$param}";
				break;
			case self::GET_TYPE_STN:
				$url = 'https://smss.seoulmetro.co.kr/api/13000.do';
				$param = "stationCd={$param}";
				break;
			default:
				throw \InvalidArgumentException('Argument $type is invalid. value='.$type);
		}

		$ch = curl_init();

		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $param);

		$res = curl_exec($ch);
		curl_close($ch);

		$res = json_decode($res, true);
		$err = json_last_error();
		if ($err != JSON_ERROR_NONE)
			throw new \RuntimeException('Json parse error.');

		if (!$res['isValid'])
			throw new \RuntimeException('Remote server error.');

		$res = $res['ttcVOList'];
		if (count($res) == 0)
			return null;

		$train_list = $this->processTrainList($res);
		return $train_list;
	}

	/*
	* 서버에서 받아온 데이터를 처리합니다.
	*
	* @access protected
	* @param array $par
	* @return ?array
	* @throw \RuntimeException, \InvalidArgumentException
	*/
	protected function processTrainList(array $par) : array {
		$train_list = array();
		foreach ($par as $e) {
			$is_line2 = strval($e['line']) == '2';
			$trn_dirs = $is_line2 ? ['I', 'O', 'SI1', 'SO1', 'SI2', 'SO2'] : ['U', 'D'];

			$train = array();
			$train['trn_no'] = ($is_line2 && substr($e['trainY'], 0, 1) != 'S' ? 'S' : '').$e['trainY'];
			$train['line'] = strval($e['line']);

			// 3, 4호선 열차번호가 잘못된 경우를 수정
			if ($train['line'] == '3' && substr($train['trn_no'], 1, 1) != '3')
				$train['trn_no'] = substr($train['trn_no'], 0, 1).'3'.substr($train['trn_no'], 2);
			else if ($train['line'] == '4' && substr($train['trn_no'], 1, 1) != '4')
				$train['trn_no'] = substr($train['trn_no'], 0, 1).'4'.substr($train['trn_no'], 2);

			$train['trn_form_no'] = $e['trainP'] ?? null;
			// 000이면 편성정보가 없다는 의미임.
			if ($train['trn_form_no'] == '000')
				$train['trn_form_no'] = null;

			$train['trn_sts'] = $e['sts'];
			if (isset($e['orgStnNm']) && isset($e['stnNm']) && $e['orgStnNm'] == $e['stnNm'] && $e['sts'] == 2)
				$train['trn_sts'] = 0;

			$train['is_exp'] = $e['directAt'] === '1';

			$train['stn_nm'] = isset($e['stationNm']) ? $this->removeSubStnNm($e['stationNm']) : null;
			switch ($train['stn_nm']) {
				case '서울':
					$train['stn_nm'] = '서울역';
					break;
				default:
			}
			$train['stn_cd'] = $train['stn_nm'] == null ? null : $this->line_data[$train['line']][$train['stn_nm']];

			if (isset($e['dir']))
				$train['trn_dir'] = $trn_dirs[$e['dir'] - 1];
			else
				$train['trn_dir'] = null;

			$train['org_stn_nm'] = isset($e['orgStnNm']) && $e['orgStnNm'] != '0' ? $this->removeSubStnNm($e['orgStnNm']) : null;
			$train['org_stn_cd'] = $train['org_stn_nm'] == null ? null : $this->line_data[$train['line']][$train['org_stn_nm']];

			if (isset($e['dstStn'])) {
				$train['dst_stn_nm'] = isset($e['dstStnNm']) && $e['dstStnNm'] != '0' ? $this->removeSubStnNm($e['dstStnNm']) : null;
				$train['dst_stn_cd'] = $train['dst_stn_nm'] == null ? null : $this->line_data[$train['line']][$train['dst_stn_nm']];
			} else {
				$train['dst_stn_nm'] = isset($e['statnTnm']) && $e['statnTnm'] != '0' ? $this->removeSubStnNm($e['statnTnm']) : null;
				
				// 2호선 외·내선순환행은 성수 시종착임.
				if ($is_line2 && ($train['dst_stn_nm'] == '외선순환' || $train['dst_stn_nm'] == '내선순환')) 
					$train['dst_stn_nm'] = '성수'; 

				$train['dst_stn_cd'] = $train['dst_stn_nm'] == null ? null : $this->line_data[$train['line']][$train['dst_stn_nm']];					
			}

			// 종착처리
			if (isset($train['dst_stn_nm']) && $train['dst_stn_nm'] == $train['stn_nm'] && $train['trn_sts'] == 2)
				$train['trn_sts'] = 5;

			$train_list[] = $train;
		}

		// TOPIS API를 이용하여 급행여부, 위치, 상태, 행선지 보정
		if (self::USE_TOPIS_API && count($train_list) > 0) {
			$train_list2 = $this->getDataByTopis($train_list[0]['line']);
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

		// 처리되지 않은 행선지 보정
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
	* @access protected
	* @param string $line_code
	* @return ?array
	* @throw \RuntimeException
	*/
	protected function getDataByTopis(string $line_code) : ?array {
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
	* 부기역명을 제거합니다.
	*
	* @access protected
	* @param string $stn_nm
	* @return string
	*/
	protected function removeSubStnNm(string $stn_nm) : string {
		return preg_replace('/\([0-9가-힣A-z]+\)$/u', '', $stn_nm);
	}

	/*
	* Json 파일에서 데이터를 불러와 Array로 반환합니다.
	*
	* @access protected
	* @param string $file_path
	* @return ?array
	*/
	protected function readArrayFromJsonFile(string $file_path) : ?array {
		if (!file_exists($file_path))
			throw new \RuntimeException('file_path is not exist., value='.$filePath);

		$fp = fopen($file_path, 'r');
		$data = fread($fp, filesize($file_path));
		fclose($fp);

		$data = json_decode($data, true);
		$err = json_last_error();
		if ($err != JSON_ERROR_NONE)
			throw new \RuntimeException('Json parse error.');

		return $data;
	}

	/*
	* 생성자. new metroLib()를 쓰지 마시고 metroLib::getInstance()를 써주세요.
	*
	* @access protected
	*/
	protected function __construct() {
		$line_data_filename = __DIR__.'/stations.json';
		$this->line_data = $this->readArrayFromJsonFile($line_data_filename);
	}
}
?>