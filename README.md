# CamFlow - WordPress 内容互动自动化插件

[![Version](https://img.shields.io/badge/version-1.2.7-blue.svg)](https://github.com/csyqlz/CamFlow/releases)
[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-green.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://www.php.net/)

面向 WordPress 系统的内容互动自动化工具，支持用户池管理、评论互动、社区发帖、AI 文案生成和运行日志。

## ✨ 主要功能

### 👥 用户池管理
- 自动生成虚拟用户（最多 3000 个）
- AI 智能生成自然昵称
- 随机头像、性别、签名
- 自定义登录名前缀

### 💬 评论互动
- 自动为文章和社区帖生成评论
- 支持多种评论风格（提问型、赞同型、补充型、讨论型）
- AI 生成个性化评论内容
- 评论状态可选（待审核/直接发布）

### 📝 社区发帖
- 自动生成社区帖子（适配子比主题）
- AI 生成标题和正文
- 自动分配板块、话题、标签
- 支持自定义发帖频率

### 🤖 AI 接入
- 支持多种 AI 服务：
  - OpenAI 兼容接口（New API、sub2api、One API 等）
  - Google Gemini API
  - OpenRouter 免费模型
- AI 调用失败自动使用备用模板
- 可自定义 AI 提示词和超时时间

### ⏰ 定时任务
- 每日自动执行内容生成
- 自定义运行时间
- 站点确认安全机制

### 📊 运行日志
- 详细记录每次操作结果
- 分类显示成功、失败、警告信息
- 支持快速定位问题

### 🔄 自动更新
- 从 GitHub Release 自动检测更新
- 支持插件内一键更新
- 完整的更新日志

## 📦 安装

### 方式 1：从 GitHub 下载
1. 访问 [Releases 页面](https://github.com/csyqlz/CamFlow/releases)
2. 下载最新版本的 `CamFlow.zip`
3. 在 WordPress 后台 → 插件 → 安装插件 → 上传插件
4. 选择下载的 zip 文件并安装
5. 启用插件

### 方式 2：手动安装
```bash
cd wp-content/plugins/
git clone https://github.com/csyqlz/CamFlow.git
```

## 🚀 快速开始

### 1. 基础配置
- 进入 WordPress 后台 → 用户自动化
- 在"计划任务"页面勾选"站点确认"
- 设置每日运行时间（默认 02:30）

### 2. 配置 AI（可选但推荐）
- 进入"AI 接入"页面
- 选择 AI 服务（推荐 OpenAI 兼容接口）
- 填写 API Key 和模型名称
- 点击"测试连接"验证配置

### 3. 调整生成参数
- **用户池**：设置用户数量和登录前缀
- **评论互动**：设置每日评论数量、评论风格、目标内容类型
- **社区发帖**：启用/禁用社区帖生成，设置每日数量

### 4. 手动测试
在"总览"页面可以立即执行：
- 补齐 200 用户池
- 立即生成评论
- 立即生成社区帖

### 5. 启用自动运行
确认配置无误后，在"计划任务"勾选"每日自动运行"

## ⚙️ 系统要求

- WordPress 5.8 或更高版本
- PHP 7.4 或更高版本（推荐 PHP 8.0+）
- MySQL 5.7 或 MariaDB 10.2+

## 🔧 配置建议

### AI 服务选择
1. **OpenAI 兼容接口**（推荐）
   - 支持 New API、sub2api、One API 等中转服务
   - 模型选择：`gpt-4o-mini`、`deepseek-chat`、`qwen-plus` 等

2. **Google Gemini**
   - 模型选择：`gemini-2.5-flash-lite`
   - 需要在 [Google AI Studio](https://aistudio.google.com/) 创建 API Key

3. **OpenRouter**
   - 支持免费模型（带 `:free` 后缀）
   - 模型示例：`openrouter/free`

### 评论设置
- 建议先设置为"待审核"，确认内容风格后再改为"直接发布"
- 每日评论数量建议从 10 开始，逐步调整
- 抽取范围建议设置为 80-100

### 社区发帖
- 仅在使用子比主题且开启社区功能时启用
- 每日社区帖数量建议 2-5 篇

## 📝 更新日志

查看 [CHANGELOG.md](CHANGELOG.md) 了解详细更新历史。

### v1.2.7 主要更新
- ✅ 修复 PHP 8.1+ 弃用警告
- ⚡ 重大性能优化（统计查询改用直接 SQL）
- 🚀 添加统计缓存机制（5 分钟）
- 🗃️ 自动创建数据库索引
- 🔒 增强安全性验证

## 🤝 贡献

欢迎提交 Issue 和 Pull Request！

## 📄 许可证

本项目采用 GPL v2 或更高版本许可证。

## 🔗 相关链接

- [官网](https://www.camwt.com)
- [GitHub 仓库](https://github.com/csyqlz/CamFlow)
- [问题反馈](https://github.com/csyqlz/CamFlow/issues)

## ⚠️ 免责声明

本插件为内容互动自动化工具，使用前请确认符合您站点的使用条款和相关法律法规。建议在测试环境充分测试后再用于生产环境。

---

**CamFlow** - 让内容互动更简单 | Made with ❤️ by [CAMWT](https://www.camwt.com)
