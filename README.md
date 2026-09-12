# Manticore DHT 搜索引擎

![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)

基于 Webman、Manticore Search 和 DHT 爬虫构建的高性能磁力检索引擎。

## 📚 项目文档

本项目包含完整的中文文档，请查阅 `docs/` 目录：
- **[架构设计 (Architecture)](docs/architecture.md)**: 系统整体架构与技术栈说明。
- **[详细设计 (Detailed Design)](docs/design.md)**: 数据库结构与接口设计。
- **[开发指南 (Development Guide)](docs/development.md)**: 环境搭建与运行命令。
- **[测试计划 (Test Plan)](docs/testing.md)**: 测试范围与用例。
- **[用户手册 (User Manual)](docs/manual.md)**: 系统使用说明与常见问题。
- **[实施计划 (Implementation Plan)](docs/implementation_plan.md)**: 记录了解决数据库连接问题及配置修复的计划。
- **[任务列表 (Task List)](docs/task.md)**: 开发过程中的详细任务跟踪。
- **[演练记录 (Walkthrough)](docs/walkthrough.md)**: 修复过程与变更记录。

## 🚀 快速开始

### 前置要求
- Docker
- Docker Compose

### 启动项目
```bash
docker compose up --build -d
```
启动成功后，访问 [http://localhost:3000](http://localhost:3000) 即可使用。

### 导入演示数据（可选）
服务启动后，可导入 50 条公开测试元数据（Linux 镜像、开源软件、公有领域影片 / 音乐 / 书籍等，
含 hash、标题、文件名、大小、时间、标签），导入后立即可在搜索框搜到：

```bash
# 在 app 容器内执行（也可直接 php scripts/seed_demo.php，需能连通 MySQL 与 Manticore）
docker compose exec app php scripts/seed_demo.php

# 重复执行是安全的（MySQL 主键 upsert + Manticore REPLACE，不会产生重复记录）
# 清理本脚本导入的演示数据：
docker compose exec app php scripts/seed_demo.php --clean
```

所有演示记录的 infohash 均以 `deadbeef` 开头，便于识别与清理；脚本末尾会自动执行搜索自测。

## ✨ 特性
- **极速检索**: 基于 Manticore Search，亿级数据毫秒响应。
- **实时爬虫**: 内置 DHT 爬虫节点，实时发现新资源。
- **隐私安全**: 不通过 Cookie 追踪用户，无广告。
- **现代 UI**: 基于 Tailwind CSS 的响应式设计，支持深色模式。
- **中文优化**: 针对中文环境优化分词与展示。

## 🔧 技术栈
- **后端**: PHP 8.3 + Webman Framework
- **数据库**: MySQL 8.0 + Redis 7.0
- **搜索引擎**: Manticore Search
- **前端**: Nginx + HTML5 + Tailwind CSS

## 📝 开源协议
MIT License
