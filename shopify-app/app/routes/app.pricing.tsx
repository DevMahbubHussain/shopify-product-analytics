import type {
  ActionFunctionArgs,
  HeadersFunction,
  LoaderFunctionArgs,
} from "react-router";
import {
  Form,
  useActionData,
  useLoaderData,
  useNavigation,
  useRouteError,
} from "react-router";
import { boundary } from "@shopify/shopify-app-react-router/server";
import { authenticate } from "../shopify.server";
import { requestAppSubscription } from "../lib/billing/billingClient.server";
import { shopifyQuery } from "../lib/shopify/graphql/shopifyGraphql.server";
import { backendClient } from "../lib/backend/backendClient.server";
import { getSubscriptionStatus } from "../features/billing/getSubscription.server";

interface ActiveSubscription {
  id: string;
  name: string;
  status: string;
  currentPeriodEnd: string | null;
}

interface AppInstallationQueryResponse {
  appInstallation: {
    activeSubscriptions: ActiveSubscription[];
  };
}

const APP_INSTALLATION_QUERY = `#graphql
query AppInstallationSubscription {
  appInstallation {
    activeSubscriptions {
      id
      name
      status
      currentPeriodEnd
    }
  }
}
`;

export const loader = async ({ request }: LoaderFunctionArgs) => {
  const { admin, session } = await authenticate.admin(request);

  // Check backend PostgreSQL subscription status (S2S source of truth)
  const backendSub = await getSubscriptionStatus(
    backendClient(),
    session.shop,
  );

  let activeSubscription: ActiveSubscription | null = null;
  try {
    const { data } = await shopifyQuery<AppInstallationQueryResponse>(
      admin,
      APP_INSTALLATION_QUERY,
    );
    const subs = data.appInstallation?.activeSubscriptions ?? [];
    activeSubscription =
      subs.find((s) => s.status.toUpperCase() === "ACTIVE") ?? null;
  } catch (error) {
    console.error("Failed to query app installation subscriptions:", error);
  }

  // Active if either backend records Pro or Shopify GraphQL reports active
  const currentPlan =
    backendSub.plan_key === "pro" || activeSubscription !== null
      ? "pro"
      : "free";

  return {
    shop: session.shop,
    currentPlan,
    activeSubscription,
    backendSub,
  };
};

export const action = async ({ request }: ActionFunctionArgs) => {
  const { admin, redirect } = await authenticate.admin(request);
  const formData = await request.formData();
  const planKey = (formData.get("planKey") as string) || "pro";

  const appUrl = process.env.SHOPIFY_APP_URL || "";
  const returnUrl = `${appUrl}/app/pricing`;

  try {
    const result = await requestAppSubscription(
      admin,
      planKey,
      returnUrl,
      process.env.NODE_ENV !== "production",
    );

    return redirect(result.confirmationUrl, { target: "_top" });
  } catch (error) {
    const message =
      error instanceof Error ? error.message : "Failed to initiate subscription.";
    return { error: message };
  }
};

export default function PricingPage() {
  const { currentPlan, activeSubscription, backendSub } =
    useLoaderData<typeof loader>();
  const actionData = useActionData<typeof action>();
  const navigation = useNavigation();
  const isUpgrading = navigation.state === "submitting";

  const nextBillingDate =
    activeSubscription?.currentPeriodEnd ?? backendSub.current_period_end;

  return (
    <s-page heading="Plans & Billing">
      <s-link slot="breadcrumb-actions" href="/app">
        Dashboard
      </s-link>

      <div style={{ maxWidth: "880px", margin: "0 auto", padding: "16px 0" }}>
        {/* Action Error Banner */}
        {actionData?.error && (
          <div style={{ marginBottom: "20px" }}>
            <s-banner heading="Subscription Request Failed" tone="critical">
              <s-paragraph>{actionData.error}</s-paragraph>
            </s-banner>
          </div>
        )}

        {/* Current status header */}
        <div
          style={{
            background: "#ffffff",
            border: "1px solid #e1e3e5",
            borderRadius: "8px",
            padding: "20px 24px",
            marginBottom: "24px",
            display: "flex",
            justifyContent: "space-between",
            alignItems: "center",
          }}
        >
          <div>
            <div
              style={{
                fontSize: "14px",
                color: "#616161",
                marginBottom: "4px",
              }}
            >
              Current Plan
            </div>
            <div
              style={{
                fontSize: "20px",
                fontWeight: 700,
                color: "#202223",
              }}
            >
              {currentPlan === "pro"
                ? "Pro Plan ($19/mo)"
                : "Free Plan ($0/mo)"}
            </div>
            {nextBillingDate && (
              <div
                style={{
                  fontSize: "12px",
                  color: "#616161",
                  marginTop: "4px",
                }}
              >
                Next billing date:{" "}
                {new Date(nextBillingDate).toLocaleDateString()}
              </div>
            )}
          </div>
          <s-badge tone={currentPlan === "pro" ? "success" : "info"}>
            {currentPlan === "pro" ? "Active Subscription" : "Free Tier"}
          </s-badge>
        </div>

        {/* Pricing Cards */}
        <div
          style={{
            display: "grid",
            gridTemplateColumns: "repeat(auto-fit, minmax(320px, 1fr))",
            gap: "24px",
          }}
        >
          {/* Free Tier */}
          <div
            style={{
              background: "#ffffff",
              border:
                currentPlan === "free"
                  ? "2px solid #008060"
                  : "1px solid #e1e3e5",
              borderRadius: "8px",
              padding: "24px",
              display: "flex",
              flexDirection: "column",
            }}
          >
            <div
              style={{
                display: "flex",
                justifyContent: "space-between",
                alignItems: "center",
                marginBottom: "12px",
              }}
            >
              <h2 style={{ fontSize: "18px", fontWeight: 700, margin: 0 }}>
                Free
              </h2>
              {currentPlan === "free" && (
                <s-badge tone="success">Current Plan</s-badge>
              )}
            </div>

            <div style={{ marginBottom: "20px" }}>
              <span style={{ fontSize: "32px", fontWeight: 800 }}>$0</span>
              <span style={{ color: "#616161", fontSize: "14px" }}>
                {" "}
                / month
              </span>
            </div>

            <ul
              style={{
                listStyle: "none",
                padding: 0,
                margin: "0 0 24px 0",
                fontSize: "14px",
                lineHeight: "28px",
                color: "#202223",
                flexGrow: 1,
              }}
            >
              <li>✓ Up to 30 days analytics history</li>
              <li>✓ Overview dashboard &amp; KPI metrics</li>
              <li>✓ Daily sales trend visualizer</li>
              <li>✓ Top products performance table</li>
              <li>✓ Single product &amp; variant drilldown</li>
              <li style={{ color: "#8c9196" }}>
                ✗ 365-day historical reporting
              </li>
              <li style={{ color: "#8c9196" }}>
                ✗ CSV data export downloads
              </li>
            </ul>

            <s-button
              disabled
              variant={currentPlan === "free" ? "secondary" : "tertiary"}
            >
              {currentPlan === "free" ? "Active" : "Included"}
            </s-button>
          </div>

          {/* Pro Tier */}
          <div
            style={{
              background: "#ffffff",
              border:
                currentPlan === "pro"
                  ? "2px solid #008060"
                  : "1px solid #008060",
              borderRadius: "8px",
              padding: "24px",
              display: "flex",
              flexDirection: "column",
              position: "relative",
              boxShadow: "0 4px 12px rgba(0, 128, 96, 0.08)",
            }}
          >
            <div
              style={{
                display: "flex",
                justifyContent: "space-between",
                alignItems: "center",
                marginBottom: "12px",
              }}
            >
              <h2 style={{ fontSize: "18px", fontWeight: 700, margin: 0 }}>
                Pro Plan
              </h2>
              {currentPlan === "pro" ? (
                <s-badge tone="success">Current Plan</s-badge>
              ) : (
                <s-badge tone="info">7-Day Free Trial</s-badge>
              )}
            </div>

            <div style={{ marginBottom: "20px" }}>
              <span style={{ fontSize: "32px", fontWeight: 800 }}>$19</span>
              <span style={{ color: "#616161", fontSize: "14px" }}>
                {" "}
                / month
              </span>
            </div>

            <ul
              style={{
                listStyle: "none",
                padding: 0,
                margin: "0 0 24px 0",
                fontSize: "14px",
                lineHeight: "28px",
                color: "#202223",
                flexGrow: 1,
              }}
            >
              <li>
                ✓ <strong>365 days</strong> historical analytics
              </li>
              <li>
                ✓ <strong>Unlimited CSV data exports</strong>
              </li>
              <li>✓ Preceding period comparison</li>
              <li>✓ Overview dashboard &amp; KPI cards</li>
              <li>✓ Daily &amp; monthly sales trend charts</li>
              <li>✓ Full product catalog performance</li>
              <li>✓ Variant-level sales &amp; return drilldown</li>
              <li>✓ 7-day risk-free trial</li>
            </ul>

            {currentPlan === "pro" ? (
              <s-button disabled variant="secondary">
                Active
              </s-button>
            ) : (
              <Form method="post">
                <input type="hidden" name="planKey" value="pro" />
                <s-button
                  type="submit"
                  variant="primary"
                  loading={isUpgrading ? true : undefined}
                >
                  Start 7-Day Free Trial
                </s-button>
              </Form>
            )}
          </div>
        </div>
      </div>
    </s-page>
  );
}

export function ErrorBoundary() {
  return boundary.error(useRouteError());
}

export const headers: HeadersFunction = (headersArgs) => {
  return boundary.headers(headersArgs);
};
