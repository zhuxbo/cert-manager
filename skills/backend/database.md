# 数据库规范（迁移/列类型/兼容性）

## MySQL 兼容性

- 兼容 MySQL 5.7，不使用 `json` 字段类型
- 数组类型字段使用 `string` 存储，由 Laravel 模型 `'array'` cast 自动 JSON 序列化

## 列类型与累加防溢出

**坑**：累加型计数列选小整数（TINYINT UNSIGNED max 255）且无硬截断，溢出抛 SQLSTATE[22003] 1264；若 catch 把异常 message 原样回写另一短字符串列，因 MySQL 异常 message 包含完整 SQL 回显（含上一次列值），形成"error 嵌套自我放大"链，列被反复写直到 VARCHAR 撑爆截断成乱码。真实案例：`cname_delegations.fail_count` 累加到 256 触发该链，把 `schedule:validate` 子进程拖到 fatal exit 255。

**规则**：

1. **累加点硬截断**：用 `min($v + 1, $cap)` 而非裸 `$v++`；$cap 取"列上限"和"业务上限"较小值（如 fail_count 取 100 — 超过没意义且远小于 TINYINT 上限）
2. **catch 写回 error/message 字段必须限长**：`mb_substr($e->getMessage(), 0, N)`，N 取列长度 ~80%（VARCHAR(255) → 200 留余量），断掉"异常 message 含 SQL 回显 → SQL 含旧列值 → 嵌套放大"链
3. **选型预留余量**：纯状态枚举（0/1/2）用 TINYINT 无妨；**累加计数即使业务上限只到 100，仍优先 SMALLINT UNSIGNED**，给抗冲击空间，除非压缩 1 字节是硬要求

## 迁移规范

- **enum vs string**：系统核心字段用 `enum` 保证约束（如 `product_type`、`status`）；插件可扩展的字段用 `string`，方便插件写入自定义值
- **优先 Laravel 系统方法**：迁移优先使用 `Schema::table` + Blueprint 方法，避免 `DB::statement` 裸 SQL
- **up 幂等**：修改表结构的迁移必须先检查当前状态（`Schema::hasColumn`/`Schema::hasTable`），避免重复执行报错
- **不写 down**：迁移只写 `up()`，不写 `down()`。生产环境不做回滚，回滚用新迁移前进修复
- **所有结构变更同步回 create 迁移**：任何 `add_/update_/drop_/rename_xxx_table` 增量迁移做的结构改动（加字段、删字段、改类型/长度/默认值/注释、加/删/重命名索引、加/删外键、改 enum 值、改字段顺序等）都必须同步回对应的 `create_yyy_table` 原迁移，让新装直接拿到最终 schema、老库继续走幂等增量。两侧字段顺序、类型、长度、默认值、nullable、索引、注释完全一致，避免新老库 schema 漂移导致 `db:structure --check` 误报
- **structure.json 不手动改**：迁移变动后发布前通过 `php artisan db:structure --export` 重新导出
- **导出前用干净测试库**：避免开发库脏数据或插件表干扰

### 迁移幂等示例

```php
// 添加字段
if (! Schema::hasColumn('orders', 'new_field')) {
    Schema::table('orders', function (Blueprint $table) {
        $table->string('new_field')->nullable()->after('existing_field');
    });
}

// 删除字段
$columns = ['col_a', 'col_b'];
$toDrop = array_filter($columns, fn ($col) => Schema::hasColumn('orders', $col));
if (! empty($toDrop)) {
    Schema::table('orders', function (Blueprint $table) use ($toDrop) {
        $table->dropColumn($toDrop);
    });
}

// 创建表
if (Schema::hasTable('new_table')) {
    return;
}
Schema::create('new_table', function (Blueprint $table) { ... });

// 修改 enum 值（扩展/缩减）
Schema::table('products', function (Blueprint $table) {
    $table->enum('product_type', ['ssl', 'codesign', 'smime', 'docsign', 'acme'])->default('ssl')->change();
});
```

---
