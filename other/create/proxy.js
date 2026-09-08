/**
 * Lightweight Deno Reverse Proxy for NVIDIA NIM / OpenAI API
 * Run locally: deno run --allow-net proxy.js
 * Or deploy to Deno Deploy (deno.com/deploy)
 */

Deno.serve({ port: Number(Deno.env.get("PORT") || 8080) }, async (req) => {
  // 1. Handle CORS Preflight
  if (req.method === "OPTIONS") {
    return new Response(null, {
      status: 204,
      headers: {
        "Access-Control-Allow-Origin": "*",
        "Access-Control-Allow-Methods": "GET, POST, PUT, DELETE, OPTIONS",
        "Access-Control-Allow-Headers": "*",
      },
    });
  }

  const url = new URL(req.url);
  const target = new URL(url.pathname + url.search, "https://integrate.api.nvidia.com");

  // 2. Clone headers and set Host to NVIDIA NIM
  const headers = new Headers(req.headers);
  headers.set("Host", "integrate.api.nvidia.com");

  try {
    // 3. Proxy request with streaming support
    const response = await fetch(target, {
      method: req.method,
      headers: headers,
      body: req.method !== "GET" && req.method !== "HEAD" ? req.body : null,
      redirect: "follow",
    });

    // 4. Return response with CORS headers
    const respHeaders = new Headers(response.headers);
    respHeaders.set("Access-Control-Allow-Origin", "*");
    respHeaders.set("Access-Control-Allow-Methods", "GET, POST, OPTIONS");
    respHeaders.set("Access-Control-Allow-Headers", "*");

    return new Response(response.body, {
      status: response.status,
      statusText: response.statusText,
      headers: respHeaders,
    });
  } catch (err) {
    return new Response(JSON.stringify({ error: err.message }), {
      status: 502,
      headers: {
        "Content-Type": "application/json",
        "Access-Control-Allow-Origin": "*",
      },
    });
  }
});
