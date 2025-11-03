# View 一覽表

本文件整理系統目前註冊的檢視表。路徑格式為 `/view/{key}`，對應資料來源與簡要功能如下。若需調整查詢或欄位，請同步更新 `config/view_tables.php`、`config/view_table_searchable.php` 以及此文件。

| Key (`/view/{key}`) | View 名稱 | 顯示標題 | 概要 |
| --- | --- | --- | --- |
| `addresses` | `View_Address` | 地址層級檢視 | 採用資料庫 View_Address，呈現地址與最多五層隸屬關係及座標。 |
| `altname-data` | `View_AltnameData` | 別名資料檢視 | 彙整 ALTNAME_DATA 及別名類型、來源文本資訊。 |
| `assoc-data` | `View_AssociationData` | 社會關係資料檢視 | 展開 ASSOC_DATA 與親屬、機構、主題、地址等關聯資訊。 |
| `biog-addr-data` | `View_BiogAddrData` | 人物地址資料檢視 | 人物地址與地址類型、年號、來源等明細。 |
| `biog-inst-addr-data` | `View_BiogInstAddrData` | 人物/社會機構/地址資料檢視 | 將任職資料結合機構地址與座標。 |
| `biog-inst-data` | `View_BiogInstData` | 人物社會機構資料檢視 | 彙整人物任職機構、角色、期間與來源。 |
| `biog-source-data` | `View_BiogSourceData` | 人物來源資料檢視 | 人物與引用文本、頁碼、連結等來源資訊。 |
| `biog-text-data` | `View_BiogTextData` | 人物著作資料檢視 | 人物著作、角色、年份與來源文本明細。 |
| `entry-data` | `View_EntryData` | 人物入仕資料檢視 | 入仕經歷與親屬、機構、年號等關聯資訊。 |
| `event-addr-data` | `View_EventAddrData` | 人物事件地址檢視 | 事件地址、干支、年號與座標等資訊。 |
| `events-data` | `View_EventData` | 人物事件資料檢視 | 人物事件代碼、角色、年份、來源等明細。 |
| `kin-addr-data` | `View_KinAddrData` | 人物親屬資料檢視 | 親屬人物、稱謂、來源及補充說明。 |
| `people-data` | `View_PeopleData` | 人物基本資料檢視 | 人物索引年、族群、年號、朝代等基礎屬性。 |
| `people-addr-data` | `View_PeopleAddrData` | 人物索引地址檢視 | 人物索引地址與地址類型描述、座標。 |
| `posessions-data` | `View_PossessionsData` | 人物財產資料檢視 | 財產行為、度量、年號與來源資訊。 |
| `posessions-addr-data` | `View_PossessionsAddrData` | 人物財產地址檢視 | 財產資料附帶地址名稱與座標。 |
| `posting-addr-data` | `View_PostingAddrData` | 任官地址資料檢視 | 任官記錄與對應地址名稱。 |
| `posting-office-data` | `View_PostingOfficeData` | 任官職務資料檢視 | 任官職務、年號、任命與來源明細。 |
| `status-data` | `View_StatusData` | 人物身份資料檢視 | 身份變動、年號區間、來源文本與備註。 |

> 若需新增檢視，請確保 `config/view_tables.php`、`config/view_table_searchable.php`、側邊欄及本文件同步更新。
