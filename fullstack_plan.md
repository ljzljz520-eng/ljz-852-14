# Manticore DHT Search 全栈实施计划

## 目标与范围
- 目标：构建可搜索的 DHT 资源检索系统，包含前端展示、后端 API、搜索引擎与爬虫入库
- 范围：基础架构、前端初始化、后端初始化、数据库与文档交付

## 项目目录结构
```
manticore-search-engine/
├── backend/
│   ├── app/
│   │   ├── controller/            # 业务控制器
│   │   ├── model/                 # ORM 模型
│   │   ├── crawler/               # DHT/元数据爬虫
│   │   ├── service/               # Manticore/Redis 等服务封装
│   │   └── view/                  # 前端模板视图
│   ├── config/                    # Webman 配置
│   ├── public/                    # 静态资源入口
│   ├── scripts/                   # 运行脚本
│   ├── Dockerfile
│   └── start.php
├── web/
│   ├── src/                       # Tailwind 源码
│   ├── dist/                      # 构建产物
│   ├── package.json
│   └── tailwind.config.js
├── docker-compose.yml
└── README.md
```

## 实施阶段

### 阶段一：基础结构
- 创建或完善以下文件与配置
  - `docker-compose.yml`：定义 MySQL、Redis、Manticore、backend、frontend、crawler 服务
  - `backend/Dockerfile`：PHP 依赖与运行环境
  - `web/Dockerfile`：构建前端静态资源
  - `backend/config/*.php`：应用、路由、数据库、日志与中间件配置
  - `backend/scripts/init.php`：数据库初始化脚本
- 配置说明
  - 环境变量：`MANTICORE_HTTP`、`MANTICORE_INDEX`、数据库连接参数
  - 端口映射：前端 3000，后端 8000，Manticore 9308

### 阶段二：前端初始化
- 创建或完善以下文件与配置
  - `web/src/input.css`：Tailwind 基础样式与主题
  - `web/tailwind.config.js`：主题与扫描路径
  - `backend/app/view/layout.html`：全局布局
  - `backend/app/view/search/index.html`：首页搜索与最新资源
  - `backend/app/view/search/list.html`：搜索列表与排序
  - `backend/app/view/torrent/detail.html`：资源详情页
- 关键交付
  - 统一中文文案、统一布局与视觉风格
  - 热门标签快速搜索
  - 搜索结果排序（最新/大小）

### 阶段三：后端初始化
- 创建或完善以下文件与配置
  - `backend/app/controller/SearchController.php`：搜索与列表逻辑
  - `backend/app/controller/TorrentController.php`：详情页逻辑
  - `backend/app/service/ManticoreClient.php`：Manticore HTTP 查询与高亮
  - `backend/app/model/Torrent.php`：资源模型
  - `backend/config/route.php`：路由入口
- 关键交付
  - MySQL 兜底查询与 Manticore 搜索切换
  - 模糊查询与高亮展示

### 阶段四：数据库与文档
- 创建或完善以下文件与配置
  - `backend/scripts/init.php`：数据库表结构与索引
  - `backend/app/crawler/*`：DHT 监听、元数据解析、Redis 队列与限速
  - `backend/config/thinkorm.php`：数据库配置
  - `backend/config/redis.php`：Redis 配置
  - `README.md`：启动步骤与服务说明
- 关键交付
  - 数据写入 MySQL 并同步到 Manticore
  - Docker 一键启动与可观测性说明

## 验证计划
- 启动验证
  - 运行 `docker compose up --build`，检查容器是否全部健康
  - 验证服务端口可访问：`http://localhost:3000`、`http://localhost:8000`
- 功能验证
  - 首页输入关键词跳转搜索列表
  - 热门标签一键搜索并返回结果
  - 排序切换（最新/大小）有效
  - 详情页磁力链接可打开
  - 无结果时提示文案正确
- 数据验证
  - 爬虫写入 MySQL 后，搜索结果可被 Manticore 检索
  - 分页数据一致与总量统计正确
