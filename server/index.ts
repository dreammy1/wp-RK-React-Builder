import { createApp } from "./app";
import { loadEnv } from "./env";
import { log } from "./logger";

const env = loadEnv();
const { app } = createApp(env);
const port = env.PORT ?? (env.NODE_ENV === "production" ? 3000 : 3001);
const server = app.listen(port, () =>
  log("info", "listening", {
    port,
    mode: env.WORDPRESS_AUTH_MODE,
    env: env.NODE_ENV,
  })
);

for (const sig of ["SIGINT", "SIGTERM"] as const) {
  process.on(sig, () => server.close(() => process.exit(0)));
}
