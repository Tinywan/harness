# Harness (PHP Version)

[English](README.md) | 简体中文

---

`harness` 是一个专为 **PHP 8.4+** 设计的编排引擎库，用于以**无头模式（Headless Mode / 无人值守模式）**统一驱动、调度与监控各类主流 AI 编程命令行工具（AI Coding CLIs）。

通过统一的 PHP 面向对象接口，抹平 **Claude Code**、**OpenAI Codex CLI**、**GitHub Copilot CLI** 和 **OpenCode** 在命令行参数、规则文件、流式输出、Token 计费及技能分发上的所有差异。

---

## 🧐 为什么需要 Harness？（解决的核心痛点）

在自动化流水线、后台任务队列、CI/CD 或沙箱容器中自动调度 AI 编码 Agent 时，开发者通常面临以下挑战：

1. **各 CLI 参数与运行模式异构**：
   - Claude Code 需要 `-p --output-format stream-json --permission-mode bypassPermissions`
   - OpenAI Codex 需要 `exec --json --sandbox danger-full-access`
   - GitHub Copilot CLI 需要 `-p --output-format json --autopilot --allow-all`
   - OpenCode 需要 `run --format json --auto`
2. **规则与指令传递规范不统一**：
   - Claude 采用 `--system-prompt` 或 `CLAUDE.md`；
   - Codex 与 OpenCode 依赖工作区根目录的 `AGENTS.md`；
   - Copilot 依赖 `.github/copilot-instructions.md`。
3. **输出流格式杂乱难以标准化**：
   - 各工具的 stdout/stderr 及 JSONL 事件结构各不相同，难以统一捕获思考过程（Thinking）、工具调用（Tool Use）和最终结果。
4. **Token 消耗与成本结算口径不一**：
   - 部分 CLI 仅输出 Token 数，部分输出 Credit 计费点数，缺乏统一的 USD 成本核算模型。
5. **Agent Skills 存放与暂存路径各异**：
   - 技能发现路径各不相同（`.claude/skills/`、`skills/`、`.github/skills/`、`.opencode/skill/`）。

**`harness` 对所有底层细节进行标准化封装，提供一致、可靠的 PHP API。**

---

## 🚀 支持的后端 CLI

| 后端标识 (`name`) | 对应二进制 CLI | 默认模型示例 | 技能发现目录（Skill Path） | 项目指导文件 | Egress 允许主机 |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **`claude`** | `claude` | `claude-opus-4-6`, `claude-sonnet-4-6` | `.claude/skills/<name>` | `CLAUDE.md` | `*.anthropic.com` |
| **`codex`** | `codex` | `gpt-5.3-codex`, `deepseek-v4-pro` | `skills/<name>` | `AGENTS.md` | `api.openai.com`, `auth0.openai.com`, `chatgpt.com` |
| **`copilot`** | `copilot` | `gpt-5.6-sol`, `claude-sonnet-5` | `.github/skills/<name>` | `.github/copilot-instructions.md` | `github.com`, `api.github.com`, `*.githubcopilot.com` |
| **`opencode`** | `opencode` | `anthropic/claude-sonnet-4-6` | `.opencode/skill/<name>` | `AGENTS.md` | `models.dev`, `api.openai.com`, `*.anthropic.com` |

---

## 🧩 核心功能与特性

- **🎯 统一驱动门面**：通过一个 `Job` 实体和 `Runner::run()` 方法调度任意后端，支持自定义模型、思考轮数、推理强度（Effort）等。
- **⚡ 标准流式事件（Event Stream）**：
  - `EventKind::Thinking`：AI 深度思考推理过程
  - `EventKind::Tool`：工具与命令调用（如 `bash`、`read`、`write`、`grep` 等）
  - `EventKind::Text`：模型回复的常规文本
  - `EventKind::Result`：终态结算（包含总费用 `costUSD`、轮数 `turns`、Token 使用明细 `usage`）
  - `EventKind::Session`：会话初始 ID（用于断点恢复）
  - `EventKind::RateLimit`：限流/配额状态与重置时间（ResetsAt）
  - `EventKind::Error`：异常与错误提示
- **💰 统一计费与成本核算**：内置主流模型价格表（Anthropic、OpenAI、Copilot Hosted），自动将 Token 计量及 Copilot nano-AIU 转换为标准 USD 费用。
- **🔄 断点会话恢复（Resume）**：自动提取 Session ID，支持中断或纠错后的断点上下文续接。
- **📦 Agent Skills 规范支持**：全面兼容 [Agent Skills Specification](https://agentskills.io/specification)，提供 `SKILL.md` 的 YAML 前置元数据解析、参数校验及自动分发暂存（Stage）。
- **🛡️ 账号限制与配额感知**：自动识别提供商 429、额度耗尽、权限失效等错误，并抛出带重置时间的 `AccountError` 供重试调度。
- **🔒 安全沙箱与域名白名单（Egress）**：一键写入 Claude 域名沙箱配置（`.claude/settings.json`），阻断未授权的外网网络请求。

---

## 📦 环境要求与安装

- **PHP** >= 8.4
- **Composer**
- **Docker**（推荐本地开发环境使用 `dnmp-php84` 容器）

```bash
composer require tinywan/harness
```

---

## 🛠️ 快速开始

### 1. 基础无头任务调用

```php
<?php

/**
 * @desc 快速运行示例
 * @author Tinywan(ShaoBo Wan)
 * @date 2026/09/01
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Harness\Harness;
use Harness\Job;
use Harness\Runner;
use Harness\Event;
use Harness\Exceptions\AccountError;

$workspace = '/path/to/project';

// 1. 获取对应的 CLI 后端（可选: claude, codex, copilot, opencode）
$backend = Harness::byName('codex');

// 2. 构建任务配置
$job = new Job(
    workspace: $workspace,
    srcDir: '.',
    prompt: '实现一个用于解析 CSV 文件的函数，并为其编写单元测试。',
    model: 'gpt-5.3-codex',
    maxTurns: 20
);

// 3. 运行并实时接收结构化事件流
try {
    Runner::run($backend, $job, function (Event $event): void {
        // 输出格式化日志: [thinking], [tool], [text], [result], [rate-limit], [error]
        echo $event->format() . "\n";
    });
} catch (AccountError $e) {
    echo "账号配额/访问限制异常: " . $e->getMessage() . "\n";
    if ($e->resetAt !== null) {
        echo "建议重试时间: " . $e->resetAt->format('Y-m-d H:i:s') . "\n";
    }
} catch (\Throwable $e) {
    echo "执行失败: " . $e->getMessage() . "\n";
}
```

---

### 2. 加载与暂存 Agent 技能 (`SKILL.md`)

```php
use Harness\Skills\SkillParser;
use Harness\Skills\SkillDelivery;
use Harness\Harness;
use Harness\Job;
use Harness\Runner;
use Harness\Event;

// 1. 解析遵循 Agent Skills 规范的 SKILL.md
$skill = SkillParser::parse('/path/to/security-review/SKILL.md');

// 2. 选择后端与配置任务
$backend = Harness::byName('claude');
$job = new Job(
    workspace: '/path/to/project',
    srcDir: '.',
    skillName: $skill->name,
    model: 'claude-sonnet-4-6',
    outputFile: 'report.json'
);

// 3. 将技能与关联依赖自动部署到该后端对应的发现目录
SkillDelivery::stage($backend, $job, $skill);

// 4. 运行任务
Runner::run($backend, $job, function (Event $event): void {
    echo $event->format() . "\n";
});
```

---

### 3. 会话恢复与断点续接 (Resume)

```php
// 使用上一次运行捕获的 sessionID 恢复上下文
$resumeJob = new Job(
    workspace: '/path/to/project',
    srcDir: '.',
    resumeSessionID: '01a0626b-cb18-7e31-876b-7d40abe94431',
    resumePrompt: '请在刚刚编写的代码基础上增加对空行和 BOM 头的处理。',
    model: 'gpt-5.3-codex'
);

Runner::run($backend, $resumeJob, function (Event $event): void {
    echo $event->format() . "\n";
});
```

---

## 💡 典型业务应用场景

1. **🏢 企业自动化代码审查与安全扫描（Code Review & SAST）**
   - 在 GitLab CI / GitHub Actions 流水线或 Git Webhook 中，自动拉起 Claude Code 或 Codex 对 PR 进行无头扫描，输出标准 JSON 审查报告。
2. **🤖 自动化 Issue / Bug 修复 Agent 服务**
   - 用户提交工单后，后台 PHP Worker 自动创建隔离沙箱，驱动 AI CLI 定位缺陷、修改代码、运行单元测试并通过 Git 自动提 PR。
3. **📊 多模型能力评测与竞技场平台（AI Benchmark / Arena）**
   - 针对同一批测试用例，通过统一代码接口并发调用不同 AI CLI，自动比对代码生成质量、耗时、通过率及 Token 消耗成本。
4. **☁️ SaaS 平台与 AI 编程中台引擎**
   - 在 Web 框架（Laravel / Webman / Symfony）中，将 AI 编程 Agent 的无头执行能力封装为异步任务，并通过 WebSocket 实时向前端推流。
---

## 🧪 Pest 单元测试

本项目全面采用 **Pest 3.0** 进行单元测试：

### 1. 本机环境运行
```bash
composer test
```

### 2. Docker 容器（`dnmp-php84`）中运行
```bash
composer run test:docker
# 或者直接通过 docker exec 执行:
docker exec -w /var/www/ai/harness dnmp-php84 ./vendor/bin/pest
```

---

## 🎨 代码格式化与检查（Mago）

本项目已配置 **Mago**（Rust 编写的高性能 PHP 工具链）配置文件 [`mago.toml`](mago.toml)：

```bash
# 代码检查
mago lint

# 代码自动格式化
mago format
```

---

## 📄 开源协议

MIT License
