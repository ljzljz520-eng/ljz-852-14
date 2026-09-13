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
| `extension` | VARCHAR(16) | 单文件资源的扩展名（多文件为空） |
| `tags` | VARCHAR(255) | 标签，逗号分隔（可空），同时同步到搜索索引支持标签检索 |
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
- **前缀匹配**: `min_prefix_len=1`，查询端自动补 `*` 的词干前缀展开可用
- **字段**:
  - `name`: 全文索引字段
  - `file_names`: 全文索引字段，种子内全部文件路径（空格拼接），支持按文件名片段检索
  - `tags`: 全文索引字段（逗号分隔的标签，参与全文检索，结果中回传）
  - `infohash`: 属性字段
  - `size_total`: 属性字段 (用于排序)
  - `created_at`: 属性字段 (用于排序)

> 老版本实时索引若缺少 `tags` / `file_names` 列或未开启 `min_prefix_len`，`init.php` / 爬虫启动 /
> `seed_demo.php` 会在 `ensureTable()` 中通过 `ALTER TABLE ... ADD COLUMN` 与
> `ALTER TABLE ... min_prefix_len='1'` 在线补齐，无需重建索引；FT 设置仅对之后写入/重写的
> 文档生效，旧文档需重新写入后才支持前缀通配。

## 3. 接口设计
主要由 `SearchController` 和 `TorrentController` 处理：
- `GET /`: 首页
- `GET /search?q=keyword`: 搜索结果页
- `GET /torrent/{infohash}`: 详情页

## 4. 前端设计
- **布局**: 基于 Tailwind CSS 的响应式布局。
- **风格**: 现代深色模式风格 (Business Theme)。
- **交互**: 服务端渲染 (SSR)，少量原生 JS 处理交互（如复制链接）。
