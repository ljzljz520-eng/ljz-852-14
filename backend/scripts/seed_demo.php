<?php

/**
 * 模拟索引导入脚本（演示 / 冒烟测试用）
 *
 * 用途：
 *   向 MySQL（torrents 表）和 Manticore（torrents_rt 实时索引）导入一批
 *   公开测试元数据（Linux 发行版镜像、开源软件、公有领域影片 / 音乐 / 书籍等），
 *   每条记录包含：infohash、标题、文件名(列表)、大小、发布时间、标签。
 *   导入完成后立即执行若干条搜索自测，确认搜索引擎“导入即可搜到”。
 *
 * 幂等性：
 *   - MySQL 使用 INSERT ... ON DUPLICATE KEY UPDATE（infohash 为主键）；
 *   - Manticore 使用 REPLACE INTO（文档 id 由 infohash 确定性派生）；
 *   因此脚本可任意重复执行，不会产生重复记录。
 *   所有演示 hash 均以固定前缀 deadbeef 开头，便于识别与清理。
 *
 * 用法：
 *   php scripts/seed_demo.php          # 导入（重复执行安全）
 *   php scripts/seed_demo.php --clean  # 删除本脚本导入的全部演示数据
 *
 * 环境变量与 crawler/init 脚本一致：
 *   DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD
 *   MANTICORE_HTTP MANTICORE_INDEX
 */

require_once __DIR__ . '/../vendor/autoload.php';

use app\crawler\Db;
use app\crawler\TorrentIndexer;
use app\service\ManticoreClient;

const MB = 1024 * 1024;

/**
 * 演示数据集（50 条，全部为开源 / 公有领域题材的虚构元数据）。
 * 单文件条目使用 file + size；多文件条目使用 files[]，size_total 自动求和。
 */
$seed = [
    // ---------- 操作系统镜像（单文件） ----------
    ['name' => 'Ubuntu 24.04 LTS Noble Numbat Desktop amd64 ISO', 'file' => 'ubuntu-24.04-desktop-amd64.iso', 'size' => 5849 * MB, 'ts' => '2024-04-25 14:30:00', 'tags' => 'Linux,Ubuntu,操作系统,安装镜像'],
    ['name' => 'Ubuntu 22.04.4 LTS Desktop amd64 ISO', 'file' => 'ubuntu-22.04.4-desktop-amd64.iso', 'size' => 4751 * MB, 'ts' => '2024-03-01 09:15:00', 'tags' => 'Linux,Ubuntu,操作系统,安装镜像'],
    ['name' => 'Debian 12.6.0 amd64 netinst ISO', 'file' => 'debian-12.6.0-amd64-netinst.iso', 'size' => 658 * MB, 'ts' => '2024-07-13 10:00:00', 'tags' => 'Linux,Debian,操作系统,安装镜像'],
    ['name' => 'Fedora Workstation 40-1.14 x86_64 Live ISO', 'file' => 'Fedora-Workstation-Live-x86_64-40-1.14.iso', 'size' => 2160 * MB, 'ts' => '2024-04-17 16:45:00', 'tags' => 'Linux,Fedora,操作系统,桌面'],
    ['name' => 'Fedora Server 39 x86_64 DVD ISO', 'file' => 'Fedora-Server-dvd-x86_64-39-1.5.iso', 'size' => 2540 * MB, 'ts' => '2024-01-20 11:20:00', 'tags' => 'Linux,Fedora,操作系统,服务器'],
    ['name' => 'CentOS Stream 9 x86_64 boot ISO 20240603', 'file' => 'CentOS-Stream-9-latest-x86_64-boot.iso', 'size' => 812 * MB, 'ts' => '2024-06-04 08:00:00', 'tags' => 'Linux,CentOS,操作系统,服务器'],
    ['name' => 'Rocky Linux 9.4 x86_64 DVD ISO', 'file' => 'Rocky-9.4-x86_64-dvd.iso', 'size' => 10240 * MB, 'ts' => '2024-05-09 13:30:00', 'tags' => 'Linux,Rocky,操作系统,服务器'],
    ['name' => 'AlmaLinux 9.4 x86_64 boot ISO', 'file' => 'AlmaLinux-9.4-x86_64-boot.iso', 'size' => 788 * MB, 'ts' => '2024-05-06 15:10:00', 'tags' => 'Linux,AlmaLinux,操作系统,服务器'],
    ['name' => 'Arch Linux 2024.09.01 x86_64 bootstrap tarball', 'file' => 'archlinux-bootstrap-x86_64.tar.zst', 'size' => 149 * MB, 'ts' => '2024-09-01 07:05:00', 'tags' => 'Linux,Arch,操作系统,极简'],
    ['name' => 'openSUSE Leap 15.6 x86_64 NET ISO', 'file' => 'openSUSE-Leap-15.6-NET-x86_64-Media.iso', 'size' => 198 * MB, 'ts' => '2024-06-12 12:00:00', 'tags' => 'Linux,openSUSE,操作系统'],
    ['name' => 'Linux Mint 22 Wilma Cinnamon 64bit ISO', 'file' => 'linuxmint-22-cinnamon-64bit.iso', 'size' => 2870 * MB, 'ts' => '2024-07-27 18:25:00', 'tags' => 'Linux,Mint,操作系统,桌面'],
    ['name' => 'Raspberry Pi OS 64bit Bookworm 2024-07-04', 'file' => '2024-07-04-raspios-bookworm-arm64.img.xz', 'size' => 1090 * MB, 'ts' => '2024-07-04 10:40:00', 'tags' => 'Linux,树莓派,ARM,嵌入式'],

    // ---------- 开源软件（单文件） ----------
    ['name' => 'Blender 4.2.0 LTS Linux x64 tar.xz', 'file' => 'blender-4.2.0-linux-x64.tar.xz', 'size' => 248 * MB, 'ts' => '2024-07-16 14:00:00', 'tags' => '开源软件,3D,Blender,建模'],
    ['name' => 'LibreOffice 24.2.5 Linux x86-64 RPM tar.gz', 'file' => 'LibreOffice_24.2.5_Linux_x86-64_rpm.tar.gz', 'size' => 302 * MB, 'ts' => '2024-08-12 09:30:00', 'tags' => '开源软件,办公,LibreOffice'],
    ['name' => 'GIMP 2.10.38 macOS arm64 dmg', 'file' => 'gimp-2.10.38-arm64.dmg', 'size' => 186 * MB, 'ts' => '2024-05-04 17:55:00', 'tags' => '开源软件,图像,GIMP,修图'],
    ['name' => 'Inkscape 1.3.2 macOS x86_64 dmg', 'file' => 'Inkscape-1.3.2_x86_64.dmg', 'size' => 148 * MB, 'ts' => '2024-05-25 16:20:00', 'tags' => '开源软件,矢量图,Inkscape'],
    ['name' => 'Krita 5.2.6 Linux x86_64 AppImage', 'file' => 'krita-5.2.6-x86_64.appimage', 'size' => 215 * MB, 'ts' => '2024-09-09 20:10:00', 'tags' => '开源软件,绘画,Krita,插画'],
    ['name' => 'OBS Studio 30.1.2 macOS dmg', 'file' => 'OBS-Studio-30.1.2-macos.dmg', 'size' => 177 * MB, 'ts' => '2024-06-18 11:45:00', 'tags' => '开源软件,直播,录屏,OBS'],
    ['name' => 'Audacity 3.6.2 Linux x86_64 AppImage', 'file' => 'audacity-linux-3.6.2-x64.AppImage', 'size' => 86 * MB, 'ts' => '2024-07-30 19:00:00', 'tags' => '开源软件,音频,Audacity,剪辑'],
    ['name' => 'VLC media player 3.0.21 source tar.xz', 'file' => 'vlc-3.0.21.tar.xz', 'size' => 26 * MB, 'ts' => '2024-06-25 10:35:00', 'tags' => '开源软件,播放器,VLC'],
    ['name' => 'Godot Engine 4.3 stable linux x86_64 zip', 'file' => 'Godot_v4.3-stable_linux.x86_64.zip', 'size' => 52 * MB, 'ts' => '2024-08-15 15:00:00', 'tags' => '开源软件,游戏引擎,Godot,开发'],
    ['name' => 'Node.js v22.9.0 linux x64 tar.xz', 'file' => 'node-v22.9.0-linux-x64.tar.xz', 'size' => 27 * MB, 'ts' => '2024-09-10 21:30:00', 'tags' => '开源软件,Node.js,JavaScript,运行时'],
    ['name' => 'Python 3.12.6 source release tgz', 'file' => 'Python-3.12.6.tgz', 'size' => 93 * MB, 'ts' => '2024-09-06 22:15:00', 'tags' => '开源软件,Python,编程语言'],
    ['name' => 'Git for Windows 2.46.1 64-bit portable zip', 'file' => 'PortableGit-2.46.1-64-bit.7z.exe.zip', 'size' => 54 * MB, 'ts' => '2024-08-22 08:50:00', 'tags' => '开源软件,Git,版本控制,Windows'],
    ['name' => 'FFmpeg 7.0.2 amd64 static build tar.xz', 'file' => 'ffmpeg-release-amd64-static.tar.xz', 'size' => 38 * MB, 'ts' => '2024-08-24 13:05:00', 'tags' => '开源软件,FFmpeg,音视频,命令行'],
    ['name' => 'Docker Desktop 4.34.2 Mac Apple Chip dmg', 'file' => 'Docker.dmg', 'size' => 612 * MB, 'ts' => '2024-08-29 09:00:00', 'tags' => '开源软件,Docker,容器,macOS'],

    // ---------- 公有领域影片 / 音乐 / 素材（多文件合集） ----------
    ['name' => 'Big Buck Bunny 大雄兔 公有领域动画短片合集 1080p', 'ts' => '2024-05-02 12:00:00', 'tags' => '公有领域,动画,电影,Blender,短片', 'files' => [
        ['path' => 'BigBuckBunny-1080p/big_buck_bunny_1080p.mp4', 'size' => 762 * MB],
        ['path' => 'BigBuckBunny-1080p/big_buck_bunny_720p.mp4', 'size' => 410 * MB],
        ['path' => 'BigBuckBunny-1080p/subs/en.srt', 'size' => 72 * 1024],
        ['path' => 'BigBuckBunny-1080p/README.txt', 'size' => 4096],
    ]],
    ['name' => 'Sintel 辛特尔 Blender 开源奇幻短片 多语字幕版', 'ts' => '2024-02-18 18:30:00', 'tags' => '公有领域,动画,奇幻,Blender', 'files' => [
        ['path' => 'Sintel/sintel-2048-surround.mp4', 'size' => 1350 * MB],
        ['path' => 'Sintel/sintel-1024.mp4', 'size' => 712 * MB],
        ['path' => 'Sintel/subs/zh.srt', 'size' => 88 * 1024],
        ['path' => 'Sintel/subs/en.srt', 'size' => 80 * 1024],
    ]],
    ['name' => 'Tears of Steel 钢铁之泪 Blender 科幻短片合集', 'ts' => '2024-03-22 20:45:00', 'tags' => '公有领域,科幻,电影,Blender', 'files' => [
        ['path' => 'TearsOfSteel/mov/tears_of_steel_1080p.mov', 'size' => 1820 * MB],
        ['path' => 'TearsOfSteel/webm/tears_of_steel_720p.webm', 'size' => 486 * MB],
        ['path' => 'TearsOfSteel/LICENSE.txt', 'size' => 2048],
    ]],
    ['name' => 'Blender 开源电影短片三部合集 4K 重制版', 'ts' => '2024-08-01 10:10:00', 'tags' => '开源电影,Blender,动画,科幻,合集', 'files' => [
        ['path' => 'blender-open-movies/ElephantsDream-4k.mkv', 'size' => 2100 * MB],
        ['path' => 'blender-open-movies/BigBuckBunny-4k.mkv', 'size' => 2450 * MB],
        ['path' => 'blender-open-movies/Sintel-4k.mkv', 'size' => 3100 * MB],
        ['path' => 'blender-open-movies/TearsOfSteel-4k.mkv', 'size' => 2680 * MB],
    ]],
    ['name' => 'Night of the Living Dead 活死人之夜 1968 公有领域修复版', 'ts' => '2023-11-05 23:00:00', 'tags' => '公有领域,老电影,恐怖,黑白片', 'files' => [
        ['path' => 'NightOfTheLivingDead1968/notld-1968-restored-1080p.mp4', 'size' => 1640 * MB],
        ['path' => 'NightOfTheLivingDead1968/notld-1968-restored-480p.avi', 'size' => 690 * MB],
        ['path' => 'NightOfTheLivingDead1968/cover.jpg', 'size' => 320 * 1024],
    ]],
    ['name' => 'Nosferatu 诺斯费拉图 1922 默片公有领域配乐版', 'ts' => '2024-01-12 21:25:00', 'tags' => '公有领域,默片,吸血鬼,经典电影', 'files' => [
        ['path' => 'Nosferatu1922/nosferatu-1922-720p.mp4', 'size' => 980 * MB],
        ['path' => 'Nosferatu1922/score/nosferatu-score.flac', 'size' => 420 * MB],
        ['path' => 'Nosferatu1922/README.md', 'size' => 1500],
    ]],

    // ---------- 公有领域古典 / 爵士音乐 ----------
    ['name' => 'Beethoven 贝多芬交响曲全集 公有领域录音 Vol.1-9', 'ts' => '2024-04-09 08:20:00', 'tags' => '古典音乐,贝多芬,公有领域,交响曲,无损', 'files' => [
        ['path' => 'Beethoven-Symphonies/sym1-cmajor.flac', 'size' => 288 * MB],
        ['path' => 'Beethoven-Symphonies/sym5-cminor.flac', 'size' => 332 * MB],
        ['path' => 'Beethoven-Symphonies/sym9-dminor.flac', 'size' => 724 * MB],
        ['path' => 'beethoven_cover.png', 'size' => 2 * MB],
    ]],
    ['name' => 'Mozart 莫扎特钢琴协奏曲精选 公有领域演绎合集', 'ts' => '2024-06-16 14:40:00', 'tags' => '古典音乐,莫扎特,钢琴,公有领域', 'files' => [
        ['path' => 'Mozart-Piano/piano-concerto-20.mp3', 'size' => 48 * MB],
        ['path' => 'Mozart-Piano/piano-concerto-21.mp3', 'size' => 52 * MB],
        ['path' => 'Mozart-Piano/piano-concerto-23.mp3', 'size' => 46 * MB],
        ['path' => 'Mozart-Piano/playlist.m3u', 'size' => 256],
    ]],
    ['name' => 'Bach 巴赫管风琴作品 Musopen 公有领域录音集', 'ts' => '2024-07-02 09:55:00', 'tags' => '古典音乐,巴赫,管风琴,公有领域', 'files' => [
        ['path' => 'Bach-Organ/toccata-d-minor-bwv565.flac', 'size' => 42 * MB],
        ['path' => 'Bach-Organ/passacaglia-bwv582.flac', 'size' => 58 * MB],
        ['path' => 'Bach-Organ/fugue-bwv542.mp3', 'size' => 18 * MB],
    ]],
    ['name' => 'Public Domain Jazz Collection Vol.1 早期爵士公有领域录音', 'ts' => '2025-01-10 16:00:00', 'tags' => '爵士乐,公有领域,音乐合集,老唱片', 'files' => [
        ['path' => 'PD-Jazz-Vol1/01-rhapsody-1920s.flac', 'size' => 36 * MB],
        ['path' => 'PD-Jazz-Vol1/02-dixieland-night.flac', 'size' => 31 * MB],
        ['path' => 'PD-Jazz-Vol1/03-harlem-strut.flac', 'size' => 29 * MB],
        ['path' => 'PD-Jazz-Vol1/art/cover.jpg', 'size' => 1 * MB],
    ]],

    // ---------- 开放素材 / 数据 ----------
    ['name' => 'Open Game Art 像素游戏素材合集 64x64 CC0 资源包', 'ts' => '2024-09-20 11:11:00', 'tags' => '游戏素材,像素风,CC0,开放素材', 'files' => [
        ['path' => 'oga-pixel-pack/tilesets/dungeon.png', 'size' => 4 * MB],
        ['path' => 'oga-pixel-pack/sprites/hero.png', 'size' => 2 * MB],
        ['path' => 'oga-pixel-pack/sprites/enemies.png', 'size' => 3 * MB],
        ['path' => 'oga-pixel-pack/LICENSE-CC0.txt', 'size' => 1024],
    ]],
    ['name' => 'Kenney Game UI Assets 游戏界面素材包 CC0 完整版', 'ts' => '2024-10-11 15:35:00', 'tags' => '游戏素材,UI,CC0,Kenney', 'files' => [
        ['path' => 'kenney-ui-pack/PNG/button-blue.png', 'size' => 128 * 1024],
        ['path' => 'kenney-ui-pack/SVG/checkbox.svg', 'size' => 8 * 1024],
        ['path' => 'kenney-ui-pack/PNG@2x/button-blue@2x.png', 'size' => 256 * 1024],
        ['path' => 'kenney-ui-pack/preview.png', 'size' => 800 * 1024],
    ]],
    ['name' => 'Wikimedia Commons 公有领域历史老照片精选集', 'ts' => '2025-02-22 10:05:00', 'tags' => '历史,老照片,公有领域,图片,维基', 'files' => [
        ['path' => 'PD-history-photos/1900-street.jpg', 'size' => 6 * MB],
        ['path' => 'PD-history-photos/1920-airshow.jpg', 'size' => 7 * MB],
        ['path' => 'PD-history-photos/1936-olympics.jpg', 'size' => 8 * MB],
        ['path' => 'PD-history-photos/METADATA.csv', 'size' => 12 * 1024],
    ]],
    ['name' => 'OpenStreetMap 中国主要城市样例数据 PBF', 'ts' => '2025-03-15 09:00:00', 'tags' => '地图,开放数据,OSM,中国,数据集', 'files' => [
        ['path' => 'osm-cn-samples/beijing-sample.osm.pbf', 'size' => 86 * MB],
        ['path' => 'osm-cn-samples/shanghai-sample.osm.pbf', 'size' => 74 * MB],
        ['path' => 'osm-cn-samples/guangzhou-sample.osm.pbf', 'size' => 58 * MB],
    ]],
    ['name' => '维基百科中文离线子集 2025-03 zim 演示数据', 'ts' => '2025-04-01 12:00:00', 'tags' => '维基百科,离线,中文,知识库,数据集', 'files' => [
        ['path' => 'wikipedia-zh-sample/wikipedia_zh_all_mini.zim', 'size' => 420 * MB],
        ['path' => 'wikipedia-zh-sample/index.idx', 'size' => 18 * MB],
        ['path' => 'wikipedia-zh-sample/SHA256SUMS', 'size' => 512],
    ]],
    ['name' => 'Common Crawl 中文网页抓取样本 WARC 100 段', 'ts' => '2025-05-19 22:30:00', 'tags' => '网页抓取,语料,开放数据,CommonCrawl', 'files' => [
        ['path' => 'cc-sample/CC-MAIN-2025-05-00000.warc.gz', 'size' => 118 * MB],
        ['path' => 'cc-sample/CC-MAIN-2025-05-00001.warc.gz', 'size' => 121 * MB],
        ['path' => 'cc-sample/CC-MAIN-2025-05-00002.warc.gz', 'size' => 115 * MB],
        ['path' => 'cc-sample/manifest.json', 'size' => 4 * 1024],
    ]],

    // ---------- 公有领域书籍 ----------
    ['name' => 'Project Gutenberg English Top 100 古登堡计划英文经典电子书合集', 'ts' => '2024-12-03 19:00:00', 'tags' => '电子书,英文,公有领域,古登堡,文学', 'files' => [
        ['path' => 'gutenberg-top100/part-01-20.epub.zip', 'size' => 24 * MB],
        ['path' => 'gutenberg-top100/part-21-40.epub.zip', 'size' => 26 * MB],
        ['path' => 'gutenberg-top100/part-41-60.epub.zip', 'size' => 22 * MB],
        ['path' => 'gutenberg-top100/part-61-80.epub.zip', 'size' => 25 * MB],
        ['path' => 'gutenberg-top100/part-81-100.epub.zip', 'size' => 23 * MB],
        ['path' => 'gutenberg-top100/INDEX.txt', 'size' => 8 * 1024],
    ]],

    // ---------- 中文古典名著（公有领域文本 / 朗读） ----------
    ['name' => '三国演义 原文朗读 有声书合集 120回 MP3', 'ts' => '2025-06-08 08:00:00', 'tags' => '有声书,三国演义,四大名著,古典文学,中文', 'files' => [
        ['path' => 'sanguoyanyi-audio/001-宴桃园豪杰三结义.mp3', 'size' => 18 * MB],
        ['path' => 'sanguoyanyi-audio/002-张翼德怒鞭督邮.mp3', 'size' => 17 * MB],
        ['path' => 'sanguoyanyi-text/sanguoyanyi.txt', 'size' => 4 * MB],
        ['path' => 'sanguoyanyi-text/chapters.json', 'size' => 64 * 1024],
    ]],
    ['name' => '水浒传 原文朗读 有声书合集 100回 MP3', 'ts' => '2025-06-20 08:30:00', 'tags' => '有声书,水浒传,四大名著,古典文学,中文', 'files' => [
        ['path' => 'shuihu-audio/001-张天师祈禳瘟疫.mp3', 'size' => 19 * MB],
        ['path' => 'shuihu-audio/002-王教头私走延安府.mp3', 'size' => 18 * MB],
        ['path' => 'shuihu-text/shuihuzhuan.txt', 'size' => 5 * MB],
    ]],
    ['name' => '西游记 原文朗读 有声书合集 100回 MP3', 'ts' => '2025-07-01 09:00:00', 'tags' => '有声书,西游记,四大名著,古典文学,神话', 'files' => [
        ['path' => 'xiyouji-audio/001-灵根育孕源流出.mp3', 'size' => 20 * MB],
        ['path' => 'xiyouji-audio/002-悟彻菩提真妙理.mp3', 'size' => 19 * MB],
        ['path' => 'xiyouji-text/xiyouji.txt', 'size' => 5 * MB],
    ]],
    ['name' => '红楼梦 原文朗读 有声书合集 120回 FLAC 无损版', 'ts' => '2025-07-14 09:30:00', 'tags' => '有声书,红楼梦,四大名著,古典文学,无损', 'files' => [
        ['path' => 'hongloumeng-audio/001-甄士隐梦幻识通灵.flac', 'size' => 88 * MB],
        ['path' => 'hongloumeng-audio/002-贾夫人仙逝扬州城.flac', 'size' => 90 * MB],
        ['path' => 'hongloumeng-text/hongloumeng.txt', 'size' => 6 * MB],
    ]],

    // ---------- 开源游戏 ----------
    ['name' => '0 A.D. Alpha 27 开源即时战略游戏 多平台安装合集', 'ts' => '2024-06-22 17:45:00', 'tags' => '开源游戏,即时战略,0AD,跨平台,合集', 'files' => [
        ['path' => '0ad-a27/0ad-0.0.27-alpha-unix-build.tar.xz', 'size' => 1450 * MB],
        ['path' => '0ad-a27/0ad-0.0.27-alpha-win64.exe', 'size' => 1520 * MB],
        ['path' => '0ad-a27/0ad-0.0.27-alpha-osx64.dmg', 'size' => 1490 * MB],
        ['path' => '0ad-a27/LICENSE.txt', 'size' => 18 * 1024],
    ]],
    ['name' => 'Xonotic 0.8.6 开源竞技场射击游戏 跨平台整合包', 'ts' => '2024-05-30 13:20:00', 'tags' => '开源游戏,FPS,射击,Xonotic,跨平台', 'files' => [
        ['path' => 'xonotic-0.8.6/xonotic-0.8.6.zip', 'size' => 1640 * MB],
        ['path' => 'xonotic-0.8.6/xonotic-0.8.6-linux-x86_64.zip', 'size' => 980 * MB],
        ['path' => 'xonotic-0.8.6/COPYING', 'size' => 35 * 1024],
    ]],
    ['name' => 'Battle for Wesnoth 韦诺之战 1.18 开源回合制战棋游戏素材合集', 'ts' => '2025-08-25 16:40:00', 'tags' => '开源游戏,回合制,战棋,韦诺之战,策略', 'files' => [
        ['path' => 'wesnoth-1.18/wesnoth-1.18.0.tar.bz2', 'size' => 520 * MB],
        ['path' => 'wesnoth-1.18/data/campaigns/SONG_OF_FIRE.tar', 'size' => 42 * MB],
        ['path' => 'wesnoth-1.18/data/themes/default.cfg', 'size' => 12 * 1024],
    ]],
];

// ---------------------------------------------------------------------------
// 基础设施连接
// ---------------------------------------------------------------------------

$db = Db::connectFromEnv();
$db->waitReady(60, 1000);
$db->ensureSchema(); // 幂等：老库缺 tags / file_count 等列时自动补齐

$indexer = new TorrentIndexer();
$indexer->ensureTable(); // 幂等：老索引缺 file_names/tags 列、未开前缀或 CJK 字符集时在线 ALTER 补齐

// 通过反射取出 Db 内部的 PDO，用于本脚本专用的固定时间戳写入与清理语句。
$reflectionPdo = new ReflectionProperty(Db::class, 'pdo');
$reflectionPdo->setAccessible(true);
$pdo = $reflectionPdo->getValue($db);

$manticoreTable = getenv('MANTICORE_INDEX') ?: 'torrents_rt';
$seedPrefix = 'deadbeef';

// ---------------------------------------------------------------------------
// 清理模式
// ---------------------------------------------------------------------------

if (in_array('--clean', array_slice($argv, 1), true)) {
    fwrite(STDOUT, "clean demo seed (infohash prefix: {$seedPrefix}) ...\n");

    $stmt = $pdo->prepare('SELECT infohash FROM torrents WHERE infohash LIKE :prefix');
    $stmt->execute([':prefix' => $seedPrefix . '%']);
    $hashes = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $ids = array_map(static fn(string $h) => (int) hexdec(substr($h, 0, 15)), $hashes);
    if ($ids) {
        $indexer->deleteIds($ids);
    }

    $pdo->prepare('DELETE FROM torrents WHERE infohash LIKE :prefix')->execute([':prefix' => $seedPrefix . '%']);
    fwrite(STDOUT, "removed " . count($hashes) . " demo records from MySQL and Manticore\n");
    return;
}

// ---------------------------------------------------------------------------
// 为每条演示数据派生确定性 infohash：deadbeef + 7 位序号 + 名称的 SHA1 后缀
// 重复执行时序号与名称不变 => infohash 与文档 id 完全一致 => 天然去重
// ---------------------------------------------------------------------------

function demo_infohash(int $index, string $name): string
{
    global $seedPrefix;
    $serial = str_pad((string) $index, 7, '0', STR_PAD_LEFT);   // 7 个 hex 字符
    $tail = substr(sha1('demo-seed:' . $name), 0, 25);          // 25 个 hex 字符
    return $seedPrefix . $serial . $tail;                       // 8 + 7 + 25 = 40
}

function extension_of(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return $ext === '' ? '' : substr($ext, 0, 16);
}

$mysqlUpsert = $pdo->prepare(
    'INSERT INTO torrents(infohash,name,size_total,file_count,extension,tags,files_json,status,created_at,updated_at)
     VALUES (:infohash,:name,:size_total,:file_count,:extension,:tags,:files_json,:status,FROM_UNIXTIME(:ts),FROM_UNIXTIME(:ts))
     ON DUPLICATE KEY UPDATE
       name=VALUES(name), size_total=VALUES(size_total), file_count=VALUES(file_count),
       extension=VALUES(extension), tags=VALUES(tags), files_json=VALUES(files_json),
       status=VALUES(status), created_at=VALUES(created_at)'
);

function count_mysql(PDO $pdo, string $prefix): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM torrents WHERE infohash LIKE :prefix');
    $stmt->execute([':prefix' => $prefix . '%']);
    return (int) $stmt->fetch()['c'];
}

function count_manticore(TorrentIndexer $indexer, string $table, array $ids): int
{
    if (!$ids) {
        return 0;
    }
    $total = 0;
    // 文档 id 为 bigint，用 IN 白名单统计，避免对 string 列使用 LIKE 的兼容问题。
    foreach (array_chunk($ids, 500) as $chunk) {
        $rows = $indexer->sqlQuery('SELECT COUNT(*) AS c FROM ' . $table . ' WHERE id IN (' . implode(',', $chunk) . ')');
        $total += (int) ($rows[0]['c'] ?? 0);
    }
    return $total;
}

/**
 * @return string[]
 */
function demo_hashes(array $seed): array
{
    $hashes = [];
    $i = 0;
    foreach ($seed as $entry) {
        $i++;
        $hashes[] = demo_infohash($i, (string) $entry['name']);
    }
    return $hashes;
}

// ---------------------------------------------------------------------------
// 导入
// ---------------------------------------------------------------------------

$demoHashes = demo_hashes($seed);
$demoIds = array_map(static fn(string $h) => (int) hexdec(substr($h, 0, 15)), $demoHashes);

$beforeMysql = count_mysql($pdo, $seedPrefix);
$beforeManticore = count_manticore($indexer, $manticoreTable, $demoIds);

fwrite(STDOUT, "seed begin: " . count($seed) . " demo records\n");
fwrite(STDOUT, "before import => mysql: {$beforeMysql}, manticore: {$beforeManticore}\n");

$i = 0;
foreach ($seed as $entry) {
    $i++;
    $name = (string) $entry['name'];
    $tags = (string) $entry['tags'];
    $ts = (int) strtotime((string) $entry['ts']);
    $infohash = $demoHashes[$i - 1];

    if (isset($entry['file'])) {
        $files = [['path' => (string) $entry['file'], 'size' => (int) $entry['size']]];
        $sizeTotal = (int) $entry['size'];
        $extension = extension_of((string) $entry['file']);
    } else {
        $files = $entry['files'];
        $sizeTotal = 0;
        foreach ($files as $f) {
            $sizeTotal += (int) $f['size'];
        }
        $extension = '';
    }

    $filesJson = json_encode($files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $mysqlUpsert->execute([
        ':infohash' => $infohash,
        ':name' => $name,
        ':size_total' => $sizeTotal,
        ':file_count' => count($files),
        ':extension' => $extension,
        ':tags' => $tags,
        ':files_json' => $filesJson,
        ':status' => 'fetched',
        ':ts' => $ts,
    ]);

    // RT 索引 REPLACE 即时生效，无需重建/刷新
    $indexer->upsert($infohash, $name, $sizeTotal, $ts, $tags, $files);

    fwrite(STDOUT, sprintf("  [%2d/%d] %s  %s\n", $i, count($seed), $infohash, $name));
}

$afterMysql = count_mysql($pdo, $seedPrefix);
$afterManticore = count_manticore($indexer, $manticoreTable, $demoIds);

fwrite(STDOUT, "after import  => mysql: {$afterMysql}, manticore: {$afterManticore}\n");

// ---------------------------------------------------------------------------
// 导入后立即自测（真实走一遍搜索服务路径）
// ---------------------------------------------------------------------------

fwrite(STDOUT, "\nsearch self-test:\n");
$client = new ManticoreClient();
$checks = [
    'Ubuntu' => '英文软件名',
    'Blender' => '英文关键词',
    'Big Buck Bunny' => '英文多关键词',
    '三国' => '中文标题分词',
    '回合制' => '仅命中标签字段',
    '古典音乐' => '仅命中标签/标签词',
    'Linux' => '英文分类词',
    'ubuntu-24.04-desktop' => '按文件名片段（单文件 .iso）',
    'big_buck_bunny_1080p' => '按文件名片段（多文件合集内路径）',
];

$failed = 0;
foreach ($checks as $query => $desc) {
    $result = $client->search($query, 1, 10, 'new');
    $total = (int) $result['total'];
    $ok = $total > 0;
    if (!$ok) {
        $failed++;
    }
    $tagProbe = '';
    if ($ok && !empty($result['items'][0]['tags'])) {
        $tagProbe = ' | tags: ' . $result['items'][0]['tags'];
    }
    fprintf(
        STDOUT,
        "  [%s] q=%-16s(%s) => hits=%d%s\n",
        $ok ? 'OK' : 'FAIL',
        '"' . $query . '"',
        $desc,
        $total,
        $tagProbe
    );
}

if ($afterMysql !== count($seed) || $afterManticore !== count($seed)) {
    fwrite(STDERR, "ERROR: record count mismatch, expected " . count($seed) . "\n");
    exit(1);
}
if ($failed > 0) {
    fwrite(STDERR, "ERROR: {$failed} self-test query(ies) returned no hits\n");
    exit(1);
}

fwrite(STDOUT, "\nseed done: 可重复执行（MySQL 主键 upsert + Manticore REPLACE，不会产生重复）。\n");
fwrite(STDOUT, "清理演示数据请运行: php scripts/seed_demo.php --clean\n");
