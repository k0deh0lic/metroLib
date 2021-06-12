# genMetroDb
genMetroDb는 metroLib(https://github.com/k0deh0lic/metroLib)에서 사용되는 metro.db 파일을 생성합니다.

## 요구 사항
PHP 7.4 이상

## 사용 방법
1. 전국도시철도운행정보표준데이터(https://www.data.go.kr/data/15013206/standard.do)에서 한국철도공사 및 서울교통공사 csv 파일을 다운로드 받아서 input 디렉터리에 넣습니다.   
2. 한국천문연구원 특일정보 OpenAPI(https://data.go.kr/tcs/dss/selectApiDataDetailView.do?publicDataPk=15012690) 키를 발급받은 뒤 genTrainDB.php의 맨 위 $service_key 변수의 값으로 설정합니다.   
3. genTrainDB.php를 실행합니다. (예: php ./genTrainDB.php)   
4. output 디렉터리의 metro.db를 metroLib.php와 동일 디렉터리에 옮기세요.   

## 라이선스
GNU Affero GPL v3

## 그 외
* 공휴일 정보는 metro.db 파일 생성일로부터 1년동안만의 정보를 DB파일에 삽입합니다. 즉, 최소 1년에 한 번은 metro.db를 다시 만들어줘야 합니다. 그렇지 않으면 정보 없음으로 인한 오작동의 위험이 있습니다.   
* 열차운행시각표 갱신 시 다시 만들어줘야 합니다. 그렇지 않으면 오작동의 위험이 있습니다.   
* 이 코드의 일부 및 전체를 사용하여 발생한 모든 일의 책임은 사용자에게 있습니다.   