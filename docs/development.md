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
