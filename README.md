# cbdb-online-main-server

按照[旧版本录入系统](http://cbdb.fas.harvard.edu/cbdbc/cbdbedit)重构，技术选型Laravel + Mysql + Vuejs + webpack （mix）

采用[Laravel 5.4](https://laravel.com/docs/5.4)框架

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

问题：
1. event表的问题，code null
2. 财产表，社会机构

###
License: [CC BY-NC-SA](https://creativecommons.org/licenses/by-nc-sa/4.0/) 
