import { useEffect } from "react";
import type {
  ActionFunctionArgs,
  HeadersFunction,
  LoaderFunctionArgs,
} from "react-router";
import { useFetcher, useLoaderData, useRevalidator } from "react-router";
import { boundary } from "@shopify/shopify-app-react-router/server";
import { authenticate, unauthenticated } from "../shopify.server";
import { backendClient } from "../lib/backend/backendClient.server";
import {
  getSyncStatus,
  type ShopSyncStatus,
} from "../features/sync/getSyncStatus.server";
import { triggerHistoricalSync } from "../features/sync/syncTrigger.server";

type SyncState = ShopSyncStatus["state"];

const RETRYABLE_STATES: ReadonlySet<SyncState> = new Set(["failed", "stalled"]);

const STATUS_LABELS: Record<SyncState, string> = {
  never_synced: "Setting up...",
  syncing: "Importing data...",
  completed: "Data synchronized",
  failed: "Sync error",
  stalled: "Sync stopped responding",
  backend_error: "Service temporarily unavailable",
};

export const loader = async ({ request }: LoaderFunctionArgs) => {
  const { admin, session } = await authenticate.admin(request);
  const backend = backendClient();
  const shopDomain = session.shop;

  let syncStatus: ShopSyncStatus;
  try {
    syncStatus = await getSyncStatus({ backend, shopDomain });

    if (syncStatus.state === "never_synced") {
      const triggerResult = triggerHistoricalSync({
        admin,
        backend,
        shopDomain,
        getAdmin: async (shop) => (await unauthenticated.admin(shop)).admin,
      });

      if (triggerResult.started) {
        syncStatus = { state: "syncing", syncRun: null };
      }
    }
  } catch (error) {
    console.error(`Backend unreachable in app loader for ${shopDomain}:`, error);
    syncStatus = {
      state: "backend_error",
      syncRun: null,
      error: error instanceof Error ? error.message : "Service unavailable",
    };
  }

  return { shopDomain, syncStatus };
};

export const action = async ({ request }: ActionFunctionArgs) => {
  const { admin, session } = await authenticate.admin(request);
  const backend = backendClient();
  const shopDomain = session.shop;

  try {
    const syncStatus = await getSyncStatus({ backend, shopDomain });

    if (!RETRYABLE_STATES.has(syncStatus.state)) {
      return { retried: false, state: syncStatus.state };
    }

    const triggerResult = triggerHistoricalSync({
      admin,
      backend,
      shopDomain,
      getAdmin: async (shop) => (await unauthenticated.admin(shop)).admin,
    });

    return { retried: triggerResult.started, state: syncStatus.state };
  } catch (error) {
    console.error(`Backend unreachable in app action for ${shopDomain}:`, error);
    return { retried: false, state: "backend_error" as const };
  }
};

function RetryButton() {
  const fetcher = useFetcher<typeof action>();
  const submitting = fetcher.state !== "idle";

  return (
    <fetcher.Form method="post">
      <s-button type="submit" variant="primary" loading={submitting}>
        Retry sync
      </s-button>
    </fetcher.Form>
  );
}

export default function Index() {
  const { shopDomain, syncStatus } = useLoaderData<typeof loader>();
  const revalidator = useRevalidator();

  useEffect(() => {
    if (syncStatus.state !== "syncing") {
      return;
    }

    const interval = setInterval(() => {
      if (revalidator.state === "idle") {
        revalidator.revalidate();
      }
    }, 3000);

    return () => clearInterval(interval);
  }, [syncStatus.state, revalidator]);

  return (
    <s-page heading="Product Analytics">
      {syncStatus.state === "syncing" && (
        <s-banner heading="Syncing your store data" tone="info">
          <s-paragraph>
            We are currently importing your products, orders, and refund history in the
            background. This may take a few moments depending on catalogue size.
          </s-paragraph>
        </s-banner>
      )}

      {syncStatus.state === "completed" && (
        <s-banner heading="Sync complete" tone="success">
          <s-paragraph>
            Your store is connected and initial data sync is complete. Product and
            order data will be processed into daily metrics.
          </s-paragraph>
        </s-banner>
      )}

      {syncStatus.state === "failed" && (
        <s-banner heading="Sync interrupted" tone="critical">
          <s-paragraph>
            We encountered an issue during data synchronization
            {syncStatus.syncRun.errorCode ? ` (${syncStatus.syncRun.errorCode})` : ""}.
          </s-paragraph>
          <RetryButton />
        </s-banner>
      )}

      {syncStatus.state === "stalled" && (
        <s-banner heading="Sync stopped responding" tone="warning">
          <s-paragraph>
            The data import has not reported progress for over 10 minutes and may
            have been interrupted. Start it again to continue.
          </s-paragraph>
          <RetryButton />
        </s-banner>
      )}

      {syncStatus.state === "backend_error" && (
        <s-banner heading="Analytics engine temporarily unreachable" tone="warning">
          <s-paragraph>
            We could not connect to the analytics backend. Background sync status
            and analytics reports may be temporarily unavailable. We will automatically
            reconnect when the service responds.
          </s-paragraph>
        </s-banner>
      )}

      <s-section heading="Overview">
        <s-paragraph>
          Store domain: <strong>{shopDomain}</strong>
        </s-paragraph>
        <s-paragraph>
          Status: <strong>{STATUS_LABELS[syncStatus.state]}</strong>
        </s-paragraph>
      </s-section>
    </s-page>
  );
}

export const headers: HeadersFunction = (headersArgs) => {
  return boundary.headers(headersArgs);
};
