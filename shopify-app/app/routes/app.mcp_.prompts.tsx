import { useMemo, useState } from "react";
import type { HeadersFunction, LoaderFunctionArgs } from "react-router";
import { useLoaderData, useNavigation, useRouteError, useSearchParams } from "react-router";
import { boundary } from "@shopify/shopify-app-react-router/server";
import { authenticate } from "../shopify.server.ts";
import { backendClient } from "../lib/backend/backendClient.server.ts";
import type { DatePresetKey } from "../lib/dates/datePresets.ts";
import type { PromptCategory, PromptInputs } from "../features/mcp/promptLibrary.ts";
import { PROMPT_TEMPLATES, renderPrompt } from "../features/mcp/promptLibrary.ts";
import type { PromptLibraryReady } from "./app.mcp.prompts.server.ts";
import { loadPromptLibrary } from "./app.mcp.prompts.server.ts";

const DEFAULT_REFUND_THRESHOLD = "5";

const CATEGORY_LABELS: Record<PromptCategory, string> = {
  health: "Data health",
  overview: "Overview",
  trends: "Trends",
  rankings: "Rankings",
  product: "Product",
  refunds: "Refunds",
  discounts: "Discounts",
  briefs: "Briefs",
  multi_step: "Multi-step analysis",
};

const SYNC_WARNINGS: Partial<Record<PromptLibraryReady["sync"], string>> = {
  syncing: "A data sync is running. Numbers may be incomplete until it finishes.",
  never_synced: "Your store has not finished its first sync yet, so prompts may return no data.",
  failed: "The last data sync failed. Numbers may be out of date.",
  stalled: "The last data sync stopped responding. Numbers may be out of date.",
  backend_error: "Sync status is unavailable right now. Numbers may be out of date.",
};

export const loader = async ({ request }: LoaderFunctionArgs) => {
  const { session } = await authenticate.admin(request);
  return loadPromptLibrary(backendClient(), session.shop, new URL(request.url).searchParams);
};

function inputValue(e: Event): string {
  const target = (e.currentTarget ?? e.target) as HTMLInputElement | null;
  return target?.value ?? "";
}

function parseThreshold(raw: string): number | undefined {
  return raw.trim() === "" ? undefined : Number(raw);
}

export default function PromptLibraryPage() {
  const data = useLoaderData<typeof loader>();

  return (
    <s-page heading="Prompt library">
      <s-link slot="breadcrumb-actions" href="/app/mcp">
        AI & MCP
      </s-link>

      {data.state === "error" && (
        <s-banner heading="Prompt library unavailable" tone="critical">
          {data.message}
        </s-banner>
      )}

      {data.state === "locked" && (
        <s-section heading="Prompt library is locked">
          <s-stack gap="base">
            <s-text>
              {data.reason === "plan"
                ? "Prompts for AI clients are part of the Pro plan."
                : "Authorize read-only data sharing on the AI & MCP page to use these prompts."}
            </s-text>
            <s-button variant="primary" href={data.reason === "plan" ? "/app/pricing" : "/app/mcp"}>
              {data.reason === "plan" ? "View plans" : "Go to AI & MCP"}
            </s-button>
          </s-stack>
        </s-section>
      )}

      {data.state === "ready" && <ReadyLibrary data={data} />}
    </s-page>
  );
}

function ReadyLibrary({ data }: { data: PromptLibraryReady }) {
  const [searchParams, setSearchParams] = useSearchParams();
  const navigation = useNavigation();
  const isLoading = navigation.state === "loading";

  const [customFrom, setCustomFrom] = useState(data.range.from);
  const [customTo, setCustomTo] = useState(data.range.to);
  const [productSearch, setProductSearch] = useState(data.productSearch);
  const [productHandle, setProductHandle] = useState(data.products[0]?.handle ?? "");
  const [threshold, setThreshold] = useState(DEFAULT_REFUND_THRESHOLD);
  const [copiedId, setCopiedId] = useState<string | null>(null);

  const product = data.products.find((p) => p.handle === productHandle) ?? data.products[0];
  const inputs: PromptInputs = { product, minRefundRatePct: parseThreshold(threshold) };
  const unavailablePresets = data.presets.filter((p) => !p.available);
  const syncWarning = SYNC_WARNINGS[data.sync];

  const updateParams = (changes: Record<string, string | null>) => {
    const next = new URLSearchParams(searchParams);
    for (const [key, value] of Object.entries(changes)) {
      if (value === null) next.delete(key);
      else next.set(key, value);
    }
    setSearchParams(next);
  };

  const choosePreset = (key: DatePresetKey) => {
    if (key === "custom") {
      updateParams({ preset: "custom", from: customFrom, to: customTo });
    } else {
      updateParams({ preset: key, from: null, to: null });
    }
  };

  const copy = async (id: string, text: string) => {
    try {
      await navigator.clipboard.writeText(text);
      setCopiedId(id);
      setTimeout(() => setCopiedId(null), 2500);
    } catch {
      console.warn("Clipboard access failed");
    }
  };

  const grouped = useMemo(() => {
    const groups = new Map<PromptCategory, typeof PROMPT_TEMPLATES[number][]>();
    for (const template of PROMPT_TEMPLATES) {
      groups.set(template.category, [...(groups.get(template.category) ?? []), template]);
    }
    return [...groups.entries()];
  }, []);

  return (
    <>
      {syncWarning && <s-banner tone="warning">{syncWarning}</s-banner>}
      {data.productsError && (
        <s-banner tone="warning">Products could not be loaded, so product prompts are unavailable.</s-banner>
      )}

      <s-section heading="Date range">
        <s-stack gap="base">
          <s-stack direction="inline" gap="small">
            {data.presets.map((preset) => (
              <s-button
                key={preset.key}
                variant={data.range.preset === preset.key ? "primary" : "secondary"}
                disabled={!preset.available || isLoading}
                onClick={() => choosePreset(preset.key)}
              >
                {preset.label}
              </s-button>
            ))}
          </s-stack>

          {unavailablePresets.length > 0 && (
            <s-stack gap="small-300">
              {unavailablePresets.map((p) => (
                <s-text key={p.key} color="subdued">
                  {p.label}: {p.reason}
                </s-text>
              ))}
            </s-stack>
          )}

          {data.range.preset === "custom" && (
            <s-stack direction="inline" gap="base" alignItems="end">
              <s-date-field label="Start date" value={customFrom} onInput={(e: Event) => setCustomFrom(inputValue(e))} onChange={(e: Event) => setCustomFrom(inputValue(e))} />
              <s-date-field label="End date" value={customTo} onInput={(e: Event) => setCustomTo(inputValue(e))} onChange={(e: Event) => setCustomTo(inputValue(e))} />
              <s-button disabled={isLoading} onClick={() => updateParams({ preset: "custom", from: customFrom, to: customTo })}>
                Apply
              </s-button>
            </s-stack>
          )}

          {data.resolved ? (
            <s-stack gap="small-200">
              <s-text>
                {data.resolved.from === data.resolved.to ? data.resolved.from : `${data.resolved.from} to ${data.resolved.to}`} ·{" "}
                {data.resolved.days} {data.resolved.days === 1 ? "day" : "days"} · {data.store.timezone} · {data.store.currency}
              </s-text>
              <s-stack direction="inline" gap="small">
                {data.resolved.comparison ? (
                  <s-badge>
                    Compared with {data.resolved.comparison.from} to {data.resolved.comparison.to}
                  </s-badge>
                ) : (
                  <s-badge tone="warning">No comparison period available</s-badge>
                )}
                {data.resolved.partialToday && <s-badge tone="info">Includes today (partial)</s-badge>}
                {data.resolved.startsBeforeHistory && (
                  <s-badge tone="warning">History starts {data.store.historyStart}</s-badge>
                )}
              </s-stack>
            </s-stack>
          ) : (
            <s-banner tone="critical" heading="This date range cannot be used">
              {data.rangeError}
            </s-banner>
          )}
        </s-stack>
      </s-section>

      <s-section heading="Prompt inputs">
        <s-stack gap="base">
          <s-stack direction="inline" gap="small" alignItems="end">
            <s-search-field
              label="Find a product"
              placeholder="Search by title"
              maxLength={255}
              value={productSearch}
              onInput={(e: Event) => setProductSearch(inputValue(e))}
            />
            <s-button disabled={isLoading} onClick={() => updateParams({ q: productSearch.trim() || null })}>
              Search
            </s-button>
          </s-stack>

          {data.products.length === 0 ? (
            <s-text color="subdued">
              {data.productSearch ? `No products match "${data.productSearch}".` : "No products available."}
            </s-text>
          ) : (
            <s-select
              label="Product for product prompts"
              value={product?.handle ?? ""}
              onChange={(e: Event) => setProductHandle(inputValue(e))}
            >
              {data.products.map((p) => (
                <s-option key={p.handle} value={p.handle}>
                  {p.title}
                </s-option>
              ))}
            </s-select>
          )}
          {data.productsTruncated && (
            <s-text color="subdued">Showing the first {data.products.length} products. Search to narrow the list.</s-text>
          )}

          <s-number-field
            label="Refund-rate threshold"
            suffix="%"
            min={0}
            max={100}
            step={0.5}
            value={threshold}
            onInput={(e: Event) => setThreshold(inputValue(e))}
          />
        </s-stack>
      </s-section>

      {grouped.map(([category, templates]) => (
        <s-section key={category} heading={CATEGORY_LABELS[category]}>
          <s-stack gap="base">
            {templates.map((template) => {
              const rendered = renderPrompt(template.id, template.needsRange ? data.resolved : null, data.store, inputs);
              return (
                <s-box key={template.id} border="base" borderRadius="base" padding="base">
                  <s-stack gap="small">
                    <s-stack direction="inline" justifyContent="space-between" alignItems="center">
                      <s-stack gap="small-300">
                        <s-heading>{template.title}</s-heading>
                        <s-text color="subdued">{template.description}</s-text>
                      </s-stack>
                      <s-button
                        icon="clipboard"
                        disabled={!rendered.ok}
                        onClick={() => rendered.ok && copy(template.id, rendered.text)}
                      >
                        {copiedId === template.id ? "Copied" : "Copy"}
                      </s-button>
                    </s-stack>
                    {rendered.ok ? (
                      <s-box background="subdued" padding="small" borderRadius="base">
                        <pre style={{ whiteSpace: "pre-wrap", margin: 0 }}>
                          <code>{rendered.text}</code>
                        </pre>
                      </s-box>
                    ) : (
                      <s-text tone="caution">{rendered.reason}</s-text>
                    )}
                  </s-stack>
                </s-box>
              );
            })}
          </s-stack>
        </s-section>
      ))}
    </>
  );
}

export function ErrorBoundary() {
  return boundary.error(useRouteError());
}

export const headers: HeadersFunction = (headersArgs) => {
  return boundary.headers(headersArgs);
};
