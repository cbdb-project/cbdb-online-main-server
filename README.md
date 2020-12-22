# cbdb-online-main-server

按照[旧版本录入系统](http://cbdb.fas.harvard.edu/cbdbc/cbdbedit)重构，技术选型Laravel + Mysql + Vuejs + webpack （mix）

更新至[Laravel 5.5](https://laravel.com/docs/5.5)框架

### CBDB inputting system URL

https://input.cbdb.fas.harvard.edu:81/basicinformation

或

http://47.114.119.106:8000/basicinformation

### Database migrations

数据库配置文件在.env中

migration 是laravel提供的一个数据库迁移功能，可以方便的在新环境上迁移数据。

```bash
php artisan make:migration creat_tasks_table --create=tasks
```
参考文档[migrations](http://d.laravel-china.org/docs/5.4/migrations)

### 验证邮箱

laravel自带的邮箱服务对国内支持不太好，本项目倾向使用[sendcloud](https://github.com/NauxLiu/Laravel-SendCloud)服务

### 用户验证

执行如下命令，使用laravel自带的用户验证功能

```bash
php artisan make:auth
```

参考文档[authentication](http://d.laravel-china.org/docs/5.4/authentication)

### 信息提示 flash

网站的各种提示使用[flash](https://github.com/laracasts/flash)

### 表单验证

表单验证会是本项目的重点之一，保证用户提交的信息准确无误

参考文档[validation](http://d.laravel-china.org/docs/5.4/validation)

### Eloquent ORM

Laravel 的 Eloquent ORM 提供了漂亮、简洁的 ActiveRecord 实现来和数据库进行交互。每个数据库表都有一个对应的「模型」可用来跟数据表进行交互。你可以通过模型查询数据表内的数据，以及将记录添加到数据表中。

本项目的数据库操作都会采取这种方式。

参考文档[eloquent](http://d.laravel-china.org/docs/5.4/eloquent)

### 控制器

使用如下命令创建控制器

```bash
php artisan make:controller BiogBasicInformationController --resource
```

使用下面命令查看路由

```
php artisan route:list
```

创建验证Request
```
php artisan make:request BiogBasicInformationRequest
```


参考文档[controllers](http://d.laravel-china.org/docs/5.4/controllers)

### webpack打包

修改resource/assets/js/bootstrape和resource/assets/css/app.scss
执行npm run dev

### vue
vue模板放在`resource\assets\js\components`
在`bootstrap.js`注册
npm run dev

### 已处理数据库表格
BIOG_MAIN
DYNASTIES
CHORONYM_CODES

### passport API认证
http://d.laravel-china.org/docs/5.4/passport#frontend-quickstart

### dingo API

### 对数据库的修改

**migrate 设置主键 寻找解决方法**

1. 把BIOG_MAIN的c_name添加binary属性，用于区分大小写查询

### 注意：
1. 官名包含 `POSTED_TO_OFFICE_DATA` `POSTED_TO_ADDR_DATA`

### 优化：
1. 添加提示，保存错误提示，尤其是操作数据库的提示

### 构建API资源服务

参考文档[controllers](http://d.laravel-china.org/docs/5.5/eloquent-resources)

创建API资源控制器，如人物主要信息

```sh
php artisan make:controller Api/BiogMainController --resource
```

在api.php里添加路由

```php
Route::resource('biog', 'Api\BiogMainController', ['name' => [
        'show' => 'biog.show',
        'create' => 'biog.create',
        'edit' => 'biog.edit',
        'update' => 'biog.update',
        'index' => 'biog.index',
    ]]);
```

包含了对改资源的操作

创建API RESOURCE格式化函数
```sh
php artisan make:resource BiogMain
```

使用如下：
修改BiogMain的toArray方法

```php
public function toArray($request)
    {
        return [
            'c_name_chn' => $this->c_name_chn,
            'source_count' => $this->source_count,
        ];
    }
```

在controller中

```php
public function show($id)
    {
        $data = $this->biogMainRepository->byPersonId($id);
        return new BiogMain($data);
    }
```

其他的方法可完全参照对应Controller的方法

批量查询使用API Resource Collection功能

```sh
php artisan make:resource BiogCollection
```

Api相关代码在
`app/Http/Controllers/Api`和`app/Http/Resources`和`routes/api.php`当中

~~### 修改通知~~

~~在此文件中：~~

~~cbdb-online-main-server/resources/views/layouts/dashboard.blade.php~~

~~修改 `<div class="callout callout-warning">` 和 `</div>` 中间的内容。如需要分段，则用 `<p>` 标签。~~
        
~~实例参见 old-server 分支：~~

~~https://github.com/cbdb-project/cbdb-online-main-server/blob/old-server/resources/views/layouts/dashboard.blade.php~~


### 首页迁移（503 page）

建立 cbdb-online-main-server/resources/views/errors/503.blade.php

503.blade.php 的內容形如

```<html>
<h3>
We will update our server from 10:00am to 12:00pm on 11/16. The inputting service will be closed for 2 hours. We apologize for this inconvenience. CBDB Team 2018.11.14
</h3>
</html>
```

如果相关目录或者文件缺失，请直接新建

编辑 503.blade.php 后，运行 php artisan down 即可开启维护模式

### 将更改应用于服务器（暂停和启动程式）

```
php artisan down

php artisan up
```

记得不要在前面加 sudo。据群超：docker 下没有 sudo 的权限，如果使用 laradock 的环境，sudo 命令将不会成功

### 關閉服務和打開服務(Centos)

STOP:

```
cd /.../cbdb-online-main-server

php artisan down

systemctl stop docker

init 0
```

START:

```
cd /.../laradock

docker-compose up -d nginx mysql

cd /.../cbdb-online-main-server

php artisan up
```

### 当数据库的 datadupm 中有 UTF8MB4 字符集的字符时（比如使用 Navicat 时）

导入的时候，务必打开 .sql 文件，检查文件头是否有 SET NAMES utf8mb4。

如果没有，请手动添加。

### 合并分支与 continuous development

1. 合并分支时，解决 conflict 问题之后，在 Pull requests 界面中使用 Squash and merge 按钮进行分支合并。

2. 合并之后，在服务器环境中的 /.../cbdb-online-main-server 下运行 git pull 即可。建议 pull 之后重启一次。

### 不使用 git pull 更新本地录入系统

先备份本地 .env 文件，以及 vendor、logs 文件夹，将前述三个文件/文件夹复制至从 github 下载的 CBDB 在线录入系统最新源代码解压后的根目录即可。

### path for API codes

https://github.com/cbdb-project/cbdb-online-main-server/tree/develop/app/Http/Controllers/Api

### 如何將 CBDB 部署於 Windows 本地環境

Contributed by [Chengxi YAN](https://github.com/yanchengxi)

https://github.com/cbdb-project/cbdb-online-main-server/issues/59

### How to setup TLS/SSL protocol for CBDB online inputting system

Contributed by [Yawei Sun](https://github.com/yaweisun)

https://github.com/cbdb-project/cbdb-online-main-server/issues/116

