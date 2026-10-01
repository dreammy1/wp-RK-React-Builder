import { useEffect, useState } from "react";
import { api } from "@/lib/api/builder";
import { describeError, isApiError } from "@/lib/api/errors";
import { sendTelemetry } from "@/lib/telemetry";
import { getApiConfig } from "@/lib/api/http";
import { useRoute } from "@/lib/router";
import { EditorPage } from "@/components/editor/EditorPage";
import { LoginScreen } from "@/components/Login";
import { PageSelector } from "@/components/PageSelector";

type Boot =
  | { phase: "booting" }
  | { phase: "error"; message: string }
  | { phase: "login" }
  | { phase: "ready" };

export default function App() {
  const { route, navigate } = useRoute();
  const [boot, setBoot] = useState<Boot>({ phase: "booting" });

  useEffect(() => {
    api
      .bootstrap()
      .then(r => setBoot({ phase: r.authenticated ? "ready" : "login" }))
      .catch(e => {
        sendTelemetry({
          type: "load_failure",
          detail: `boot: ${isApiError(e) ? e.kind : "unknown"}`,
        });
        setBoot({ phase: "error", message: describeError(e) });
      });
  }, []);

  if (boot.phase === "booting")
    return (
      <main className="center-screen">
        <p role="status">Starting…</p>
      </main>
    );
  if (boot.phase === "error")
    return (
      <main className="center-screen">
        <div className="card-panel" role="alert">
          <h1>Builder unavailable</h1>
          <p className="muted">{boot.message}</p>
          <button className="save-btn" onClick={() => location.reload()}>
            Retry
          </button>
        </div>
      </main>
    );
  if (boot.phase === "login")
    return <LoginScreen onDone={() => setBoot({ phase: "ready" })} />;

  if (route.name === "builder") {
    return (
      <EditorPage
        key={route.pageId}
        pageId={route.pageId}
        demo={route.demo}
        navigate={navigate}
      />
    );
  }
  return (
    <PageSelector
      navigate={navigate}
      onSignOut={
        getApiConfig().mode === "proxy"
          ? () => {
              void api.logout().then(() => setBoot({ phase: "login" }));
            }
          : undefined
      }
    />
  );
}
