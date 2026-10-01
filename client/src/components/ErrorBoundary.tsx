import { Component, type ErrorInfo, type ReactNode } from "react";
import { sendTelemetry } from "@/lib/telemetry";

/** Last line of defence: a render crash shows a recoverable screen instead of a blank page. */
export class ErrorBoundary extends Component<
  { children: ReactNode },
  { failed: boolean }
> {
  state = { failed: false };
  static getDerivedStateFromError() {
    return { failed: true };
  }
  componentDidCatch(error: Error, _info: ErrorInfo) {
    sendTelemetry({ type: "js_error", detail: `render: ${error.message}` });
  }
  render() {
    if (!this.state.failed) return this.props.children;
    return (
      <main className="center-screen">
        <div className="card-panel" role="alert">
          <h1>Something went wrong</h1>
          <p className="muted">
            The editor hit an unexpected error. Unsaved changes are kept on this
            device and offered back when you reload.
          </p>
          <button className="save-btn" onClick={() => location.reload()}>
            Reload the editor
          </button>
        </div>
      </main>
    );
  }
}
