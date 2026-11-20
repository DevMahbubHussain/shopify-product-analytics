import type { ActionFunctionArgs, LoaderFunctionArgs } from "react-router";
import { redirect, Form, useActionData, useLoaderData } from "react-router";

import { authenticate, login } from "../../shopify.server";
import { loginErrorMessage } from "./error.server";

import styles from "./styles.module.css";

export const loader = async ({ request }: LoaderFunctionArgs) => {
  const url = new URL(request.url);

  if (url.searchParams.get("shop")) {
    throw redirect(`/app?${url.searchParams.toString()}`);
  }

  try {
    const { session } = await authenticate.admin(request);
    if (session) {
      throw redirect(`/app${url.search}`);
    }
  } catch (error) {
    if (error instanceof Response) {
      throw error;
    }
  }

  return { showForm: Boolean(login) };
};

export const action = async ({ request }: ActionFunctionArgs) => {
  const errors = loginErrorMessage(await login(request));

  return { errors };
};

export default function App() {
  const { showForm } = useLoaderData<typeof loader>();
  const actionData = useActionData<typeof action>();
  const errors = actionData?.errors;

  return (
    <div className={styles.index}>
      <div className={styles.content}>
        <h1 className={styles.heading}>Product Analytics</h1>
        <p className={styles.text}>
          Product-level sales reporting, variant analytics, and performance
          insights for Shopify merchants.
        </p>
        {showForm && (
          <Form className={styles.form} method="post">
            <label className={styles.label}>
              <span>Shop domain</span>
              <input
                className={styles.input}
                type="text"
                name="shop"
                placeholder="my-store.myshopify.com"
              />
              <span>{errors?.shop ?? "e.g: my-shop-domain.myshopify.com"}</span>
            </label>
            <button className={styles.button} type="submit">
              Log in
            </button>
          </Form>
        )}
        <ul className={styles.list}>
          <li>
            <strong>Overview Performance</strong>. Real-time net sales, gross
            sales, units sold, discounts, refunds, and average order revenue
            across flexible date ranges.
          </li>
          <li>
            <strong>Product &amp; Variant Insights</strong>. Deep-dive into
            variant-level sales breakdowns, revenue shares, and stock levels to
            guide merchandising.
          </li>
          <li>
            <strong>Automated Sync &amp; CSV Exports</strong>. Continuous
            automated data synchronization with on-demand CSV report generation
            and downloads.
          </li>
        </ul>
      </div>
    </div>
  );
}
