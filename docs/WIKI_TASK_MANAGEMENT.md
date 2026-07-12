# Wiki 导入任务管理

> ⚠️ **仅限首次全量导入。** 本文描述的 `WikiMaintenanceController` / `wiki:task` 工具会**删除某 `c_textid` 的全部记录再重灌**，**不得用于增量新增／修正**。日常增量维护中文维基条目（BIOG_SOURCE_DATA）请走 mutation API，见 [ZHWIKI_SOURCE_SYNC.md](./ZHWIKI_SOURCE_SYNC.md) 与技能 [.claude/skills/mutation-api-record-editing.md](../.claude/skills/mutation-api-record-editing.md)。

Wiki 维护工具现在支持任务取消功能，包括前端界面取消和命令行管理。

## 前端取消功能

### 界面控制
- 导入开始后会显示进度条和"取消导入"按钮
- 点击取消按钮会弹出确认对话框
- 取消后任务状态变为 `cancelled`，进度条变为橙色

### 取消时机
- 任务状态为 `running` 时可以取消
- 任务完成、失败或已取消时不显示取消按钮
- 取消请求会立即设置取消标志，导入进程在下一个检查点停止

## 命令行管理

### 安装
命令已自动注册，可直接使用：

```bash
php artisan wiki:task <action> [taskId]
```

### 可用命令

#### 1. 列出所有任务
```bash
php artisan wiki:task list
```

显示最近1小时内的所有导入任务，包括：
- Task ID
- 状态 (running/completed/error/cancelled)
- 进度百分比
- 当前消息
- 开始时间

#### 2. 查看任务详情
```bash
php artisan wiki:task show <taskId>
```

显示指定任务的详细信息：
- 完整状态和进度
- 详细消息
- 数据源名称
- 开始/更新/完成时间
- 如果任务正在运行，显示取消命令提示

#### 3. 取消任务
```bash
php artisan wiki:task cancel <taskId>
```

取消指定的正在运行的任务：
- 检查任务是否存在和正在运行
- 要求确认操作
- 设置取消标志，任务将在下一个检查点停止

**强制取消（跳过确认）：**
```bash
php artisan wiki:task cancel <taskId> --force
```

**权限要求：**
由于缓存权限的限制，命令行操作需要以 www-data 组身份运行：
```bash
sg www-data -c "php artisan wiki:task cancel <taskId> --force"
```

### 使用示例

```bash
# 查看所有任务
sg www-data -c "php artisan wiki:task list"

# 查看特定任务
sg www-data -c "php artisan wiki:task show import_1762406611_68942"

# 取消运行中的任务（需要确认）
sg www-data -c "php artisan wiki:task cancel import_1762406611_68942"

# 强制取消运行中的任务（跳过确认）
sg www-data -c "php artisan wiki:task cancel import_1762406611_68942 --force"
```

## 取消机制详解

### 检查点
导入进程在以下关键点检查取消状态：
1. 开始执行任务时
2. 下载完成后，开始解析前
3. 数据库事务中，每处理 100 条记录
4. 每次批量插入前（1000条记录）

### 数据一致性
- 取消操作会触发数据库回滚
- 确保数据库状态保持一致
- 已插入的批次数据会被回滚

### 状态传播
- 前端取消：通过 AJAX 请求设置取消标志
- 命令行取消：直接修改缓存中的任务状态
- 后台进程：定期检查缓存中的取消标志

## 任务ID格式

任务ID格式：`import_{timestamp}_{sourceId}`

其中：
- `timestamp`: Unix 时间戳
- `sourceId`: 数据源ID
  - `60795`: 中文维基百科
  - `68942`: 维基数据
  - `68943`: 英文维基百科

示例：`import_1762406611_68942`

## 错误处理

### 任务不存在
```
Task 'invalid_task_id' not found.
```

### 任务未运行
```
Task 'import_1762406611_68942' is not running (status: completed).
```

### 网络错误
前端会显示网络错误信息并恢复取消按钮状态。

## 监控建议

### 定期检查
```bash
# 每分钟检查一次正在运行的任务
sg www-data -c "php artisan wiki:task list" | grep running
```

### 日志监控
导入操作会记录到 Laravel 日志中：
```bash
tail -f storage/logs/laravel.log | grep "Wiki Maintenance Operation"
```

### 长时间运行任务
如果任务运行时间过长，可以：
1. 检查任务详情确认进度
2. 检查服务器资源使用情况
3. 如有必要，取消任务重新开始

## 故障排除

### 任务卡住
如果任务显示为 `running` 但长时间无进度更新：
```bash
# 查看详情检查最后更新时间
sg www-data -c "php artisan wiki:task show <taskId>"

# 如果确认任务卡住，可以强制取消
sg www-data -c "php artisan wiki:task cancel <taskId> --force"
```

### 缓存问题
如果遇到缓存相关问题：
```bash
# 清除应用缓存
php artisan cache:clear

# 然后重新查看任务
sg www-data -c "php artisan wiki:task list"
```

### 权限问题
确保运行命令的用户有适当的权限访问缓存和日志文件。