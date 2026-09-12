# 开发指南

## 1. 环境准备
- Docker (20.10+)
- Docker Compose (2.0+)
- 推荐 OS: Linux / macOS

## 2. 项目启动
### 2.1 首次启动
```bash
# 构建并后台运行所有服务
docker compose up --build -d
```
启动后访问 `http://localhost:3000` 即可看到首页。

### 2.2 常用命令
- **重启 Web 服务**: `docker compose restart web`
- **重启后端 App**: `docker compose restart app`
- **查看日志**: `docker compose logs -f app`
- **进入容器**: `docker compose exec app bash`

## 3. 目录结构
```tree
.
├── backend/             # 后端 PHP 代码
│   ├── app/             # 业务逻辑 (Controller, Model, View)
│   ├── config/          # 配置文件 (Route, Database, App)
│   ├── support/         # 辅助类库 (Exception Handler)
│   ├── scripts/         # 初始化脚本
│   └── public/          # 静态资源入口
├── web/                 # 前端资源 (Tailwind, Nginx 配置)
├── docker/              # Docker 相关脚本
└── docs/                # 项目文档
```

## 4. 调试与排错
- **500 错误**: 检查 `backend/runtime/logs` 下的日志文件。
- **数据库连接错误**: 确保 MySQL 容器健康状态为 `healthy`。
- **搜索无结果**: 检查 Manticore 容器是否运行，以及 `init.php` 是否成功执行初始化。

### 2.3 脚本
位于 `backend/scripts/`，均通过 `docker compose exec app php scripts/<脚本名>` 运行：

- `init.php`：容器启动时自动执行，建库建表并同步基础数据到 Manticore（幂等）。
- `crawler.php`：DHT 爬虫主进程（爬虫容器的默认命令）。
- `seed_demo.php`：导入 50 条公开测试元数据（hash / 标题 / 文件名 / 大小 / 时间 / 标签）到
  MySQL 与 Manticore，导入后立即自测搜索；可重复执行且不会产生重复记录，`--clean` 可清理。
- `reload_jieba.php`：更新结巴用户词典后热加载。
- `pressure_test.php`：写入大量随机数据做性能压测。
