import { createServer } from "node:http";
import { backendClient } from "./services/backendClient.js";

const PORT = parseInt(process.env["PORT"] ?? "3000", 10);

const server = createServer(async (req, res) => {
  const url = new URL(req.url ?? "/", `http://${req.headers.host}`);

  if (url.pathname === "/health") {
    res.writeHead(200, { "Content-Type": "application/json" });
    res.end(JSON.stringify({ status: "healthy", service: "shopify-app" }));
    return;
  }

  if (url.pathname === "/s2s-handshake") {
    try {
      const shopDomain = url.searchParams.get("shop") ?? "test-store.myshopify.com";
      const result = await backendClient.ping(shopDomain);
      res.writeHead(200, { "Content-Type": "application/json" });
      res.end(JSON.stringify({ s2s: "verified", backendResponse: result }));
    } catch (err: unknown) {
      res.writeHead(502, { "Content-Type": "application/json" });
      res.end(JSON.stringify({ s2s: "failed", error: err instanceof Error ? err.message : String(err) }));
    }
    return;
  }

  res.writeHead(200, { "Content-Type": "application/json" });
  res.end(JSON.stringify({
    app: "Shopify Product Analytics",
    status: "running",
    environment: process.env["NODE_ENV"] ?? "development",
  }));
});

server.listen(PORT, "0.0.0.0", () => {
  console.log(`Shopify App server listening on port ${PORT}`);
});
