import { createHash, createHmac, randomUUID } from "node:crypto";

export interface BackendClientConfig {
  baseUrl: string;
  secret: string;
}

export class BackendError extends Error {
  public readonly status: number;

  public constructor(status: number, body: string) {
    super(`Backend S2S call failed (${status}): ${body}`);
    this.name = "BackendError";
    this.status = status;
  }
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

export type QueryParams = Record<
  string,
  string | number | boolean | undefined | null
>;

function buildQueryString(
  inlineQuery?: string,
  params?: QueryParams,
): string {
  const searchParams = new URLSearchParams(inlineQuery ?? "");
  if (params) {
    for (const [key, value] of Object.entries(params)) {
      if (value !== undefined && value !== null) {
        searchParams.set(key, String(value));
      }
    }
  }
  const query = searchParams.toString();
  return query ? `?${query}` : "";
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
    const response = await this.signedFetch("POST", path, shopDomain, correlationId, {
      body: JSON.stringify(body),
    });
    return (await response.json()) as TResponse;
  }

  public async deleteInternal<TResponse = unknown>(
    path: string,
    shopDomain: string,
    correlationId: string = randomUUID(),
  ): Promise<TResponse> {
    const response = await this.signedFetch("DELETE", path, shopDomain, correlationId);
    return (await response.json()) as TResponse;
  }

  public async getInternal<TResponse = unknown>(
    path: string,
    shopDomain: string,
    correlationId: string = randomUUID(),
    params?: QueryParams,
  ): Promise<TResponse> {
    const response = await this.signedFetch("GET", path, shopDomain, correlationId, {
      params,
    });
    return (await response.json()) as TResponse;
  }

  public async getRaw(
    path: string,
    shopDomain: string,
    correlationId: string = randomUUID(),
    params?: QueryParams,
  ): Promise<Response> {
    return this.signedFetch("GET", path, shopDomain, correlationId, {
      params,
      accept: "*/*",
    });
  }

  private async signedFetch(
    method: "GET" | "POST" | "DELETE",
    path: string,
    shopDomain: string,
    correlationId: string,
    options: { body?: string; params?: QueryParams; accept?: string } = {},
  ): Promise<Response> {
    const [pathOnly, inlineQuery] = path.split("?");
    const normalizedPath = normalizePath(pathOnly);
    const queryString = buildQueryString(inlineQuery, options.params);
    const body = options.body ?? "";
    const timestamp = Math.floor(Date.now() / 1000);
    const nonce = randomUUID();

    const headers: Record<string, string> = {
      Accept: options.accept ?? "application/json",
      "X-App-Shop-Domain": shopDomain,
      "X-App-Timestamp": timestamp.toString(),
      "X-App-Nonce": nonce,
      "X-App-Signature": this.generateSignature(
        method,
        normalizedPath,
        timestamp,
        nonce,
        shopDomain,
        body,
      ),
      "X-Correlation-ID": correlationId,
    };
    if (options.body !== undefined) {
      headers["Content-Type"] = "application/json";
    }

    const response = await fetch(`${this.baseUrl}${normalizedPath}${queryString}`, {
      method,
      headers,
      body: options.body,
    });

    if (!response.ok) {
      throw new BackendError(response.status, await response.text());
    }

    return response;
  }
}

let sharedClient: BackendClient | undefined;

export function backendClient(): BackendClient {
  sharedClient ??= new BackendClient();
  return sharedClient;
}
