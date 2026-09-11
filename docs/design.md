# 详细设计文档

## 1. 数据库设计 (MySQL)

### 1.1 `torrents` 表
存储种子的核心元数据。

| 字段 | 类型 | 说明 |
| :--- | :--- | :--- |
| `infohash` | CHAR(40) | 主键，磁力链接哈希值 |
| `name` | VARCHAR(1024) | 种子名称 |
| `size_total` | BIGINT | 总大小 (字节) |
| `file_count` | INT | 文件数量 |
| `files_json` | JSON | 文件列表详情 |
| `status` | VARCHAR(32) | 状态 (active, dead, suspect, fetched) |
| `created_at` | TIMESTAMP | 创建时间 |

### 1.2 `torrent_peers` 表
记录发现种子的节点信息（用于热度分析）。

| 字段 | 类型 | 说明 |
| :--- | :--- | :--- |
| `infohash` | CHAR(40) | 关联种子 |
| `ip` | VARCHAR(45) | 节点 IP |
| `port` | INT | 端口 |
| `last_seen_at` | TIMESTAMP | 最后发现时间 |

### 1.3 `crawl_queue` 表
爬虫任务队列，管理待抓取任务。

## 2. 搜索引擎设计 (Manticore)

### 2.1 索引结构 (`torrents_rt`)
- **类型**: Real-time Index (及该索引支持实时写入)
- **分词器**: `jieba_chinese` (支持中文分词)
- **字段**:
  - `name`: 全文索引字段
  - `infohash`: 属性字段
  - `size_total`: 属性字段 (用于排序)
  - `created_at`: 属性字段 (用于排序)

## 3. 接口设计
主要由 `SearchController` 和 `TorrentController` 处理：
- `GET /`: 首页
- `GET /search?q=keyword`: 搜索结果页
- `GET /torrent/{infohash}`: 详情页

## 4. 前端设计
- **布局**: 基于 Tailwind CSS 的响应式布局。
- **风格**: 现代深色模式风格 (Business Theme)。
- **交互**: 服务端渲染 (SSR)，少量原生 JS 处理交互（如复制链接）。
