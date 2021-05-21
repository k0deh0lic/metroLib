# metroLib
metroLib는 대한민국 수도권 전철의 역별 열차 실시간 운행정보를 조회할 수 있는 API 라이브러리입니다.   
서울시 API(이하 TOPIS API)와 달리 수인·분당선 실시간 운행정보도 가져올 수 있습니다.   
또한 편성번호 조회도 가능하여 이를 통해 똥차 거르기(...), 자전거 거치대가 설치된 열차를 확인할 수 있습니다.   
자전거거치대가 설치된 편성에 대해서는 haveBicycleRack.md를 참조하시기 바랍니다.  

## 지원하는 노선
1호선   
2호선   
3호선   
4호선   
5호선   
6호선   
7호선   
8호선   
경춘선   
경의·중앙선   
수인·분당선   
경강선   

## 서버 요구 사항
PHP 7.4 이상

## 설정
자세한 사항은 metroLib.php 내부에 기재되어 있습니다.   

## 사용 방법
require_once()로 metroLib.php를 참조한 후, metroLib::getInstance()로 객체를 가져옵니다.   

### 메서드
getDataByStation(string $station_code) : ?array   
특정 노선의 특정 역 기준으로 열차 목록을 가져옵니다.   
기준 역 ±4역 범위 내의 열차 운행정보만을 가져올 수 있습니다.   
   
getDataByLine(string $line_code) : ?array   
특정 노선의 전체 열차 운행정보를 가져옵니다.   

### 파라메터 설명
line_code: 노선 코드
station_code: 역 코드   

#### 노선 코드
1: 1호선   
2: 2호선   
3: 3호선   
4: 4호선   
5: 5호선   
6: 6호선   
7: 7호선   
8: 8호선   
G: 경춘선   
K: 경의·중앙선   
SU: 수인·분당선   
KK: 경강선   

#### 역 코드
역 코드에 대해서는 첨부한 stations.json을 참고하시기 바랍니다.   

### 반환값 및 동작
열차가 존재할 경우 배열로 반환되며, 열차가 존재하지 않을 경우 null을 반환합니다.
만약 실행 중 오류가 발생할 시 RuntimeException이 발생합니다.   
   
trn_no: 열차번호 (string)   
trn_form_no: 편성번호 (?string)   
stn_cd: 현재 역 코드 (?string)   
stn_nm: 현재 역 이름 (?string)   
trn_sts: 열차 상태 코드 (integer)   
line: 노선 코드 (string)   
is_exp: 급행 여부 (boolean)   
trn_dir: 열차 운행 방향 (integer)   
dst_stn_nm: 행선지 역 이름 (?string)   
dst_stn_nm: 행선지 역 코드 (?string)   

#### 열차 상태 코드
0: 대기   
1: 진입   
2: 도착   
3: 출발   
4: 운행중
5: 종착   

#### 열차 운행 방향
U: 상행   
D: 하행   
I: 내선순환(2호선)   
O: 외선순환(2호선)   
SI1: 성수내선(2호선)   
SO1: 성수외선(2호선)   
SI2: 신정내선(2호선)   
SO2: 신정외선(2호선)   

### 예제

```
// 라이브러리를 불러옵니다.
require_once('metroLib.php');

// 객체 인스턴스를 생성합니다.
$ml = NXLogisMetro\metroLib::getInstance();

// 역별 열차 운행정보를 조회합니다. (예시: 1호선 구로역)
$trainList = ml->getDataByStation('1701')

// 화면에 데이터를 표시합니다.
var_dump($trainList);
```

## 라이선스
GNU Affero GPL v3   

## 그 외
* metroLib를 통해 얻은 정보는 정확하지 않을 수 있습니다. 
* 일부 구간(노선)에서는 편성정보 및 시·종착역 정보가 제공되지 않을 수 있습니다.
* 서울교통공사의 비공개 API(엄밀히는 또타지하철 앱 전용 API)를 이용한 것이므로 사용상의 책임은 사용자가 모두 부담합니다.
* metroLib 및 metroLib를 통해 얻은 데이터를 악용하거나 한국철도공사(KORAIL) 또는 서울교통공사(SeoulMetro)에 민원을 제기하지 마세요.
