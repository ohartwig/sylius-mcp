# sylius-mcp

> MCP-Schicht ueber sylius/mcp-server-plugin (Config + eigene Tools)

Thin layer over the official [`sylius/mcp-server-plugin`](https://github.com/Sylius/McpServerPlugin):
project-specific tool configuration, auth and custom MCP tools for the content-commerce use case.
We do NOT reimplement MCP — we configure/extend the official plugin.

## Usage

In the `commerce` app the official plugin exposes the MCP server at `POST /_mcp`
(HTTP transport). This bundle adds custom `#[McpTool]` tools that are
auto-discovered via `autoconfigure` (see `config/services.php`).

First tool: **`content_product_search`** (`src/Tool/ContentAwareProductSearchTool.php`)
— searches Sylius products by phrase + locale; content-aware re-ranking via
`koh/typo3-sylius-bridge` is a documented TODO.

Enable in `commerce`:
1. `composer require koh/sylius-mcp` (pulls `sylius/mcp-server-plugin`).
2. Register `Koh\SyliusMcp\KohSyliusMcpBundle` in `config/bundles.php`.
3. Import the plugin config + routes (`config/packages/sylius_mcp_server.yaml`, `config/routes/*`).
4. Verify: `POST /_mcp` `tools/list` lists `content_product_search`.

---

Teil der **AI Ready Plattform** (Content-Commerce: Sylius × TYPO3). Status: funktionaler First-Pass.

<!-- release: retry after release-bot maintainer grant -->
