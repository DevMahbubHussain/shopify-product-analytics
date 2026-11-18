import { createHash, createHmac, randomUUID } from "node:crypto";

export interface BackendClientConfig {
  baseUrl: string;
  secret: string;
}

function requireEnv(name: string): string {
  const value = process.env[name];
  if (value === undefined || value === "") {
    throw new Error(`${name} environment variable is required.`);
  }
  return value;
}

function normalizePath(path: string): string {
  return "/" + path.replace(/^\/+/, "");
}

export class BackendClient {
  private readonly baseUrl: string;
  private readonly secret: string;

  public constructor(config?: Partial<BackendClientConfig>) {
    this.baseUrl = (config?.baseUrl ?? requireEnv("BACKEND_API_URL")).replace(
      /\/+$/,
      "",
    );
    this.secret = config?.secret ?? requireEnv("S2S_SHARED_SECRET");
  }

  public generateSignature(
    method: string,
    path: string,
    timestamp: number,
    nonce: string,
    shopDomain: string,
    body: string,
  ): string {
    const bodyHash = createHash("sha256").update(body).digest("hex");
    const canonical = [
      method.toUpperCase(),
      normalizePath(path),
      timestamp.toString(),
      nonce,
      shopDomain,
      bodyHash,
    ].join("\n");

    return createHmac("sha256", this.secret).update(canonical).digest("hex");
  }

  public async postInternal<TResponse = unknown>(
    path: string,
    shopDomain: string,
    body: object,
    correlationId: string = randomUUID(),
  ): Promise<TResponse> {
    const normalizedPath = normalizePath(path);
    const timestamp = Math.floor(Date.now() / 1000);
    const nonce = randomUUID();
    const bodyString = JSON.stringify(body);

    const response = await fetch(`${this.baseUrl}${normalizedPath}`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-App-Shop-Domain": shopDomain,
        "X-App-Timestamp": timestamp.toString(),
        "X-App-Nonce": nonce,
        "X-App-Signature": this.generateSignature(
          "POST",
          normalizedPath,
          timestamp,
          nonce,
          shopDomain,
          bodyString,
        ),
        "X-Correlation-ID": correlationId,
      },
      body: bodyString,
    });

    if (!response.ok) {
      const errorText = await response.text();
      throw new Error(
        `Backend S2S call failed (${response.status}): ${errorText}`,
      );
    }

    return (await response.json()) as TResponse;
  }
}

let sharedClient: BackendClient | undefined;

export function backendClient(): BackendClient {
  sharedClient ??= new BackendClient();
  return sharedClient;
}
