# Wiki 维护工具测试文档

本文档描述了 Wiki 维护工具的测试覆盖范围和运行方法。

## 测试文件

### Feature Tests

**WikiMaintenanceControllerTest.php** - Wiki 维护控制器的功能测试
- 测试认证和授权
- 测试页面渲染和内容
- 测试 URL 导入功能
- 测试进度查询功能
- 测试数据验证
- 测试错误处理功能

### Unit Tests

**WikiImportJobTest.php** - Wiki 导入任务的单元测试
- 测试 Job 类的基本属性
- 测试接口和 trait 的正确使用
- 测试序列化和反序列化

## 运行测试

### 运行所有 Wiki 相关测试
```bash
./vendor/bin/phpunit tests/Feature/WikiMaintenanceControllerTest.php
./vendor/bin/phpunit tests/Unit/WikiImportJobTest.php
```

### 运行特定测试
```bash
# 运行单个测试方法
./vendor/bin/phpunit --filter="test_unauthenticated_user_cannot_access_wiki_maintenance" tests/Feature/WikiMaintenanceControllerTest.php

# 运行所有单元测试
./vendor/bin/phpunit tests/Unit/WikiImportJobTest.php
```

## 测试覆盖范围

### Controller 测试覆盖

✅ **认证和授权**
- 未认证用户重定向到登录页面
- 认证用户可以访问页面

✅ **页面内容**
- 显示正确的数据源选项
- 显示页面标题和基本UI元素

✅ **URL 导入功能**
- 输入验证（URL格式、数据源验证）
- 成功请求返回正确响应格式
- 错误处理

✅ **进度查询**
- 不存在任务的处理
- 存在任务的数据返回
- 缓存机制测试

✅ **数据处理方法**
- 进度初始化和更新
- 记录数据准备和验证
- JSON 和 HTTP 错误消息处理
- 格式验证功能

### Job 测试覆盖

✅ **基本功能**
- Job 类属性设置
- 接口实现验证
- Trait 使用验证

✅ **队列功能**
- 序列化和反序列化
- ShouldQueue 接口实现

## 测试数据

测试使用以下数据源 ID：
- `60795` - 中文維基百科 (Wikipedia)
- `68942` - 維基數據 (Wikidata)
- `68943` - 英文維基百科 (Wikipedia)

## 注意事项

1. **数据库事务**: 测试使用 `DatabaseTransactions` trait 确保测试之间的数据隔离
2. **缓存清理**: 功能测试在 tearDown 中清理缓存
3. **Laravel 版本**: 测试适配 Laravel 5.6，使用旧的工厂语法 `factory(User::class)->create()`
4. **PHPUnit 版本**: 使用 PHPUnit 7.5，注意使用相容的断言方法

## 模拟数据示例

测试中使用的示例 JSON 数据格式：
```json
{
  "generated_at": "2025-11-05T00:00:00Z",
  "source": "Wikidata SPARQL",
  "schema_version": 1,
  "records": [
    {
      "cbdb_personid": 12345,
      "wikidata_qid": "Q123456",
      "wikipedia": {
        "zh": "司马光",
        "en": "Sima_Guang"
      }
    }
  ]
}
```

## 扩展测试

要添加新的测试：

1. **功能测试**: 在 `WikiMaintenanceControllerTest.php` 中添加新的测试方法
2. **单元测试**: 在 `WikiImportJobTest.php` 中添加新的测试方法
3. **集成测试**: 可以创建新的测试文件测试完整的导入流程

## 最佳实践

1. 每个测试方法应该只测试一个功能点
2. 使用描述性的测试方法名
3. 在测试中添加注释说明测试目的
4. 使用适当的断言方法
5. 确保测试数据的清理