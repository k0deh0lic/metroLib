<?php

namespace NXLogisMetro;

if (date_default_timezone_get() != 'Asia/Seoul')
	date_default_timezone_set('Asia/Seoul');

class metroLib {
	/*
	* 수도권 전철 1호선, 3호선, 4호선 및 7호선의 경우 행선지, 급행여부가 누락되거나 오기되는 경우가 있어 이를 TOPIS API를 이용하여 보정할 수 있습니다.
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
	* 열차별 기·종점 DB 사용 여부
	* 열차별 기·종점 DB를 참조하여 열차별 기·종점 오류를 보정할 수 있습니다.
	* 이 기능을 사용하려면 metroLib.php와 동일한 경로에 metro.db 파일이 필요합니다.
	* metro.db는 genTrainDB를 이용해서 만들 수 있습니다.
	*/
	protected const USE_LOCAL_DB = true;

	/*
	* 여기서부터는 건드리지 마십시오.
	*/
	protected const GET_TYPE_LINE = 0;
	protected const GET_TYPE_STN = 1;

	protected static $instance = null;
	protected $line_data = null;
	protected $db = null;

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
		$is_smrt = false;
		if ($type == self::GET_TYPE_STN) {
			foreach ($this->line_data as $line => $stns) {
				if ($line != '5' && $line != '6' && $line != '7' && $line != '8')
					continue;

				foreach ($stns as $stn_nm => $stn_cd) {
					if ($stn_cd == $param) {
						$is_smrt = true;
						break;
					}
				}
			}
		}
		
		$url = $is_smrt ? 'https://sgapp.seoulmetro.co.kr/api/' : 'https://smss.seoulmetro.co.kr/api/';
		switch ($type) {
			case self::GET_TYPE_LINE:
				$url .= '3010.do';
				$param = "lineNumCd={$param}";
				break;
			case self::GET_TYPE_STN:
				$url .= '13000.do';
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

		$train_list = $this->processTrainList($res, $is_smrt);
		return $train_list;
	}

	/*
	* 서버에서 받아온 데이터를 처리합니다.
	*
	* @access protected
	* @param array $par
	* @param bool $is_smrt = false
	* @return ?array
	* @throw \RuntimeException, \InvalidArgumentException
	*/
	protected function processTrainList(array $par, bool $is_smrt = false) : array {
		$train_list = array();
		foreach ($par as $e) {
			$is_line2 = strval($e['line']) == '2';
			$trn_dirs = $is_line2 ? ['I', 'O', 'SI1', 'SO1', 'SI2', 'SO2'] : ['U', 'D'];

			$train = array();
			$train['trn_no'] = (($is_line2 || $is_smrt) && substr($e['trainY'], 0, 1) != 'S' ? 'S' : '').$e['trainY'];
			$train['line'] = strval($e['line']);

			if (isset($e['dir']))
				$train['trn_dir'] = $trn_dirs[$e['dir'] - 1];
			else
				$train['trn_dir'] = null;

			if ($train['line'] == '2') { // 2호선 열차번호가 잘못된 경우를 수정
				if (($train['trn_dir'] == 'SI1' || $train['trn_dir'] == 'SO1') && substr($train['trn_no'], 1, 1) != '1')
					$train['trn_no'] = substr($train['trn_no'], 0, 1).'1'.substr($train['trn_no'], 2); // 성수지선은 1000번대
				else if (($train['trn_dir'] == 'SI2' || $train['trn_dir'] == 'SO2') && substr($train['trn_no'], 1, 1) != '5')
					$train['trn_no'] = substr($train['trn_no'], 0, 1).'5'.substr($train['trn_no'], 2); // 신정지선은 5000번대
			} else if ($train['line'] == '3' && substr($train['trn_no'], 1, 1) != '3') { // 3호선 열차번호가 잘못된 경우를 수정
				$train['trn_no'] = substr($train['trn_no'], 0, 1).'3'.substr($train['trn_no'], 2);
			} else if ($train['line'] == '4' && substr($train['trn_no'], 1, 1) != '4') { // 4호선 열차번호가 잘못된 경우를 수정
				$train['trn_no'] = substr($train['trn_no'], 0, 1).'4'.substr($train['trn_no'], 2);
			} else if ($train['line'] == '5' || $train['line'] == '6' || $train['line'] == '7' || $train['line'] == '8') // 5-8호선은 접두어 SMRT
				$train['trn_no'] = 'SMRT'.substr($train['trn_no'], 1);

			$train['trn_form_no'] = $e['trainP'] ?? null;
			// 000이면 편성정보가 없다는 의미임.
			if ($train['trn_form_no'] == '000')
				$train['trn_form_no'] = null;
			// 서울교통공사 편성번호는 0123 식으로 나오기에, 앞에 있는 0을 떼줌.
			if ($train['trn_form_no'] != null)
				$train['trn_form_no'] = ltrim($train['trn_form_no'], '0');
			
			$train['trn_sts'] = $e['sts'];

			if (isset($e['directAt']))
				$train['is_exp'] = $e['directAt'] === '1';
			else
				$train['is_exp'] = false;

			$train['stn_nm'] = isset($e['stationNm']) ? $this->removeSubStnNm($e['stationNm']) : null;
			if (($train['line'] == '1' || $train['line'] == '4') && $train['stn_nm'] == '서울')
				$train['stn_nm'] = '서울역'; // 서울역 관련 처리
			else if ($train['line'] == '1' && $train['stn_nm'] == null)
				$train['stn_nm'] = '탕정'; // 2021-06 기준 탕정역이 null로 나오는 문제가 있음.

			$train['stn_cd'] = $train['stn_nm'] == null ? null : $this->line_data[$train['line']][$train['stn_nm']];

			if (isset($e['dstStnNm'])) 
				$train['dst_stn_nm'] = isset($e['dstStnNm']) && $e['dstStnNm'] != '0' ? $this->removeSubStnNm($e['dstStnNm']) : null;
			else 
				$train['dst_stn_nm'] = isset($e['statnTnm']) && $e['statnTnm'] != '0' ? $this->removeSubStnNm($e['statnTnm']) : null;

			if (($train['line'] == '1' || $train['line'] == '4') && $train['dst_stn_nm'] == '서울')
				$train['dst_stn_nm'] = '서울역'; // 서울역 관련 처리

			$train['dst_stn_cd'] = $train['dst_stn_nm'] == null ? null : $this->line_data[$train['line']][$train['dst_stn_nm']];

			// Local DB를 이용하여 보정
			if (self::USE_LOCAL_DB) {
				$data = $this->getTrainDataFromLocalDb($train['trn_no']);
				if ($data != null) {
					$org_stn_nm = $data['org_stn_nm'];
					if ($org_stn_nm != null && $org_stn_nm == $train['stn_nm'] && ($train['trn_sts'] == 1 || $train['trn_sts'] == 2))
						$train['trn_sts'] = 0;

					$train['dst_stn_nm'] = $data['dst_stn_nm'];
					$train['is_exp'] = $data['is_express'] == 1;
				}
			}

			$train_list[] = $train;
		}

		// TOPIS API를 이용하여 급행여부, 위치, 상태, 행선지 보정
		if (self::USE_TOPIS_API && count($train_list) > 0) {
			$train_list2 = $this->getDataByTopis($train_list[0]['line']);
			if ($train_list2 != null) {
				foreach ($train_list as &$train) {
					$trn_no = str_replace('MRT', '', $train['trn_no']);
					$trn_no = substr($trn_no, 1);
					if (array_key_exists($trn_no, $train_list2)) {
						$train['is_exp'] = $train_list2[$trn_no]['is_exp'];

						// 2호선은 따로 처리
						if ($train['line'] == '2') {
							$train['dst_stn_nm'] = $train_list2[$trn_no]['dst_stn_nm'];
							$train['dst_stn_cd'] = $train_list2[$trn_no]['dst_stn_cd'];
						} else {
							if ($train['stn_nm'] == null) {
								$train['stn_nm'] = $train_list2[$trn_no]['stn_nm'];
								$train['stn_cd'] = $train_list2[$trn_no]['stn_cd'];
							}

							// 석남행 열차가 부평구청행으로 나오기에 어쩔 수 없이 조건 추가
							if ($train['dst_stn_nm'] == null || $train['dst_stn_nm'] == '부평구청') {
								$train['dst_stn_nm'] = $train_list2[$trn_no]['dst_stn_nm'];
								$train['dst_stn_cd'] = $train_list2[$trn_no]['dst_stn_cd'];
							}		
							
						}

					}
				}
			}
		}

		// 처리되지 않은 행선지 보정
		foreach ($train_list as &$train) {
			if ($train['line'] == '1') {
				switch ($train['dst_stn_nm']) {
					case '수원': // 서동탄행이 수원행으로 나오는 문제가 있음.
						$train['dst_stn_nm'] = '서동탄';
						$train['dst_stn_cd'] = $this->line_data['1']['서동탄'];
						break;
					case '동대문': // 구로행이 동대문행으로 나오는 문제가 있음.
						$train['dst_stn_nm'] = '구로';
						$train['dst_stn_cd'] = $this->line_data['1']['구로'];
						break;
					default:
				}
			}

			if (isset($train['dst_stn_nm']) && $train['dst_stn_nm'] == $train['stn_nm'] && ($train['trn_sts'] == 2 || $train['trn_sts'] == 3 || $train['trn_sts'] == 4))
				$train['trn_sts'] = 5;
		}

		return $train_list;
	}

	/*
	* TOPIS API 서버로부터 열차 정보를 갖고옵니다. (1, 3, 4, 7호선 보정용)
	*
	* @access protected
	* @param string $line_code
	* @return ?array
	* @throw \RuntimeException
	*/
	protected function getDataByTopis(string $line_code) : ?array {
		$line_list = ['1' => '1호선', '2' => '2호선', '3' => '3호선', '4' => '4호선', '7' => '7호선'];

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

		if (isset($res['status'])) {
			// 유효한 노선인데도 TOPIS에서 데이터가 없다고 하는 경우가 있어서 조치
			if ($res['status'] == 500 && $res['code'] == 'INFO-200')
				return null;

			throw new \RuntimeException('TOPIS server error. '.$res['message']);
		}
			
		if (isset($res['errorMessage']) && $res['errorMessage']['status'] != 200)
			throw new \RuntimeException('TOPIS server error. '.$res['errorMessage']['message']);

		if (count($res['realtimePositionList']) == 0)
			return null;

		$train_list = array();
		foreach ($res['realtimePositionList'] as $e) {
			$trn_no = $e['trainNo'];

			$dst_stn_nm = $this->removeSubStnNm($e['statnTnm']);
			// 역명 불일치 문제 수정
			if ($dst_stn_nm == '서울')
				$dst_stn_nm = '서울역';
			else if ($line_code == '7' && $dst_stn_nm === '53') // 석남행은 53으로 나옴.
				$dst_stn_nm = '석남';

			$stn_nm = $this->removeSubStnNm($e['statnNm']);
			// 역명 불일치 문제 수정
			switch ($stn_nm) {
				case '서울':
					$stn_nm = '서울역';
					break;
				case '춘의역':
					$stn_nm = '춘의';
					break;
			}

			// 2호선 관련 추가 처리
			if ($line_code == '2') {
				$dst_stn_nm = preg_replace('/지선|종착$/', '', $dst_stn_nm);
				$stn_nm = preg_replace('/지선|종착$/', '', $stn_nm);
				switch($trn_no[0]) {
					case 1:
					case 5: // 지선
						break;
					case 9: // 시운전
						break;
					case 6:  // 순환종료
						$dst_stn_nm = '성수';
						$trn_no = '2'.substr($trn_no, 1);
						break;
					default:
						$trn_no = '2'.substr($trn_no, 1);
						if ($dst_stn_nm == '성수')
							$dst_stn_nm = ($e['updnLine'] == 0 ? '내선' : '외선').'순환';
				}
			}

			$trn_sts = 3;
			switch ($e['trainSttus']) {
				case '0':
					$trn_sts = 1;
					break;
				case '1':
					$trn_sts = 2;
					break;
			}

			$train_list[$trainNo] = array('dst_stn_nm' => $dst_stn_nm,
									'dst_stn_cd' => $this->line_data[$line_code][$dst_stn_nm],
									'is_exp' => $e['directAt'] == '1',
									'stn_nm' => $stn_nm,
									'stn_cd' => $this->line_data[$line_code][$stn_nm],
									'trn_sts' => $trn_sts);
		}

		return $train_list;
	}

	/*
	* 로컬 DB에서 열차 데이터를 가져옵니다.
	*
	* @access protected
	* @param string $trn_no
	* @return ?array
	* @throws \RuntimeException
	*/
	protected function getTrainDataFromLocalDb(string $trn_no) : ?array {
		if ($this->db == null) {
			$db_filename = __DIR__.'/metro.db';
			if (!file_exists($db_filename))
				throw new \RuntimeException('Cannot find metro.db file.');

			$this->db = new \Sqlite3($db_filename);
		}
		
		$today = date('Ymd', time() - (intval(date('H')) < 4 ? (60 * 60 * 24) : 0));
		
		$day_type = 0;
		$res = $this->db->query("SELECT * FROM holiday_list WHERE date=\"{$today}\";");
		if ($res === false)
			throw new \RuntimeException('Local DB error!');

		if ($res->fetchArray(SQLITE3_ASSOC) === false) {
			$weekday_idx = date('w', strtotime($today));
			$day_type = $weekday_idx == 0 || $weekday_idx == 6 ? 2 : 0;
		} else
			$day_type = 2;

		$res = $this->db->query("SELECT org_stn_nm, dst_stn_nm, is_express FROM train_list WHERE trn_no=\"{$trn_no}\" AND day_type={$day_type};");
		if ($res === false)
			throw new \RuntimeException('Local DB error!');

		$data = $res->fetchArray(SQLITE3_ASSOC);
		if ($data === false)
			return null;

		$res->finalize();
		return $data;
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
			throw new \RuntimeException('file_path is not exist., value='.$file_path);

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
