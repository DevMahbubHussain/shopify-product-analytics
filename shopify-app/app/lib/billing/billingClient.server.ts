import {
  shopifyQuery,
  type GraphqlAdmin,
} from "../shopify/graphql/shopifyGraphql.server.ts";

export interface PlanConfig {
  name: string;
  planKey: string;
  price: number;
  currencyCode: string;
  interval: "EVERY_30_DAYS" | "ANNUAL";
  trialDays: number;
}

export const PLANS: Record<string, PlanConfig> = {
  pro: {
    name: "Pro Plan",
    planKey: "pro",
    price: 19.0,
    currencyCode: "USD",
    interval: "EVERY_30_DAYS",
    trialDays: 7,
  },
};

export interface AppSubscriptionUserError {
  field: string[];
  message: string;
}

export interface AppSubscriptionPayload {
  id: string;
  name: string;
  status: string;
}

export interface AppSubscriptionCreateResponse {
  appSubscriptionCreate: {
    appSubscription: AppSubscriptionPayload | null;
    confirmationUrl: string | null;
    userErrors: AppSubscriptionUserError[];
  };
}

export const APP_SUBSCRIPTION_CREATE_MUTATION = `#graphql
mutation AppSubscriptionCreate(
  $name: String!
  $lineItems: [AppSubscriptionLineItemInput!]!
  $returnUrl: URL!
  $trialDays: Int
  $test: Boolean
) {
  appSubscriptionCreate(
    name: $name
    lineItems: $lineItems
    returnUrl: $returnUrl
    trialDays: $trialDays
    test: $test
  ) {
    appSubscription {
      id
      name
      status
    }
    confirmationUrl
    userErrors {
      field
      message
    }
  }
}
`;

export interface SubscriptionRequestResult {
  confirmationUrl: string;
  appSubscription: AppSubscriptionPayload | null;
}

export async function requestAppSubscription(
  admin: GraphqlAdmin,
  planKey: string,
  returnUrl: string,
  isTest: boolean = process.env.NODE_ENV !== "production",
): Promise<SubscriptionRequestResult> {
  const plan = PLANS[planKey];
  if (!plan) {
    throw new Error(`Unknown plan key: ${planKey}`);
  }

  const variables = {
    name: plan.name,
    returnUrl,
    trialDays: plan.trialDays,
    test: isTest,
    lineItems: [
      {
        plan: {
          appRecurringPricingDetails: {
            price: {
              amount: plan.price,
              currencyCode: plan.currencyCode,
            },
            interval: plan.interval,
          },
        },
      },
    ],
  };

  const { data } = await shopifyQuery<AppSubscriptionCreateResponse>(
    admin,
    APP_SUBSCRIPTION_CREATE_MUTATION,
    variables,
  );

  const payload = data.appSubscriptionCreate;

  if (payload.userErrors.length > 0) {
    throw new Error(payload.userErrors.map((e) => e.message).join("; "));
  }

  if (!payload.confirmationUrl) {
    throw new Error("No confirmationUrl returned by Shopify.");
  }

  return {
    confirmationUrl: payload.confirmationUrl,
    appSubscription: payload.appSubscription,
  };
}
