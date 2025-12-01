# AdminLTE v3 升级进度

## 概述

本文档记录 AdminLTE 从 v2.3.8 升级到 v3.2 的进度和说明。

## 当前状态

### 已完成

✅ **创建 AdminLTE v3 布局文件（使用 CDN）**
- `resources/views/layouts/dashboard-v3.blade.php` - 主布局文件
- `resources/views/layouts/header-v3.blade.php` - 导航栏组件
- `resources/views/layouts/sidebar-v3.blade.php` - 侧边栏组件

✅ **替换 /codes 列表页面為 v3 版本**
- `resources/views/codes/index.blade.php` 採用 AdminLTE v3 佈局（原 v3 測試頁已合併進正式路由 `/codes`）

### 使用的 CDN 资源

```html
<!-- Font Awesome 5 -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

<!-- AdminLTE v3.2 -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<!-- Bootstrap 4 -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- AdminLTE App -->
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
```

## 如何测试

### 访问页面

1. 确保你已登录系统
2. 访问页面: `http://your-domain/codes`

### 主要变化对比

#### 1. 布局结构

**v2:**
```blade
@extends('layouts.dashboard')
```

**v3:**
```blade
@extends('layouts.dashboard-v3')
```

#### 2. Box/Panel → Card

**v2:**
```html
<div class="box box-default">
    <div class="box-header with-border">
        <h3 class="box-title">标题</h3>
        <div class="box-tools pull-right">
            <button data-widget="collapse"><i class="fa fa-minus"></i></button>
        </div>
    </div>
    <div class="box-body">
        内容
    </div>
</div>
```

**v3:**
```html
<div class="card card-default">
    <div class="card-header">
        <h3 class="card-title">标题</h3>
        <div class="card-tools">
            <button data-card-widget="collapse"><i class="fas fa-minus"></i></button>
        </div>
    </div>
    <div class="card-body">
        内容
    </div>
</div>
```

#### 3. 侧边栏菜单

**v2:**
```html
<ul class="sidebar-menu">
    <li class="header">HEADER</li>
    <li class="active">
        <a href="#"><i class="fa fa-dashboard"></i> <span>Link</span></a>
    </li>
</ul>
```

**v3:**
```html
<ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview">
    <li class="nav-header">HEADER</li>
    <li class="nav-item">
        <a href="#" class="nav-link active">
            <i class="nav-icon fa fa-dashboard"></i>
            <p>Link</p>
        </a>
    </li>
</ul>
```

#### 4. 表格类名

**v2:**
```html
<table class="table table-hover table-condensed">
```

**v3:**
```html
<table class="table table-hover table-sm">
```

#### 5. 工具类

| v2 | v3 |
|----|----|
| `pull-right` | `float-right` |
| `pull-left` | `float-left` |
| `hidden-xs` | `d-none d-sm-inline` |
| `label label-*` | `badge badge-*` |

## 下一步计划

### 短期任务

1. **测试 v3 布局**
   - [ ] 测试所有导航菜单链接
   - [ ] 测试响应式布局
   - [ ] 测试用户下拉菜单
   - [ ] 测试模态框
   - [ ] 测试折叠功能

2. **迁移更多页面**
   - [ ] codes/show.blade.php
   - [ ] basicinformation/index.blade.php
   - [ ] 其他常用页面

3. **处理插件兼容性**
   - [ ] DataTables - 升级到 Bootstrap 4 版本
   - [ ] Select2 - 测试兼容性
   - [ ] DatePicker - 测试兼容性
   - [ ] iCheck - 考虑替代方案

### 中期任务

1. **批量迁移**
   - [ ] 创建自动化脚本批量替换类名
   - [ ] 迁移所有 69+ 个使用 dashboard 布局的页面

2. **自定义样式适配**
   - [ ] 检查 `resources/assets/css/styles.css` 的兼容性
   - [ ] 调整与 Bootstrap 4 冲突的样式

3. **JavaScript 更新**
   - [ ] 更新依赖 Bootstrap 3 的 JS 代码
   - [ ] 测试所有交互功能

### 长期任务

1. **完全替换 v2**
   - [ ] 将所有页面迁移到 v3
   - [ ] 移除旧的 AdminLTE v2 文件
   - [ ] 从 Bower 迁移到 npm

2. **性能优化**
   - [ ] 考虑本地化 CDN 资源
   - [ ] 优化资源加载

## 类名映射速查表

### 容器组件

| v2 | v3 |
|----|-----|
| `box` | `card` |
| `box-header` | `card-header` |
| `box-title` | `card-title` |
| `box-body` | `card-body` |
| `box-footer` | `card-footer` |
| `box-tools` | `card-tools` |
| `panel` | `card` |
| `panel-heading` | `card-header` |
| `panel-body` | `card-body` |
| `panel-footer` | `card-footer` |

### 表格

| v2 | v3 |
|----|-----|
| `table-condensed` | `table-sm` |

### 工具类

| v2 | v3 |
|----|-----|
| `pull-right` | `float-right` |
| `pull-left` | `float-left` |
| `hidden-xs` | `d-none d-sm-block` |
| `hidden-sm` | `d-none d-md-block` |
| `hidden-md` | `d-none d-lg-block` |
| `visible-xs` | `d-block d-sm-none` |

### 标签和徽章

| v2 | v3 |
|----|-----|
| `label label-default` | `badge badge-secondary` |
| `label label-primary` | `badge badge-primary` |
| `label label-success` | `badge badge-success` |
| `label label-info` | `badge badge-info` |
| `label label-warning` | `badge badge-warning` |
| `label label-danger` | `badge badge-danger` |

### 导航菜单

| v2 | v3 |
|----|-----|
| `sidebar-menu` | `nav nav-pills nav-sidebar flex-column` |
| `<li class="header">` | `<li class="nav-header">` |
| `<li>` | `<li class="nav-item">` |
| `<a>` | `<a class="nav-link">` |
| `treeview` | `has-treeview` |
| `treeview-menu` | `nav nav-treeview` |

### 数据属性

| v2 | v3 |
|----|-----|
| `data-widget="collapse"` | `data-card-widget="collapse"` |
| `data-widget="remove"` | `data-card-widget="remove"` |

### 图标

| v2 (Font Awesome 4) | v3 (Font Awesome 5) |
|---------------------|---------------------|
| `fa fa-dashboard` | `fas fa-tachometer-alt` |
| `fa fa-minus` | `fas fa-minus` |
| `fa fa-plus` | `fas fa-plus` |

## 注意事项

1. **Font Awesome 升级**
   - v2 使用 Font Awesome 4.5.0
   - v3 使用 Font Awesome 5.15.4
   - 部分图标名称已更改，需要逐一检查

2. **Bootstrap 4 变化**
   - 不再支持 IE9 及以下版本
   - Flexbox 作为默认布局系统
   - 部分组件结构有重大变化（如 input-group）

3. **兼容性**
   - v2 和 v3 可以共存
   - 通过不同的布局文件（dashboard.blade.php vs dashboard-v3.blade.php）实现隔离
   - 建议逐步迁移，而非一次性替换

## 问题和解决方案

### 已知问题

暂无

### 待确认

- [ ] 所有第三方插件的 Bootstrap 4 兼容性
- [ ] 自定义 CSS 是否需要大幅调整
- [ ] 是否所有图标都能找到对应的 FA5 版本

## 参考资源

- [AdminLTE v3 官方文档](https://adminlte.io/docs/3.2/)
- [AdminLTE v3 升级指南](https://adminlte.io/docs/3.2/upgrade-guide.html)
- [Bootstrap 4 迁移指南](https://getbootstrap.com/docs/4.6/migration/)
- [Font Awesome 4 到 5 升级](https://fontawesome.com/docs/web/setup/upgrade/)

---

**最后更新**: 2025-12-01
**当前分支**: `claude/adminlte-upgrade-v3-013XbCqYBRHQZ4LhChViYDsL`
