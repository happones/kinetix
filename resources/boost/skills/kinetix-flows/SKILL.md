---
name: kinetix-flows
description: "Visual bot flows on Kinetix Channels + Inbox: triggers, steps (message, question, options, condition, set, http, delay, hours, handoff, end), drafts and versions, simulator, per-node stats, inbox start/stop. Activates when working with KinetixFlowEditor, KinetixFlowList, FlowEngine, FlowNode or bot flows."
license: MIT
metadata:
  author: happones
---

# Kinetix Flows Development

## When to Apply

Activate this skill when:
- Mounting `<KinetixFlowList>` / `<KinetixFlowEditor>` or using `useKinetixFlows`.
- Working on `FlowEngine`, `FlowInterpreter`, node types (`FlowNode`) or triggers.
- Adding a custom step (`KinetixFlows::node`) or reacting to `FlowRunFinished`.

## Documentation

For full details, reference `docs/flows.md` (published at https://happones.github.io/kinetix/flows).

## Configuration

```bash
php artisan vendor:publish --tag=kinetix-flows-migrations   # after channels + inbox migrations
# schedule kinetix:flows:resume every minute
```

```php
'flows' => [
    'enabled' => env('KINETIX_FLOWS_ENABLED', false),   // needs kinetix.channels + kinetix.inbox
    'pause_on_reply' => true, 'skip_assigned' => true,
    'max_steps' => 50, 'http_timeout' => 10, 'simulate_http' => true,
],
```

The canvas needs Vue Flow, registered by the host with `registerKinetixFlowEditor()` (Tiptap pattern). Never import `@vue-flow/*` from published components.

## Rules

- **Tables**:
  - `kinetix_flows`: `draft` graph, `trigger`, `channel_ids`, `published_version_id`
  - `kinetix_flow_versions`: frozen graphs
  - `kinetix_flow_runs`: per conversation; `status` running, waiting_reply, waiting_delay, completed, handed_off, paused or failed
  - `kinetix_flow_run_steps`: one row per node entered, for stats
- **Graph**:
  - `{nodes: [{id, type, position, data}], edges: [{source, sourceHandle, target}]}`.
  - An output = node + handle (`next`, `yes`/`no`, option id, `open`/`closed`, `error`, `invalid`, `other`).
  - An unwired output ends the flow.
- **One walk** (`FlowInterpreter::advance`) for real runs and the simulator.
  - Real runs persist state and step rows, and dispatch `ResumeFlowRun` for delays.
  - The simulator uses `RecordingOutbox` and skips waits instantly.
- **Triggers**:
  - Precedence: keyword (contains/exact/regex) → first_message → any_message. `manual` is inbox only.
  - Ties: priority desc, then id.
  - No auto-start when the conversation is assigned. One active run per conversation.
- **Bot ↔ people**:
  - Bot messages go through `InboxManager::sendAsBot()` (meta `bot`, `author_name`, `options`).
  - A human reply (`ConversationReplied`) pauses the run.
  - Inbox endpoints: `inbox/{c}/flows` (list + run), `inbox/{c}/flows/{flow}` (start), `inbox/{c}/flows/stop`.
- **Versions**: publish validates (`FlowGraphValidator`) → new `FlowVersion`, status active. Runs keep `flow_version_id`.
- **Options**:
  - Telegram sends inline buttons (callback_query parsed as a text message).
  - WhatsApp sends a numbered menu (`OutgoingMessage::textWithOptions()`).
- **HTTP step**: guarded by `WebhookUrlGuard` (SSRF). Errors follow `error`.
- **Custom step**:
  - Implement `FlowNode`: `outputs()`, `execute()` → `NodeOutcome::next|waitReply|waitUntil|stop`, `receive()`, `problem()`.
  - Register it with `KinetixFlows::node()`.
- Endpoints (`flows.manage`): index, store, show, update (draft), destroy, `{flow}/publish|activate|deactivate|duplicate|simulate`, `{flow}/stats?version=`.
