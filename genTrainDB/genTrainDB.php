<?php

// 한국천문연구원 특일 정보 OpenAPI Key (not encoded)
// OpenAPI 정보: https://data.go.kr/tcs/dss/selectApiDataDetailView.do?publicDataPk=15012690
$service_key = '';

if (date_default_timezone_get() != 'Asia/Seoul')
	date_default_timezone_set('Asia/Seoul');

function println(string $par) {
	echo $par.PHP_EOL;
}

function fix_stn_nm(string $par) : string {
	$xs = [	'지하서' => '서울역',
			'신인천' => '인천',
			'평내호' => '평내호평',
			'1지청' => '청량리',
			'3수서' => '수서',
			'오평일' => '오금',
			'서울' => '서울역',
			'4서울' => '서울역',
			'4창동' => '창동',
			'신수원' => '수원',
			'신판교' => '판교',
			 ];

	if (array_key_exists($par, $xs))
		return $xs[$par];

	return $par;
}

function read_file(string $filename) : string {
	if (!file_exists($filename))
		throw new RuntimeException('파일을 찾을 수 없습니다.');

	$fp = fopen($filename, 'r');
	$data = fread($fp, filesize($filename));
	fclose($fp);

	$cur_encoding = strtolower(mb_detect_encoding($data, 'ascii,utf-8,euc-kr'));
	if ($cur_encoding == 'ascii' || $cur_encoding == 'utf-8')
		return $data;

	return mb_convert_encoding($data, 'utf-8', $cur_encoding);
}

function strfind(string $foo, string $bar) : bool {
	return mb_strpos($foo, $bar, 0, 'utf-8') !== false;
}

function getRestDayList() : ?array {
	global $service_key;

	$year = date('Y');
	$month = date('m');
	$loop_cnt = 0;
	$holidays = array();
	$ch = curl_init();

	do {
		$url = "http://apis.data.go.kr/B090041/openapi/service/SpcdeInfoService/getRestDeInfo?serviceKey={$service_key}&solYear={$year}&solMonth={$month}&_type=json&numOfRows=50";
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$res = curl_exec($ch);

		$res2 = json_decode($res, true);
		if (json_last_error() != JSON_ERROR_NONE)
			throw new RuntimeException('Bad response.:'.$res);

		$res = $res2['response'];
		if ($res['header']['resultCode'] == '00') {
			if ($res['body']['items'] !== '') {
				if (isset($res['body']['items']['item']['seq'])) {
					$holidays[] = array('date' => strval($res['body']['items']['item']['locdate']), 'name' => $res['body']['items']['item']['dateName']);
				} else {
					foreach ($res['body']['items']['item'] as $item) {
						if ($item['isHoliday'] == 'Y')
							$holidays[] = array('date' => strval($item['locdate']), 'name' => $item['dateName']);
					}
				}
			}
		} else
			throw new RuntimeException('Result code: '.$res['header']['resultCode']);

		$month = intval($month) + 1;
		if ($month > 12) {
			$month %= 12;
			$year = strval(intval($year) + 1);
		}
		$month = sprintf('%02d', $month);

		$loop_cnt++;
		usleep(100000); // sleep 0.1s
	} while ($loop_cnt < 12);

	curl_close($ch);

	return $holidays;
}

$inputs = scandir(__DIR__.'/input');
if ($inputs === false)
	die('input 디렉터리가 없는 것 같습니다.');

$trains = array();
foreach ($inputs as $filename) {
	$filename = basename($filename);
	if ($filename == '.' || $filename == '..')
		continue;

	$file_type = 0;
	if (strfind($filename, '서울교통공사_'))
		$file_type = 1;

	$data = read_file(__DIR__.'/input/'.$filename);
	$data = explode("\n", $data);
	for ($i = 1; $i < count($data); $i++) {
		$line = trim($data[$i]);
		if ($line == '')
			continue;

		// 한국철도공사: "열차번호",노선번호,노선명,운행구간기점명,운행구간종점명,운행유형,요일구분,운행구간정거장,정거장도착시각,정거장출발시각,운행속도,운영기관전화번호,데이터기준일자
		// 서울교통공사: "열차번호",노선번호,노선명,운행구간기점명,운행구간종점명,운행유형,요일구분,운행구간정거장,정거장도착시각,정거장출발시각,운행속도,운영기관전화번호,데이터기준일자
		$train_raw = explode(',', $line);
		$train = array();

		// 동해선, 9호선 전철 필터링
		if ($train_raw[1] == 'K1211' || $train_raw[1] == 'S1109')
			continue; 

		// 1, 3, 4호선은 코레일 데이터만 처리.
		if ($train_raw[1] == 'S1101' || $train_raw[1] == 'S1103' || $train_raw[1] == 'S1104')
			continue; 

		$trn_no = null;
		if (is_numeric($train_raw[0])) {
			if ($file_type == 0)
				continue; // ITX-청춘 필터링

			if ($train_raw[1] == 'S1105' || $train_raw[1] == 'S1106' || $train_raw[1] == 'S1107' || $train_raw[1] == 'S1108')
				$trn_no = sprintf('SMRT%04d', intval($train_raw[0])); // 5-8호선 접두어는 SMRT
			else
				$trn_no = sprintf('S%04d', intval($train_raw[0]));
		} else
			$trn_no = sprintf('%s%04d', substr($train_raw[0], 0, 1), intval(substr($train_raw[0], 1)));
		
		$train['trn_no'] = $trn_no;

		$train['org_stn_nm'] = fix_stn_nm($train_raw[3]);
		$train['dst_stn_nm'] = fix_stn_nm($train_raw[4]);

		$train['is_express'] = $train_raw[5] === '급행';

		$day_type = $train_raw[6];
		switch ($day_type) {
			case '평일':
				$day_type = 0;
				break;
			case '토':
			case '일':
			case '토요일+공휴일':
				$day_type = 2;
				break;
			default:
				die('처리되지 않은 값: '.$train_raw[6].', Row: '.$i);
		}
		$train['day_type'] = $day_type;

		$trains[$trn_no.'/'.$day_type] = $train;
	} 
}

ksort($trains);
$trains = array_values($trains);

println('열차 데이터 파싱 완료');

$holidays = getRestDayList();

println('공휴일 정보 파싱 완료');

$output_filename = __DIR__.'/output/metro.db';
// 기존 파일이 있으면 삭제함.
if (file_exists($output_filename))
	unlink($output_filename);

$db = new Sqlite3($output_filename);
$db->query('PRAGMA synchronous = OFF;');
$db->query('PRAGMA journal_mode = MEMORY;');

$db->exec('BEGIN TRANSACTION');
$db->exec('CREATE TABLE "train_list" ('.
	'"trn_no"	TEXT NOT NULL,'.
	'"day_type"	INTEGER NOT NULL,'.
	'"is_express"	INTEGER NOT NULL,'.
	'"org_stn_nm"	TEXT NOT NULL,'.
	'"dst_stn_nm"	TEXT NOT NULL'.
	');');

$stmt = $db->prepare('INSERT INTO "train_list" VALUES (:1, :2, :3, :4, :5);');
foreach ($trains as $train) {
	$stmt->bindValue(':1', $train['trn_no'], SQLITE3_TEXT);
	$stmt->bindValue(':2', $train['day_type'], SQLITE3_INTEGER);
	$stmt->bindValue(':3', $train['is_express'] ? 1 : 0, SQLITE3_INTEGER);
	$stmt->bindValue(':4', $train['org_stn_nm'], SQLITE3_TEXT);
	$stmt->bindValue(':5', $train['dst_stn_nm'], SQLITE3_TEXT);
	$stmt->execute();
	$stmt->clear();
	$stmt->reset();
}
$db->exec('COMMIT');

$db->exec('BEGIN TRANSACTION');
$db->exec('CREATE TABLE "holiday_list" ('.
	'"date"	TEXT NOT NULL,'.
	'"name"	TEXT NOT NULL'.
	');');

$stmt = $db->prepare('INSERT INTO "holiday_list" VALUES (:1, :2);');
foreach ($holidays as $holiday) {
	$stmt->bindValue(':1', $holiday['date'], SQLITE3_TEXT);
	$stmt->bindValue(':2', $holiday['name'], SQLITE3_TEXT);
	$stmt->execute();
	$stmt->clear();
	$stmt->reset();
}

$db->exec('COMMIT');
$db->close();

println('DB 저장 완료');
?>