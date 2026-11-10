import { createHash, createHmac, randomUUID } from "node:crypto";

export interface S2SClientConfig {
  baseUrl: string;
  secret: string;
}

export interface PingResponse {
  status: string;
  message: string;
  shop_domain: string;
  timestamp: number;
}

export class BackendClient {
  private readonly baseUrl: string;
  private readonly secret: string;

  public constructor(config?: Partial<S2SClientConfig>) {
    this.baseUrl = (
      config?.baseUrl ??
      process.env["BACKEND_API_URL"] ??
      "http://localhost:8000"
    ).replace(/\/+$/, "");

    this.secret =
      config?.secret ??
      process.env["S2S_SHARED_SECRET"] ??
      "7f8c9b2a1e4d5f6a8b0c2d4e6f8a0b2c4d6e8f0a2b4c6d8e0f2a4b6c8d0e2f4";
  }

  public generateSignature(
    method: string,
    path: string,
    timestamp: number,
    shopDomain: string,
    body: string
  ): string {
    const normalizedPath = "/" + path.replace(/^\/+/, "");
    const bodyHash = createHash("sha256").update(body).digest("hex");
    const canonicalPayload = `${method.toUpperCase()}\n${normalizedPath}\n${timestamp}\n${shopDomain}\n${bodyHash}`;

    return createHmac("sha256", this.secret)
      .update(canonicalPayload)
      .digest("hex");
  }

  public async ping(shopDomain: string): Promise<PingResponse> {
    return this.postInternal<PingResponse, Record<string, string>>(
      "/internal/v1/ping",
      shopDomain,
      { client: "react-router-shopify-app" }
    );
  }

  public async postInternal<TResponse, TBody extends object>(
    path: string,
    shopDomain: string,
    body: TBody
  ): Promise<TResponse> {
    const normalizedPath = "/" + path.replace(/^\/+/, "");
    const timestamp = Math.floor(Date.now() / 1000);
    const bodyString = JSON.stringify(body);
    const signature = this.generateSignature(
      "POST",
      normalizedPath,
      timestamp,
      shopDomain,
      bodyString
    );
    const correlationId = randomUUID();

    const response = await fetch(`${this.baseUrl}${normalizedPath}`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-App-Shop-Domain": shopDomain,
        "X-App-Timestamp": timestamp.toString(),
        "X-App-Signature": signature,
        "X-Correlation-ID": correlationId,
      },
      body: bodyString,
    });

    if (!response.ok) {
      const errorText = await response.text();
      throw new Error(
        `Backend S2S call failed (${response.status}): ${errorText}`
      );
    }

    return (await response.json()) as TResponse;
  }
}

export const backendClient = new BackendClient();
