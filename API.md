# 使用方法
將下文輸入示例中 /api... 前接 input.cbdb.fas.harvard.edu

形如: [https://input.cbdb.fas.harvard.edu/api/post_list?id=06&start=0&list=100](https://input.cbdb.fas.harvard.edu/api/post_list?id=06&start=0&list=100)

# 文檔
# 一、根據官職類別代碼獲取其下屬官職列表
## 輸入參數:
| 參數名| 參數類型 | 說明 |
| ------ | ------ | ------ |
| id | 數字 | 官職類別代碼|
| start | 數字 | 開始筆數 |
| list | 數字 | 列表長度 |
## 輸入示例: 
`/api/post_list?id=06&start=0&list=100`獲取【唐朝】下的前100個官職(開始筆數 =0，結束筆數=99, ⻑度=100）  
`/api/post_list?id=06&start=100&list=100` 獲取【唐朝】下的第100-200個官職 (開始筆數=100，結束筆數=199, ⻑度=100)  
`/api/post_list?id=0601` 獲取【唐朝-帝后制度類】下的全部官職  

## 輸出格式:  
數據類型：`物件`  
```json
{
    "total":100,
    "start":1,
    "end":2,
    "data":[
        {"pId":"27","pName":"shang shu sheng gong bu shang shu","pNameChn":"尚書省工部尚書","pNameChnAlt":"工部尚書;尚書;工部一書;工書;冬卿;冬卿常伯"},
        {"pId":"28","pName":"shang shu sheng gong bu shi lang","pNameChn":"尚書省工部侍郎","pNameChnAlt":"工部侍郎;工侍;小司空;司平少常伯;冬官之貳;共工之貳"}        
    ]
}
```
| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 官職列表 |
| data[`i`].pId | 數字 | office_id |
| data[`i`].pName | 字符串 | 官職名，英文 |
| data[`i`].pNameChn | 字符串 | 官職名，中文 | 
| data[`i`].pNameChnAlt | 字符串 | 官職別名，中文 [OFFICE_CODES].[c_office_chn_alt] |  

# 二、根據入仕途徑類別代碼獲取其下屬的入仕途徑
## 輸入參數:
| 參數名| 參數類型 | 說明 |
| ------ | ------ | ------ |
| id | 數字 | 入仕途徑代碼 |
| start | 數字 | 開始筆數 |
| list | 數字 | 列表長度 |
## 輸入示例: 
`/api/entry_list?id=04` 獲取【科舉門】下的所有入仕途徑  
`/api/entry_list?id=0403` 獲取【科舉門-制舉】下的所有入仕途徑    

## 預期輸出:  
數據類型：`物件`  
```json
{
    "total":100,
    "start":1,
    "end":2,
    "data":[
        {"eId":"47","eName":"school: licentiate ","eNameChn":"學校：生員（庠生）"},
        {"eId":"173","eName":"county school student","eNameChn":"縣學生員"}        
    ]
}
```
| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 入仕途徑列表 |
| data[`i`].eId | 數字 | entry_code |
| data[`i`].eName | 字符串 | 入仕途徑名，英文 |
| data[`i`].eNameChn | 字符串 | 入仕途徑名，中文 |

# 三、根據名稱、存在時間等條件獲取地點
| 參數名| 參數類型 | 說明 |
| ------ | ------ | ------ |
| name | 字符串 | 地點名稱，中文或英文(必須) |
| startTime | 數字 | 起始時間(可選) |
| endTime | 數字 | 結束時間(可選) |
| accurate| 數字 | 是否精確匹配，若精確匹配為`0`，若部分匹配，為`1` ~~是的你沒看錯，真的是這樣~~ |
| start | 數字 | 開始筆數|
| list | 數字 | 列表長度 |

## 輸入示例: 
`/api/place_list?name=%E6%99%AE%E6%B4%B2%E9%81%93&accurate=1` 模糊匹配地名為“普洱道”的所有地點  
`/api/place_list?name=Puer&accurate=0` 精確匹配名稱為“Puer”的地點

## 輸出格式:  
數據類型：`物件`  
```json
{
    "total":100,
    "start":1,
    "end":2,
    "data":[
            {"pId":9099,
            "pName":"Guangzhou Shi",
            "pNameChn":"廣州市",
            "pStartTime":1912,
            "pEndTime":1949,
            "pBName":"Guangdong sheng",
            "pBNameChn":"廣東省"
            },
            {"pId":9102,
            "pName":"Guangzhou Shi",
            "pNameChn":"廣州市",
            "pStartTime":1912,
            "pEndTime":1949,
            "pBName":"Guangxi sheng",
            "pBNameChn":"廣西省"}
            ]    
}
```
| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 入仕途徑列表 |
| data[`i`].pId | 數字 | 地點代碼 |
| data[`i`].pName | 字符串 | 地點名稱，英文 |
| data[`i`].pNameChn | 字符串 | 地點名稱，中文 |
| data[`i`].pStartTime | 數字 | 地點起始時間|
| data[`i`].pEndTime | 數字 | 地點結束時間 |
| data[`i`].pBName | 字符串 | 上一級地點名稱，英文 |
| data[`i`].pBNameChn | 字符串 | 上一級地點名稱，中文 |

# 四、搜尋該地點下的所有地點
| 參數名| 參數類型 | 說明 |
| ------ | ------ | ------ |
| id | 數字 | 地點代碼 |
| start | 數字 |開始筆數 |
| list | 數字 | 列表⻑度 |

## 輸入示例: 
`/api/place_belongs_to?id=16773` 搜索屬於“安⻄都護府”的所有地點  

## 輸出格式:  
數據類型：`物件`  
```json
{
    "total":100,
    "start":1,
    "end":2,
    "data":[
            {"pId":9099,
            "pName":"Guangzhou Shi",
            "pNameChn":"廣州市",
            "pStartTime":1912,
            "pEndTime":1949,
            "pBName":"Guangdong sheng",
            "pBNameChn":"廣東省"
            },
            {"pId":9102,
            "pName":"Guangzhou Shi",
            "pNameChn":"廣州市",
            "pStartTime":1912,
            "pEndTime":1949,
            "pBName":"Guangxi sheng",
            "pBNameChn":"廣西省"}
            ]    
}
```
| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 入仕途徑列表 |
| data[`i`].pId | 數字 | 地點代碼 |
| data[`i`].pName | 字符串 | 地點名稱，英文 |
| data[`i`].pNameChn | 字符串 | 地點名稱，中文 |
| data[`i`].pStartTime | 數字 | 地點起始時間|
| data[`i`].pEndTime | 數字 | 地點結束時間 |
| data[`i`].pBName | 字符串 | 上一級地點名稱，英文 |
| data[`i`].pBNameChn | 字符串 | 上一級地點名稱，中文 |

**注：**
*  在ACCESS系統中，不論一個地點下面是否有其他地點屬於它，結果中都會返回這一地點本身。
此處亦應遵守此原則。
* 為了保證返回數據的一致性，API返回查詢結果之前應該按照相同的標準進行排序。用輸入參數中id所對應的當前表格中的id進行排序(此處為地點的id)

# 五、根據官職中英文名獲取官職列表
## 輸入參數:

| 參數名| 參數類型 | 說明 |
| ------ | ------ | ------ |
| pName | 字符串 | 官職名稱，中文或英文，經過轉碼 |
| start | 數字 | 開始筆數 |
| list | 數字 | 列表長度 |
| accurate|數字|是否採用精確匹配，是=1，否=0|

## 輸入示例: 
`/api/office_list_by_name?pName=%E5%B0%9A%E6%9B%B8%E7%9C%81%E5%B7%A5%E9%83%A8&start=1&list=2&accurate=0`搜尋所有名稱含有**尚書省工部**的官職，從第1筆開始，至多2筆結果  
`/api/office_list_by_name?pName=%E5%B0%9A%E6%9B%B8%E7%9C%81%E5%B7%A5%E9%83%A8%E4%BE%8D%E9%83%8E&start=1&list=2&accurate=1`精確匹配名稱為**尚書省工部侍郎**的官職，從第1筆開始，至多返回2筆結果

## 輸出格式示例:    
數據類型：`物件` 
```json
{
    "total":100,
    "start":1,
    "end":2,
    "data":[
        {"pId":"27","pName":"shang shu sheng gong bu shang shu","pNameChn":"尚書省工部尚書","pNameChnAlt":"工部尚書;尚書;工部一書;工書;冬卿;冬卿常伯"},
        {"pId":"28","pName":"shang shu sheng gong bu shi lang","pNameChn":"尚書省工部侍郎","pNameChnAlt":"工部侍郎;工侍;小司空;司平少常伯;冬官之貳;共工之貳"}        
    ]
}
```
| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 官職列表 |
| data[`i`].pId | 數字 | office_id |
| data[`i`].pName | 字符串 | 官職名，英文 |
| data[`i`].pNameChn| 字符串 | 官職名，中文 |  
| data[`i`].pNameChnAlt | 字符串 | 官職別名，中文 [OFFICE_CODES].[c_office_chn_alt] |  

# 六、根據入仕途徑中英文名獲取入仕途徑列表
## 輸入參數:
| 參數名| 參數類型 | 說明 |
| ------ | ------ | ------ |
| eName | 字符串 | 入仕途徑名稱，中文或英文，經過轉碼 |
| start | 數字 | 開始筆數 |
| list | 數字 | 列表長度 |
| accurate|數字|是否採用精確匹配，是=1，否=0|

## 輸入示例: 
`/api/entry_list_by_name?eName=%%E7%94%9F%E5%93%A1&start=1&list=2&accurate=0`搜尋所有名稱含有**生員**的入仕途徑,從第1筆開始，至多2筆結果    
`/api/entry_list_by_name?eName=%%E7%B8%A3%E5%AD%B8%E7%94%9F%E5%93%A1&start=1&list=2&accurate=1`精確匹配名稱為**縣學生員**的官職，從第1筆開始，至多2筆結果  

## 輸出格式:    
數據類型：`物件` 
示例：
```json
{
    "total":100,
    "start":0,
    "end":2,
    "data":[
        {"eId":"47","eName":"school: licentiate ","eNameChn":"學校：生員（庠生）"},
        {"eId":"173","eName":"county school student","eNameChn":"縣學生員"}        
    ]
}
```
| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 入仕途徑列表 |
| data[`i`].eId | 數字 | entry_code |
| data[`i`].eName | 字符串 | 入仕途徑名，英文 |
| data[`i`].eNameChn | 字符串 | 入仕途徑名，中文 |

# 七、查詢擔任過給定職官的人
## 輸入參數:
| 參數名| 參數類型 | 說明 |
| ------ | ------ | ------ |
| office | 陣列 | 要查詢的職官ID列表 |
| useOfficePlace |數字 | 是否啟用與職官相關的地點這一條件。是=1，否=0 |
| officePlace | 陣列 | 與職官相關的地點列表 |
| usePeoplePlace | 數字 | 是否啟用與人物相關的地點這一條件。是=1，否=0 |
| peoplePlace | 陣列 | 與人物相關的地點列表 |
| indexYear|數字|是否採用指數年，是=1，否=0|
| indexStartTime|數字|指數年開始日期|
| indexEndTime|數字|指數年結束日期|
| useXy|數字|是否使用xy座標，是=1，否=0|
| start|數字|結果開始筆數|
| list|數字|列表長度|

**注：`useOfficePlace` `usePeoplePlace` `indexYear` 的優先級高於`officePlace` `peoplePlace` `indexYearStartTime` `indexYearEndTime`，即若以`use`開頭的三個變數取值為0，就不使用相應的條件（不論陣列是否為空）**
## 輸入示例: 
**注：採用POST方法，Content-Type: application/json**
`/api/query_people_in_office`
```json
RequestPayload:{
    "office":[920,1022,1023],
    "useOfficePlace":0,
    "officePlace":[],
    "usePeoplePlace":1,
    "peoplePlace":[2928,10522,12553,13947,13949],
    "indexYear":1,
    "indexStartTime":960,
    "indexEndTime":1250,
    "useXy":1,
    "start":11,
    "list":10
}
```
說明：查找所有曾擔任宰相、左丞相、右丞相（宋朝），且人物地點為興化/興化軍，指數年介於960和1250年間的人。返回結果的第11筆到第20筆。
## 輸出格式: 
數據類型：`物件` 
示例   
```json
{
    "total":100,
    "start":11,
    "end":20,
    "data":[
        {"PersonID":332,"Name": "Zhang Yong", "NameChn": "張詠", "Sex": "M", "IndexYear": 1005, "AddrID": 11273, "AddrType":"Basic Affiliation","AddrTypeChn":"籍貫（基本地址）","AddrName": "Juancheng", "AddrChn": "鄄城", "X": 115.5583, "Y": 35.61019, "xy_count": " 1"},
        ...
        ]
}
```
| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 任官人物列表 |
| data[`i`].PersonID | 數字 | 人物ID |
| data[`i`].Name | 字符串 | 人物名，英文 |
| data[`i`].NameChn | 字符串 | 人物名，中文 |
| data[`i`].Sex | 字符串 | 人物性別 |
| data[`i`].IndexYear | 數字 | 人物指數年 |
| data[`i`].AddrID | 數字 | 人物地點ID |
| data[`i`].AddrType | 字符串 | 地點類型，英文 |
| data[`i`].AddrTypeChn | 字符串 | 地點類型，中文 |
| data[`i`].AddrName | 字符串 | 人物地點，英文 |
| data[`i`].AddrChn | 字符串 | 人物地點，中文 |
| data[`i`].X | 數字 | 人物地點經度座標 |
| data[`i`].Y | 數字 | 人物地點緯度座標 |
| data[`i`].xy_count | 數字 | 結果中該地點存在的人物數 |

# 八、查詢除授記錄（Office Postings）

## 輸入參數:

| 參數名         | 參數類型 | 說明                                            |
| -------------- | -------- | ----------------------------------------------- |
| office         | 陣列     | 要查詢的職官 ID 列表                            |
| useOfficePlace | 數字     | 是否啟用與職官相關的地點這一條件。是=1，否=0    |
| officePlace    | 陣列     | 與職官相關的地點列表                            |
| usePeoplePlace | 數字     | 是否啟用與人物相關的地點這一條件。是=1，否=0    |
| peoplePlace    | 陣列     | 與人物相關的地點列表                            |
| useDate        | 數字     | 是否採用日期這一條件，是=1，否=0                |
| dateType       | 字串     | 採用指數年抑或是朝代。指數年=index 朝代=dynasty |
| indexStartTime | 數字     | 指數年開始日期                                  |
| indexEndTime   | 數字     | 指數年結束日期                                  |
| dynStart       | 數字     | 開始朝代                                        |
| dynEnd         | 數字     | 結束朝代                                        |
| useXy          | 數字     | 是否使用 xy 座標，是=1，否=0                    |
| start          | 數字     | 結果開始筆數                                    |
| list           | 數字     | 列表長度                                        |

**注：`useOfficePlace` `usePeoplePlace` `useDate` 的優先級高於`officePlace` `peoplePlace` `indexYearStartTime` `indexYearEndTime` `dynStart` `dynEnd`，即若以`use`開頭的三個變數取值為 0，就不使用相應的條件（不論其陣列是否為空）**
**注：如果使用朝代作為條件，返回的結果中應包括`dynStart` `dynEnd`中指定的朝代**

## 輸入示例:

**注：採用 POST 方法，Content-Type: application/json**
`/api/query_office_postings`

```json
RequestPayload:{
    "office":[920,1022,1023],
    "useOfficePlace":0,
    "officePlace":[],
    "usePeoplePlace":1,
    "peoplePlace":[2928,10522,12553,13947,13949],
    "useDate":1,
    "dateType":"index",
    "indexStartTime":960,
    "indexEndTime":1250,
    "dynStart":null,
    "dynEnd":null,
    "useXy":1,
    "start":11,
    "list":10
}
```

說明：查找所有曾擔任宰相、左丞相、右丞相（宋朝），且人物地點為興化/興化軍，指數年介於 960 和 1250 年間的人的任官記錄。返回結果的第 11 筆到第 20 筆。

### 查詢示例 (by POST)

```
https://input.cbdb.fas.harvard.edu/api/query_office_postings?RequestPayload={"office":[920,1022,1023],"useOfficePlace":0,"officePlace":[],"usePeoplePlace":0,"peoplePlace":[],"useDate":0,"dateType":"index","indexStartTime":960,"indexEndTime":1250,"dynStart":null,"dynEnd":null,"useXy":0,"start":0,"list":65535}
```

## 輸出格式:

數據類型：`物件`
示例

```json
{
    "total":100,
    "start":11,
    "end":20,
    "data":[
        {"PersonID":332,"Name": "Zhang Yong", "NameChn": "張詠", "Sex": "M", "IndexYear": 1005, "AddrID": 11273, "AddrType":"Basic Affiliation","AddrTypeChn":"籍貫（基本地址）","AddrName": "Juancheng", "AddrChn": "鄄城", "X": 115.5583, "Y": 35.61019, "OfficeCode":2383,"OfficeName":"Left Assistant Director of the Department of Affairs(Hucker)","OfficeNameChn":"尚書省左丞","FirstYear":1010,"LastYear":0,"Dynasty":"未詳","OfficeAddrID":0,"OfficeAddrName":"[Unknown]","OfficeAddrChn":"[未詳]","OfficeX":"","OfficeY":"","office_xy_count": " 6", "PostingID":63598,"ApptType":"","ApptTypeChn":"","AssumedOffice":"","AssumedOfficeChn":"","Notes":"LZL MasterFileLineID12259"}
        ...
        ]
}
```

| 屬性名                        | 屬性類型 | 說明             |
| ----------------------------- | -------- | ---------------- |
| total                         | 數字     | 數據總筆數       |
| start                         | 數字     | 當前數據開始筆數 |
| end                           | 數字     | 當前數據結束筆數 |
| data                          | 陣列     | 除授記錄列表     |
| data[`i`].PersonID            | 數字     | 人物 ID          |
| data[`i`].Name                | 字符串   | 人物名，英文     |
| data[`i`].NameChn             | 字符串   | 人物名，中文     |
| data[`i`].Sex                 | 字符串   | 人物性別         |
| data[`i`].IndexYear           | 數字     | 人物指數年       |
| data[`i`].AddrID              | 數字     | 人物地點 ID      |
| data[`i`].AddrType            | 字符串   | 地點類型，英文   |
| data[`i`].AddrTypeChn         | 字符串   | 地點類型，中文   |
| data[`i`].AddrName            | 字符串   | 人物地點，英文   |
| data[`i`].AddrChn             | 字符串   | 人物地點，中文   |
| data[`i`].X                   | 數字     | 人物地點經度座標 |
| data[`i`].Y                   | 數字     | 人物地點緯度座標 |
| data[`i`].OfficeCode          | 數字     | 官職 ID          |
| data[`i`].OfficeName          | 字符串   | 官職名，英文     |
| data[`i`].OfficeNameChn       | 字符串   | 官職名，中文     |
| data[`i`].FirstYear           | 數字     | 任官開始年       |
| data[`i`].LastYear            | 數字     | 任官結束年       |
| data[`i`].Dynasty             | 字符串   | 朝代             |
| data[`i`].OfficeAddrID        | 數字     | 官職地點 ID      |
| data[`i`].OfficeAddrName      | 字符串   | 官職地點名，英文 |
| data[`i`].OfficeAddrChn       | 字符串   | 官職地點名，中文 |
| data[`i`].OfficeX             | 數字     | 官職地點經度座標 |
| data[`i`].OfficeY             | 數字     | 官職地點緯度座標 |
| data[`i`].office_xy_count     | 數字     | 職官地址數       |
| data[`i`].PostingID           | 數字     | 除授記錄         |
| data[`i`].ApptType            | 字符串   | 除授類型，英文   |
| data[`i`].ApptTypeChn         | 字符串   | 除授類型，中文   |
| data[`i`].AssumptionOffice    | 字符串   | 赴任情況，英文   |
| data[`i`].AssumptionOfficeChn | 字符串   | 赴任情況，中文   |
| data[`i`].Notes               | 字符串   | 備註             |

### 補充說明：

若要忽略地址只檢索官職，示例如下：

宋代所有地區的知州(office_id = 950)

```/api/query_office_postings?RequestPayload={**"office":[950],"useOfficePlace":0,"officePlace":[]**,"usePeoplePlace":0,"peoplePlace":[],"useDate":0,"dateType":"index","indexStartTime":960,"indexEndTime":1250,"dynStart":null,"dynEnd":null,"useXy":0,"start":0,"list":65535}````

若要忽略官職檢索本地區的所有任官者，可使用「通過地區查詢」API 進行檢索。（「查詢除授記錄（Office Postings）」API 中官職 ID 是必填項。）

# 九、通過入仕途徑查詢人物

## 輸入參數:

| 參數名         | 參數類型 | 說明                                                                                       |
| -------------- | -------- | ------------------------------------------------------------------------------------------ |
| entry          | 陣列     | 要查詢的入仕途徑 ID 列表                                                                   |
| usePeoplePlace | 數字     | 是否啟用與人物相關的地點這一條件。是=1，否=0                                               |
| peoplePlace    | 陣列     | 與人物相關的地點列表                                                                       |
| locationType   | 字符串   | 與人物相關的地點的類型 pAddr 為僅查找人物地點 eAddr 為僅查找入仕地點 peAddr 為同時查找兩者 |
| useDate        | 數字     | 是否採用年份這一條件，是=1，否=0                                                           |
| dateType       | 字符串   | 年份類型 entry 為入仕年 index 為指數年 dynasty 為朝代                                      |
| dateStartTime  | 數字     | 年份開始日期                                                                               |
| dateEndTime    | 數字     | 年份結束日期                                                                               |
| dynStart       | 數字     | 朝代開始                                                                                   |
| dynEnd         | 數字     | 朝代結束                                                                                   |
| useXy          | 數字     | 是否使用 xy 座標，是=1，否=0                                                               |
| start          | 數字     | 結果開始筆數                                                                               |
| list           | 數字     | 列表長度                                                                                   |

**注：`usePeoplePlace` `useDate` 的優先級高於`peoplePlace` `locationType` `dateType` `dateStartTime` `dateEndTime`，即若以`use`開頭的 2 個變數取值為 0，就不使用後面的條件（不論陣列是否為空）**
**注：當 `dateType` 取值為 `entry` 或 `index` 時，僅考慮 `dateStartTime` 與 `dateEndTime` 兩個字段的值，不考慮 `dynStart` 與 `dynEnd` 的取值。反之， 當 `dateType` 取值為 `dynasty` 時，僅考慮 `dynStart` 與 `dynEnd` 的取值，不考慮 `dateStartTime` 與 `dateEndTime` 兩個字段的值**

## 輸入示例:

**注：採用 POST 方法，Content-Type: application/json**
`/api/query_entry_postings`

```json
RequestPayload:{
    "entry": [36],
    "usePeoplePlace": 0,
    "peoplePlace":[],
    "locationType": "peAddr",
    "useDate": 1,
    "dateType": "entry",
    "dateStartTime": 1368,
    "dynStart": null,
    "dynEnd": null,
    "dateEndTime": 1644,
    "useXy": 1,
    "start": 1,
    "list": 10
}
```

說明：查找入仕途徑為科舉：進士（籠統）且入仕年介於 1368-1644 的所有人物。返回第 1-10 筆結果

```json
RequestPayload:{
    "entry":[36],
    "usePeoplePlace":0,
    "peoplePlace":[],
    "locationType":"peAddr",
    "useDate":1,
    "dateType":"entry",
    "dateStartTime":null,
    "dateEndTime":null,
    "dynStart": 17,
    "dynEnd": 22,
    "useXy":1,
    "start":1,
    "list":10
}
```

說明：查找入仕途徑為科舉：進士（籠統）且入仕年介於 金朝 到 清朝 的所有人物。返回第 1-10 筆結果

## 輸出格式:

數據類型：`物件`
示例

```json
{
    "total":100,
    "start":1,
    "end":10,
    "data":[
        {"PersonID":26219,"Name": "Sheng Yingyang", "NameChn": "盛應陽", "Sex": "M", "IndexYear": 1553, "EntryDesc":"examination: jinshi (general)","EntryChn":"科舉: 進士(籠統)","EntryYear":1526,"EntryRank":0,"KinType":"U","KinName":"Wei Xiang","KinChn":"未詳","Association":"[Undefined]","AssocName":"Wei Xiang","AssocChn":"未詳","AddrType":"Basic Affiliation","AddrTypeChn":"籍貫（基本地址）","AddrName": "Wu Xian", "AddrChn": "吳縣", "X": 120.61862, "Y": 31.31271,"xy_count":17,"ParentState":"[Unknown]","ParentStateChn":"未詳","EntryPlace":"","EntryPlaceChn":"","EntryX":"","EntryY":"","entry_xy_count":""}
        ...
        ]
}
```

| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 除授記錄列表 |
| data[`i`].PersonID | 數字 | 人物ID |
| data[`i`].Name | 字符串 | 人物名，英文 |
| data[`i`].NameChn | 字符串 | 人物名，中文 |
| data[`i`].Sex | 字符串 | 人物性別 |
| data[`i`].IndexYear | 數字 | 人物指數年 |
| data[`i`].EntryDesc | 字符串 | 入仕途徑，英文 |
| data[`i`].EntryChn | 字符串 | 入仕途徑，中文 |
| data[`i`].EntryYear | 數字 | 入仕年 |
| data[`i`].EntryRank | 數字 | 考試排名 |
| data[`i`].KinType | 字符串 | 親屬關係 |
| data[`i`].KinName | 字符串 | 親屬姓名，英文 |
| data[`i`].KinChn | 字符串 | 親屬姓名，中文 |
| data[`i`].Association | 字符串 | 社會關係 |
| data[`i`].AssocName | 字符串 | 社會關係人姓名，英文 |
| data[`i`].AssocChn | 字符串 | 社會關係人姓名，中文 |
| data[`i`].AddrID | 數字 | 人物地點ID |
| data[`i`].AddrName | 字符串 | 人物地點，英文 |
| data[`i`].AddrChn | 字符串 | 人物地點，中文 |
| data[`i`].X | 數字 | 人物地點經度座標 |
| data[`i`].Y | 數字 | 人物地點緯度座標 |
| data[`i`].xy_count | 數字 | 結果同一人物地點的人物數 |
| data[`i`].ParentState | 字符串 | 父母情況，英文 |
| data[`i`].ParentStateChn | 字符串 | 父母情況，中文 |
| data[`i`].EntryPlace | 字符串 | 入仕地點，英文 |
| data[`i`].EntryPlaceChn | 字符串 | 入仕地點，中文 |
| data[`i`].EntryX | 數字 | 入仕地點經度座標 |
| data[`i`].EntryY | 數字 | 入仕地點緯度座標 |
| data[`i`].dynasty | 數字 | 朝代 英文 |
| data[`i`].dynastyChn| 數字 | 朝代 中文 |
| data[`i`].entry_xy_count | 數字 | 結果同一入仕地點的人物數 |


# 十、根據給定人物陣列查詢人物親屬
## 輸入參數:
數據類型：`物件`
| 參數名| 參數類型 | 說明 |
| ------ | ------ | ------ |
| people | 陣列 | 要查詢的人物ID列表 |
| mCircle|數字|是否採用五服查詢，是=1，否=0|
| MAncGen | 數字 | 最大祖先距離 |
| MDecGen | 數字 | 最大後代距離 |
| MColLink | 數字 | 最大同輩距離 |
| MMarLink | 數字 | 最大姻親距離 |
| MLoop | 數字 | 最大循環次數 |

**注：`mCircle` 的優先級高於`MAncGen` `MDecGen` `MColLink` `MMarLink` `MLoop `，即若以`mCircle`開頭的變數取值為0，則查詢列表人物的五服親屬，不論`MAncGen` `MDecGen` `MColLink` `MMarLink` `MLoop `取值為何**
## 輸入示例: 
**注：採用POST方法，Content-Type: application/json**
`/api/query_relatives`
```json
RequestPayload:{
    "people":[1762],
    "mCircle":0,
    "MAncGen":1,
    "MDecGen":1,
    "MColLink":1,
    "MMarLink":1,
    "MLoop":2
}
說明：查找王安石的親屬，採用自定義參數查找。最大向上1層，最大向下1層，最大同輩關係為1層，最大婚姻關係為1層   
```
## 預期輸出示例:    
數據類型：`物件` 
```json
{
    "total":100,
    "start":1,
    "end":2,
    "data":[
        {"rId":"1762","rName":"Wang Anshi","rNameChn":"王安石","pId":"1762","pName":"Wang Anshi","pNameChn":"王安石","pAddrID":"100513","pAddrType":"Basic Affiliation","pAddrTypeChn":"籍貫（基本地址）","pAddrName":"Linchuan","pAddrNameChn":"臨川","pX":"116.351341","pY":"27.984781","Id":"7404","Name":"Ye Tao","NameChn":"葉濤","IndexYear":"1080","sex":"M","pkinship":"DH","rKinship":"DH","up":0,"down":1,"col":0,"mar":1,"AddrID":"100650","AddrType":"Basic Affiliation","AddrTypeChn":"籍貫（基本地址）","AddrName":"Longquan","AddrNameChn":"龍泉","X":"119.12091","Y":"28.082565","xy_count":1,"pDistance":"272.04077877","rDistance":"272.04077877","KinRelCal":"","Notes":"據宋史列傳CBDB宋史分傳#1055"},
        {"rId":"1762","rName":"Wang Anshi","rNameChn":"王安石","pId":"1760","pName":"Wang Anli","pNameChn":"王安禮","pAddrID":"100513","pAddrType":"Basic Affiliation","pAddrTypeChn":"籍貫（基本地址）","pAddrName":"Linchuan","pAddrNameChn":"臨川","pX":"116.351341","pY":"27.984781","Id":"20583","Name":"Wang Jian","NameChn":"王瑊","IndexYear":"1093","sex":"M","pkinship":"SSS","rKinship":"BSSS","up":0,"down":3,"col":1,"mar":0,"AddrID":"100513","AddrType":"Basic Affiliation","AddrTypeChn":"籍貫（基本地址）","AddrName":"Linchuan","AddrNameChn":"臨川","X":"116.351341","Y":"27.984781","xy_count":17,"pDistance":"0","rDistance":"0","KinRelCal":"","Notes":""}       
    ]
}
```
| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 結果列表 |
| data[`i`].rId | 數字 | 中心人物ID |
| data[`i`].rName | 字符串 | 中心人物名，英文 |
| data[`i`].rNameChn | 字符串 | 中心人物名，中文 ||
| data[`i`].Id | 數字 | 親屬關係目標人物ID |
| data[`i`].Name | 字符串 | 親屬關係目標人物名，英文 |
| data[`i`].NameChn | 字符串 | 親屬關係目標人物名，中文 |
| data[`i`].Sex | 字符串 | 親屬關係目標人物性別 |
| data[`i`].IndexYear | 數字 | 親屬關係目標人物指數年 |
| data[`i`].rKinship | 字符串 | 與中心人物的親屬關係 |
| data[`i`].up | 數字 | 向上查找的距離 |
| data[`i`].down | 數字 | 向下查找的距離 |
| data[`i`].col | 數字 | 同輩關係查找的距離 |
| data[`i`].mar | 數字 | 姻親關係查找的距離 |
| data[`i`].AddrID | 數字 | 親屬關係目標人物地點ID |
| data[`i`].AddrType | 字符串 | 親屬關係目標人物地點類型，英文 |
| data[`i`].AddrTypeChn | 字符串 | 親屬關係目標人物地點類型，中文 |
| data[`i`].AddrName | 字符串 |親屬關係目標人物地點名，英文 |
| data[`i`].AddrNameChn | 字符串 | 親屬關係目標人物地點名，中文 |
| data[`i`].X | 數字 | 親屬關係目標人物地點經度座標 |
| data[`i`].Y | 數字 | 親屬關係目標人物地點緯度座標 |
| data[`i`].rDistance | 數字 | 親屬關係目標人物所的點與中心人物的地點之距離 |
| data[`i`].xy_count | 數字 | 結果中親屬關係目標人物所在地點的總人物數 |
| data[`i`].Notes  | 字符串 | 備註 |

**注：返回中心人物記錄時`rKinship`取值為`'ego'`，以p開頭的變數返回`''`（空字符串）即可**

# 十一、根據社會關係類型代碼獲取社會關係
## 輸入參數:
| 參數名| 參數類型 | 說明 |
| ------ | ------ | ------ |
| aType| 數字 | 社會關係類型代碼 |

## 輸入示例: 
**注：採用GET方法**
`/api/get_assoc?aType=0406`
說明：獲取薦舉保任下的所有社會關係   
  
## 預期輸出示例:    
數據類型：`物件` 
```json
{
    "total":10,
    "start":1,
    "end":2,
    "data":[
        {"aId":"351","aName":"Posthumous titular office prosposed for","aNameChn":"提議封贈Y"},       
    ]
}
```
| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 結果列表 |
| data[`i`].aId | 數字 | 社會關係code |
| data[`i`].aName | 字符串 | 社會關係名，英文 |
| data[`i`].aNameChn | 字符串 | 社會關係名，中文 |

# 十二、查找社會關係
## 輸入參數:
| 參數名| 參數類型 | 說明 |
| ------ | ------ | ------ |
| aName| 字符串 | 社會關係名，中文或英文 |

## 輸入示例: 
**注：採用GET方法**
**注：採用模糊匹配**
`/api/find_assoc?aName=%E9%96%80%E4%BA%BA`
說明：匹配所有含有“門人”的記錄
  
## 預期輸出示例:    
數據類型：`物件` 
```json
{
    "total":10,
    "start":1,
    "end":2,
    "data":[
        {"aId":351,
        "aName":"Posthumous titular office prosposed for","aNameChn":"提議封贈Y"
        },       
    ]
}
```
| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 結果列表 |
| data[`i`].aId | 數字 | 社會關係code |
| data[`i`].aName | 字符串 | 社會關係名，英文 |
| data[`i`].aNameChn | 字符串 | 社會關係名，中文 |

# 十三、查詢人物社會關係
## 輸入參數:
數據類型：`物件`
| 參數名| 參數類型 | 說明 |
| ------ | ------ | ------ |
| association | 陣列 | 要查詢的社會關係列表 |
| place|陣列|人物地點列表|
| usePeoplePlace | 數字 | 是否啟用人物地點列表，是=1，否=0 |
| useXy|數字|是否使用xy座標，是=1，否=0|
| indexYear|數字|是否採用指數年，是=1，否=0|
| indexStartTime|數字|指數年開始日期|
| indexEndTime|數字|指數年結束日期|
| broad|數字|行政區域範圍是廣義的還是狹義的。廣義=1，狹義=0。廣義 `+/- 0.06` 狹義 `+/- 0.03`|

**注：`usePeoplePlace` 的優先級高於`place`，即若以`usePeoplePlace`開頭的變數取值為0，則不採用人物地點列表，不論其取值是否為空**
## 輸入示例: 
**注：採用POST方法，Content-Type: application/json**
`/api/query_associates`
```json
RequestPayload:{
    "association":[22],
    "place":[101125],
    "usePeoplePlace":1,
    "useXy":1,
    "indexYear":1,
    "indexStartTime":960,
    "indexEndTime":1250,
    "broad":0
}
```
說明：查找滿足下列關係的Y：所有指數年在960年至1250年間，地點在“建州”的人物（X），其有“為Y之學生”關係。換句話說就是：查找960年至1250年間且地點在“建州”的人物（X）的老師（Y）  
`/api/query_associates`
```json
RequestPayload:{
    "association":[23],
    "place":[101125],
    "usePeoplePlace":1
}
```
說明：查找滿足下列關係的Y：所有地點在“建州”的人物（X），其有“為Y之老師”關係。換句話說就是要查找地點在“建州”的人物（X）的學生（Y）  
  
## 預期輸出示例:    
數據類型：`物件` 
```json
{
    "total":100,
    "start":1,
    "end":2,
    "data":[
        {"pId":"46398","pName":"Liang Zhuan","pNameChn":"梁瑑","aId":"3257","aName":"Zhu Xi","aNameChn":"朱熹","pIndexYear":"","pSex":"M","aIndexYear":"1189","aSex":"M","pAddrID":"100577","pAddrName":"Shaowu","pAddrNameChn":"邵武","pX":"114.483398","pY":"27.337692","aAddrID":"101125","aAddrName":"Jianzhou","aAddrNameChn":"建州","aX":"118.32378387","aY":"27.038864136","pKinshipRelation":"U","pKinshipRelationChn":"未詳","pKinName":"Wei Xiang","pKinNameChn":"未詳","aKinshipRelation":"U","aKinshipRelationChn":"未詳","aKinName":"Wei Xiang","aKinNameChn":"未詳","distance":"89.516896410","p_xy_count":17,"a_xy_count":7,"KinRelCal":""},       
    ]
}
```
| 屬性名| 屬性類型 | 說明 |
| ------ | ------ | ------ |
| total |  數字 | 數據總筆數 |
| start | 數字 | 當前數據開始筆數 |
| end | 數字 | 當前數據結束筆數 |
| data | 陣列 | 結果列表 |
| data[`i`].pId | 數字 | 中心人物ID |
| data[`i`].pName | 字符串 | 中心人物名，英文 |
| data[`i`].pNameChn | 字符串 | 中心人物名，中文 |
| data[`i`].pSex | 字符串 | 中心人物性別 |
| data[`i`].pIndexYear | 數字 | 中心人物指數年 |
| data[`i`].pAddrID | 數字 | 中心人物的地點ID |
| data[`i`].pAddrName | 字符串 | 中心人物的地點名，英文 |
| data[`i`].pAddrNameChn | 字符串 | 中心人物的地點名，中文 |
| data[`i`].pX | 數字 | 中心人物的地點經度座標 |
| data[`i`].pY | 數字 | 中心人物的地點緯度座標 |
| data[`i`].p_xy_count | 數字 | 結果中中心人物所在地點的總人物數| 
| data[`i`].pKinshipRelation | 字符串 | 中心人物的親屬關係名，英文 |
| data[`i`].pKinshipRelationChn | 字符串 | 中心人物的親屬關係名，中文 |
| data[`i`].pKinName | 字符串 | 中心人物的親屬姓名，英文 |
| data[`i`].pKinNameChn | 字符串 | 中心人物的親姓名，中文 |
| data[`i`].pAddrNameChn | 字符串 | 中心人物的地點名，中文 |
| data[`i`].aId | 數字 | 社會關係人ID |
| data[`i`].aName | 字符串 | 社會關係人物名，英文 |
| data[`i`].aNameChn | 字符串 | 社會關係人物名，中文 |
| data[`i`].aSex | 字符串 | 社會關係人物性別 |
| data[`i`].aIndexYear | 數字 | 社會關係人物指數年 |
| data[`i`].aAddrID | 數字 | 社會關係人地點ID |
| data[`i`].aAddrName | 字符串 |社會關係人地點名，英文 |
| data[`i`].aAddrNameChn | 字符串 | 社會關係人地點名，中文 |
| data[`i`].aX | 數字 | 社會關係人地點經度座標 |
| data[`i`].aY | 數字 | 社會關係人地點緯度座標 |
| data[`i`].a_xy_count | 數字 |結果中社會關係人所在地點的總人物數 人物數 |
| data[`i`].aKinshipRelation | 字符串 | 社會關係人的親屬關係名，英文 |
| data[`i`].aKinshipRelationChn | 字符串 | 社會關係人的親屬關係名，中文 |
| data[`i`].aKinName | 字符串 | 社會關係人的親屬姓名，英文 |
| data[`i`].aKinNameChn | 字符串 | 社會關係人的親姓名，中文 |
| data[`i`].distance | 數字 | 中心人物與社會關係人之間的距離|


# 十四、通過地區查詢

## 輸入參數:

| 參數名        | 參數類型 | 說明                                                                                                                                                                      |
| ------------- | -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| peoplePlace   | 陣列     | 要查詢的地點 ID 的陣列                                                                                                                                                    |
| placeType     | 陣列     | 地點類型的陣列。取值包括：`individual`人 `entry`入仕 `association` 社會關係`officePosting`職官 `institutional`社交機構 `kinship`親屬 `associate` 社會關係的人             |
| useDate       | 數字     | 是否啟用“時間”條件。1 代表啟用，0 代表不啟用。這一變數的優先級高於下面的 `dateType` `dateStartTime` ` dateEndTime` `dynStart` `dynEnd` 。如果其取值為 0，則無視上述參數。 |
| dateType      | 字串     | 時間條件的類型（指數年：`index`，朝代：`dynasty`）                                                                                                                        |
| dateStartTime | 數字     | 指數年開始日期期                                                                                                                                                          |
| dateEndTime   | 數字     | 指數年結束日期期                                                                                                                                                          |
| dynStart      | 數字     | 朝代開始                                                                                                                                                                  |
| dynEnd        | 數字     | 朝代結束                                                                                                                                                                  |
| useXy         | 數字     | 是否使用 xy 座標，是=1，否=0                                                                                                                                              |
| start         | 數字     | 結果開始筆數                                                                                                                                                              |
| list          | 數字     | 列表長度                                                                                                                                                                  |

**注：`useDate` 的優先級高於 `dateType` `dateStartTime` `dateEndTime` `dynStart` `dynEnd`，即若變數取值為 0，就不使用後面的條件（不論陣列是否為空）**
**注：當 `dateType` 取值為 `entry` 或 `index` 時，僅考慮 `dateStartTime` 與 `dateEndTime` 兩個字段的值，不考慮 `dynStart` 與 `dynEnd` 的取值。反之， 當 `dateType` 取值為 `dynasty` 時，僅考慮 `dynStart` 與 `dynEnd` 的取值，不考慮 `dateStartTime` 與 `dateEndTime` 兩個字段的值**

## 輸入示例:

**注：採用 POST 方法，Content-Type: application/json**
`/api/query_place`

```json
RequestPayload:{
    "peoplePlace":[2928,10522,12553,13947,13949],
    "placeType": ["individual","entry","officePosting"],
    "useDate": 1,
    "dateType": "index",
    "dateStartTime": 1368,
    "dateEndTime": 1644,
    "dynStart": null,
    "dynEnd": null,
    "useXy": 1,
    "start": 1,
    "list": 10
}
```

說明：查找人物地點為`2928` `10522` `12553` `13947` `13949`，地點類型為“人”“入仕”“職官”，指數年年介於 1368-1644 的所有人物。返回第 1-10 筆結果

```json
RequestPayload:{
    "peoplePlace":[2928,10522,12553,13947,13949],
    "placeType": ["individual","entry","officePosting"],
    "useDate": 1,
    "dateType": "dynasty",
    "dateStartTime": null,
    "dateEndTime": null,
    "dynStart": 17,
    "dynEnd": 22,
    "useXy": 1,
    "start": 1,
    "list": 10
}
```

說明：查找人物地點為`2928` `10522` `12553` `13947` `13949`，地點類型為“人”“入仕”“職官”，朝代介於 金朝 到 清朝 的所有人物。返回第 1-10 筆結果

## 輸出格式:

數據類型：`物件`
示例

```json
{
    "total":100,
    "start":1,
    "end":10,
    "data":[
        {},
        ...
        ]
}
```

| 屬性名                       | 屬性類型 | 說明                   |
| ---------------------------- | -------- | ---------------------- |
| total                        | 數字     | 數據總筆數             |
| start                        | 數字     | 當前數據開始筆數       |
| end                          | 數字     | 當前數據結束筆數       |
| data                         | 陣列     | 除授記錄列表           |
| data[`i`].PersonID           | 數字     | 人物 ID                |
| data[`i`].Name               | 字串     | 人物名，英文           |
| data[`i`].NameChn            | 字串     | 人物名，中文           |
| data[`i`].Sex                | 字串     | 人物性別               |
| data[`i`].IndexYear          | 數字     | 人物指數年             |
| data[`i`].IndexYearType      | 字串     | 指數年類型，英文       |
| data[`i`].IndexYearTypeChn   | 字串     | 指數年類型，中文       |
| data[`i`].IndexYearCode      | 字串     | 指數年類型代碼         |
| data[`i`].PlaceName          | 字串     | 地址名稱，英文         |
| data[`i`].PlaceNameChn       | 字串     | 地址名稱，中文         |
| data[`i`].PlaceAssocName     | 字串     | 地區關係人姓名，英文   |
| data[`i`].PlaceAssocChn      | 字串     | 地區關係人姓名，中文   |
| data[`i`].PlaceAssocStart    | 數字     | 地區關係開始年        |
| data[`i`].PlaceAssocEnd      | 數字     | 地區關係結束年        |
| data[`i`].PlaceType          | 字串     | 地址關係類別           |
| data[`i`].PlaceTypeDetail    | 字串     | 地址關係詳細類別，英文 |
| data[`i`].PlaceTypeDetailChn | 字串     | 地址關係詳細類別，中文 |
| data[`i`].X                  | 數字     | 人物地點經度座標       |
| data[`i`].Y                  | 數字     | 人物地點緯度座標       |
