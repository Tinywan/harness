# Harness (PHP Version)

English | [简体中文](README.zh-CN.md)

---

`harness` is an orchestration engine library designed for **PHP 8.4+**, built to unify the execution, scheduling, and monitoring of major AI coding command-line interfaces (AI Coding CLIs) in **Headless / Unattended Mode**.

Through a unified PHP object-oriented interface, it abstracts away all discrepancies across **Claude Code**, **OpenAI Codex CLI**, **GitHub Copilot CLI**, and **OpenCode** in command-line arguments, rule files, streaming output, Token billing, and skill distribution.

---

## 🧐 Why Harness? (Core Problems Solved)

When automatically scheduling AI coding agents in automated pipelines, background task queues, CI/CD, or sandbox containers, developers typically face the following challenges:

1. **Heterogeneous CLI arguments and execution modes**:
   - Claude Code requires `-p --output-format stream-json --permission-mode bypassPermissions`
   - OpenAI Codex requires `exec --json --sandbox danger-full-access`
   - GitHub Copilot CLI requires `-p --output-format json --autopilot --allow-all`
   - OpenCode requires `run --format json --auto`
2. **Inconsistent rules and instruction delivery standards**:
   - Claude uses `--system-prompt` or `CLAUDE.md`;
   - Codex and OpenCode rely on `AGENTS.md` at the workspace root;
   - Copilot relies on `.github/copilot-instructions.md`.
3. **Chaotic output stream formats that are difficult to standardize**:
   - Disparate stdout/stderr and JSONL event structures across tools make it hard to uniformly capture thinking processes (`Thinking`), tool calls (`Tool Use`), and final results.
4. **Inconsistent Token consumption and cost accounting standards**:
   - Some CLIs output only token counts, while others output credit billing points, lacking a unified USD cost calculation model.
5. **Divergent Agent Skills discovery and staging paths**:
   - Different skill discovery paths (`.claude/skills/`, `skills/`, `.github/skills/`, `.opencode/skill/`).

**`harness` standardizes and encapsulates all these underlying details, providing a consistent, reliable PHP API.**

---

## 🚀 Supported Backend CLIs

| Backend (`name`) | Binary CLI | Default Model Example | Skill Discovery Path | Project Instruction File | Egress Allowed Hosts |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **`claude`** | `claude` | `claude-opus-4-6`, `claude-sonnet-4-6` | `.claude/skills/<name>` | `CLAUDE.md` | `*.anthropic.com` |
| **`codex`** | `codex` | `gpt-5.3-codex`, `deepseek-v4-pro` | `skills/<name>` | `AGENTS.md` | `api.openai.com`, `auth0.openai.com`, `chatgpt.com` |
| **`copilot`** | `copilot` | `gpt-5.6-sol`, `claude-sonnet-5` | `.github/skills/<name>` | `.github/copilot-instructions.md` | `github.com`, `api.github.com`, `*.githubcopilot.com` |
| **`opencode`** | `opencode` | `anthropic/claude-sonnet-4-6` | `.opencode/skill/<name>` | `AGENTS.md` | `models.dev`, `api.openai.com`, `*.anthropic.com` |

---

## 🧩 Key Features & Capabilities

- **🎯 Unified Driver Facade**: Schedule any backend using a single `Job` entity and `Runner::run()` method, with support for custom models, thinking turns, reasoning effort, and more.
- **⚡ Standardized Event Stream**:
  - `EventKind::Thinking`: AI deep thinking and reasoning process
  - `EventKind::Tool`: Tool and command invocation (e.g. `bash`, `read`, `write`, `grep`, etc.)
  - `EventKind::Text`: Regular text response from the model
  - `EventKind::Result`: Final settlement (including total cost `costUSD`, `turns`, and token usage breakdown `usage`)
  - `EventKind::Session`: Initial session ID (for breakpoint resumption)
  - `EventKind::RateLimit`: Rate limit/quota status and reset timestamp (`ResetsAt`)
  - `EventKind::Error`: Exceptions and error messages
- **💰 Unified Billing & Cost Accounting**: Built-in pricing tables for major models (Anthropic, OpenAI, Copilot Hosted), automatically converting token metrics and Copilot nano-AIU into standardized USD costs.
- **🔄 Session Resumption (Resume)**: Automatically extracts Session IDs, supporting context continuation after interruptions or error corrections.
- **📦 Agent Skills Specification Support**: Fully compatible with the [Agent Skills Specification](https://agentskills.io/specification), providing `SKILL.md` YAML frontmatter parsing, parameter validation, and automated staging.
- **🛡️ Account Rate Limit & Quota Awareness**: Automatically detects provider 429s, quota exhaustion, permission revocations, etc., and throws an `AccountError` containing the reset timestamp for retry scheduling.
- **🔒 Security Sandbox & Domain Whitelisting (Egress)**: One-click generation of Claude domain sandbox configuration (`.claude/settings.json`) to block unauthorized external network requests.

---

## 📦 Requirements & Installation

- **PHP** >= 8.4
- **Composer**
- **Docker** (Recommended for local development with the `dnmp-php84` container)

```bash
composer require tinywan/harness
```

---

## 🛠️ Quick Start

### 1. Basic Headless Task Execution

```php
<?php

/**
 * @desc Quick Start Example
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

// 1. Get corresponding CLI backend (options: claude, codex, copilot, opencode)
$backend = Harness::byName('codex');

// 2. Build task configuration
$job = new Job(
    workspace: $workspace,
    srcDir: '.',
    prompt: 'Implement a function to parse CSV files and write unit tests for it.',
    model: 'gpt-5.3-codex',
    maxTurns: 20
);

// 3. Run and receive structured event stream in real-time
try {
    Runner::run($backend, $job, function (Event $event): void {
        // Output formatted log: [thinking], [tool], [text], [result], [rate-limit], [error]
        echo $event->format() . "\n";
    });
} catch (AccountError $e) {
    echo "Account quota / access limit error: " . $e->getMessage() . "\n";
    if ($e->resetAt !== null) {
        echo "Suggested retry time: " . $e->resetAt->format('Y-m-d H:i:s') . "\n";
    }
} catch (\Throwable $e) {
    echo "Execution failed: " . $e->getMessage() . "\n";
}
```

---

### 2. Loading & Staging Agent Skills (`SKILL.md`)

```php
use Harness\Skills\SkillParser;
use Harness\Skills\SkillDelivery;
use Harness\Harness;
use Harness\Job;
use Harness\Runner;
use Harness\Event;

// 1. Parse SKILL.md adhering to the Agent Skills specification
$skill = SkillParser::parse('/path/to/security-review/SKILL.md');

// 2. Select backend and configure job
$backend = Harness::byName('claude');
$job = new Job(
    workspace: '/path/to/project',
    srcDir: '.',
    skillName: $skill->name,
    model: 'claude-sonnet-4-6',
    outputFile: 'report.json'
);

// 3. Automatically stage the skill and associated dependencies into the backend discovery path
SkillDelivery::stage($backend, $job, $skill);

// 4. Run task
Runner::run($backend, $job, function (Event $event): void {
    echo $event->format() . "\n";
});
```

---

### 3. Session Resume & Context Continuation

```php
// Resume context using sessionID captured from a previous run
$resumeJob = new Job(
    workspace: '/path/to/project',
    srcDir: '.',
    resumeSessionID: '01a0626b-cb18-7e31-876b-7d40abe94431',
    resumePrompt: 'Please add handling for empty lines and BOM headers to the code just written.',
    model: 'gpt-5.3-codex'
);

Runner::run($backend, $resumeJob, function (Event $event): void {
    echo $event->format() . "\n";
});
```

---

## 💡 Typical Use Cases

1. **🏢 Enterprise Automated Code Review & Security Scanning (Code Review & SAST)**
   - Automatically spin up Claude Code or Codex in GitLab CI / GitHub Actions pipelines or Git Webhooks for headless PR scanning and output standardized JSON review reports.
2. **🤖 Automated Issue / Bug Fixing Agent Service**
   - When a user submits an issue ticket, the background PHP Worker automatically creates an isolated sandbox, drives the AI CLI to locate bugs, modify code, run unit tests, and submit a PR via Git.
3. **📊 Multi-Model Capability Benchmarking & Arena Platform (AI Benchmark / Arena)**
   - Concurrently invoke different AI CLIs against the same test suite via a unified interface, automatically comparing code generation quality, execution time, pass rates, and Token consumption costs.
4. **☁️ SaaS Platforms & AI Coding Middle-End Engine**
   - Encapsulate headless AI coding agent capabilities into asynchronous jobs within web frameworks (Laravel / Webman / Symfony) and stream real-time events to the frontend via WebSocket.

---

## 🧪 Pest Unit Testing

This project uses **Pest 3.0** for comprehensive unit testing:

### 1. Run locally
```bash
composer test
```

### 2. Run inside Docker container (`dnmp-php84`)
```bash
composer run test:docker
# Or run directly via docker exec:
docker exec -w /var/www/ai/harness dnmp-php84 ./vendor/bin/pest
```

---

## 🎨 Code Formatting & Linting (Mago)

This project is configured with **Mago** (a high-performance PHP toolchain written in Rust) via [`mago.toml`](mago.toml):

```bash
# Code linting
mago lint

# Code auto-formatting
mago format
```

---

## 📄 License

MIT License
