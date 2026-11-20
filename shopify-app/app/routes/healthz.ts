export const loader = async () => {
  return new Response(
    JSON.stringify({
      status: "ok",
      app: "product-analytics-gateway",
      timestamp: new Date().toISOString(),
    }),
    {
      status: 200,
      headers: {
        "Content-Type": "application/json",
        "Cache-Control": "no-store, no-cache, must-revalidate",
      },
    },
  );
};
