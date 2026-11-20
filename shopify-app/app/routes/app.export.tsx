import type { ActionFunctionArgs, LoaderFunctionArgs } from "react-router";
import { authenticate } from "../shopify.server.ts";
import { backendClient, BackendError } from "../lib/backend/backendClient.server.ts";

export interface ExportInitiateResponse {
  exportId?: string;
  status?: string;
  error?: string;
  message?: string;
}

export const action = async ({
  request,
}: ActionFunctionArgs): Promise<Response> => {
  const { session } = await authenticate.admin(request);
  const formData = await request.formData();
  const from = formData.get("from") as string;
  const to = formData.get("to") as string;

  if (!from || !to) {
    return new Response(
      JSON.stringify({ error: "missing_dates", message: "Date range is required." }),
      { status: 400, headers: { "Content-Type": "application/json" } },
    );
  }

  const backend = backendClient();

  try {
    const res = await backend.postInternal<{
      data: { id: string; status: string };
    }>("/api/v1/exports/products", session.shop, { from, to });

    return new Response(
      JSON.stringify({ exportId: res.data.id, status: res.data.status }),
      { status: 202, headers: { "Content-Type": "application/json" } },
    );
  } catch (error) {
    if (error instanceof BackendError) {
      try {
        const parsed = JSON.parse(
          error.message.replace(/^Backend S2S call failed \(\d+\): /, ""),
        );
        return new Response(JSON.stringify(parsed), {
          status: error.status,
          headers: { "Content-Type": "application/json" },
        });
      } catch {
        return new Response(
          JSON.stringify({ error: "backend_error", message: error.message }),
          {
            status: error.status,
            headers: { "Content-Type": "application/json" },
          },
        );
      }
    }

    return new Response(
      JSON.stringify({ error: "server_error", message: "Failed to create export." }),
      { status: 500, headers: { "Content-Type": "application/json" } },
    );
  }
};

export const loader = async ({
  request,
}: LoaderFunctionArgs): Promise<Response> => {
  const { session } = await authenticate.admin(request);
  const url = new URL(request.url);
  const exportId = url.searchParams.get("id");
  const download = url.searchParams.get("download") === "true";

  if (!exportId) {
    return new Response(JSON.stringify({ error: "missing_id" }), {
      status: 400,
      headers: { "Content-Type": "application/json" },
    });
  }

  const backend = backendClient();

  if (download) {
    try {
      const rawRes = await backend.getRaw(
        `/api/v1/exports/${exportId}/download`,
        session.shop,
      );

      const contentDisposition =
        rawRes.headers.get("content-disposition") ||
        `attachment; filename="products_export_${exportId}.csv"`;

      return new Response(rawRes.body, {
        status: 200,
        headers: {
          "Content-Type": "text/csv; charset=UTF-8",
          "Content-Disposition": contentDisposition,
        },
      });
    } catch (error) {
      const status = error instanceof BackendError ? error.status : 500;
      return new Response(JSON.stringify({ error: "download_failed" }), {
        status,
        headers: { "Content-Type": "application/json" },
      });
    }
  }

  // Poll status
  try {
    const res = await backend.getInternal<{
      data: { id: string; status: string; row_count: number };
    }>(`/api/v1/exports/${exportId}`, session.shop);

    return new Response(
      JSON.stringify({
        id: res.data.id,
        status: res.data.status,
        rowCount: res.data.row_count,
      }),
      { status: 200, headers: { "Content-Type": "application/json" } },
    );
  } catch (error) {
    const status = error instanceof BackendError ? error.status : 500;
    return new Response(JSON.stringify({ error: "status_check_failed" }), {
      status,
      headers: { "Content-Type": "application/json" },
    });
  }
};
