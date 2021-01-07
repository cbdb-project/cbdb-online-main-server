# 引得系統獲取傳主生卒地及其最高行政區域編碼步驟分解（畫傳主生卒桑基圖與遷徙圖）


## 第一步：生成傳主生卒地編碼临时表，加快後續查詢效率。

出生地：若無則用籍貫代替，死亡地：若無則用卒地代替。
 
### 生成語句
 
 ``` sql
drop table if exists tj_biog_birth_and_death_addr;
create table tj_biog_birth_and_death_addr as
select bi.c_personid, bi.c_name, bi.c_name_chn, bi.c_index_year, bi.c_dy,
(
	select c_addr_id from biog_addr_data where c_personid = bi.c_personid and c_addr_id > 0 and c_addr_type in( 8,1 ) order by c_addr_type desc limit 1
) as c_birth_addr_id,
(
	select c_addr_id from biog_addr_data where c_personid = bi.c_personid and c_addr_id > 0 and c_addr_type in( 10, 9) order by c_addr_type desc limit 1
) as c_death_addr_id
from biog_main bi where c_personid > 0;
create index idx_birth_addr_id on tj_biog_birth_and_death_addr(c_birth_addr_id);
create index idx_death_addr_id on tj_biog_birth_and_death_addr(c_death_addr_id);
 ```
 
### 結果列表

| `c_personid` | `c_name` | `c_name_chn` | `c_index_year` | `c_dy` | `c_birth_addr_id` | `c_death_addr_id` | 
| ------ | ------ | ------ | ------ | ------ | ------ | ------ | 
| 10 | ChaoQianzhi | 晁謙之 | 1095 | 15 | 12889 | 12889 | 
| 562 | FanChong | 范冲 | 1067 | 15 | 13292 | 100163 | 
| 1491 | SuChi | 蘇遲 | 1096 | 15 | 12785 | 12788 | 
| 1519 | SunJin | 孫近 | 1074 | 15 | 12724 | 12922 | 
| 1762 | WangAnshi | 王安石 | 1021 | 15 | 100513 | NULL | 
 
## 第二步：生成所有生卒地對應最高行政區編碼映射對照表

`CBDB_20201110`版本數據庫提供的 `addresses` 表對照的上級地區字段 `belongs[1-5]_ID` 並不精確（數據不全或最後級並不是朝代信息），故考慮直接從 `addr_belongs_data` 表采用遞歸查找上級區域編碼的方式查詢倒數第2級的地區編碼做為“最高行政區編碼”。考慮到庫中總共有47萬多位傳主，逐條用遞歸查詢生卒地效率很低（47萬＊2次理論查詢遞歸次數），這裏我先對所有傳主生卒地進行合並匯總排重，最終只得到了`7099`個唯一地址，我們只需要建立這部分地址的映射關系，遞歸查詢上級行政區編碼即可。

> 注：遞歸查詢最高行政區編碼（倒數第2級）用到了我寫的一個mysql自定義函數，見附後。   
> 本人測試用mbp2017 16G內存，生成此映射表總耗時118秒。

### 生成語句

``` sql
drop table if exists tj_addr_belongs_mapping;
create table tj_addr_belongs_mapping as
select c_addr_id, getAddrParentId(c_addr_id) as c_addr_parent_id from (
	select c_birth_addr_id as c_addr_id from tj_biog_birth_and_death_addr where c_birth_addr_id is not null
	union 
	select c_death_addr_id as c_addr_id from tj_biog_birth_and_death_addr where c_death_addr_id is not null
) t1;
create index idx_addr_id on tj_addr_belongs_mapping(c_addr_id);
```

### 結果列表

| `c_addr_id` | `c_addr_parent_id` | 
| ------ | ------ | 
| 12889 | 12824 | 
| 13292 | 13284 | 
| 100163 | 12753 | 
| 100513 | 12907 | 


### 查詢最高級別行政區域編碼函數

```
-- 查詢最高級別行政區域編碼函數
DROP FUNCTION IF EXISTS `getAddrParentId`;
CREATE FUNCTION `getAddrParentId`(temp_addr_id INT) RETURNS INT
BEGIN
	DECLARE sParentList VARCHAR(2000);
	DECLARE sParentTemp VARCHAR(20);
	DECLARE sPrevParentAddrId VARCHAR(20);
	DECLARE sReturnAddrId VARCHAR(20);
	DECLARE error VARCHAR(20);
	DECLARE COUNT INT;
	DECLARE CONTINUE HANDLER FOR SQLSTATE '02000' SET sParentTemp = NULL; -- 异常跳出定义，重要
	SET sParentTemp = CAST(temp_addr_id AS CHAR);
	SET COUNT = 1;
	WHILE sParentTemp IS NOT NULL AND COUNT < 10 DO -- 防止死循环，最多查询10次
		SET COUNT = COUNT + 1;
		IF sParentList IS NOT NULL THEN
			SET sParentList = CONCAT(sPrevParentAddrId, ',', sParentList);
		ELSE
			SET sParentList = sPrevParentAddrId;
		END IF;
		SET sReturnAddrId = sPrevParentAddrId;
		SET sPrevParentAddrId = sParentTemp;
		SELECT c_belongs_to INTO sParentTemp FROM addr_belongs_data WHERE c_addr_id = sParentTemp AND c_belongs_to > 0 LIMIT 1;
	END WHILE;
	RETURN CAST(sReturnAddrId AS UNSIGNED);
END
```

## 第三步：生成最終的生卒地與對應最高行政區編碼表

### 生成語句

``` sql
select 
a.c_personid, c_name, c_name_chn, c_index_year, c_dy,
c_birth_addr_id, b.c_addr_parent_id as c_birth_belongs_to_addr_id,
c_death_addr_id, c.c_addr_parent_id as c_death_belongs_to_addr_id
from tj_biog_birth_and_death_addr a
left join tj_addr_belongs_mapping b on a.c_birth_addr_id = b.c_addr_id
left join tj_addr_belongs_mapping c on a.c_death_addr_id = c.c_addr_id
where c_birth_addr_id is not null;
```

### 結果列表
| `c_personid` | `c_name` | `c_name_chn` | `c_index_year` | `c_dy` | `c_birth_addr_id` | `c_birth_belongs_to_addr_id `  | `c_death_addr_id ` | `c_death_belongs_to_addr_id ` | 
| ------ | ------ | ------ | ------ | ------ | ------ | ------ |  ------ | ------ | 
| 10 | Chao Qianzhi | 晁謙之 | 1095 | 15 | 12889 | 12824 | 12889 | 12824 | 
| 562 | Fan Chong | 范冲 | 1067 | 15 | 13292 | 13284 | 100163 | 12753 | 
| 1491 | Su Chi | 蘇遲 | 1096 | 15 | 12785 | 12753 | 12788 | 12753 | 
| 1519 | Sun Jin | 孫近 | 1074 | 15 | 12724 | 12669 | 12922 | 12907 | 
| 1762 | Wang Anshi | 王安石 | 1021 | 15 | 100513 | 12907 |  |  | 


## 總結

通過以上幾步操作後，即可得到傳主生卒地與對應行政區域編碼表，再結合 `c_addr_code` 表即可查詢出每個地址對應的中文名稱與GIS坐標，以此來畫出傳生卒地桑其圖或遷徙地圖，達到可視化展示的目的。