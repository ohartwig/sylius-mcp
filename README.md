<!--
SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
SPDX-License-Identifier: MIT
-->

# sylius-mcp

> MCP-Schicht ueber sylius/mcp-server-plugin (Config + eigene Tools)

Thin layer over the official [`sylius/mcp-server-plugin`](https://github.com/Sylius/McpServerPlugin):
project-specific tool configuration, auth and custom MCP tools for the content-commerce use case.
We do NOT reimplement MCP — we configure/extend the official plugin.

## Usage

In the `commerce` app the official plugin exposes the MCP server at `POST /_mcp`
(HTTP transport). This bundle registers its own tools with that server
(`Koh\SyliusMcp\Mcp\KohCapabilityLoader`, tagged `mcp.loader`) and puts a
policy in front of **every** tool the server registers — ours, the plugin's and
the application's own.

Own tools:

- **`content_product_search`** — searches products by phrase and locale;
  content-aware re-ranking via `koh/typo3-sylius-bridge` is a documented TODO.
- **`fetch_product`** — one product by code, with its variant codes. Replaces
  the plugin's tool of the same name (see *Name conflicts*).

Enable in `commerce`:

1. `composer require koh/sylius-mcp` (pulls `sylius/mcp-server-plugin`).
2. Register `Koh\SyliusMcp\KohSyliusMcpBundle` in `config/bundles.php`.
3. Import the plugin config + routes (`config/packages/sylius_mcp_server.yaml`, `config/routes/*`).
4. Verify: `POST /_mcp` `tools/list` lists `content_product_search`.

## Tool policy

Every tool has a risk class — the three classes of the handbook's agentic-systems
standard:

| Class | Meaning | Examples |
|---|---|---|
| `read` | changes nothing | `search_products`, `fetch_order`, `list_articles` |
| `write` | changes state no customer sees yet | the plugin's guest-cart tools |
| `high_risk` | catalogue, published content, real orders | `create_product`, `update_product`, `complete_checkout` |

The class drives the `readOnlyHint` / `destructiveHint` / `idempotentHint`
annotations in `tools/list` and on the server card. The plugin's tools and the
application's tools are classified by name in
`Koh\SyliusMcp\Policy\ToolPolicyProvider`; a tool that is in neither the table
nor the configuration counts as `high_risk` and is logged as unclassified.

```yaml
# config/packages/koh_sylius_mcp.yaml
koh_sylius_mcp:
    transport_path: '/_mcp'
    default_tool_class: high_risk   # for tools nobody classified
    tools:
        search_products: { enabled: false }          # gone from tools/list, the card and tools/call
        create_order: { class: high_risk }
        some_app_tool: { class: write, idempotent: true, destructive: false }
```

`enabled: false` does not hide a tool, it keeps it out of the registry: it
cannot be called by name either. This works for the plugin's tools too, which
the plugin itself registers unconditionally.

### Name conflicts

The SDK registry keys tools by name, and the plugin and this package both ship a
`fetch_product`. Until 1.x they overwrote each other in loader order. The policy
registry decides now: a tool from this package wins, whichever loader runs
first, and the decision is logged on the `mcp` channel.

### Server card

`/.well-known/mcp/server-card.json` lists the tools of the MCP registry — the
same registry `tools/list` answers from — with their annotations. It is public,
so it shows every tool that is switched on, not what one token may call.

---

Teil der **AI Ready Plattform** (Content-Commerce: Sylius × TYPO3). Status: funktionaler First-Pass.

<!-- release: retry after release-bot maintainer grant -->
