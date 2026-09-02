# 开源了一个 PHP 8.4 库：用 5 行代码无头驱动 Claude Code / Codex / Copilot 等 AI 编程 Agent

> AI Agent 时代，谁来帮你的后端系统调度这些 AI 编程命令行？

---

## 前言

2026 年，AI 编程工具已经从"辅助补全"全面进入了 **"Agent 自主编码"** 时代。

Anthropic 的 **Claude Code**、OpenAI 的 **Codex CLI**、GitHub 的 **Copilot CLI**、开源社区的 **OpenCode** —— 这些强大的 AI 编程命令行工具，可以直接在终端中自主分析代码、执行命令、读写文件、运行测试，甚至完成从需求到提交 PR 的全流程。

但问题来了：

> 如果我想在 **CI/CD 流水线**、**后台任务队列**、**企业内部平台** 或者 **Docker 沙箱** 里，以 **无人值守** 的方式自动调度这些 AI CLI，该怎么办？

每个 CLI 的命令行参数不一样、输出格式不一样、规则文件放的位置不一样、Token 计费口径也不一样…… 手动适配每一个？太痛苦了。

今天，分享一个我刚刚开源的 PHP 库 —— **`tinywan/harness`**，专门解决这个问题。

---

## 一、到底解决什么问题？

先看一组对比，体会一下这些 AI CLI 之间的差异有多大：

**启动参数：**

```bash
# Claude Code
claude -p --output-format stream-json --permission-mode bypassPermissions "你的提示词"

# OpenAI Codex
codex exec --json --sandbox danger-full-access -- "你的提示词"

# GitHub Copilot CLI
copilot -p --output-format json --autopilot --allow-all "你的提示词"

# OpenCode
opencode run --format json --auto "你的提示词"
```

**规则/指令文件：**
- Claude 读 `CLAUDE.md`
- Codex 和 OpenCode 读 `AGENTS.md`
- Copilot 读 `.github/copilot-instructions.md`

**技能（Skills）存放目录：**
- Claude 放在 `.claude/skills/`
- Codex 放在 `skills/`
- Copilot 放在 `.github/skills/`
- OpenCode 放在 `.opencode/skill/`

**输出格式：**
- 各家的 JSONL 结构完全不同，字段名、嵌套层级、事件类型名称全部不一致。

**Token 计费：**
- Claude 输出 Token 数量 + 总费用 USD
- Codex 输出 Token 数量但不输出费用
- Copilot 输出 nano-AIU 积分，需要自行换算

用 Harness 之后，**以上所有差异全部被抹平**，你只需要写同一套代码。

---

## 二、Harness 是什么？

**`harness`** 是一个专为 PHP 8.4+ 设计的 **AI 编程 Agent 编排引擎库**，核心能力是以 **无头模式（Headless）** 统一驱动四大 AI 编程 CLI：

| 后端 | CLI 命令 | 代表模型 |
| :--- | :--- | :--- |
| **Claude Code** | `claude` | claude-opus-4-6, claude-sonnet-4-6 |
| **OpenAI Codex** | `codex` | gpt-5.3-codex, deepseek-v4-pro |
| **GitHub Copilot** | `copilot` | gpt-5.6-sol, claude-sonnet-5 |
| **OpenCode** | `opencode` | anthropic/claude-sonnet-4-6 |

一句话总结：**你的 PHP 后端系统通过 Harness 可以像调用一个普通类方法一样，驱动任何一个 AI Agent 去自动完成编程任务。**

---

## 三、核心特性一览

### 1. 统一接口，一键切换后端

无论底层是 Claude Code 还是 Codex，上层 PHP 代码完全一致：

```php
use Harness\Harness;
use Harness\Job;
use Harness\Runner;
use Harness\Event;

// 选择后端（claude / codex / copilot / opencode）
$backend = Harness::byName('codex');

// 定义任务
$job = new Job(
    workspace: '/path/to/project',
    srcDir: '.',
    prompt: '实现一个 CSV 解析函数，并编写完整的单元测试。',
    model: 'deepseek-v4-pro',
    maxTurns: 20
);

// 运行并实时接收事件
Runner::run($backend, $job, function (Event $event): void {
    echo $event->format() . "\n";
});
```

想换成 Claude Code？只需把 `'codex'` 改成 `'claude'`，其余代码 **一行不用动**。

### 2. 标准化流式事件

Harness 会把各家杂乱的 stdout 实时解析为干净的领域事件对象：

| 事件类型 | 含义 |
| :--- | :--- |
| `Thinking` | AI 的思维链 / 深度推理过程 |
| `Tool` | 执行 Bash 命令、读写文件、搜索代码等 |
| `Text` | 模型的常规文本回复 |
| `Result` | 终态结算：总费用、轮数、Token 明细 |
| `Session` | 会话 ID（可用于断点恢复） |
| `RateLimit` | 限流状态及重置时间 |
| `Error` | 错误信息 |

前端拿到这些标准事件后，可以轻松实现 **实时思考动画**、**命令执行进度展示**、**费用面板** 等功能。

### 3. 精准的 Token 费用核算

内置了 Anthropic、OpenAI、Copilot 主流模型的价格字典，自动完成：
- Token 数量 → USD 费用
- Copilot nano-AIU 积分 → USD 费用

对于企业来说，这意味着可以 **精确核算每次 AI 调用的真实成本**，方便做部门分摊或对外计费。

### 4. Agent Skills 规范支持

遵循标准 [Agent Skills Specification](https://agentskills.io/specification)，只需编写一次 `SKILL.md`：

```php
$skill = SkillParser::parse('/path/to/security-audit/SKILL.md');

// 一行代码自动部署到对应 CLI 的发现目录
SkillDelivery::stage($backend, $job, $skill);
```

Harness 会自动解析 YAML 元数据、校验参数约束，并将技能文件暂存（Stage）到对应后端的正确目录下。

### 5. 断点恢复 & 安全沙箱

**断点恢复：** 每次运行都会捕获 Session ID，下次可以从上次中断的地方继续：

```php
$resumeJob = new Job(
    workspace: '/path/to/project',
    resumeSessionID: '01a0626b-cb18-7e31-876b-7d40abe94431',
    resumePrompt: '请在刚才的代码基础上增加 BOM 头处理。',
);
```

**安全沙箱：** 一键写入出站域名白名单，阻止 AI Agent 向未授权的外部域名发送网络请求：

```php
use Harness\Egress\Sandbox;

Sandbox::writeSandboxSettings($workspace, $backend->getEgressHosts());
```

### 6. 智能错误感知

自动识别各大 AI 服务商的：
- **429 限流** → 提取精确的限流重置时间
- **配额耗尽** → 标记为不可恢复错误
- **账号权限失效** → 触发 `AccountError` 异常

上层调度系统可以据此决定 **等待重试** 还是 **切换其他后端**。

---

## 四、实际运行效果

在我的本地环境（`dnmp-php84` Docker 容器）中运行 `php examples/run_codex.php`，实际输出如下：

```text
Running Codex CLI via Harness...
[session] 01a0626c-a72b-7820-99e9-8da96b506775
我先看一下工作区里现有的项目结构和语言，再决定怎么实现和写测试。
[command] Get-ChildItem -Force | Select-Object Mode,Length,Name
[command] python --version
工作区是空的，没有指定语言。我按 Python 3 来实现……
[file_change] csv_parser.py, test_csv_parser.py
[command] python -m unittest -v
已完成，新增了两个文件：
- csv_parser.py：支持引号、转义、字段内换行、UTF-8 BOM、自定义分隔符
- test_csv_parser.py：12 个测试用例全部通过（Ran 12 tests in 0.117s OK）
[result] cost=$0.0000 turns=1
```

从 "发出 Prompt" 到 "AI 自主分析环境 → 编写代码 → 写测试 → 运行测试 → 返回结果"，整个过程 **完全无人干预**。

---

## 五、典型企业级应用场景

### 场景一：CI/CD 自动化代码审查

在 GitLab MR 或 GitHub PR 提交时，通过 Webhook 自动触发：

```
PR 提交 → Webhook → PHP Worker → Harness 调度 Claude Code
→ AI 自主审查代码 → 输出 JSON 审查报告 → 自动回帖到 PR
```

### 场景二：自动化 Bug 修复 Agent

```
用户提交工单 → 后台 Worker 拉起 Docker 沙箱
→ Harness 驱动 Codex 分析代码
→ AI 定位缺陷 → 修改代码 → 运行测试
→ 测试通过 → 自动提交 PR
```

### 场景三：多模型能力评测（Arena）

同一批编程任务，同时调度 Claude Code、Codex、Copilot、OpenCode：

```
统一任务集 → Harness 并发调度 4 个后端
→ 对比代码质量、通过率、耗时、Token 成本
→ 生成基准评测报告
```

### 场景四：企业 AI 编程中台

在 Laravel / Webman / Symfony 体系中，把 AI 编程能力包装为内部 API：

```
前端页面 → 发起 AI 编程请求 → 后端 API
→ Harness 调度 → WebSocket 实时推送事件流
→ 前端展示思考过程、命令执行、最终结果
```

---

## 六、工程化实践

这个项目本身也是一个现代 PHP 工程化的实践案例：

| 维度 | 选型 |
| :--- | :--- |
| **运行时** | PHP 8.4（强类型、构造器属性提升、枚举） |
| **测试框架** | Pest 3.0（40 测试用例，168 断言，100% 通过） |
| **格式化 & 静态分析** | Mago（Rust 编写的高性能 PHP 工具链） |
| **CI/CD** | GitHub Actions（Pest 自动测试 + Mago 代码质量门禁） |
| **本地开发** | Docker 容器 `dnmp-php84` |
| **包管理** | Composer，遵循 PSR-4 自动加载 |

---

## 七、安装与使用

**环境要求：** PHP >= 8.4 + Composer

```bash
composer require tinywan/harness
```

**开源地址：**

🔗 GitHub：**https://github.com/Tinywan/harness**

当前最新版本：**v1.0.0**

---

## 八、项目结构

```
harness/
├── src/
│   ├── Backends/               # 四大 CLI 后端适配器
│   │   ├── ClaudeHarness.php
│   │   ├── CodexHarness.php
│   │   ├── CopilotHarness.php
│   │   └── OpencodeHarness.php
│   ├── Skills/                 # Agent Skills 解析与分发
│   │   ├── Skill.php
│   │   ├── SkillParser.php
│   │   ├── SkillDelivery.php
│   │   ├── SkillFilter.php
│   │   └── SkillWalker.php
│   ├── Egress/Sandbox.php      # 安全沙箱配置
│   ├── Exceptions/AccountError.php
│   ├── Harness.php             # 统一门面
│   ├── HarnessInterface.php    # 后端契约接口
│   ├── Runner.php              # 子进程驱动器
│   ├── Job.php                 # 任务实体
│   ├── Event.php               # 流式事件模型
│   ├── EventKind.php           # 事件类型枚举
│   ├── Pricing.php             # Token 价格与费用计算
│   ├── Usage.php               # Token 消耗计量
│   └── ...
├── tests/                      # Pest 3.0 单元测试（40 tests, 168 assertions）
├── examples/                   # 四个可执行示例脚本
├── mago.toml                   # Mago 格式化与检查配置
├── composer.json
└── .github/workflows/ci.yml    # GitHub Actions CI
```

---

## 写在最后

AI Agent 时代，"对话式编程"只是起点。真正的生产力释放，在于 **让 AI 编程 Agent 融入你已有的工程体系**——你的 CI/CD、你的任务队列、你的内部平台。

`harness` 要做的事情很简单：**当一座桥，让 PHP 开发者可以用最小的成本，把 AI 编程 Agent 的能力接入自己的系统。**

如果你也在做 AI + 自动化编程相关的事情，欢迎 Star ⭐️ 和 PR，一起完善这个项目！

---

**🔗 项目地址：https://github.com/Tinywan/harness**

**📦 安装命令：`composer require tinywan/harness`**

**🏷️ 当前版本：v1.0.0**

**📄 开源协议：MIT License**

---

*作者：Tinywan（ShaoBo Wan）*
*日期：2026年9月2日*
